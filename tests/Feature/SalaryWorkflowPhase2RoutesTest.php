<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SalaryWorkflowPhase2RoutesTest extends TestCase
{
    public function test_workflow_routes_are_nested_under_authenticated_dashboard(): void
    {
        foreach (['salary-workflow.reconcile', 'salary-workflow.show', 'salary-workflow.transition'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertStringStartsWith('dashboard/salary/workflow', $route->uri());
        }
    }

    public function test_unauthenticated_hr_workflow_is_rejected(): void
    {
        $this->postJson(route('salary-workflow.reconcile'), ['year' => 2026, 'month' => 10])->assertStatus(401);
    }
}
