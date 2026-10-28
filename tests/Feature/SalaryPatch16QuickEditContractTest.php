<?php
namespace Tests\Feature;

use Tests\TestCase;

final class SalaryPatch16QuickEditContractTest extends TestCase
{
    public function test_component_override_requires_login(): void
    {
        $this->postJson('/dashboard/salary/calculator/1/component-override', [])->assertUnauthorized();
    }

    public function test_first_edit_uses_canonical_reconciler_without_new_posting_path(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/SalaryEmployeeComponentOverrideController.php'));
        $this->assertStringContainsString('SalaryWorkflowRosterService::class', $source);
        $this->assertStringNotContainsString('SalaryBillPostingService::class', $source);
    }
}
