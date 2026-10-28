<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Support\Facades\Route;
final class SalaryBulkEditorPatch11Test extends TestCase {
    public function test_bulk_ui_route_requires_login(): void {
        $this->get('/dashboard/salary/estimation/bulk-components?year=2026&month=10')->assertRedirect('/login');
    }
    public function test_bulk_ui_reuses_existing_financial_write_and_approval_endpoints(): void {
        $this->assertTrue(Route::has('salary-estimation.bulk-components'));
        $this->assertTrue(Route::has('salary-calculator.component-override'));
        $this->assertTrue(Route::has('salary-workflow.bulk-approve'));
        $this->assertTrue(Route::has('salary-estimation.start'));
    }
}
