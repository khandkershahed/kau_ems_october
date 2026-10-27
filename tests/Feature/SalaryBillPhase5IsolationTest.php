<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\SalaryBillPostingService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Never run this posting test against a real or remotely hosted database. */
final class SalaryBillPhase5IsolationTest extends TestCase
{
    private function requireIsolated(): void
    {
        $db=config('database.default');
        $name=(string)config('database.connections.mysql.database');
        $host=strtolower((string)config('database.connections.mysql.host'));
        if ($db!=='mysql' || !preg_match('/^kau_.*_(test|staging)$/',$name)
            || !in_array($host,['127.0.0.1','localhost','::1'],true)
            || !in_array(app()->environment(),['local','testing'],true)) {
            $this->markTestSkipped('A disposable local kau_*_test/staging MySQL clone is mandatory.');
        }
        if (!Schema::hasTable('salary_bill_source_links')) $this->markTestSkipped('Phase 5 migrations missing.');
    }

    public function test_preview_is_read_only_and_posting_is_idempotent_with_rollback(): void
    {
        $this->requireIsolated();
        $service=app(SalaryBillPostingService::class);
        $year=2028;$month=12;
        $candidate=null;
        foreach (User::where('isActive',true)->orderBy('id')->limit(60)->pluck('id') as $userId) {
            if (DB::table('salary_generates')->where('user_id',$userId)
                ->where('monthId',$month)->where('salaryGenerationYear',$year)->exists()) continue;
            try {$service->preview((int)$userId,$year,$month);$candidate=(int)$userId;break;}
            catch (DomainException $e) {continue;}
        }
        if (!$candidate) $this->markTestSkipped('No evidence-complete employee fixture for isolated posting test.');
        DB::beginTransaction();
        try {
            $before=DB::table('salary_generates')->where('user_id',$candidate)
                ->where('monthId',$month)->where('salaryGenerationYear',$year)->count();
            $service->preview($candidate,$year,$month);
            $this->assertSame($before,DB::table('salary_generates')->where('user_id',$candidate)
                ->where('monthId',$month)->where('salaryGenerationYear',$year)->count());
            $first=$service->post($candidate,$year,$month);
            $second=$service->post($candidate,$year,$month);
            $this->assertSame('generated',$first['result']);
            $this->assertSame('skipped_existing',$second['result']);
            $this->assertSame($first['bill_id'],$second['bill_id']);
            $this->assertSame(1,DB::table('salary_bill_source_links')->where('user_id',$candidate)
                ->where('salary_year',$year)->where('salary_month',$month)->count());
        } finally { DB::rollBack(); }
    }
}
