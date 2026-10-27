<?php

/** Phase 1 — deep reconciliation: financial totals vs structural JSON differences. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\HistoricalSalaryImportService;
use Illuminate\Support\Facades\DB;

$service = new HistoricalSalaryImportService();
$all = $service->parse();

echo 'Historical rows June-Sept 2026: '.count($all)."\n";

$summary = ['financially_identical' => 0, 'financially_conflict' => 0, 'structurally_same' => 0,
    'structurally_diff' => 0, 'missing' => 0, 'unmatched' => 0, 'ambiguous' => 0];
$conflictRows = [];
$missingRows = [];

$matching = $service->matchEmployees($all);
$summary['unmatched'] = count($matching['unmatched']);
$summary['ambiguous'] = count($matching['ambiguous']);

$userIds = [];
foreach ($matching['matched'] as $m) { $userIds[$m['user_id']] = true; }
$existing = DB::table('salary_generates')
    ->where('salaryGenerationYear', 2026)
    ->whereIn('monthId', [6, 7, 8, 9])
    ->get()
    ->groupBy(fn ($r) => $r->user_id.'-'.$r->monthId);

foreach ($all as $row) {
    $code = $row['employeeCode'];
    if (! isset($matching['matched'][$code])) { continue; }
    $userId = $matching['matched'][$code]['user_id'];
    $key = $userId.'-'.$row['monthId'];
    if (! isset($existing[$key])) {
        $summary['missing']++;
        $missingRows[] = $row;
        continue;
    }
    $cur = $existing[$key]->first();
    $totals = ['totalAllowance', 'totalDeduction', 'taxDeduction', 'gpfDeduction', 'salaryTotal'];
    $finDiff = [];
    foreach ($totals as $t) {
        if (abs(((float) $row[$t]) - ((float) $cur->{$t})) > 0.0000001) {
            $finDiff[] = $t;
        }
    }
    if ($finDiff === []) {
        $summary['financially_identical']++;
        $structureSame = $service->compare($row, $cur);
        if ($structureSame) { $summary['structurally_same']++; } else { $summary['structurally_diff']++; }
    } else {
        $summary['financially_conflict']++;
        $conflictRows[] = ['code' => $code, 'month' => $row['monthId'], 'user_id' => $userId,
            'source_total_allowance' => $row['totalAllowance'], 'current_total_allowance' => $cur->totalAllowance,
            'source_total_deduction' => $row['totalDeduction'], 'current_total_deduction' => $cur->totalDeduction,
            'source_net' => $row['salaryTotal'], 'current_net' => $cur->salaryTotal,
            'source_review' => $row['reviewStatus'], 'current_review' => $cur->reviewStatus,
            'diff_fields' => implode(',', $finDiff)];
    }
}

echo "=== SUMMARY ===\n";
echo json_encode($summary, JSON_PRETTY_PRINT)."\n";

echo "=== FINANCIAL CONFLICTS (amounts differ between historical dump and current DB) ===\n";
echo 'count: '.count($conflictRows)."\n";
foreach (array_slice($conflictRows, 0, 30) as $c) {
    echo json_encode($c)."\n";
}

echo "=== MISSING ROWS (in dump but absent in current DB) ===\n";
echo 'count: '.count($missingRows)."\n";
foreach (array_slice($missingRows, 0, 20) as $m) {
    echo ' id='.$m['id'].' code='.$m['employeeCode'].' '.$m['salaryGenerationYear'].'-'.$m['monthId']
        .' net='.$m['salaryTotal'].' review='.$m['reviewStatus']."\n";
}

echo "=== EMPLOYEE 20057 ===\n";
foreach ($all as $row) {
    if ($row['employeeCode'] === '20057') {
        $allow = json_decode($row['salaryAllowanceCalcObject'], true) ?: [];
        $basic = null;
        $houseRent = null;
        $special = null;
        foreach ($allow as $c) {
            if (str_contains($c['name'], 'Basic')) { $basic = $c['calculated_amount'] ?? $c['amount']; }
            if (str_contains(strtolower($c['name']), 'house')) { $houseRent = $c['calculated_amount'] ?? $c['amount']; }
            if (str_contains(strtolower($c['name']), 'special')) { $special = $c['calculated_amount'] ?? $c['amount']; }
        }
        $ded = json_decode($row['salaryDeductionCalcObject'], true) ?: [];
        $gpf = null;
        $revenue = null;
        foreach ($ded as $c) {
            if (str_contains($c['name'], 'Provident')) { $gpf = $c['calculated_amount'] ?? $c['amount']; }
            if (str_contains($c['name'], 'Stamp')) { $revenue = $c['calculated_amount'] ?? $c['amount']; }
        }
        echo 'month='.$row['monthId'].' id='.$row['id'].' review='.$row['reviewStatus']
            .' basic='.(is_numeric($basic) ? $basic : 'null')
            .' house_rent='.(is_numeric($houseRent) ? $houseRent : 'null')
            .' special='.(is_numeric($special) ? $special : 'null')
            .' gpf='.(is_numeric($gpf) ? $gpf : 'null')
            .' revenue_stamp='.(is_numeric($revenue) ? $revenue : 'null')
            .' totalAllowance='.$row['totalAllowance']
            .' totalDeduction='.$row['totalDeduction']
            .' salaryTotal='.$row['salaryTotal']."\n";
    }
}
