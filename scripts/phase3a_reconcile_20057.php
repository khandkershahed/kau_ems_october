<?php

/** Phase 3A — read-only reconciliation for Employee 20057 using the new source resolver. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo 'DB='.DB::connection()->getDatabaseName()."\n";
$user = DB::table('users')->where('id', 58)->first('id');
echo 'user58='.($user ? 'found' : 'missing')."\n";

$svc = app(App\Services\SalaryStructuredArrearService::class);
$ref = new ReflectionMethod($svc, 'sources');
$ref->setAccessible(true);
$evidence = $ref->invoke($svc, 58);

foreach ($evidence['revised_basics'] ?? [] as $period => $basic) {
    echo "revised {$period}: basic={$basic} source=".($evidence['revised_sources'][$period] ?? 'null')."\n";
}
echo "june_basic=".($evidence['june_basic'] ?? 'null')."\n";
echo "old_increment=".($evidence['old_increment'] ?? 'null')." status=".($evidence['increment_status'] ?? 'null')."\n";
foreach ($evidence['paid_months'] ?? [] as $period => $paid) {
    echo "paid {$period}: ".json_encode($paid)."\n";
}

$calculated = App\Services\SalaryStructuredArrearService::compute($evidence);
foreach ($calculated as $code => $entry) {
    echo "{$code}: amount=".($entry['amount'] ?? 'null')." direction={$entry['direction']} status={$entry['status']}\n";
}
$gross = $calculated['BASIC_ARREAR_GROSS']['amount'] ?? null;
$inc = $calculated['BASIC_INCREMENT_ADJUSTMENT']['amount'] ?? null;
echo "expected gross=30555 got=".var_export($gross, true)." | expected adjustment=2790 got=".var_export($inc, true);
echo " | expected net=".(($gross !== null && $inc !== null) ? ($gross - $inc) : 'null')."\n";

