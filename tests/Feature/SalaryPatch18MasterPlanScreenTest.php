<?php
namespace Tests\Feature;
use Tests\TestCase;
class SalaryPatch18MasterPlanScreenTest extends TestCase
{
    public function test_estimation_requires_authentication(): void
    {
        $this->get('/dashboard/salary/estimation')->assertRedirect();
    }
    public function test_master_plan_two_worklists_and_no_posting_button(): void
    {
        $view = file_get_contents(resource_path('js/Pages/Salary/Estimation/Workspace.vue'));
        $this->assertStringContainsString('Blocked Employee List', $view);
        $this->assertStringContainsString('Calculated Employee List', $view);
        $this->assertStringContainsString('Save selected calculations', $view);
        $this->assertStringNotContainsString('Generate Bill', $view);
        $this->assertStringNotContainsString('salary/bulk-runs', $view);
    }
}
