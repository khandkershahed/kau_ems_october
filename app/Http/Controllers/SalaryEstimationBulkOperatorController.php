<?php
namespace App\Http\Controllers;

use App\Http\Controllers\SalaryBulkCalculationPersistenceController;
use App\Services\SalaryWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use DomainException;
use Throwable;

/** HR calculation orchestration: no salary bill, arrear payment or GPF ledger writes. */
final class SalaryEstimationBulkOperatorController extends Controller
{
    public function batch(Request $request, SalaryBulkCalculationPersistenceController $bulk, SalaryWorkflowService $workflow)
    {
        abort_unless($request->user(), 401);
        $d = $request->validate([
            'year'=>'required|integer|in:2026', 'month'=>'required|integer|in:10',
            'cursor'=>'nullable|integer|min:0', 'batch_size'=>'nullable|integer|between:1,25',
            'reason'=>'required|string|min:3|max:2000',
            'action'=>'required|in:approved,draft',
        ]);
        if (!Schema::hasTable('salary_calculation_versions') || !Schema::hasTable('salary_monthly_workflows')) {
            return response()->json(['message'=>'Payroll workflow migrations are required.'],409);
        }
        $cursor=(int)($d['cursor']??0); $size=(int)($d['batch_size']??10);
        $profiles=DB::table('employee_infos')->select('user_id')
            ->selectRaw('MIN(employeeCode) AS code, COUNT(*) AS profiles')
            ->groupBy('user_id');
        $candidates=DB::query()->fromSub($profiles,'p')
            ->leftJoin('salary_monthly_workflows as w',function($j){
                $j->on('w.user_id','=','p.user_id')->where('w.salary_year','=',2026)->where('w.salary_month','=',10);
            })
            ->where('p.user_id','>',$cursor)
            ->where(function($q){$q->whereNull('w.id')->orWhereIn('w.workflow_status',['BLOCKED','CALCULATED']);})
            ->select('p.user_id','p.code','p.profiles','w.workflow_status','w.revision','w.active_calculation_version_id')
            ->orderBy('p.user_id')->limit($size)->get();
        $results=[];
        foreach($candidates as $candidate){
            $uid=(int)$candidate->user_id;
            $cursor=$uid;
            try {
                if ((int)$candidate->profiles !== 1) throw new DomainException('Duplicate employee profiles; HR identity review required.');
                if (DB::table('salary_generates')->where('user_id',$uid)->where('salaryGenerationYear',2026)->where('monthId',10)->exists()) {
                    throw new DomainException('Already posted; no recalculation.');
                }
                $latest=DB::table('salary_calculation_versions')->where('user_id',$uid)
                    ->where('salary_year',2026)->where('salary_month',10)->orderByDesc('version')->first();
                if ($latest && $latest->status==='approved') {
                    $state=DB::table('salary_monthly_workflows')->where('user_id',$uid)->where('salary_year',2026)->where('salary_month',10)->first();
                    if ($state && $state->workflow_status==='BLOCKED') {
                        $workflow->transition($uid,2026,10,'approve_calculation',(int)$request->user()->id,$d['reason'],(int)$latest->id,null,null,(int)$state->revision);
                    }
                    $results[]=['user_id'=>$uid,'code'=>$candidate->code,'ok'=>true,'status'=>'preserved_approved','message'=>'Existing approved version retained; not overwritten.'];
                    continue;
                }
                // Both statuses use the Calculator's authoritative snapshot persistence.
                $internal=Request::create('/dashboard/salary/estimation/bulk-save-calculations','POST',[
                    'year'=>2026,'month'=>10,'employee_codes'=>[(string)$candidate->code],'reason'=>$d['reason'],
                    'save_status'=>$d['action'],
                ]);
                $internal->setUserResolver(fn()=>$request->user());
                $response=$bulk->save($internal,app(SalaryCalculatorEntryController::class),app(\App\Services\ApprovedFixationResolver::class),app(\App\Services\SalaryCalculationService::class),app(\App\Services\SalaryWorkflowRosterService::class));
                $body=json_decode((string)$response->getContent(),true)?:[];
                $r=$body['results'][0]??null;
                if (!$r || empty($r['ok'])) throw new DomainException($r['error']??$body['message']??'Bulk persistence failed.');
                $saved=DB::table('salary_calculation_versions')->where('id',$r['version_id'])->first();
                $state=DB::table('salary_monthly_workflows')->where('user_id',$uid)->where('salary_year',2026)->where('salary_month',10)->first();
                if (!$saved || $saved->status!==$d['action'] || !$state) throw new DomainException('Requested snapshot or workflow missing after persistence.');
                if ($d['action'] === 'approved' && $state->workflow_status==='BLOCKED') {
                    $workflow->transition($uid,2026,10,'approve_calculation',(int)$request->user()->id,$d['reason'],
                        (int)$saved->id,null,null,(int)$state->revision);
                }
                $results[]=['user_id'=>$uid,'code'=>$candidate->code,'ok'=>true,'status'=>$d['action'] === 'draft' ? 'saved_draft' : 'calculated','version_id'=>(int)$saved->id];
            } catch(Throwable $e){
                $results[]=['user_id'=>$uid,'code'=>$candidate->code,'ok'=>false,'status'=>'blocked','message'=>$e->getMessage()];
            }
        }
        $remaining=DB::query()->fromSub($profiles,'p')->leftJoin('salary_monthly_workflows as w',function($j){
            $j->on('w.user_id','=','p.user_id')->where('w.salary_year','=',2026)->where('w.salary_month','=',10);
        })->where('p.user_id','>',$cursor)->where(function($q){$q->whereNull('w.id')->orWhereIn('w.workflow_status',['BLOCKED','CALCULATED']);})->exists();
        return response()->json(['cursor'=>$cursor,'examined'=>count($results),'has_more'=>$remaining,
            'succeeded'=>count(array_filter($results,fn($r)=>$r['ok'])),
            'failed'=>count(array_filter($results,fn($r)=>!$r['ok'])),'results'=>$results]);
    }
}
