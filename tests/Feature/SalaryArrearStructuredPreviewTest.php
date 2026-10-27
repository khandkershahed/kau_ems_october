<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 3C-Fix (Task G): focused structured-preview endpoint tests.
 * Read-only HTTP tests; run ONLY on an isolated local kau_*_test/staging
 * MySQL clone. Never against codershat_kau or any remote database.
 */
final class SalaryArrearStructuredPreviewTest extends TestCase
{
    private function requireIsolated(): void
    {
        $name = (string) config('database.connections.mysql.database');
        $host = strtolower((string) config('database.connections.mysql.host'));
        if (config('database.default') !== 'mysql'
            || !preg_match('/^kau_.*_(test|staging)$/', $name)
            || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
            || !in_array(app()->environment(), ['local', 'testing'], true)) {
            $this->markTestSkipped('Dedicated disposable local kau_*_test/staging MySQL clone is required.');
        }
    }

    private function employee(): int
    {
        $id = (int) (DB::table('employee_infos')->whereNotNull('user_id')->orderBy('id')->value('user_id') ?? 0);
        if (!$id) $this->markTestSkipped('Test clone has no employee profiles.');
        return $id;
    }

    private function periodId(): int
    {
        if (!Schema::hasTable('salary_arrear_periods')) {
            $this->markTestSkipped('Apply arrear migrations in test clone first.');
        }
        $id = (int) (DB::table('salary_arrear_periods')->orderByDesc('id')->value('id') ?? 0);
        if (!$id) {
            $id = (int) DB::table('salary_arrear_periods')->insertGetId([
                'name' => 'Phase3C-Fix test period', 'entitlement_start' => '2026-07-01',
                'entitlement_end' => '2026-09-30', 'regular_scale_activation' => '2027-07-01',
                'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $id;
    }

    public function test_structured_preview_returns_200_with_five_components_and_no_writes(): void
    {
        $this->requireIsolated();
        $user = User::query()->orderBy('id')->first();
        if (!$user) $this->markTestSkipped('No users in test clone.');
        $userId = $this->employee();
        $this->assertCount(5, $body['components']);
        // Read-only: no snapshot, salary bill, GPF or journal writes occurred.
        $periodId = $this->periodId();
        $before = [
            'snapshots' => Schema::hasTable('salary_arrear_snapshots') ? DB::table('salary_arrear_snapshots')->count() : 0,
            'bills' => Schema::hasTable('salary_generates') ? DB::table('salary_generates')->count() : 0,
            'gpf' => Schema::hasTable('general_provident_funds') ? DB::table('general_provident_funds')->count() : 0,
            'journals' => Schema::hasTable('journals') ? DB::table('journals')->count() : 0,
        ];

        $response = $this->actingAs($user)
            ->getJson("/salary/arrears/periods/{$periodId}/employees/{$userId}/structured-preview");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame('STRUCTURED_PAY_SCALE_2026', $body['calculation_method']);
        $this->assertSame(
            \App\Services\SalaryStructuredArrearService::CODES,
            array_map(fn ($c) => $c['code'], $body['components'])
        );
        // Missing rule records still show five rows with display-only fallbacks.
        $this->assertCount(5, $body['components']);
        $this->assertSame($before['snapshots'], Schema::hasTable('salary_arrear_snapshots') ? DB::table('salary_arrear_snapshots')->count() : 0);
        $this->assertSame($before['bills'], Schema::hasTable('salary_generates') ? DB::table('salary_generates')->count() : 0);
        $this->assertSame($before['gpf'], Schema::hasTable('general_provident_funds') ? DB::table('general_provident_funds')->count() : 0);
        $this->assertSame($before['journals'], Schema::hasTable('journals') ? DB::table('journals')->count() : 0);
        // Source metadata renders safely: never NaN/undefined payloads.
        foreach ($body['components'] as $component) {
            $this->assertIsArray($component['month_sources']);
        }
    }

    public function test_structured_preview_returns_404_for_invalid_employee(): void
    {
        $this->requireIsolated();
        $user = User::query()->orderBy('id')->first();
        if (!$user) $this->markTestSkipped('No users in test clone.');
        $periodId = $this->periodId();
        $this->actingAs($user)
            ->getJson("/salary/arrears/periods/{$periodId}/employees/999999999/structured-preview")
            ->assertStatus(404);
    }

    public function test_legacy_statement_route_remains_functional(): void
    {
        $this->requireIsolated();
        $user = User::query()->orderBy('id')->first();
        if (!$user) $this->markTestSkipped('No users in test clone.');
        $userId = $this->employee();
        $periodId = $this->periodId();
        $this->actingAs($user)
            ->getJson("/salary/arrears/periods/{$periodId}/employees/{$userId}")
            ->assertStatus(200)
            ->assertJsonPath('payable', false)
            ->assertJsonPath('gpf_posted', false);
    }
}
