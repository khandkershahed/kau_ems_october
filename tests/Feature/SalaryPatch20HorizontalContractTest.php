<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Support\Facades\Route;
class SalaryPatch20HorizontalContractTest extends TestCase {
 public function test_batch_report_route_is_authenticated_and_get_only(): void {
  $route=Route::getRoutes()->getByName('salary-estimation.horizontal-bill');
  $this->assertNotNull($route);
  $this->assertContains('GET',$route->methods());
  $this->get('/dashboard/salary/estimation/horizontal-bill?year=2026&month=10')->assertRedirect();
 }
}
