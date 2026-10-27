<?php
/** Silent-navigation fix smoke: STRICTLY READ-ONLY controller invocations. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\SalaryOfficialReportController;
use App\Services\SalaryOfficialReportSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$tables = ['salary_generates', 'general_provident_funds', 'salary_calculation_versions',
    'salary_bill_source_links', 'salary_arrear_snapshots'];
$before = [];
foreach ($tables as $t) $before[$t] = Schema::hasTable($t) ? DB::table($t)->count() : -1;

$call = function (array $query) {
    $req = Request::create('/salary-official-report', 'GET', $query);
    $req->setLaravelSession(app('session.store'));
    try {
        $resp = app(SalaryOfficialReportController::class)
            ->index($req, app(SalaryOfficialReportSource::class));
        $status = $resp->getStatusCode();
        $body = (string) $resp->getContent();
        return [$status, $body];
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        return [$e->getStatusCode(), $e->getMessage()];
    }
};

// 26 employees (any): October 2026 has no posted bills -> all draft rows.
$ids = DB::table('employee_infos')->whereNotNull('user_id')->orderBy('id')->limit(26)->pluck('user_id')->all();
if (count($ids) < 26) { fwrite(STDERR, "not enough employees\n"); exit(1); }
[$status, $body] = $call(['ids' => implode(',', $ids), 'year' => 2026, 'month' => 10,
    'kind' => 'bulk', 'per_page' => 25, 'format' => 'html', 'mode' => 'preview']);
echo 'bulk_preview status='.$status.' len='.strlen($body).PHP_EOL;
$checks = [
    'http200' => $status === 200,
    'draft_marker' => str_contains($body, 'DRAFT'),
    'bulk_title' => str_contains($body, 'Horizontal Salary Bill'),
    'landscape' => str_contains($body, 'landscape'),
    'no_redirect_marker' => !str_contains($body, 'Redirecting'),
];
echo 'checks='.json_encode($checks).PHP_EOL;
foreach ($checks as $k => $v) if (!$v) { fwrite(STDERR, "FAILED: $k\n"); exit(1); }

[$s2, $b2] = $call(['ids' => '', 'year' => 2026, 'month' => 10, 'kind' => 'bulk']);
echo 'empty_ids status='.$s2.' (expect 422)'.PHP_EOL;
if ($s2 !== 422) { fwrite(STDERR, "empty ids must 422, got $s2\n"); exit(1); }

[$s3, $b3] = $call(['ids' => implode(',', $ids), 'year' => 2026, 'month' => 10,
    'kind' => 'bulk', 'per_page' => 25]);
echo 'no_mode status='.$s3.' (expect 422 reportError)'.PHP_EOL;
if ($s3 !== 422) { fwrite(STDERR, "non-preview without bills must 422, got $s3\n"); exit(1); }

foreach ($tables as $t) {
    $after = Schema::hasTable($t) ? DB::table($t)->count() : -1;
    if ($after !== $before[$t]) { fwrite(STDERR, "WRITE DETECTED in $t\n"); exit(1); }
}
echo 'zero writes confirmed; READ-ONLY OK'.PHP_EOL;
