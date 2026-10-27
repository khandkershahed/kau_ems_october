<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Read-only route registration test. Does not generate or post payroll. */
final class SalaryOfficialReportStep07FeatureTest extends TestCase
{
    public function test_canonical_salary_report_route_exists(): void
    {
        $this->assertTrue(Route::has('reports.salary-official.index'));
        $route = Route::getRoutes()->getByName('reports.salary-official.index');
        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    public function test_canonical_salary_report_does_not_require_new_payroll_role_middleware(): void
    {
        $route = Route::getRoutes()->getByName('reports.salary-official.index');
        $this->assertNotNull($route);
        $middleware = implode(',', $route->gatherMiddleware());
        $this->assertStringContainsString('auth', $middleware);
        $this->assertStringNotContainsString('payroll_hr', $middleware);
        $this->assertStringNotContainsString('superadmin', $middleware);
    }
}
