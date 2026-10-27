<?php

/** Phase 1 — verification: where did the 415 inserted rows go (monthId/year distribution) + counts. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo 'target DB: '.DB::select('SELECT DATABASE() AS d')[0]->d."\n\n";

echo "=== 1. Imported (inserted) rows by monthId/year (run_id=2) ===\n";
$dist = DB::table('salary_import_records')
    ->where('run_id', 2)->where('action', 'inserted')
    ->select('year', 'month_id', DB::raw('count(*) as c'))
    ->groupBy('year', 'month_id')->orderBy('year')->orderBy('month_id')->get();
$totalInserted = 0;
foreach ($dist as $r) {
    echo "  {$r->year}-{$r->month_id} = {$r->c}\n";
    $totalInserted += $r->c;
}
echo "  TOTAL inserted = {$totalInserted}\n";

echo "\n=== 2. All run_id=2 actions summary ===\n";
foreach (DB::table('salary_import_records')->where('run_id', 2)
    ->select('action', DB::raw('count(*) as c'))->groupBy('action')->get() as $r) {
    echo "  {$r->action} = {$r->c}\n";
}

echo "\n=== 3. salary_generates month/year distribution (June-Sept 2026) ===\n";
foreach (DB::table('salary_generates')
    ->where('salaryGenerationYear', 2026)->whereIn('monthId', [6, 7, 8, 9])
    ->select('monthId', DB::raw('count(*) as c'))->groupBy('monthId')->orderBy('monthId')->get() as $r) {
    echo "  2026-{$r->monthId} = {$r->c}\n";
}

echo "\n=== 4. Import provenance check ===\n";
$provCount = DB::table('salary_import_records')->where('run_id', 2)->where('action', 'inserted')
    ->whereNotNull('target_salary_generate_id')->count();
echo "  inserted records with target id: {$provCount}\n";
$orphan = DB::table('salary_import_records as r')->where('r.run_id', 2)->where('r.action', 'inserted')
    ->leftJoin('salary_generates as g', 'g.id', '=', 'r.target_salary_generate_id')
    ->whereNull('g.id')->count();
echo "  orphan provenance rows (target missing): {$orphan}\n";

echo "\n=== 5. reviewStatus of imported rows ===\n";
foreach (DB::table('salary_generates as g')
    ->join('salary_import_records as r', 'r.target_salary_generate_id', '=', 'g.id')
    ->where('r.run_id', 2)->where('r.action', 'inserted')
    ->select('g.reviewStatus', DB::raw('count(*) as c'))->groupBy('g.reviewStatus')->get() as $r) {
    echo "  {$r->reviewStatus} = {$r->c}\n";
}

echo "\n=== 6. Net total sums of imported rows ===\n";
$sums = DB::table('salary_generates as g')
    ->join('salary_import_records as r', 'r.target_salary_generate_id', '=', 'g.id')
    ->where('r.run_id', 2)->where('r.action', 'inserted')
    ->selectRaw('sum(g.totalAllowance) sAllow, sum(g.totalDeduction) sDed, sum(g.salaryTotal) sNet')
    ->first();
echo '  totalAllowance='.number_format((float) $sums->sAllow, 2)
    .' totalDeduction='.number_format((float) $sums->sDed, 2)
    .' salaryTotal(net)='.number_format((float) $sums->sNet, 2)."\n";

echo "\n=== 7. Duplicate detection (user_id+month+year in June-Sept 2026) ===\n";
$dups = DB::table('salary_generates')
    ->where('salaryGenerationYear', 2026)->whereIn('monthId', [6, 7, 8, 9])
    ->select('user_id', 'monthId', DB::raw('count(*) as c'))
    ->groupBy('user_id', 'monthId')->havingRaw('count(*) > 1')->get();
echo '  duplicate employee-month pairs: '.count($dups)."\n";
foreach (array_slice($dups->all(), 0, 10) as $d) {
    echo "  user_id={$d->user_id} month={$d->monthId} count={$d->c}\n";
}

echo "\n=== 8. Runs table state ===\n";
foreach (DB::table('salary_import_runs')->get(['id', 'mode', 'status', 'source_sha256', 'created_at']) as $r) {
    echo '  run '.$r->id.': mode='.$r->mode.' status='.$r->status."\n";
}
