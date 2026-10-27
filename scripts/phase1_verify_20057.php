<?php

/** Phase 1 — employee 20057 reconciliation between current codershat_kau and the historical dump. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo 'target DB: '.DB::select('SELECT DATABASE() AS d')[0]->d."\n";

echo "--- CURRENT codershat_kau rows for 20057 ---\n";
$rows = DB::table('salary_generates')
    ->where('employeeCode', '20057')
    ->orderBy('salaryGenerationYear')->orderBy('monthId')
    ->get(['id', 'user_id', 'employeeCode', 'monthId', 'salaryGenerationYear', 'reviewStatus',
        'totalAllowance', 'totalDeduction', 'salaryTotal']);
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
}

echo "--- HISTORICAL kau-app.sql rows for 20057 (June-Sept 2026) ---\n";
$service = new App\Services\HistoricalSalaryImportService();
$all = $service->parse();
foreach ($all as $row) {
    if ($row['employeeCode'] === '20057') {
        echo json_encode([
            'id' => $row['id'], 'user_id' => $row['user_id'], 'monthId' => $row['monthId'],
            'year' => $row['salaryGenerationYear'], 'reviewStatus' => $row['reviewStatus'],
            'totalAllowance' => $row['totalAllowance'], 'totalDeduction' => $row['totalDeduction'],
            'salaryTotal' => $row['salaryTotal'], 'gpfDeduction' => $row['gpfDeduction'],
        ], JSON_UNESCAPED_UNICODE)."\n";
    }
}

echo "--- Basic components from historical JSON for 20057 ---\n";
foreach ($all as $row) {
    if ($row['employeeCode'] === '20057') {
        $allow = json_decode($row['salaryAllowanceCalcObject'], true) ?: [];
        $ded = json_decode($row['salaryDeductionCalcObject'], true) ?: [];
        $basic = null;
        $houseRent = null;
        $special = null;
        $gpf = null;
        $revenue = null;
        foreach ($allow as $c) {
            if (str_contains($c['name'], 'Basic')) { $basic = $c['calculated_amount'] ?? $c['amount']; }
            if (str_contains(strtolower($c['name']), 'house')) { $houseRent = $c['calculated_amount'] ?? $c['amount']; }
            if (str_contains(strtolower($c['name']), 'special')) { $special = $c['calculated_amount'] ?? $c['amount']; }
        }
        foreach ($ded as $c) {
            if (str_contains($c['name'], 'Provident')) { $gpf = $c['calculated_amount'] ?? $c['amount']; }
            if (str_contains(strtolower($c['name']), 'stamp')) { $revenue = $c['calculated_amount'] ?? $c['amount']; }
        }
        echo $row['salaryGenerationYear'].'-'.$row['monthId']
            .' basic='.(is_numeric($basic) ? $basic : 'null')
            .' house_rent='.(is_numeric($houseRent) ? $houseRent : 'null')
            .' special='.(is_numeric($special) ? $special : 'null')
            .' gpf='.(is_numeric($gpf) ? $gpf : 'null')
            .' revenue_stamp='.(is_numeric($revenue) ? $revenue : 'null')."\n";
    }
}

echo "--- Current rows for 20057 (June-Sept 2026) full component check ---\n";
$current = DB::table('salary_generates')
    ->where('employeeCode', '20057')
    ->where('salaryGenerationYear', 2026)
    ->whereIn('monthId', [6, 7, 8, 9])
    ->get();
foreach ($current as $r) {
    $allow = json_decode($r->salaryAllowanceCalcObject, true);
    $basic = null;
    foreach ($allow as $c) {
        if (str_contains($c['name'], 'Basic')) { $basic = $c['calculated_amount'] ?? $c['amount']; }
    }
    echo 'current id='.$r->id.' month='.$r->monthId.' basic='.(is_numeric($basic) ? $basic : 'null')
        .' reviewStatus='.$r->reviewStatus."\n";
}
