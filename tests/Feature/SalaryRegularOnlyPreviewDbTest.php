<?php

namespace Tests\Feature;

use App\Services\SalaryBillCompositionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * October 2026 regular-only preview DB checks. READ-ONLY.
 * Runs ONLY on a disposable local kau_*_test/staging clone; refused elsewhere.
 */
final class SalaryRegularOnlyPreviewDbTest extends TestCase
{
    private function requireIsolated(): void
    {
        $name = (string) config('database.connections.mysql.database');
        $host = strtolower((string) config('database.connections.mysql.host'));
        if (config('database.default') !== 'mysql'
            || !preg_match('/^kau_.*_(test|staging)$/', $name)
            || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->markTestSkipped('Dedicated disposable local kau_*_test/staging MySQL clone is required.');
        }
    }

    private function uidByCode(string $code): int
    {
        return (int) (DB::table('employee_infos')->where('employeeCode', $code)->value('user_id') ?? 0);
    }

    /** depends*/
    public function test_employees_20057_and_40173_regular_only_preview(): void
    {
        $this->requireIsolated();
        $svc = app(SalaryBillCompositionService::class);
        $u20057 = $this->uidByCode('20057');
        if (!$u20057) $this->markTestSkipped('Clone has no employee 20057.');
        $out = $svc->composeRegularOnly($u20057, 2026, 10);
        $this->assertSame('READY', $out['status']);
        $this->assertSame('Govt. Pay Scale 2015', $out['pay_scale_source']);
        $this->assertNotNull($out['regular_gross']);
        $this->assertNotNull($out['regular_net']);
        $this->assertArrayHasKey('gpf_deduction', $out['regular']);
        $u40173 = $this->uidByCode('40173');
        if (!$u40173) $this->markTestSkipped('Clone has no employee 40173.');
        $out2 = $svc->composeRegularOnly($u40173, 2026, 10);
        $this->assertContains($out2['status'], ['READY', 'BLOCKED']);
    }

    public function test_preview_causes_zero_database_writes(): void
    {
        $this->requireIsolated();
        $tables = ['salary_generates', 'general_provident_funds', 'salary_calculation_versions',
            'salary_bill_source_links', 'salary_arrear_snapshots'];
        $svc = app(SalaryBillCompositionService::class);
        $uid = $this->uidByCode('20057');
        if (!$uid) $this->markTestSkipped('Clone has no employee 20057.');
        $counts = [];
        foreach ($tables as $t) $counts[$t] = Schema::hasTable($t) ? DB::table($t)->count() : -1;
        $svc->composeRegularOnly($uid, 2026, 10);
        foreach ($tables as $t) {
            $after = Schema::hasTable($t) ? DB::table($t)->count() : -1;
            $this->assertSame($counts[$t], $after, "unexpected write in $t");
        }
    }
}
