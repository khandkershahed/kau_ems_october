<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Route;

class SalaryPatch06RoutesTest extends TestCase
{
    public function test_bulk_approval_requires_authentication(): void
    {
        $response=$this->postJson('/dashboard/salary/workflow/bulk-approve',[]);
        $this->assertContains($response->status(),[302,401,403]);
    }

    public function test_provisional_report_requires_authentication(): void
    {
        $response=$this->get('/dashboard/salary/provisional/1?year=2026&month=10');
        $this->assertContains($response->status(),[302,401,403]);
    }

    public function test_new_routes_registered(): void
    {
        $this->assertTrue(Route::has('salary-workflow.bulk-approve'));
        $this->assertTrue(Route::has('salary-provisional.show'));
    }
}
