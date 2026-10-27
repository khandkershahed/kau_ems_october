<?php

/** Phase 1 — detailed side-by-side comparison of the 2 conflicts. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$conflicts = [
    ['source_id' => 5448, 'target_id' => 5455, 'code' => '40173', 'month' => 8],
    ['source_id' => 5903, 'target_id' => 5850, 'code' => '20057', 'month' => 9],
];

foreach ($conflicts as $c) {
    echo "=== conflict: code={$c['code']} 2026-{$c['month']} (source id={$c['source_id']}, target id={$c['target_id']}) ===\n";
    $target = DB::table('salary_generates')->where('id', $c['target_id'])->first();
    echo '  CURRENT target: reviewStatus='.$target->reviewStatus
        .' totalAllowance='.$target->totalAllowance
        .' totalDeduction='.$target->totalDeduction
        .' taxDeduction='.$target->taxDeduction
        .' gpfDeduction='.$target->gpfDeduction
        .' salaryTotal='.$target->salaryTotal."\n";
    $curAllow = json_decode($target->salaryAllowanceCalcObject, true) ?: [];
    $curDed = json_decode($target->salaryDeductionCalcObject, true) ?: [];
    echo '  CURRENT components: ';
    foreach ($curAllow as $k) { echo $k['name'].'='.(isset($k['calculated_amount']) ? $k['calculated_amount'] : $k['amount']).' '; }
    foreach ($curDed as $k) { echo '[-]'.$k['name'].'='.(isset($k['calculated_amount']) ? $k['calculated_amount'] : $k['amount']).' '; }
    echo "\n";
}
