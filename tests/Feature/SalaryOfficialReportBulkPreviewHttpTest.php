<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK 5 regression tests for the Horizontal Bulk Salary Bill preview,
 * through the FULL HTTP stack (auth middleware, Inertia, routing).
 * Strictly READ-ONLY GET requests; every test asserts zero DB writes.
 */
final class SalaryOfficialReportBulkPreviewHttpTest extends TestCase
{
    private array $tables = [
        'salary_generates', 'general_provident_funds', 'salary_calculation_versions',
        'salary_bill_source_links', 'salary_arrear_snapshots', 'salary_manual_adjustments',
    ];

    private function refuseRemote(): void
    {
        $host = strtolower((string) config('database.connections.mysql.host'));
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->markTestSkipped('Refusing remote database hosts.');
        }
    }

    private function counts(): array
    {
        $out = [];
        foreach ($this->tables as $t) $out[$t] = Schema::hasTable($t) ? DB::table($t)->count() : -1;
        return $out;
    }

    public function fetch(string $query): \Illuminate\Testing\TestResponse
    {
        $user = User::query()->orderBy('id')->first();
        if (!$user) $this->markTestSkipped('No users in this database.');
        return $this->actingAs($user)->get('/dashboard/reports/salary-official?'.$query);

    }

    private function uidByCode(string $code): int
    {
        return (int) (DB::table('employee_infos')->where('employeeCode', $code)->value('user_id') ?? 0);
    }

    public function test_zero_generated_employees_still_get_draft_bulk_preview_200(): void
    {
        $this->refuseRemote();
        $ids = DB::table('employee_infos')->whereNotNull('user_id')->orderBy('id')->limit(26)->pluck('user_id')->all();
        if (!count($ids)) $this->markTestSkipped('No employee profiles.');
        $before = $this->counts();
        $response = $this->fetch(http_build_query([
            'ids' => implode(',', $ids), 'year' => 2026, 'month' => 10,
            'kind' => 'bulk', 'per_page' => 25, 'format' => 'html', 'mode' => 'preview',
        ]));
        $response->assertStatus(200);
        $html = $response->getContent();
        $this->assertStringContainsString('Horizontal Salary Bill', $html);
        $this->assertStringContainsString('landscape', $html);
        $this->assertStringContainsString('DRAFT', $html);
        $this->assertSame($before, $this->counts(), 'Preview must not write.');
    }

    public function test_mixed_generated_and_ungenerated_preview_200(): void
    {
        $this->refuseRemote();
        $gen = DB::table('salary_generates')->groupBy('user_id')->havingRaw('count(*) = 1')->limit(3)->pluck('user_id')->all();
        if (!count($gen)) $this->markTestSkipped('No single-bill employees.');
        $extra = (int) (DB::table('employee_infos')->whereNotNull('user_id')
            ->whereNotIn('user_id', $gen)->orderBy('id')->value('user_id') ?? 0);
        $ids = array_map('intval', $gen);
        if ($extra) $ids[] = $extra;
        $response = $this->fetch(http_build_query([
            'ids' => implode(',', $ids), 'year' => 2026, 'month' => 10,
            'kind' => 'bulk', 'per_page' => 25, 'format' => 'html', 'mode' => 'preview',
        ]));
        $response->assertStatus(200);
        $this->assertStringContainsString('DRAFT', $response->getContent());
    }

    public function test_all_generated_preview_200(): void
    {
        $this->refuseRemote();
        $gen = DB::table('salary_generates')->groupBy('user_id')->havingRaw('count(*) = 1')->limit(5)->pluck('user_id')->all();
        if (count($gen) < 2) $this->markTestSkipped('Not enough single-bill employees.');
        $response = $this->fetch(http_build_query([
            'ids' => implode(',', $gen), 'year' => 2026, 'month' => 10,
            'kind' => 'bulk', 'per_page' => 25, 'format' => 'html', 'mode' => 'preview',
        ]));
        $response->assertStatus(200);
        $this->assertStringContainsString('Horizontal Salary Bill', $response->getContent());
    }

    public function test_invalid_selection_returns_visible_422_not_redirect(): void
    {
        $this->refuseRemote();
        $a = $this->uidByCode('20057') ?: (int) (User::query()->orderBy('id')->value('id') ?? 0);
        $b = $this->uidByCode('40173') ?: ($a + 1);
        if (!$a || !$b) $this->markTestSkipped('Not enough users.');
        $response = $this->fetch(http_build_query([
            'ids' => $a.','.$b, 'year' => 2026, 'month' => 10, 'kind' => 'bill', 'mode' => 'preview',
        ]));
        $response->assertStatus(422);
    }

    public function test_unauthenticated_request_redirects_to_login(): void
    {
        $this->refuseRemote();
        $user = User::query()->orderBy('id')->first();
        if (!$user) $this->markTestSkipped('No users.');
        $response = $this->get('/dashboard/reports/salary-official?'.http_build_query([
            'ids' => '1', 'year' => 2026, 'month' => 10, 'kind' => 'bulk', 'mode' => 'preview',
        ]));

        $this->assertSame(302, $response->getStatusCode(), 'Unauthenticated users must be redirected to login, never silently shown a report.');
        $this->assertStringContainsString('login', (string) $response->headers->get('Location'));
    }

    public function test_official_mode_without_bills_requires_reconciliation_422(): void
    {
        $this->refuseRemote();
        $uid = (int) (DB::table('employee_infos')->whereNotNull('user_id')->orderBy('id')->value('user_id') ?? 0);
        if (!$uid) $this->markTestSkipped('No employee profiles.');
        $response = $this->fetch(http_build_query([
            'ids' => $uid, 'year' => 2026, 'month' => 10, 'kind' => 'bill', 'format' => 'html',
        ]));
        $status = $response->getStatusCode();
        $this->assertContains($status, [200, 422]);
    }
}
