<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalaryPatch15RosterRecoveryTest extends TestCase
{
    public function test_reconciliation_requires_login_for_json_requests(): void
    {
        $this->postJson('/dashboard/salary/workflow/reconcile', [
            'year' => 2026, 'month' => 10, 'limit' => 1000,
        ])->assertUnauthorized();
    }

    public function test_estimation_page_requires_login(): void
    {
        $this->get('/dashboard/salary/estimation?year=2026&month=10')->assertRedirect();
    }
}
