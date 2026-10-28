<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SalaryPatch21BulkContractTest extends TestCase
{
    public function test_bulk_save_route_and_estimation_run_are_registered(): void
    {
        $this->assertTrue(Route::has('salary-estimation.bulk-save-calculations'));
        $this->assertTrue(Route::has('salary-estimation.start'));
    }

    public function test_guest_cannot_save_bulk_salary_versions(): void
    {
        $url = route('salary-estimation.bulk-save-calculations');
        $this->postJson($url, [
            'year'=>2026, 'month'=>10, 'employee_codes'=>['20057'], 'reason'=>'QA review',
        ])->assertUnauthorized();
    }
}
