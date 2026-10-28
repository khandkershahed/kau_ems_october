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
    /** Full selected/filtered provisional horizontal bill. Reads saved versions; never recalculates or posts. */
    public function download(Request $request, SalaryEstimationWorkspaceController $directory)
    {
        abort_unless($request->user(), 401);
        $data = $request->validate([
            'scope' => 'required|in:selected,filtered',
            'year' => 'required|integer|between:2020,2100',
            'month' => 'required|integer|between:1,12',
            'user_ids' => 'required_if:scope,selected|array|min:1|max:500',
            'user_ids.*' => 'integer|min:1|distinct',
        ]);
        $year = (int) $data['year'];
        $month = (int) $data['month'];
        $ids = $data['scope'] === 'filtered'
            ? $directory->horizontalReportIds($request)
            : array_map('intval', $data['user_ids']);
        if (!$ids) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'user_ids' => 'No employees match the selected filters.',
            ]);
        }

        // A saved calculation is the report authority. Never use a generated bill or live calculator.
        $versions = DB::table('salary_calculation_versions')
            ->whereIn('user_id', $ids)->where('salary_year', $year)->where('salary_month', $month)
            ->orderByDesc('version')->orderByDesc('id')->get()->groupBy('user_id');
        $workflows = DB::table('salary_monthly_workflows')->whereIn('user_id', $ids)
            ->where('salary_year', $year)->where('salary_month', $month)->get()->keyBy('user_id');
        $profiles = DB::table('employee_infos as ei')
            ->leftJoin('payroll_groups as pg', 'pg.id', '=', 'ei.payroll_group_id')
            ->leftJoin('payroll_group_types as pgt', 'pgt.id', '=', 'pg.payroll_group_type_id')
            ->whereIn('ei.user_id', $ids)
            ->get(['ei.user_id', 'ei.employeeCode', 'pgt.group_name as designation'])->groupBy('user_id');
        $users = DB::table('users')->whereIn('id', $ids)->get(['id', 'name'])->keyBy('id');

        $arrearCodes = self::ARREAR_CODES;
        $columns = [
            'basic', 'hra', 'medical', 'special_benefit', 'education', 'other_allowance',
            'regular_gross', 'gpf', 'income_tax', 'other_deduction', 'regular_deduction',
            'BASIC_ARREAR_GROSS', 'BASIC_INCREMENT_ADJUSTMENT', 'SPECIAL_BENEFIT_RECOVERY',
            'HOUSE_RENT_RECOVERY', 'ADDITIONAL_GPF', 'net',
        ];
        $rows = []; $errors = [];
        foreach ($ids as $id) {
            $profileSet = $profiles->get($id, collect());
            if ($profileSet->count() !== 1) {
                $errors[] = "User {$id}: employee profile missing or ambiguous (".$profileSet->count().')';
                continue;
            }
            $versionSet = $versions->get($id, collect());
            $workflow = $workflows->get($id);
            $version = $workflow && $workflow->active_calculation_version_id
                ? $versionSet->firstWhere('id', (int) $workflow->active_calculation_version_id)
                : null;
            $version = $version ?: $versionSet->first();
            if (!$version) {
                $errors[] = "User {$id}: no saved calculator snapshot for {$year}-".sprintf('%02d', $month);
                continue;
            }
            $regular = json_decode((string) $version->calculated_result, true);
            $arrear = json_decode((string) $version->arrear_snapshot, true);
            if (!is_array($regular)) {
                $errors[] = "User {$id}: saved regular salary result is invalid";
                continue;
            }
            $missing = [];
            $number = static function ($value, string $field) use (&$missing): ?float {
                if (!is_numeric($value) || !is_finite((float) $value)) {
                    $missing[] = $field;
                    return null;
                }
                return round((float) $value, 2);
            };
            $gross = $number($regular['total_allowance'] ?? null, 'regular_gross');
            $ded = $number($regular['total_deduction'] ?? null, 'regular_deduction');
            $netRegular = $number($regular['net_salary'] ?? null, 'regular_net');
            $basic = $number($regular['basic'] ?? null, 'basic');
            $gpf = $number($regular['gpf_deduction'] ?? null, 'gpf');
            $tax = $number($regular['tax_deduction'] ?? null, 'income_tax');
            $parts = [
                'basic' => $basic,
                'hra' => 0.0, 'medical' => 0.0, 'special_benefit' => 0.0,
                'education' => 0.0, 'other_allowance' => 0.0,
                'regular_gross' => $gross, 'gpf' => $gpf, 'income_tax' => $tax,
                'other_deduction' => null, 'regular_deduction' => $ded,
            ];
            $allowances = $regular['allowances'] ?? null;
            if (!is_array($allowances)) {
                foreach (['hra', 'medical', 'special_benefit', 'education', 'other_allowance'] as $key) $parts[$key] = null;
                $missing[] = 'allowance breakdown';
            } else {
                $allowanceSum = 0.0;
                foreach ($allowances as $line) {
                    if (!is_array($line)) { $missing[] = 'allowance row'; continue; }
                    $name = strtolower((string) ($line['name'] ?? ''));
                    $amount = $number($line['calculated_amount'] ?? null, 'allowance: '.$name);
                    if ($amount === null) continue;
                    $allowanceSum += $amount;
                    if (preg_match('/\\bbasic(?:\\s+pay|\\s+salary)?\\b/i', $name)) continue;
                    if (preg_match('/house\\s*rent|\\bhra\\b/i', $name)) $key = 'hra';
                    elseif (str_contains($name, 'medical')) $key = 'medical';
                    elseif (str_contains($name, 'special benefit')) $key = 'special_benefit';
                    elseif (str_contains($name, 'education')) $key = 'education';
                    else $key = 'other_allowance';
                    $parts[$key] = round($parts[$key] + $amount, 2);
                }
                if ($gross !== null && abs($gross - $allowanceSum) > 0.015) $missing[] = 'allowance/gross mismatch';
            }
            if ($ded !== null && $gpf !== null && $tax !== null) {
                $parts['other_deduction'] = round($ded - $gpf - $tax, 2);
                if ($parts['other_deduction'] < -0.015) {
                    $parts['other_deduction'] = null;
                    $missing[] = 'regular deduction breakdown mismatch';
                }
            } else $missing[] = 'other_deduction';
            if ($gross !== null && $ded !== null && $netRegular !== null
                && abs($gross - $ded - $netRegular) > 0.015) $missing[] = 'regular net mismatch';

            $arrearValues = array_fill_keys($arrearCodes, null);
            if (!is_array($arrear) || !is_array($arrear['rows'] ?? null)) {
                $missing[] = 'arrear snapshot';
            } else {
                foreach ($arrear['rows'] as $line) {
                    if (!is_array($line)) continue;
                    $code = (string) ($line['code'] ?? '');
                    if (!array_key_exists($code, $arrearValues)) continue;
                    $amount = $number($line['final_amount'] ?? null, 'arrear: '.$code);
                    if ($amount === null) continue;
                    $arrearValues[$code] = round(($arrearValues[$code] ?? 0) + $amount, 2);
                }
            }
            foreach ($arrearValues as $code => $amount) if ($amount === null) $missing[] = $code;
            $parts = array_merge($parts, $arrearValues);
            $parts['net'] = null;
            if ($netRegular !== null && !in_array(null, $arrearValues, true)) {
                $net = round($netRegular + $arrearValues['BASIC_ARREAR_GROSS']
                    - $arrearValues['BASIC_INCREMENT_ADJUSTMENT']
                    - $arrearValues['SPECIAL_BENEFIT_RECOVERY']
                    - $arrearValues['HOUSE_RENT_RECOVERY'] - $arrearValues['ADDITIONAL_GPF'], 2);
                if (isset($arrear['net_arrear']) && is_numeric($arrear['net_arrear'])
                    && abs($net - $netRegular - (float) $arrear['net_arrear']) > 0.015) {
                    $missing[] = 'saved net arrear mismatch';
                } else $parts['net'] = $net;
            }
            $rows[] = [
                'user_id' => $id, 'employee_code' => $profileSet->first()->employeeCode,
                'name' => $users->get($id)->name ?? "User {$id}",
                'designation' => $profileSet->first()->designation ?? 'Not recorded',
                'version' => $version->version, 'version_status' => $version->status,
                'workflow_status' => $workflow->workflow_status ?? 'NOT_INITIALIZED',
                'financial' => $parts, 'missing' => array_values(array_unique($missing)),
            ];
        }
        $totals = [];
        foreach ($columns as $key) {
            $values = array_map(static fn ($row) => $row['financial'][$key] ?? null, $rows);
            $totals[$key] = count($values) && !in_array(null, $values, true)
                ? round(array_sum($values), 2) : null;
        }
        $reportData = [
            'year' => $year, 'month' => $month, 'requested' => count($ids), 'rows' => $rows,
            'errors' => $errors, 'totals' => $totals, 'columns' => $columns,
        ];
        if ($request->wantsJson()) {
            return response()->json($reportData)->header('Cache-Control', 'private, no-store, max-age=0');
        }
        return response()->view('salary.provisional-horizontal-download', $reportData)
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('X-Content-Type-Options', 'nosniff');
    }

}
