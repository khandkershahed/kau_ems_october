<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$user = User::query()->orderBy('id')->first();
Auth::login($user);
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$ids = DB::table('employee_infos')->whereNotNull('user_id')->orderBy('id')
    ->pluck('user_id')->unique()->values()->all();
foreach ([26, 412] as $n) {
    $q = http_build_query(['ids' => implode(',', array_slice($ids, 0, $n)), 'year' => 2026,
        'month' => 10, 'kind' => 'bulk', 'per_page' => 25, 'format' => 'html', 'mode' => 'preview']);
    $req = Request::create('/dashboard/reports/salary-official?'.$q, 'GET');
    $req->setLaravelSession(app('session.store'));
    $html = (string) $kernel->handle($req)->getContent();
    echo "n=$n html sheets=".substr_count($html, 'class="sheet"')
        .' page_totals='.substr_count($html, 'PAGE TOTAL')
        .' draft_rows='.substr_count($html, '(DRAFT)')
        .' draft_banner='.substr_count($html, 'DRAFT - NOT GENERATED').PHP_EOL;
}
