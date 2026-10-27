<?php

/**
 * Phase 1 — read-only inspection of the local codershat_kau database.
 * No writes. Safe to run repeatedly.
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo 'DB='.DB::connection()->getDatabaseName()
    .' HOST='.config('database.connections.mysql.host')
    .' PORT='.config('database.connections.mysql.port')
    .' SELECT_DB='.DB::selectOne('select database() as d')->d
    .' VERSION='.DB::selectOne('select version() as v')->v."\n";

echo "salary_generates by month/year:\n";
foreach (DB::select('select monthId m, salaryGenerationYear y, count(*) c from salary_generates group by monthId, salaryGenerationYear order by y, m') as $r) {
    echo "  {$r->y}-{$r->m} = {$r->c}\n";
}

echo "users/employee_infos:\n";
foreach (DB::select('select count(*) c from users') as $r) { echo "  users = {$r->c}\n"; }
foreach (DB::select('select count(*) c from employee_infos') as $r) { echo "  employee_infos = {$r->c}\n"; }
foreach (DB::select('select count(*) c from employee_infos where employeeCode = ?', ['20057']) as $r) {
    echo "  employeeCode 20057 rows = {$r->c}\n";
}

echo "employee 20057 mapping:\n";
foreach (DB::select('select e.user_id, e.employeeCode, u.name from employee_infos e join users u on u.id = e.user_id where e.employeeCode = ?', ['20057']) as $r) {
    echo "  user_id={$r->user_id} code={$r->employeeCode} name={$r->name}\n";
}

echo "salary_fixations count:\n";
foreach (DB::select('select count(*) c from salary_fixations') as $r) { echo "  salary_fixations = {$r->c}\n"; }

echo "sample historical salary_generates rows (any month/year):\n";
foreach (DB::select('select id, user_id, employeeCode, monthId, salaryGenerationYear, reviewStatus, totalAllowance, totalDeduction, salaryTotal from salary_generates order by id limit 3') as $r) {
    echo "  id={$r->id} user={$r->user_id} code={$r->employeeCode} {$r->salaryGenerationYear}-{$r->monthId} status={$r->reviewStatus} allow={$r->totalAllowance} ded={$r->totalDeduction} net={$r->salaryTotal}\n";
}
