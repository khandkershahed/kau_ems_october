<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SalaryFixationWorkspacePatch03Test extends TestCase
{
    public function test_workspace_route_is_authenticated(): void
    {
        $route = Route::getRoutes()->getByName('salary-fixation.workspace');
        $this->assertNotNull($route);
        $this->assertSame('GET', $route->methods()[0]);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertSame('dashboard/salary/fixation/{user}/workspace', $route->uri());
    }

    public function test_guest_cannot_open_unified_financial_workspace(): void
    {
        $this->getJson(route('salary-fixation.workspace', ['user'=>1, 'year'=>2026, 'month'=>10]))
            ->assertUnauthorized();
    }

    public function test_guest_cannot_write_employee_component_overrides(): void
    {
        $this->postJson(route('salary-calculator.component-override', ['user'=>1]), [
            'year'=>2026, 'month'=>10,
        ])->assertUnauthorized();
    }
}
