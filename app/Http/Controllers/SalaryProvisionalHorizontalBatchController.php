<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Read-only multi-employee report over saved reviewed versions; never posts or recalculates. */
final class SalaryProvisionalHorizontalBatchController extends Controller
{
    private const ARREAR_CODES = [
        'BASIC_ARREAR_GROSS','BASIC_INCREMENT_ADJUSTMENT',
        'SPECIAL_BENEFIT_RECOVERY','HOUSE_RENT_RECOVERY','ADDITIONAL_GPF',
    ];
    public function show(Request $request)
    {
        abort_unless($request->user(), 401);
        $data = $request->validate([
            'year' => 'required|integer|between:2020,2100',
            'month' => 'required|integer|between:1,12',
            'user_ids' => 'required|array|min:1|max:50',
            'user_ids.*' => 'required|integer|min:1|distinct',
        ]);
        $year = (int)$data['year']; $month = (int)$data['month'];
        $out=[]; $errors=[];
        foreach ($data['user_ids'] as $uid) {
            $uid=(int)$uid;
            $workflow=DB::table('salary_monthly_workflows')
                ->where('user_id',$uid)->where('salary_year',$year)->where('salary_month',$month)->first();
            if (!$workflow || !in_array($workflow->workflow_status,['CALCULATED','READY_FOR_GENERATION'],true)) {
                $errors[]="User {$uid}: not in Calculated/Ready status";continue;
            }
            if (DB::table('employee_infos')->where('user_id',$uid)->count()!==1) {
                $errors[]="User {$uid}: employee profile missing/ambiguous";continue;
            }
            $version=DB::table('salary_calculation_versions')
                ->where('id',$workflow->active_calculation_version_id)
                ->where('user_id',$uid)->where('salary_year',$year)->where('salary_month',$month)
                ->where('status','approved')->first();
            if (!$version) { $errors[]="User {$uid}: active approved snapshot unavailable";continue; }
            $regular=json_decode((string)$version->calculated_result,true);
            $arrear=json_decode((string)$version->arrear_snapshot,true);
            if (!is_array($regular) || !is_array($arrear)) {
                $errors[]="User {$uid}: invalid persisted calculation/arrear JSON";continue;
            }
            $byCode=array_fill_keys(self::ARREAR_CODES,null);
            $missing=[];
            foreach (($arrear['rows']??[]) as $item) {
                $code=(string)($item['code']??'');
                if (!array_key_exists($code,$byCode)) continue;
                if (!array_key_exists('final_amount',$item) || !is_numeric($item['final_amount'])) {
                    $missing[]=$code; continue;
                }
                $byCode[$code]=($byCode[$code]??0)+(float)$item['final_amount'];
            }
            $regularKeys=['basic','total_allowance','total_deduction','gpf_deduction','net_salary'];
            $financial=[];
            foreach ($regularKeys as $key) {
                $financial[$key]=isset($regular[$key]) && is_numeric($regular[$key]) ? (float)$regular[$key] : null;
                if ($financial[$key]===null) $missing[]=$key;
            }
            $employee=DB::table('users')->where('id',$uid)->first(['name']);
            $out[]=['user_id'=>$uid,'name'=>$employee->name??"User {$uid}",
                'version_id'=>$version->id,'version'=>$version->version,'hash'=>$version->snapshot_hash,
                'regular'=>$financial,'arrear'=>$byCode,'missing'=>array_values(array_unique($missing)),
                'status'=>$workflow->workflow_status];
        }
        // Do not invent totals: aggregations are allowed only if ALL included employee values exist.
        $totals=[];
        foreach (array_merge(['basic','total_allowance','total_deduction','gpf_deduction','net_salary'],self::ARREAR_CODES) as $field) {
            $values=array_map(static fn($r)=>array_key_exists($field,$r['regular'])?$r['regular'][$field]:$r['arrear'][$field],$out);
            $totals[$field]=count($values)>0 && !in_array(null,$values,true) ? round(array_sum($values),2) : null;
        }
        return response()->view('salary.provisional-horizontal-batch',[
            'year'=>$year,'month'=>$month,'rows'=>$out,'errors'=>$errors,'totals'=>$totals,
            'arrearCodes'=>self::ARREAR_CODES,
        ])->header('Cache-Control','private, no-store, max-age=0');
    }
}
