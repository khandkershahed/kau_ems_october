<?php
namespace Tests\Feature;

use Tests\TestCase;

final class SalaryPatch09IntegrationContractTest extends TestCase
{
    public function test_generate_workspace_routes_require_authentication(): void
    {
        $this->get('/dashboard/salary/generation-workspace?year=2026&month=10')->assertRedirect();
        $this->post('/dashboard/salary/generation-workspace/runs', ['year'=>2026,'month'=>10])->assertRedirect();
    }
    public function test_posting_calls_approved_parity_inside_transaction(): void
    {
        $src=file_get_contents(app_path('Services/SalaryBillPostingService.php'));
        $this->assertStringContainsString('->assertReadySource($workflow, $source, $userId, $year, $month)', $src);
        $this->assertLessThan(strpos($src, 'SalaryBulkSourceGuard::assertMatches'), strpos($src, '->assertReadySource($workflow'));
    }
}
