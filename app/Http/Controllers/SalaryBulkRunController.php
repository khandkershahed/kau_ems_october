<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SalaryBillPostingService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Resumable payroll batch: authenticated HR-style operator, no role workflow. */
final class SalaryBulkRunController extends Controller
{
    private function getRun(string $token): object
    {
        return DB::table('salary_bulk_runs')->where('run_token',$token)->first()
            ?? throw new DomainException('Unknown batch run token.');
    }

    public function start(Request $request)
    {
        $input=$request->validate([
            'year'=>'required|integer|between:2020,2100',
            'month'=>'required|integer|between:1,12',
            'user_ids'=>'required|array|min:1|max:1000',
            'user_ids.*'=>'required|integer|min:1|distinct',
            'preview_hashes'=>'required|array|min:1',
        ]);
        if (!Schema::hasTable('salary_bulk_runs')) return response()->json(['message'=>'Phase 5 migration is required.'],422);
        $ids=array_values(array_unique(array_map('intval',$input['user_ids'])));
        $existing=User::whereIn('id',$ids)->pluck('id')->map(fn($v)=>(int)$v)->all();
        if (count($existing)!==count($ids)) return response()->json(['message'=>'An employee selection does not exist.'],422);
        $hashes=$input['preview_hashes'];
        foreach ($ids as $id) {
            $hash=$hashes[$id] ?? null;
            if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
                return response()->json(['message'=>'A current read-only salary preview and source hash is required for every selected employee.'],422);
            }
        }
        if (count($hashes)!==count($ids)) {
            return response()->json(['message'=>'Preview hash count differs from the selected employee count.'],422);
        }
        $token=(string)Str::uuid();
        DB::transaction(function() use ($token,$input,$ids,$hashes,$request){
            $id=DB::table('salary_bulk_runs')->insertGetId([
                'run_token'=>$token,'salary_year'=>$input['year'],'salary_month'=>$input['month'],
                'status'=>'prepared','created_by'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach (array_chunk($ids,200) as $group){
                DB::table('salary_bulk_run_items')->insert(array_map(fn($u)=>[
                    'salary_bulk_run_id'=>$id,'user_id'=>$u,'status'=>'queued',
                    'source_hash'=>$hashes[$u],
                    'created_at'=>now(),'updated_at'=>now(),
                ],$group));
            }
        });
        return response()->json(['run_token'=>$token,'total'=>count($ids),'batch_size'=>25]);
    }

    /** A bounded request whose employee transactions commit independently.
     * The phase5 source transaction guards all financial row mutations for one employee.
     */
    public function next(Request $request, string $token, SalaryBillPostingService $service)
    {
        if (!Schema::hasTable('salary_bulk_runs')) return response()->json(['message'=>'Phase 5 migration is required.'],422);
        try {
            $run=$this->getRun($token);
            // A previous PHP timeout can leave an item claimed. Re-queue only
            // stale claims; SalaryBillPostingService makes recovery idempotent.
            DB::table('salary_bulk_run_items')->where('salary_bulk_run_id',$run->id)
                ->where('status','processing')->where('updated_at','<',now()->subMinutes(10))
                ->update(['status'=>'queued','updated_at'=>now()]);
            $candidates=DB::table('salary_bulk_run_items')->where('salary_bulk_run_id',$run->id)
                ->where('status','queued')->orderBy('id')->limit(25)->pluck('id');
            $processed=0;
            foreach($candidates as $id) {
                // Claim each item atomically so concurrent workers do not do
                // duplicate work; never lock an entire 25-employee generation.
                $claimed=DB::table('salary_bulk_run_items')->where('id',$id)
                    ->where('status','queued')->update(['status'=>'processing','updated_at'=>now()]);
                if (!$claimed) continue;
                $item=DB::table('salary_bulk_run_items')->where('id',$id)->first();
                $processed++;
                try {
                    $saved=$service->post((int)$item->user_id,(int)$run->salary_year,(int)$run->salary_month,(int)$run->created_by,null,
                        $item->source_hash ? (string)$item->source_hash : null);
                    DB::table('salary_bulk_run_items')->where('id',$id)->update([
                        'status'=>$saved['result']==='generated'?'generated':'skipped',
                        'salary_generate_id'=>$saved['bill_id'],
                        'net_payable'=>$saved['net_salary'],
                        'source_hash'=>$saved['source_hash']??null,
                        'message'=>$saved['result'],'updated_at'=>now(),
                    ]);
                } catch (DomainException $e) {
                    $this->recordPostingFailure($item, $run, $e->getMessage());
                    DB::table('salary_bulk_run_items')->where('id',$id)->update([
                        'status'=>'blocked','message'=>$e->getMessage(),'updated_at'=>now(),
                    ]);
                } catch (\Throwable $e) {
                    $this->recordPostingFailure($item, $run, 'Unexpected posting failure; inspect Laravel logs.');
                    report($e);
                    DB::table('salary_bulk_run_items')->where('id',$id)->update([
                        'status'=>'error','message'=>'Unexpected server error; inspect Laravel logs.','updated_at'=>now(),
                    ]);
                }
            }
            $remaining=DB::table('salary_bulk_run_items')->where('salary_bulk_run_id',$run->id)
                ->whereIn('status',['queued','processing'])->count();
            DB::table('salary_bulk_runs')->where('id',$run->id)->update([
                'status'=>$remaining?'running':'completed','updated_at'=>now(),
            ]);
            return response()->json(['remaining'=>$remaining,'processed'=>$processed]+$this->summaryData($this->getRun($token)));
        } catch (DomainException $e) {return response()->json(['message'=>$e->getMessage()],422);}
    }

    /** A failed bill transaction has already rolled back. Record the failure separately. */
    private function recordPostingFailure(object $item, object $run, string $reason): void
    {
        try {
            $state = DB::table('salary_monthly_workflows')
                ->where('user_id', $item->user_id)->where('salary_year', $run->salary_year)
                ->where('salary_month', $run->salary_month)->first();
            if (!$state || $state->workflow_status !== 'READY_FOR_GENERATION') return;
            app(\App\Services\SalaryWorkflowService::class)->transition(
                (int) $item->user_id, (int) $run->salary_year, (int) $run->salary_month,
                'posting_failed', (int) $run->created_by, 'Posting failed: '.substr($reason, 0, 1700),
                null, null, substr($reason, 0, 1700), (int) $state->revision
            );
        } catch (\Throwable $auditException) {
            // Never conceal the original posting failure or alter an existing bill.
            report($auditException);
        }
    }

    private function summaryData(object $run): array
    {
        $items=DB::table('salary_bulk_run_items')->where('salary_bulk_run_id',$run->id);
        $counts=(clone $items)->select('status',DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total','status')->all();
        return ['run_token'=>$run->run_token,'status'=>$run->status,'counts'=>$counts,
            'total'=>array_sum($counts),
            'net_total'=>(float)(clone $items)->whereIn('status',['generated','skipped'])->sum('net_payable')];
    }

    public function summary(string $token)
    {
        try {return response()->json($this->summaryData($this->getRun($token)));}
        catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],404);}
    }

    /** CSV is based on saved generation outcomes, not projected payable promises. */
    public function csv(string $token)
    {
        try {$run=$this->getRun($token);}catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],404);}
        $rows=DB::table('salary_bulk_run_items')->where('salary_bulk_run_id',$run->id)->orderBy('id')->get();
        return response()->streamDownload(function() use ($rows){
            $out=fopen('php://output','w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Bengali spreadsheet tools.
            fputcsv($out,['user_id','status','bill_id','net_payable','source_hash','message']);
            foreach($rows as $row) {
                $values=[$row->user_id,$row->status,$row->salary_generate_id,$row->net_payable,$row->source_hash,$row->message];
                fputcsv($out,array_map(static function($value){
                    if (!is_string($value)) return $value;
                    return preg_match('/^\s*[=+@-]/u',$value) ? '\''.$value : $value;
                },$values));
            }
            fclose($out);
        },'KAU_salary_batch_'.$token.'.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
    }
}
