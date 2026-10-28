<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Support\Facades\Route;
class SalaryEstimationPatch05Test extends TestCase
{
    public function test_estimation_endpoints_are_registered(): void
    {
        $this->assertTrue(Route::has('salary-estimation.index'));
        $this->assertTrue(Route::has('salary-estimation.start'));
        $this->assertTrue(Route::has('salary-estimation.next'));
        $this->assertTrue(Route::has('salary-estimation.errors'));
    }
    public function test_guests_cannot_start_salary_estimation(): void
    {
        $response=$this->postJson(route('salary-estimation.start'), ['year'=>2026,'month'=>10]);
        $this->assertContains($response->status(),[401,302]);
    }
    public function test_guests_cannot_read_salary_roster(): void
    {
        $response=$this->get(route('salary-estimation.index'));
        $response->assertRedirect();
    }
}
