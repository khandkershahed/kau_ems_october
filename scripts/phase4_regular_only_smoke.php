<?php
/** Regular-only preview smoke: READ-ONLY. Aborts unless a kau_*_test clone. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$name = (string) config('database.connections.mysql.database');
if (!preg_match('/^kau_.*_(test|staging)$/', $name)) {
    fwrite(STDERR, "REFUSED: $name is not a disposable test/staging clone.\n");
    exit(1);
}
$tables = ['salary_generates', 'general_provident_funds', 'salary_calculation_versions',
    'salary_bill_source_links', 'salary_arrear_snapshots', 'salary_manual_adjustments'];
$before = [];
foreach ($tables as $t) $before[$t] = Schema::hasTable($t) ? DB::table($t)->count() : -1;

$svc = app(App\Services\SalaryBillCompositionService::class);
foreach (['20057', '40173'] as $code) {
    $uid = (int) (DB::table('employee_infos')->where('employeeCode', $code)->value('user_id') ?? 0);
    $out = $uid ? $svc->composeRegularOnly($uid, 2026, 10) : ['status' => 'NO_PROFILE'];
    echo $code.' => '.json_encode([
        'user_id' => $uid, 'status' => $out['status'] ?? '?',
        'gross' => $out['regular_gross'] ?? null, 'ded' => $out['regular_deductions'] ?? null,
        'net' => $out['regular_net'] ?? null, 'scale' => $out['pay_scale_source'] ?? null,
        'ready' => $out['arrear_readiness'] ?? null, 'reasons' => count($out['arrear_blocking_reasons'] ?? []),
    ]).PHP_EOL;
}
foreach ($tables as $t) {
    $after = Schema::hasTable($t) ? DB::table($t)->count() : -1;
    if ($after !== $before[$t]) {
        fwrite(STDERR, "WRITE DETECTED in $t\n");
        exit(1);
    }
}
echo 'zero writes confirmed'.PHP_EOL;
