<?php

/** Phase 1 — DB state audit after the failed apply run. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo 'target DB: '.DB::select('SELECT DATABASE() AS d')[0]->d."\n";
foreach (DB::select('select monthId m, salaryGenerationYear y, count(*) c from salary_generates where salaryGenerationYear=2026 and monthId in (6,7,8,9) group by monthId, salaryGenerationYear order by monthId') as $r) {
    echo "  2026-{$r->m} = {$r->c}\n";
}

echo 'salary_import_runs: '."\n";
foreach (DB::table('salary_import_runs')->get(['id', 'source_file', 'source_sha256', 'mode', 'status', 'created_at']) as $r) {
    echo json_encode($r)."\n";
}
echo 'salary_import_records: '."\n";
foreach (DB::table('salary_import_records')->get(['id', 'run_id', 'source_row_id', 'target_salary_generate_id', 'employee_code', 'month_id', 'year', 'action']) as $r) {
    echo json_encode($r)."\n";
}
