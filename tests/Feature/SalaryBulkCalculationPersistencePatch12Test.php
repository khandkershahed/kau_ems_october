<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SalaryBulkCalculationPersistencePatch12Test extends TestCase
{
    public function test_bulk_calculation_endpoint_exists_and_is_post_only(): void
    {
        $route = Route::getRoutes()->getByName('salary-estimation.bulk-save-calculations');
        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods());
        $this->assertStringContainsString('auth', implode(' ', $route->gatherMiddleware()));
    }

    public function test_guest_cannot_save_bulk_calculations(): void
    {
        $this->postJson('/dashboard/salary/estimation/bulk-save-calculations', [
            'year'=>2026,'month'=>10,'employee_codes'=>['20057'],'reason'=>'Audit',
        ])->assertUnauthorized();
    }
}
