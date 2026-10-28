<?php

namespace App\Console\Commands;

use App\Services\SalaryCalculationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Local-only review migration: creates a new DRAFT, never rewrites old payroll records. */
final class KauSyncOctoberBasicGpf extends Command
{
    protected $signature = 'kau:sync-october-basic-gpf {--apply : Save revised draft versions} {--user= : Test one user ID first}';
    protected $description = 'Sync October 2026 Basic to approved July fixation and GPF to 10%, preserving other component cash amounts';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $only = $this->option('user');
        if ($apply && app()->environment('production')) {
            $this->error('Production environment refused. Execute only against a backed-up local/staging database.');
            return self::FAILURE;
        }
        $query = DB::table('salary_calculation_versions as v')
            ->joinSub(DB::table('salary_calculation_versions')
                ->selectRaw('user_id, MAX(id) AS latest_id')
                ->where('salary_year', 2026)->where('salary_month', 10)->groupBy('user_id'),
                'last', fn ($j) => $j->on('last.latest_id', '=', 'v.id'))
            ->join('salary_fixations as f', function ($j) {
                $j->on('f.id', '=', 'v.fixation_id')->on('f.user_id', '=', 'v.user_id')
                    ->where('f.status', '=', 'approved');
            })
            ->select('v.*', 'f.calculation as fixation_calculation');
        if ($only !== null && $only !== '') $query->where('v.user_id', (int) $only);
        $rows = $query->orderBy('v.user_id')->get();
        $counts = ['matched'=>0, 'candidate'=>0, 'saved'=>0, 'skipped'=>0];
        foreach ($rows as $row) {
            try {
                $fixation = json_decode((string) $row->fixation_calculation, true, 512, JSON_THROW_ON_ERROR);
                $basicValue = $fixation['phase_2026_07'] ?? null;
                if (!is_numeric($basicValue) || (float)$basicValue <= 0) { $counts['skipped']++; continue; }
                $newBasic = round((float)$basicValue, 2);
                $result = json_decode((string)$row->calculated_result, true, 512, JSON_THROW_ON_ERROR);
                $adds = json_decode((string)$row->additions, true, 512, JSON_THROW_ON_ERROR);
                $deds = json_decode((string)$row->deductions, true, 512, JSON_THROW_ON_ERROR);
                $arrear = json_decode((string)$row->arrear_snapshot, true);
                if (!is_array($result) || !is_array($adds) || !is_array($deds)) throw new \RuntimeException('Invalid snapshot JSON');
                $basicIdx = [];
                foreach ($adds as $i => $a) if (preg_match('/^basic(?: pay| salary)?$/i', trim((string)($a['name'] ?? '')))) $basicIdx[] = $i;
                if (count($basicIdx) !== 1) throw new \RuntimeException('Expected exactly one Basic Pay row');
                $oldBasic = (float)($result['basic'] ?? 0);
                $gpfIndices = [];
                foreach ($deds as $i => $d) if (SalaryCalculationService::isGpf($d)) $gpfIndices[] = $i;
                if (count($gpfIndices) > 1) throw new \RuntimeException('Duplicate GPF components; needs manual reconciliation');
                // This project uses HALF-UP TO WHOLE TAKA for regular GPF by default.
                $newGpf = SalaryCalculationService::halfUpTaka($newBasic * .10);
                $oldGpf = (float)($result['gpf_deduction'] ?? 0);
                if (abs($newBasic - $oldBasic) < .009 && abs($newGpf - $oldGpf) < .009) { $counts['matched']++; continue; }
                if ($oldBasic <= 0) throw new \RuntimeException('Old basic is not positive');
                if (!isset($result['allowances'], $result['deductions']) || !is_array($result['allowances']) || !is_array($result['deductions'])) throw new \RuntimeException('Missing normalized component arrays');

                $updateBasic = static function (&$a) use ($newBasic, $row) {
                    $a['amount'] = $newBasic; $a['calcType'] = 'fixed'; $a['calculated_amount'] = $newBasic;
                    $a['approved_fixation_id'] = (int)$row->fixation_id;
                };
                $updateBasic($adds[$basicIdx[0]]);
                $normalizedBasic = [];
                foreach ($result['allowances'] as $i => $a) if (preg_match('/^basic(?: pay| salary)?$/i', trim((string)($a['name'] ?? '')))) $normalizedBasic[]=$i;
                if (count($normalizedBasic)!==1) throw new \RuntimeException('Result Basic Pay row ambiguous');
                $updateBasic($result['allowances'][$normalizedBasic[0]]);

                $updateGpf = static function (&$d) use ($newGpf) {
                    $d['calcType']='percentage'; $d['amount']=10.0;
                    $d['calculated_amount']=$newGpf;
                    $d['gpf_calculation_source']='employee_override';
                    unset($d['percentage_base_amount']);
                };
                if (count($gpfIndices) === 1) $updateGpf($deds[$gpfIndices[0]]);
                else $deds[] = ['name'=>'General Provident Fund (GPF)', 'calcType'=>'percentage', 'amount'=>10.0, 'component_type'=>'deduction', 'calculated_amount'=>$newGpf, 'gpf_calculation_source'=>'employee_override'];
                $normGpf=[];
                foreach ($result['deductions'] as $i=>$d) if (SalaryCalculationService::isGpf($d)) $normGpf[]=$i;
                if (count($normGpf)>1) throw new \RuntimeException('Result has duplicate GPF');
                if (count($normGpf)===1) $updateGpf($result['deductions'][$normGpf[0]]);
                else $result['deductions'][]=['name'=>'General Provident Fund (GPF)', 'calcType'=>'percentage', 'amount'=>10.0, 'component_type'=>'deduction', 'calculated_amount'=>$newGpf, 'gpf_calculation_source'=>'employee_override'];

                // Existing non-Basic and non-GPF amounts remain EXACTLY unchanged.
                $result['basic']=$newBasic;
                $result['total_allowance']=round(array_sum(array_map(fn($a)=>(float)($a['calculated_amount']??0),$result['allowances'])),2);
                $result['total_deduction']=round(array_sum(array_map(fn($d)=>(float)($d['calculated_amount']??0),$result['deductions'])),2);
                $result['gpf_deduction']=$newGpf;
                $result['net_salary']=round($result['total_allowance']-$result['total_deduction'],2);
                $result['gpf_rule']=['type'=>'percentage','amount'=>10.0,'source'=>'employee_override'];
                $result['calculation_source']='approved_fixation';
                $result['fixation_id']=(int)$row->fixation_id;
                if ($result['net_salary'] < 0) throw new \RuntimeException('Negative regular net');
                $gpfOverride=['type'=>'percentage','amount'=>10.0];
                $hash=hash('sha256',json_encode([
                    'user_id'=>(int)$row->user_id, 'year'=>2026, 'month'=>10,
                    'fixation_id'=>(int)$row->fixation_id,
                    'additions'=>$result['allowances'],'deductions'=>$result['deductions'],
                    'result'=>$result, 'arrear'=>$arrear,
                ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
                $counts['candidate']++;
                $this->line("user={$row->user_id} old={$oldBasic} new={$newBasic} gpf={$newGpf} net={$result['net_salary']}" );
                if (!$apply) continue;
                DB::transaction(function() use($row,$adds,$deds,$result,$gpfOverride,$hash) {
                    DB::table('users')->where('id',$row->user_id)->lockForUpdate()->first();
                    $latest=DB::table('salary_calculation_versions')->where('user_id',$row->user_id)->where('salary_year',2026)->where('salary_month',10)->orderByDesc('version')->first();
                    if (!$latest || (int)$latest->id !== (int)$row->id) throw new \RuntimeException('Snapshot changed: retry later');
                    if (DB::table('salary_generates')->where('user_id',$row->user_id)->where('salaryGenerationYear',2026)->where('monthId','10')->exists()) throw new \RuntimeException('Already posted; skipped');
                    $copy=(array)$row;
                    unset($copy['id'],$copy['fixation_calculation']);
                    $copy['version']=(int)$row->version+1;
                    $copy['status']='draft';
                    $copy['approved_at']=null;
                    $copy['additions']=json_encode($result['allowances'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                    $copy['deductions']=json_encode($result['deductions'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                    $copy['gpf_override']=json_encode($gpfOverride,JSON_THROW_ON_ERROR);
                    $copy['calculated_result']=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                    $copy['snapshot_hash']=$hash;
                    $copy['reason']='Local HR July 2026 Basic and regular GPF 10% synchronization; other cash amounts frozen';
                    $copy['evidence_reference']='Approved July 2026 salary fixation #'.$row->fixation_id;
                    $copy['created_at']=now();$copy['updated_at']=now();
                    DB::table('salary_calculation_versions')->insert($copy);
                });
                $counts['saved']++;
            } catch (Throwable $e) {
                $counts['skipped']++;
                $this->warn('SKIP user='.$row->user_id.' : '.$e->getMessage());
            }
        }
        $this->info(json_encode($counts));
        $this->warn('New versions are DRAFT. Posting rules/policy still need separate testing.');
        return self::SUCCESS;
    }
}
