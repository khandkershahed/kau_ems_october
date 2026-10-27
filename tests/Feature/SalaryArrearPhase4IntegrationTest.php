<?php

namespace Tests\Feature;

use App\Services\SalaryArrearRuleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SalaryArrearPhase4IntegrationTest extends TestCase
{
    public function test_read_only_arrear_preview_preserves_salary_and_gpf_rows(): void
    {
        $db=(string)config('database.connections.mysql.database');
        $host=(string)config('database.connections.mysql.host');
        if (!in_array(app()->environment(), ['local','testing'], true)
            || !preg_match('/kau.*(?:test|staging)/i', $db)
            || !in_array($host, ['127.0.0.1','localhost','::1'], true)) {
            $this->markTestSkipped('Use only a local isolated kau_*_test/staging MySQL clone.');
        }
        self::assertTrue(Schema::hasTable('salary_arrear_rules'));
        self::assertTrue(Schema::hasTable('salary_arrear_employee_overrides'));
        self::assertTrue(Schema::hasColumn('salary_calculation_versions','arrear_snapshot'));
        $profile=DB::table('employee_infos')->orderBy('id')->first();
        if (!$profile) $this->markTestSkipped('Test clone has no employee profiles.');
        $beforeBills=DB::table('salary_generates')->count();
        $beforeGpf=DB::table('general_provident_funds')->count();
        $rows=app(SalaryArrearRuleService::class)->preview((int)$profile->user_id,2026,10);
        self::assertIsArray($rows);
        self::assertSame($beforeBills,DB::table('salary_generates')->count());
        self::assertSame($beforeGpf,DB::table('general_provident_funds')->count());
    }
}
