<?php
namespace Tests\Feature;

use Tests\TestCase;

final class SalaryPatch13WorkflowRecoveryTest extends TestCase
{
    public function test_workflow_reconcile_requires_authentication(): void
    {
        $this->postJson('/dashboard/salary/workflow/reconcile', [
            'year'=>2026, 'month'=>10, 'user_ids'=>[1], 'limit'=>1,
        ])->assertUnauthorized();
    }

    public function test_read_only_workspaces_have_initializer_buttons(): void
    {
        $fixation = file_get_contents(resource_path('js/Pages/Salary/Fixation/Workspace.vue'));
        $bulk = file_get_contents(resource_path('js/Pages/Salary/Estimation/BulkComponents.vue'));
        $this->assertStringContainsString('initializeWorkflow', $fixation);
        $this->assertStringContainsString('initializeEligible', $bulk);
        $this->assertStringContainsString('/dashboard/salary/workflow/reconcile', $bulk);
        $this->assertStringContainsString('Select pending 18', $bulk);
    }
}
