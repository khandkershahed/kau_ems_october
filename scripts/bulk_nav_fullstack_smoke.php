<?php
/** Full HTTP kernel + middleware + auth simulation. STRICTLY READ-ONLY. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$tables = ['salary_generates', 'general_provident_funds', 'salary_calculation_versions',
    'salary_bill_source_links', 'salary_arrear_snapshots'];
$before = [];
foreach ($tables as $t) $before[$t] = Schema::hasTable($t) ? DB::table($t)->count() : -1;

$user = User::query()->orderBy('id')->first();
if (!$user) { fwrite(STDERR, "no users\n"); exit(1); }
Auth::login($user);

$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$call = function (string $query) use ($kernel) {
    $req = Request::create('/dashboard/reports/salary-official?'.$query, 'GET');
    $req->setLaravelSession(app('session.store'));
    return $kernel->handle($req);
};

// 1. Zero generated employees (October 2026) with mode=preview.
$ids = DB::table('employee_infos')->whereNotNull('user_id')->orderBy('id')->limit(26)->pluck('user_id')->implode(',');
$r1 = $call(http_build_query(['ids' => $ids, 'year' => 2026, 'month' => 10,
    'kind' => 'bulk', 'per_page' => 25, 'format' => 'html', 'mode' => 'preview']));
echo 'zero_generated status='.$r1->getStatusCode().' redirect='.(string) $r1->headers->get('Location').PHP_EOL;
echo '  draft='.var_export(str_contains((string) $r1->getContent(), 'DRAFT'), true)
    .' bulk_title='.var_export(str_contains((string) $r1->getContent(), 'Horizontal Salary Bill'), true).PHP_EOL;

// 2. Invalid selection: kind=bill with two ids -> visible 422.
$r2 = $call(http_build_query(['ids' => '1,2', 'year' => 2026, 'month' => 10, 'kind' => 'bill', 'mode' => 'preview']));
echo 'invalid status='.$r2->getStatusCode().' (expect 422)'.PHP_EOL;

// 3. Official (non-preview) mode without bills -> 422 reconciliation.
$r3 = $call(http_build_query(['ids' => '1', 'year' => 2026, 'month' => 10, 'kind' => 'bill', 'format' => 'html']));
echo 'official_no_bill status='.$r3->getStatusCode().' (expect 422)'.PHP_EOL;

foreach ($tables as $t) {
    $after = Schema::hasTable($t) ? DB::table($t)->count() : -1;
    if ($after !== $before[$t]) { fwrite(STDERR, "WRITE DETECTED in $t\n"); exit(1); }
}
echo 'zero writes confirmed; FULL-STACK READ-ONLY OK'.PHP_EOL;
