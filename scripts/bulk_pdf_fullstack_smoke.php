<?php
/** Bulk PDF end-to-end smoke: full HTTP kernel, STRICTLY READ-ONLY. */
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

$allIds = DB::table('employee_infos')->whereNotNull('user_id')->orderBy('id')->pluck('user_id')->unique()->values()->all();
echo 'eligible_employees='.count($allIds).PHP_EOL;
$call = function (array $ids, int $count) use ($kernel) {
    $ids = array_slice($ids, 0, $count);
    $q = http_build_query(['ids' => implode(',', $ids), 'year' => 2026, 'month' => 10,
        'kind' => 'bulk', 'per_page' => 25, 'format' => 'pdf', 'mode' => 'preview']);
    $req = Request::create('/dashboard/reports/salary-official?'.$q, 'GET');
    $req->setLaravelSession(app('session.store'));
    $resp = $kernel->handle($req);
    $status = $resp->getStatusCode();
    $ct = (string) $resp->headers->get('Content-Type');
    $body = (string) $resp->getContent();
    $len = strlen($body);
    $sig = str_starts_with($body, '%PDF');
    $pages = 0;
    if ($sig) {
        $pages = preg_match_all('#/Type\s*/Page[^s]#', $body);

        preg_match('#/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]#', $body, $mb);
        $landscape = isset($mb[1]) && (float) $mb[1] > (float) $mb[2];
    }
    echo "count=$count status=$status ct=$ct bytes=$len sig=".var_export($sig, true)
        ." pages=$pages expected=".((int) ceil($count / 25))
        .(isset($landscape) ? ' landscape='.var_export($landscape, true) : '').PHP_EOL;
    if ($status !== 200 || $ct !== 'application/pdf' || !$sig || $len < 1000) {
        fwrite(STDERR, "PDF FAILED for count=$count\n");
        exit(1);
    }
};

$call($allIds, 1);
$call($allIds, 26);
$call($allIds, 412);

foreach ($tables as $t) {
    $after = Schema::hasTable($t) ? DB::table($t)->count() : -1;
    if ($after !== $before[$t]) { fwrite(STDERR, "WRITE DETECTED in $t\n"); exit(1); }
}
echo 'zero writes confirmed; PDF END-TO-END OK'.PHP_EOL;
