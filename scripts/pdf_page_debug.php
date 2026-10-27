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
$n = 412;
foreach (['html', 'pdf'] as $format) {
    $q = http_build_query(['ids' => implode(',', array_slice($ids, 0, $n)), 'year' => 2026,
        'month' => 10, 'kind' => 'bulk', 'per_page' => 25, 'format' => $format, 'mode' => 'preview']);
    $req = Request::create('/dashboard/reports/salary-official?'.$q, 'GET');
    $req->setLaravelSession(app('session.store'));
    $resp = $kernel->handle($req);
    $body = (string) $resp->getContent();
    echo $format.': status='.$resp->getStatusCode().' sheets='.substr_count($body, 'class="sheet"')
        .' pdf_pages='.preg_match_all('#/Type\s*/Page[^s]#', $body).' bytes='.strlen($body).PHP_EOL;
    if ($format === 'pdf') {
        preg_match('#/Count (\d+)#', $body, $m);
        echo 'pdf /Count='.($m[1] ?? 'none').PHP_EOL;
    }
}
