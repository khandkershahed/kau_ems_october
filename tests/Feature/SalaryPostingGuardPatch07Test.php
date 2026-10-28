<?php
namespace Tests\Feature;

use Tests\TestCase;

final class SalaryPostingGuardPatch07Test extends TestCase
{
    public function test_ready_worklist_requires_login(): void
    {
        $this->get('/dashboard/salary/generate-ready?year=2026&month=10')
            ->assertRedirect();
    }

    public function test_central_posting_service_calls_approval_guard(): void
    {
        $source = file_get_contents(app_path('Services/SalaryBillPostingService.php'));
        $this->assertStringContainsString('->assertReady($userId, $year, $month)', $source);
        $this->assertStringContainsString('->recordGenerated($workflow, (int)$bill->id, $actorId)', $source);
    }
}
