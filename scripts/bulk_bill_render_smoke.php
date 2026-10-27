<?php
/** Horizontal bulk salary bill render smoke: STRICTLY READ-ONLY. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SalaryGenerate;
use App\Services\SalaryOfficialReportSource;
use DomainException;
use Illuminate\Support\Facades\DB;

// Find a month with posted bills (any month) to exercise the renderer.
$months = DB::table('salary_generates')
    ->selectRaw('salaryGenerationYear y, monthId m, count(*) c')
    ->groupBy('salaryGenerationYear', 'monthId')->orderByDesc('c')->get();
if (!$months->count()) { fwrite(STDERR, "no bills\n"); exit(1); }
$y = (int) $months[0]->y; $m = (int) $months[0]->m;
$ids = DB::table('salary_generates')->where('salaryGenerationYear', $y)->where('monthId', $m)
    ->groupBy('user_id')->havingRaw('count(*) = 1')->limit(30)->pluck('user_id')->all();
echo 'source month='.$y.'-'.$m.' employees='.count($ids).PHP_EOL;
$source = app(SalaryOfficialReportSource::class);
$rows = [];
foreach ($ids as $id) {
    $bill = SalaryGenerate::where('user_id', $id)
        ->where('salaryGenerationYear', $y)->where('monthId', $m)->first();
    try { $rows[] = $source->fromPostedBill($bill); }
    catch (DomainException $e) { /* skip inconsistent bill */ }
}
echo 'renderable rows='.count($rows).PHP_EOL;
if (count($rows) < 26) { fwrite(STDERR, "need >=26 rows to test 25/page; got ".count($rows)."\n"); exit(1); }
$pages = array_chunk($rows, 25);
echo 'page_sizes='.json_encode(array_map('count', $pages)).PHP_EOL;
$html = view('salary.official-report', [
    'rows' => $rows, 'pages' => $pages, 'kind' => 'bulk',
    'year' => $y, 'month' => $m, 'grandTotal' => round(array_sum(array_column($rows, 'net')), 2),
    'perPage' => 25, 'pdfAvailable' => false, 'logoData' => null, 'embed' => '0',
])->render();
$checks = [
    'landscape' => str_contains($html, 'landscape'),
    'bulk_title' => str_contains($html, 'Horizontal Salary Bill'),
    'arrear_plus' => str_contains($html, 'Arrear +'),
    'arrear_minus' => str_contains($html, 'Arrear −'),
    'gpf_column' => stripos($html, 'gpf') !== false,
    'net_column' => str_contains($html, 'Net'),
    'no_undefined' => !str_contains($html, 'undefined') && !str_contains($html, 'NaN'),
];
echo 'checks='.json_encode($checks).PHP_EOL;
foreach ($checks as $k => $v) if (!$v) { fwrite(STDERR, "FAILED: $k\n"); exit(1); }
echo 'single bill still renders='.(str_contains(view('salary.official-report', [
    'rows' => [$rows[0]], 'pages' => [[$rows[0]]], 'kind' => 'bill', 'year' => $y, 'month' => $m,
    'grandTotal' => $rows[0]['net'], 'perPage' => 15, 'pdfAvailable' => false, 'logoData' => null, 'embed' => '0',
])->render(), 'Salary Bill') ? 'yes' : 'no').PHP_EOL;
echo 'READ-ONLY OK'.PHP_EOL;
