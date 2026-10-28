<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Support\Facades\Route;
class SalaryEstimationPatch17Test extends TestCase
{
    public function test_existing_routes_are_available(): void
    {
        $this->assertTrue(Route::has('salary-estimation.index'));
        $this->assertTrue(Route::has('salary-estimation.start'));
        $this->assertTrue(Route::has('salary-workflow.reconcile'));
    }
    public function test_unauthenticated_operator_cannot_open_roster(): void
    {
        $this->get(route('salary-estimation.index'))->assertRedirect();
    }
    public function test_unauthenticated_operator_cannot_reconcile_roster(): void
    {
        $this->postJson(route('salary-workflow.reconcile'), ['year'=>2026,'month'=>10])->assertUnauthorized();
    }
}
