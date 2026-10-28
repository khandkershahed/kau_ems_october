<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Route;

final class SalaryPatch19ProvisionalContractTest extends TestCase
{
    public function test_provisional_route_is_available_and_uses_authenticated_dashboard(): void
    {
        $route = Route::getRoutes()->getByName('salary-provisional.show');
        $this->assertNotNull($route);
        $this->assertContains('GET', $route->methods());
        $this->get('/dashboard/salary/provisional/1?year=2026&month=10')->assertRedirect();
    }

    public function test_guest_cannot_print_versioned_provisional_bill(): void
    {
        $this->get('/dashboard/salary/provisional/1?year=2026&month=10&version_id=123')->assertRedirect();
    }
}
