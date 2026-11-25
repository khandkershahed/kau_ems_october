<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\{SalaryEstimationWorkspaceController, SalaryReportController, SalaryOfficialReportController};
use App\Models\{SalaryGenerate, User};
use App\Services\{MonthlySalarySetupService, SalaryCalculationService, SalaryOfficialReportSource};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function cents($amount) { return (int) round($amount * 100); }
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function out($label, $data) { echo "$label ", json_encode($data, JSON_THROW_ON_ERROR), PHP_EOL; }
function req($uri, $params) {
    global $app, $operator;
    $r = Request::create($uri, 'GET', $params, [], [], ['HTTP_ACCEPT'=>'application/json','HTTP_X_INERTIA'=>'true']);
    $app->instance('request', $r);
    $r->setUserResolver(fn () => $operator);
    return $r;
}
check(DB::getDriverName() === 'mysql' && DB::selectOne('SELECT DATABASE() AS db')->db === 'trektrwu_kau', 'Wrong database');
DB::statement('SET TRANSACTION READ ONLY');
DB::beginTransaction();
try {
    $historical = DB::table('salary_generates')->whereRaw('salaryGenerationYear * 100 + monthId <= 202610')->orderBy('id')->get();
    $oct = $historical->where('salaryGenerationYear', 2026)->where('monthId', 10);
    $posted = DB::table('salary_generates')->where('salaryGenerationYear', 2026)->where('monthId', 11)->count();
    $links = DB::table('salary_bill_source_links')->where('salary_year', 2026)->where('salary_month', 11)->count();
    $snapshots = DB::table('salary_arrear_snapshots')->count();
    out('SQL counts', ['october'=>count($oct),'october_net_cents'=>$oct->sum(fn ($row) => cents($row->salaryTotal)), 'history_rows'=>count($historical), 'history_sha256'=>hash('sha256',$historical->toJson()),'november_posted'=>$posted,'november_links'=>$links,'structured_arrear_snapshots_all_periods'=>$snapshots]);
    check($posted === 0, 'Expected empty November state for preview audit');
    $operator = User::where('isActive', 1)->firstOrFail();
    $setup = app(MonthlySalarySetupService::class);
    $get = req('/dashboard/salary/estimation', ['year'=>2026,'month'=>11]);
    $page = app(SalaryEstimationWorkspaceController::class)->index($get,$setup)->toResponse($get)->getData(true);
    $ids = array_column($page['props']['eligiblePending'],'user_id');
    check(count($ids) === 412, 'Eligible roster count');
    $rows = $setup->getSummariesForUsers($ids,2026,11);
    $december = $setup->getSummariesForUsers($ids,2026,12);
    check(count($december) === 412 && !array_filter($december, fn ($r) => cents($r['arrear_allowances']) || cents($r['arrear_deductions'])), 'December carry-forward');
    $source = app(SalaryOfficialReportSource::class);
    $controller = app(SalaryReportController::class);
    $normalize = new ReflectionMethod($controller,'summaryComponentRows');
    $total = $heads = [];
    $payerCount = 0;
    $lineCount = 0;
    foreach ($rows as $id=>$row) {
        $totals = array_intersect_key($row, array_flip(['regular_allowances','arrear_allowances','total_allowances','regular_deductions','arrear_deductions','total_deductions','regular_net','net_arrear','net_payable']));
        foreach ($totals as $key=>$v) $total[$key] = ($total[$key] ?? 0) + cents($v);
        $items = [];
        $stampCount = 0;
        foreach (['allowances','deductions'] as $side) {
            $saved = [];
            foreach ($row[$side] as $entry) {
                $c = cents($entry['current_amount']);
                $a = cents($entry['arrear_amount']);
                $s = cents($entry['total_amount']);
                check($c + $a === $s, 'Monthly component split');
                if ($c === 0 && $a === 0) continue;
                $lineCount++;
                $name = $entry['name'];
                $heads[$side][$name] = ($heads[$side][$name] ?? 0) + $a;
                if ($side === 'deductions' && MonthlySalarySetupService::isRevenueStamp($name)) {
                    $stampCount++;
                    check($c === 1000, 'Revenue Stamp amount');
                }
                if ($side === 'deductions' && SalaryCalculationService::isGpf($entry) && $c !== 0) {
                    $payerCount++;
                    check($c === cents(round($row['basic_pay'] * 0.10, 2)), 'GPF amount');
                }
                $saved[] = ['name'=>$name,'amount'=>$entry['current_amount'],'current_amount'=>$entry['current_amount'],'arrear_amount'=>$entry['arrear_amount'],'calculated_amount'=>$entry['total_amount']];
            }
            $normalized = $normalize->invoke($controller,$saved);
            $arrears = array_filter($normalized, fn ($r) => $r['monthly_arrear'] ?? false);
            check(array_sum(array_map(fn ($r) => cents($r['calculated_amount']), $normalized)) === cents($row['total_'.$side]), 'Summary normalized total');
            check(array_sum(array_map(fn ($r) => cents($r['calculated_amount']), $arrears)) === cents($row['arrear_'.$side]), 'Summary signed arrear total');
            $items[$side] = $saved;
        }
        check($stampCount === 1, 'Revenue Stamp count');
        $bill = new SalaryGenerate(['user_id'=>$id,'salaryGenerationYear'=>2026,'monthId'=>11,'totalAllowance'=>$row['total_allowances'],'totalDeduction'=>$row['total_deductions'],'salaryTotal'=>$row['net_payable'],'salaryAllowanceCalcObject'=>$items['allowances'],'salaryDeductionCalcObject'=>$items['deductions']]);
        $bill->setRelation('user', null);
        $parsed = $source->fromPostedBill($bill);
        foreach (['gross'=>'total_allowances','total_deductions'=>'total_deductions','current_gross'=>'regular_allowances','current_deductions'=>'regular_deductions','net_arrear'=>'net_arrear','net'=>'net_payable'] as $actual=>$expected) {
            check(cents($parsed[$actual]) === cents($row[$expected]), 'Official source amount '.$actual);
        }
    }
    out('November eligible monthly projections in cents', $total);
    out('November arrears by head in cents', array_map('array_filter',$heads));
    out('Checks', ['component_rows'=>$lineCount,'in_memory_bill_checks'=>count($rows),'regular_gpf_payers'=>$payerCount,'stamps'=>count($rows),'stamp_cents'=>count($rows)*1000,'nonzero_december_arrears'=>0]);
    $summary = $controller->index(req('/dashboard/reports/salary-reports',['reportType'=>'Salary Summary','monthId'=>11,'salaryGenerationYear'=>2026,'all'=>'true','fixationUserIds'=>implode(',',$ids)]))->getData(true);
    out('Salary Summary preview row count', count($summary['data']));
    $matched = 0;
    foreach ($summary['data'] as $r) {
        if (!isset($rows[$r['user_id']])) continue;
        $matched++;
        $ref = $rows[$r['user_id']];
        foreach (['total_allowance'=>'total_allowances','total_deduction'=>'total_deductions','salary_total'=>'net_payable'] as $actual=>$expected) check(cents($r[$actual]) === cents($ref[$expected]), 'Summary preview amount '.$actual);
    }
    out('Salary Summary matching workspace rows', $matched);
    $horizontal = app(SalaryOfficialReportController::class)->index(req('/dashboard/reports/salary-official', ['ids'=>implode(',',$ids),'year'=>2026,'month'=>11,'kind'=>'bulk','format'=>'json','mode'=>'preview','columns'=>'full']), $source)->getData(true);
    check(count($horizontal['records']) === 412 && count($horizontal['horizontal_columns']) === 20, 'Horizontal preview dimensions');
    foreach ($horizontal['records'] as &$r) {
        $r = array_intersect_key($r, array_flip(['horizontal_columns','horizontal_formatted']));
        $r += ['employee_code'=>'10000','grade'=>'Grade 10','name'=>'Audit Employee','designation'=>'Audit Designation'];
    }
    unset($r);
    out('Horizontal preview totals', $horizontal['horizontal_totals_formatted']);
    file_put_contents(getcwd().'/storage/app/bridge-qa/november.json',json_encode($horizontal,JSON_THROW_ON_ERROR));
} finally {
    DB::rollBack();
}




