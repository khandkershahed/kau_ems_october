<?php

// Step 3B-R preflight — READ ONLY. Task A/B/C evidence.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo 'db='.DB::connection()->getDatabaseName().' host='.config('database.connections.mysql.host').PHP_EOL;
$e = DB::table('employee_infos')->where('employeeCode', '20057')->first();
echo 'employeeCode=20057 user_id='.($e->user_id ?? 'NULL').PHP_EOL;

$s = DB::table('salary_generates')->where('id', 5850)->first();
echo '5850: user_id='.$s->user_id.' month='.$s->monthId.' year='.$s->salaryGenerationYear
    .' status='.$s->reviewStatus.' totalA='.$s->totalAllowance.' totalD='.$s->totalDeduction
    .' salaryTotal='.$s->salaryTotal.' gpf='.$s->gpfDeduction.PHP_EOL;

echo '--- GPF ledger 5628 + chain ---'.PHP_EOL;
foreach (DB::table('general_provident_funds')->where('user_id', 58)
    ->where('year', 2026)->orderBy('month')->get(['id','month','openingBalance','contributionAmount','closingAmount','isSettled','salary_generate_id']) as $g) {
    echo 'GPF id='.$g->id.' m'.$g->month.' open='.$g->openingBalance.' contrib='.$g->contributionAmount
        .' close='.$g->closingAmount.' settled='.$g->isSettled.' sgid='.$g->salary_generate_id.PHP_EOL;
}
echo '--- any later GPF entries depending on 5628 (user 58, month>9) ---'.PHP_EOL;
foreach (DB::table('general_provident_funds')->where('user_id', 58)->where('month', '>', 9)->get(['id','month','year','openingBalance']) as $g) {
    echo 'GPF id='.$g->id.' m'.$g->month.'/'.$g->year.' open='.$g->openingBalance.PHP_EOL;
}
echo '--- tables referencing salary_generates 5850 ---'.PHP_EOL;
foreach (['bank_advices','bank_advice_records','salary_payments','payment_records','salary_bill_arrear_gpf_entries',
    'salary_arrear_source_overrides','salary_arrear_periods','arrear_postings','salary_approvals'] as $t) {
    if (!Schema::hasTable($t)) { echo $t.': no-table'.PHP_EOL; continue; }
    $cols = Schema::getColumnListing($t);
    $ref = null;
    foreach ($cols as $c) {
        if (preg_match('/salary_generate_id|salary_generate_id|salary_generates_id/i', $c)) { $ref = $c; }
    }
    if ($ref) {
        $n = DB::table($t)->where($ref, 5850)->count();
        echo $t.'.'.$ref.' -> 5850: '.$n.' rows'.PHP_EOL;
        foreach (DB::table($t)->where($ref, 5850)->limit(3)->get() as $row) {
            echo '   '.substr(json_encode((array) $row), 0, 200).PHP_EOL;
        }
    } else {
        echo $t.': no salary_generate_id column'.PHP_EOL;
    }
}
echo '--- source row 5903 + provenance ---'.PHP_EOL;
foreach (DB::table('salary_import_records')->where('user_id', 58)->where('month_id', 9)
    ->get(['id','run_id','source_row_id','target_salary_generate_id','action','source_review_status','note']) as $p) {
    echo 'prov id='.$p->id.' run='.$p->run_id.' source_row='.$p->source_row_id.' target_sgid='.$p->target_salary_generate_id
        .' action='.$p->action.' review='.$p->source_review_status.' note='.substr((string) $p->note, 0, 80).PHP_EOL;
}
echo '--- import run fingerprint ---'.PHP_EOL;
foreach (DB::table('salary_import_runs')->get(['id','source_sha256','status','created_at']) as $run) {
    echo 'run id='.$run->id.' sha256='.substr((string) $run->source_sha256, 0, 16).'... status='.$run->status.' at='.$run->created_at.PHP_EOL;
}
echo '--- import run fingerprint ---'.PHP_EOL;
foreach (DB::table('salary_import_runs')->get(['id','source_sha256','created_at','status']) as $run) {
    echo 'run id='.$run->id.' sha256='.substr((string) $run->source_sha256, 0, 16).'... status='.$run->status.' at='.$run->created_at.PHP_EOL;
}
echo '--- approved fixation user 58 ---'.PHP_EOL;
foreach (DB::table('salary_fixations')->where('user_id', 58)->where('status', 'approved')
    ->get(['id','status','old_basic','approved_at']) as $f) {
    echo 'fixation id='.$f->id.' status='.$f->status.' old_basic='.$f->old_basic.' approved_at='.$f->approved_at.PHP_EOL;
}
echo '--- employee 40173 sanity (must be untouched) ---'.PHP_EOL;
$u = DB::table('employee_infos')->where('employeeCode', '40173')->first();
echo 'user_id 40173 = '.($u->user_id ?? 'NULL').PHP_EOL;
