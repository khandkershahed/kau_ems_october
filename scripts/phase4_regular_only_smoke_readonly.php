<?php
/** STRICTLY READ-ONLY per-employee check. Calls composeRegularOnly only. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$tables = ['salary_generates', 'general_provident_funds', 'salary_calculation_versions',
    'salary_bill_source_links', 'salary_arrear_snapshots'];
$before = [];
foreach ($tables as $t) $before[$t] = Schema::hasTable($t) ? DB::table($t)->count() : -1;

$svc = app(App\Services\SalaryBillCompositionService::class);
foreach (['20057', '40173'] as $code) {
    $uid = (int) (DB::table('employee_infos')->where('employeeCode', $code)->value('user_id') ?? 0);
    $out = $uid ? $svc->composeRegularOnly($uid, 2026, 10) : ['status' => 'NO_PROFILE', 'arrear_blocking_reasons' => []];
    echo $code.' => '.json_encode([
        'user_id' => $uid, 'status' => $out['status'] ?? '?',
        'code' => $out['employee_code'] ?? null, 'gross' => $out['regular_gross'] ?? null,
        'ded' => $out['regular_deductions'] ?? null, 'net' => $out['regular_net'] ?? null,
        'scale' => $out['pay_scale_source'] ?? null, 'ready' => $out['arrear_readiness'] ?? null,
        'reasons' => array_slice($out['arrear_blocking_reasons'] ?? [], 0, 3),
    ], JSON_UNESCAPED_UNICODE).PHP_EOL;
}
foreach ($tables as $t) {
    $after = Schema::hasTable($t) ? DB::table($t)->count() : -1;
    if ($after !== $before[$t]) {
        fwrite(STDERR, "WRITE DETECTED in $t\n");
        exit(1);
    }
}
echo 'zero writes confirmed'.PHP_EOL;
