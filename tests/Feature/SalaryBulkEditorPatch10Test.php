<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Support\Facades\Route;
final class SalaryBulkEditorPatch10Test extends TestCase
{
    public function test_bulk_editor_route_uses_authentication(): void
    {
        $route = Route::getRoutes()->getByName('salary-estimation.bulk-components');
        $this->assertNotNull($route);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->get('/dashboard/salary/estimation/bulk-components?year=2026&month=10')->assertRedirect();
    }
    public function test_existing_component_override_write_route_remains(): void
    {
        $this->assertNotNull(Route::getRoutes()->getByName('salary-calculator.component-override'));
    }
}
