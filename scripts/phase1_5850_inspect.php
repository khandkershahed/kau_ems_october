<?php

/** Phase 3A — read-only inspection of bill 5850 and its provenance. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$r = DB::table('salary_generates')->where('id', 5850)->first();
echo $r ? json_encode([
    'id' => $r->id,
    'reviewStatus' => $r->reviewStatus,
    'user_id' => $r->user_id,
    'month' => $r->monthId,
    'year' => $r->salaryGenerationYear,
    'net' => $r->salaryTotal,
]) : 'none';
echo "\n";
echo 'provenance_rows_for_5850='.DB::table('salary_import_records')->where('target_salary_generate_id', 5850)->count()."\n";
echo 'imported_rows_for_20057='.DB::table('salary_import_records')->where('employee_code', '20057')->get(['action', 'month_id', 'year', 'target_salary_generate_id'])->toJson()."\n";
