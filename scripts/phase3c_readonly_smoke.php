<?php
/** Phase 3C-Fix read-only smoke: never writes any record. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$svc = app(App\Services\SalaryStructuredArrearService::class);
$uid = (int) (DB::table('employee_infos')->where('employeeCode', '20057')->value('user_id') ?? 20057);
$pid = (int) DB::table('salary_arrear_periods')->orderByDesc('id')->value('id');
$rules = App\Services\SalaryStructuredArrearService::canonicalRuleSet(
    Schema::hasTable('salary_arrear_rules')
        ? DB::table('salary_arrear_rules')->whereIn('code', App\Services\SalaryStructuredArrearService::CODES)
            ->where('published', 1)->orderBy('display_order')->get()->all()
        : []
);
$out = $svc->preview($uid, $rules);
echo 'user='.$uid.' period='.$pid.' rows='.count($out).PHP_EOL;
echo 'codes='.implode(',', array_column($out, 'code')).PHP_EOL;
echo 'amounts='.json_encode(array_column($out, 'calculated_amount')).PHP_EOL;
echo 'ms_shape='.json_encode(array_map(fn ($c) => gettype($c['month_sources']), $out)).PHP_EOL;
echo 'summary_keys='.implode(',', array_keys($svc->evidenceSummary($uid))).PHP_EOL;
