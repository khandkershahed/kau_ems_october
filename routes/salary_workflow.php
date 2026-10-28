<?php

use App\Http\Controllers\SalaryWorkflowController;
use Illuminate\Support\Facades\Route;

// Included by routes/web.php within auth-protected dashboard routes.
Route::prefix('salary/workflow')->name('salary-workflow.')->group(function (): void {
    Route::post('/reconcile', [SalaryWorkflowController::class, 'reconcile'])->name('reconcile');
    Route::get('/{user}/{year}/{month}', [SalaryWorkflowController::class, 'show'])->name('show')
        ->whereNumber('user')->whereNumber('year')->whereNumber('month');
    Route::post('/{user}/{year}/{month}/transition', [SalaryWorkflowController::class, 'transition'])->name('transition')
        ->whereNumber('user')->whereNumber('year')->whereNumber('month');
});

// PATCH 06: authenticated role-free bulk workflow and read-only provisional report.
Route::post('/salary/workflow/bulk-approve', [\App\Http\Controllers\SalaryBulkWorkflowApprovalController::class, 'approve'])->name('salary-workflow.bulk-approve');
Route::get('/salary/provisional/{user}', [\App\Http\Controllers\SalaryProvisionalBillController::class, 'show'])->whereNumber('user')->name('salary-provisional.show');
