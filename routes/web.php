<?php

use App\Http\Controllers\BonusGenerateController;
use App\Http\Controllers\BonusSetupController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\EmployeeAttributeController;
use App\Http\Controllers\EmployeeInfoController;
use App\Http\Controllers\GeneralProvidentFundController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SalaryGenerateController;
use App\Models\PayrollComponent;
use App\Models\PayrollComponentType;
use App\Models\PayrollGroup;
use App\Models\PayrollGroupPayScale;
use App\Models\PayScale;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

use function PHPUnit\Framework\isEmpty;

Route::redirect('/', '/login');



// Dashboard routes

Route::middleware(['auth'])->prefix('dashboard')->group(function () {
    Route::get('/', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');

    // Employee
    Route::get('/employee/create', [\App\Http\Controllers\EmployeeController::class, 'create'])->name('employees.create');
    Route::post('/employee', [\App\Http\Controllers\EmployeeController::class, 'store'])->name('employees.store');
    Route::get('/employee/update', [\App\Http\Controllers\EmployeeController::class, 'edit'])->name('employees.edit');
    Route::put('/employee/{user}', [\App\Http\Controllers\EmployeeController::class, 'update'])->name('employees.update');
    Route::delete('/employee/{user}', [\App\Http\Controllers\EmployeeController::class, 'destroy'])->name('employees.destroy');
    Route::get('/employee/history', [\App\Http\Controllers\EmployeeController::class, 'history'])->name('employees.history');
    Route::post('/employee/update-single-salary-calc', [EmployeeInfoController::class, 'updateSalaryComponentsForUser'])->name('employees.update.salaryCalc');
    Route::patch('/employee/{user}/bulk-profile-update', [\App\Http\Controllers\EmployeeController::class, 'bulkProfileUpdateSingle'])->name('employees.bulkProfileUpdateSingle');
    // Payroll Scale Routes
    Route::get('/payroll/scales', [\App\Http\Controllers\PayScaleController::class, 'index'])->name('pay-scales.index');
    Route::post('/payroll/scales', [\App\Http\Controllers\PayScaleController::class, 'store'])->name('pay-scales.store');
    Route::put('/payroll/scales/{payScale}', [\App\Http\Controllers\PayScaleController::class, 'update'])->name('pay-scales.update');
    Route::delete('/payroll/scales/{payScale}', [\App\Http\Controllers\PayScaleController::class, 'destroy'])->name('pay-scales.destroy');

    // Payroll Grades Routes
    Route::get('/payroll/grades', [\App\Http\Controllers\GradeController::class, 'index'])->name('grades.index');
    Route::post('/payroll/grades', [\App\Http\Controllers\GradeController::class, 'store'])->name('grades.store');
    Route::put('/payroll/grades/{grade}', [\App\Http\Controllers\GradeController::class, 'update'])->name('grades.update');
    Route::delete('/payroll/grades/{grade}', [\App\Http\Controllers\GradeController::class, 'destroy'])->name('grades.destroy');

    // Payroll Component Types Routes
    Route::get('/payroll/component-types', [\App\Http\Controllers\PayrollComponentTypeController::class, 'index'])->name('payroll-component-types.index');
    Route::post('/payroll/component-types', [\App\Http\Controllers\PayrollComponentTypeController::class, 'store'])->name('payroll-component-types.store');
    Route::put('/payroll/component-types/{payrollComponentType}', [\App\Http\Controllers\PayrollComponentTypeController::class, 'update'])->name('payroll-component-types.update');
    Route::delete('/payroll/component-types/{payrollComponentType}', [\App\Http\Controllers\PayrollComponentTypeController::class, 'destroy'])->name('payroll-component-types.destroy');

    // Payroll Components Routes
    Route::get('/payroll/components', [\App\Http\Controllers\PayrollComponentController::class, 'index'])->name('payroll-components.index');
    Route::post('/payroll/components', [\App\Http\Controllers\PayrollComponentController::class, 'store'])->name('payroll-components.store');
    Route::put('/payroll/components/{payrollComponent}', [\App\Http\Controllers\PayrollComponentController::class, 'update'])->name('payroll-components.update');
    Route::delete('/payroll/components/{payrollComponent}', [\App\Http\Controllers\PayrollComponentController::class, 'destroy'])->name('payroll-components.destroy');

    // Payroll Group Types Routes
    Route::get('/payroll/group-types', [\App\Http\Controllers\PayrollGroupTypeController::class, 'index'])->name('payroll-group-types.index');
    Route::post('/payroll/group-types', [\App\Http\Controllers\PayrollGroupTypeController::class, 'store'])->name('payroll-group-types.store');
    Route::put('/payroll/group-types/{payrollGroupType}', [\App\Http\Controllers\PayrollGroupTypeController::class, 'update'])->name('payroll-group-types.update');
    Route::delete('/payroll/group-types/{payrollGroupType}', [\App\Http\Controllers\PayrollGroupTypeController::class, 'destroy'])->name('payroll-group-types.destroy');

    // Payroll Groups Routes
    Route::get('/payroll/groups', [\App\Http\Controllers\PayrollGroupController::class, 'index'])->name('payroll-groups.index');
    Route::post('/payroll/groups', [\App\Http\Controllers\PayrollGroupController::class, 'store'])->name('payroll-groups.store');
    Route::put('/payroll/groups/{payrollGroup}', [\App\Http\Controllers\PayrollGroupController::class, 'update'])->name('payroll-groups.update');
    Route::delete('/payroll/groups/{payrollGroup}', [\App\Http\Controllers\PayrollGroupController::class, 'destroy'])->name('payroll-groups.destroy');

    // Bonus Components Routes
    Route::get('/payroll/bonus-components', [\App\Http\Controllers\BonusComponentController::class, 'index'])->name('bonus-components.index');
    Route::post('/payroll/bonus-components', [\App\Http\Controllers\BonusComponentController::class, 'store'])->name('bonus-components.store');
    Route::put('/payroll/bonus-components/{bonusComponent}', [\App\Http\Controllers\BonusComponentController::class, 'update'])->name('bonus-components.update');
    Route::delete('/payroll/bonus-components/{bonusComponent}', [\App\Http\Controllers\BonusComponentController::class, 'destroy'])->name('bonus-components.destroy');

    // Bonus Setup (Bonus Groups) Routes
    Route::get('/payroll/bonus-groups', [\App\Http\Controllers\BonusGroupController::class, 'index'])->name('bonus-groups.index');
    Route::post('/payroll/bonus-groups', [\App\Http\Controllers\BonusGroupController::class, 'store'])->name('bonus-groups.store');
    Route::put('/payroll/bonus-groups/{bonusGroup}', [\App\Http\Controllers\BonusGroupController::class, 'update'])->name('bonus-groups.update');
    Route::delete('/payroll/bonus-groups/{bonusGroup}', [\App\Http\Controllers\BonusGroupController::class, 'destroy'])->name('bonus-groups.destroy');

    // Office Management Routes
    Route::get('/office/department-offices', [\App\Http\Controllers\DepartmentOfficeController::class, 'index'])->name('department-offices.index');
    Route::post('/office/department-offices', [\App\Http\Controllers\DepartmentOfficeController::class, 'store'])->name('department-offices.store');
    Route::put('/office/department-offices/{departmentOffice}', [\App\Http\Controllers\DepartmentOfficeController::class, 'update'])->name('department-offices.update');
    Route::delete('/office/department-offices/{departmentOffice}', [\App\Http\Controllers\DepartmentOfficeController::class, 'destroy'])->name('department-offices.destroy');

    // User Categories Routes
    Route::get('/employee/user-categories', [\App\Http\Controllers\UserCategoryController::class, 'index'])->name('user-categories.index');
    Route::post('/employee/user-categories', [\App\Http\Controllers\UserCategoryController::class, 'store'])->name('user-categories.store');
    Route::put('/employee/user-categories/{userCategory}', [\App\Http\Controllers\UserCategoryController::class, 'update'])->name('user-categories.update');
    Route::delete('/employee/user-categories/{userCategory}', [\App\Http\Controllers\UserCategoryController::class, 'destroy'])->name('user-categories.destroy');

    // Reports Routes
    Route::get('/reports/blank-forms', [\App\Http\Controllers\ReportController::class, 'blankForms'])->name('reports.blank-forms.index');
    Route::get('/reports/salary-reports', [\App\Http\Controllers\SalaryReportController::class, 'index'])->name('reports.salary-reports.index');
    // Step 07: read-only canonical posted salary/arrear print reports.
    Route::get('/reports/salary-official', [\App\Http\Controllers\SalaryOfficialReportController::class, 'index'])->name('reports.salary-official.index');

    // Salary Setup Routes
    Route::get('/salary/setup', [\App\Http\Controllers\SalarySetupController::class, 'index'])->name('salary-setup.index');
    Route::get('/salary/setup/{user}', [\App\Http\Controllers\SalarySetupController::class, 'show'])->name('salary-setup.show');
    // Phase 2: employee/month read-only salary calculation entry; Phase 3 implements editing.
    // Phase 4 read-only/catalog settings; explicit rule changes are version-audited.
    Route::get('/salary/arrear-settings', [\App\Http\Controllers\SalaryArrearSettingsController::class, 'index'])->name('salary-arrear-settings.index');
    Route::post('/salary/arrear-settings/rules', [\App\Http\Controllers\SalaryArrearSettingsController::class, 'saveRule'])->name('salary-arrear-settings.rules.store');
    Route::delete('/salary/arrear-settings/rules/{id}', [\App\Http\Controllers\SalaryArrearSettingsController::class, 'archive'])->name('salary-arrear-settings.rules.archive');
    Route::get('/salary/arrear-settings/preview', [\App\Http\Controllers\SalaryArrearSettingsController::class, 'preview'])->name('salary-arrear-settings.rules.preview');
    Route::post('/salary/arrear-settings/components', [\App\Http\Controllers\SalaryArrearSettingsController::class, 'saveComponent'])->name('salary-arrear-settings.components.store');
    Route::get('/salary/calculator/{user}', [\App\Http\Controllers\SalaryCalculatorEntryController::class, 'show'])->name('salary-calculator.show');
    Route::post('/salary/calculator/{user}/generate-bill', [\App\Http\Controllers\SalaryCalculatorEntryController::class, 'generateBill'])->name('salary-calculator.generate-bill');
    Route::post('/salary/calculator/{user}/component-override', [\App\Http\Controllers\SalaryEmployeeComponentOverrideController::class, 'save'])->name('salary-calculator.component-override');
    Route::post('/salary/calculator/{user}/versions', [\App\Http\Controllers\SalaryCalculatorEntryController::class, 'save'])->name('salary-calculator.versions.store');
    Route::post('/salary/calculator/{user}/arrear-preview', [\App\Http\Controllers\SalaryCalculatorEntryController::class, 'previewArrear'])->name('salary-calculator.arrear-preview');
    Route::post('/salary/setup/update-single-salary-calc', [\App\Http\Controllers\SalarySetupController::class, 'updateSalaryCalculationComponentsForUser'])->name('salary-setup.update.single-calc');
    Route::post('/salary/setup/{user}/send-review', [\App\Http\Controllers\SalarySetupController::class, 'sendReview'])->name('salary-setup.send-review');
    Route::post('/salary/setup/send-bulk-review', [\App\Http\Controllers\SalarySetupController::class, 'sendBulkReview'])->name('salary-setup.send-bulk-review');

    // Automated salary fixation: read-only form and audit snapshots. No payroll mutation.
    Route::get('/salary/fixation', [\App\Http\Controllers\SalaryFixationController::class, 'index'])->name('salary-fixation.index');
    Route::get('/salary/fixation/{user}/preview', [\App\Http\Controllers\SalaryFixationController::class, 'preview'])->name('salary-fixation.preview');
    Route::post('/salary/fixation/{user}/snapshots', [\App\Http\Controllers\SalaryFixationController::class, 'store'])->name('salary-fixation.store');
    Route::post('/salary/fixation/{user}/adjustment', [\App\Http\Controllers\SalaryFixationController::class, 'saveAdjustment'])->name('salary-fixation.adjustment');
    Route::post('/salary/fixation/review/{salaryFixation}/send', [\App\Http\Controllers\SalaryFixationController::class, 'sendReview'])->name('salary-fixation.send-review');
    Route::post('/salary/fixation/review/{salaryFixation}/approve', [\App\Http\Controllers\SalaryFixationController::class, 'approve'])->name('salary-fixation.approve');
    Route::post('/salary/fixation/{user}/approve-now', [\App\Http\Controllers\SalaryFixationController::class, 'approveNow'])->name('salary-fixation.approve-now');
    Route::post('/salary/fixation/{user}/reject', [\App\Http\Controllers\SalaryFixationController::class, 'reject'])->name('salary-fixation.reject');
    Route::post('/salary/fixation/bulk-decision', [\App\Http\Controllers\SalaryFixationController::class, 'bulkDecision'])->name('salary-fixation.bulk-decision');
    Route::post('/salary/fixation/bulk-approve-saved', [\App\Http\Controllers\SalaryFixationController::class, 'bulkApproveSaved'])->name('salary-fixation.bulk-approve-saved');
    Route::get('/salary/fixation/review/{salaryFixation}/print', [\App\Http\Controllers\SalaryFixationController::class, 'adminPrintSnapshot'])->name('salary-fixation.admin-print');
    Route::get('/my/salary/fixation/{salaryFixation}', [\App\Http\Controllers\SalaryFixationController::class, 'employeeReview'])->name('salary-fixation.employee-review');
    Route::post('/my/salary/fixation/{salaryFixation}/respond', [\App\Http\Controllers\SalaryFixationController::class, 'employeeRespond'])->name('salary-fixation.employee-respond');
    Route::get('/my/salary/fixation/{salaryFixation}/print', [\App\Http\Controllers\SalaryFixationController::class, 'employeePrint'])->name('salary-fixation.employee-print');

    // Review & Generate Salary Bills Routes
    Route::get('/salary/manual-adjustments', [\App\Http\Controllers\SalaryManualAdjustmentController::class, 'index'])->name('salary-manual-adjustments.index');
    Route::post('/salary/manual-adjustments', [\App\Http\Controllers\SalaryManualAdjustmentController::class, 'store'])->name('salary-manual-adjustments.store');
    Route::delete('/salary/manual-adjustments/{id}', [\App\Http\Controllers\SalaryManualAdjustmentController::class, 'destroy'])->name('salary-manual-adjustments.destroy');
    Route::get('/salary/payroll-preview', [\App\Http\Controllers\SalaryPayrollPreviewController::class, 'index'])->name('salary-payroll-preview.index');
    Route::get('/salary/payroll-preview/data', [\App\Http\Controllers\SalaryPayrollPreviewController::class, 'data'])->name('salary-payroll-preview.data');
    // Step 4: arrears are independently reconciled and never posted by preview.
    Route::get('/my/salary/arrears', [\App\Http\Controllers\SalaryArrearController::class, 'employeeInbox'])->name('salary-arrears.employee-inbox');
    Route::get('/salary/arrears', [\App\Http\Controllers\SalaryArrearController::class, 'index'])->name('salary-arrears.index');
    Route::get('/salary/arrears/periods', [\App\Http\Controllers\SalaryArrearController::class, 'periods'])->name('salary-arrears.periods');
    Route::post('/salary/arrears/periods', [\App\Http\Controllers\SalaryArrearController::class, 'storePeriod'])->name('salary-arrears.periods.store');
    Route::put('/salary/arrears/periods/{periodId}', [\App\Http\Controllers\SalaryArrearController::class, 'updatePolicy'])->name('salary-arrears.periods.policy');
    Route::get('/salary/arrears/periods/{periodId}/preview', [\App\Http\Controllers\SalaryArrearController::class, 'preview'])->name('salary-arrears.preview');
    Route::get('/salary/arrears/periods/{periodId}/employees/{userId}', [\App\Http\Controllers\SalaryArrearController::class, 'statement'])->name('salary-arrears.statement');
    Route::get('/salary/arrears/periods/{periodId}/employees/{userId}/structured-preview', [\App\Http\Controllers\SalaryArrearController::class, 'structuredPreview'])->name('salary-arrears.structured-preview');
Route::get('/salary/arrears/periods/{periodId}/employees/{userId}/structured-preview', [\App\Http\Controllers\SalaryArrearController::class, 'structuredPreview'])->name('salary-arrears.structured-preview');
    Route::post('/salary/arrears/periods/{periodId}/employees/{userId}/payment-confirmation', [\App\Http\Controllers\SalaryArrearController::class, 'certifyPayment'])->name('salary-arrears.payment-confirmation');
    Route::post('/salary/arrears/periods/{periodId}/employees/{userId}/source', [\App\Http\Controllers\SalaryArrearController::class, 'sourceOverride'])->name('salary-arrears.source');
    Route::post('/salary/arrears/periods/{periodId}/employees/{userId}/manual', [\App\Http\Controllers\SalaryArrearController::class, 'manualItem'])->name('salary-arrears.manual');
    Route::post('/salary/arrears/periods/{periodId}/employees/{userId}/snapshots', [\App\Http\Controllers\SalaryArrearController::class, 'snapshot'])->name('salary-arrears.snapshot');
    Route::post('/salary/arrears/snapshots/{snapshotId}/review', [\App\Http\Controllers\SalaryArrearController::class, 'review'])->name('salary-arrears.review');
    Route::post('/salary/arrears/snapshots/{snapshotId}/approve', [\App\Http\Controllers\SalaryArrearController::class, 'approve'])->name('salary-arrears.approve');
    Route::post('/salary/arrears/post-approved', [\App\Http\Controllers\SalaryArrearPostingController::class, 'post'])->name('salary-arrears.post-approved');
    Route::get('/salary/generate-bills', [SalaryGenerateController::class, 'index'])->name('salary-generates.index');
    Route::post('/salary/generate-single', [SalaryGenerateController::class, 'generateSalaryForEmployee'])->name('salary-generates.generate-single');
    Route::get('/salary/bulk-candidates', [SalaryGenerateController::class, 'bulkCandidates'])->name('salary-generates.bulk-candidates');
    Route::post('/salary/bulk-runs', [\App\Http\Controllers\SalaryBulkRunController::class, 'start'])->name('salary-bulk-runs.start');
    Route::post('/salary/bulk-runs/{token}/next', [\App\Http\Controllers\SalaryBulkRunController::class, 'next'])->name('salary-bulk-runs.next');
    Route::get('/salary/bulk-runs/{token}', [\App\Http\Controllers\SalaryBulkRunController::class, 'summary'])->name('salary-bulk-runs.summary');
    Route::get('/salary/bulk-runs/{token}/csv', [\App\Http\Controllers\SalaryBulkRunController::class, 'csv'])->name('salary-bulk-runs.csv');
    Route::post('/salary/generate-bulk', [SalaryGenerateController::class, 'generateBulk'])->name('salary-generates.generate-bulk');
    Route::patch('/salary/generates/override-bulk-review', [SalaryGenerateController::class, 'overrideBulkReviewStatus'])->name('salary-generates.override-bulk-review');
    Route::get('/salary/generates/{salaryGenerate}', [SalaryGenerateController::class, 'show'])->name('salary-generates.show');
    Route::post('/salary/generates/{salaryGenerate}/send-review', [SalaryGenerateController::class, 'sendReview'])->name('salary-generates.send-review');
    Route::post('/salary/generates/send-bulk-review', [SalaryGenerateController::class, 'sendBulkReview'])->name('salary-generates.send-bulk-review');
    Route::patch('/salary/generates/{salaryGenerate}/override-review', [SalaryGenerateController::class, 'overrideReviewStatus'])->name('salary-generates.override-review');

    // Bonus Setup Routes (Bonus Estimation)
    Route::get('/bonus/setup', [BonusSetupController::class, 'index'])->name('bonus-setup.index');
    Route::get('/bonus/setup/{user}', [BonusSetupController::class, 'show'])->name('bonus-setup.show');
    Route::post('/bonus/setup/update-single-bonus-calc', [BonusSetupController::class, 'updateBonusCalculationForUser'])->name('bonus-setup.update.single-calc');
    Route::post('/bonus/setup/assign-group', [BonusSetupController::class, 'assignBonusGroup'])->name('bonus-setup.assign-group');
    Route::post('/bonus/setup/calculate-default', [BonusSetupController::class, 'calculateDefault'])->name('bonus-setup.calculate-default');
    Route::post('/bonus/setup/{user}/send-review', [BonusSetupController::class, 'sendReview'])->name('bonus-setup.send-review');
    Route::post('/bonus/setup/send-bulk-review', [BonusSetupController::class, 'sendBulkReview'])->name('bonus-setup.send-bulk-review');

    // Review & Generate Bonus Bills Routes (Generate Bonus)
    Route::get('/bonus/generate-bills', [BonusGenerateController::class, 'index'])->name('bonus-generates.index');
    Route::post('/bonus/generate-single', [BonusGenerateController::class, 'generateBonusForEmployee'])->name('bonus-generates.generate-single');
    Route::post('/bonus/generate-bulk', [BonusGenerateController::class, 'generateBulk'])->name('bonus-generates.generate-bulk');
    Route::patch('/bonus/generates/override-bulk-review', [BonusGenerateController::class, 'overrideBulkReviewStatus'])->name('bonus-generates.override-bulk-review');
    Route::get('/bonus/generates/{bonusGenerate}', [BonusGenerateController::class, 'show'])->name('bonus-generates.show');
    Route::post('/bonus/generates/{bonusGenerate}/send-review', [BonusGenerateController::class, 'sendReview'])->name('bonus-generates.send-review');
    Route::post('/bonus/generates/send-bulk-review', [BonusGenerateController::class, 'sendBulkReview'])->name('bonus-generates.send-bulk-review');
    Route::patch('/bonus/generates/{bonusGenerate}/override-review', [BonusGenerateController::class, 'overrideReviewStatus'])->name('bonus-generates.override-review');

    // Archive Salary Routes (Import & Preview)
    Route::prefix('archive')->name('archive.')->group(function () {
        Route::get('/import-preview', [\App\Http\Controllers\ArchiveSalaryController::class, 'index'])->name('import-preview');
        Route::get('/load', [\App\Http\Controllers\ArchiveSalaryController::class, 'loadFile'])->name('load');
        Route::post('/upload', [\App\Http\Controllers\ArchiveSalaryController::class, 'uploadFile'])->name('upload');
        Route::post('/write', [\App\Http\Controllers\ArchiveSalaryController::class, 'writeRecords'])->name('write');
        Route::post('/write-single', [\App\Http\Controllers\ArchiveSalaryController::class, 'writeSingleRecord'])->name('write-single');
    });

    // General Provident Fund (GPF) Routes
    Route::prefix('payroll/gpf')->name('gpf.')->group(function () {
        Route::get('/', [GeneralProvidentFundController::class, 'index'])->name('index');
        Route::post('/', [GeneralProvidentFundController::class, 'store'])->name('store');
        Route::post('/past-contribution', [GeneralProvidentFundController::class, 'storePastContribution'])->name('store-past');
        Route::get('/user/{user}/ledger', [GeneralProvidentFundController::class, 'userLedger'])->name('user-ledger');
        Route::get('/{generalProvidentFund}', [GeneralProvidentFundController::class, 'show'])->name('show');
        Route::put('/{generalProvidentFund}', [GeneralProvidentFundController::class, 'update'])->name('update');
        Route::delete('/{generalProvidentFund}', [GeneralProvidentFundController::class, 'destroy'])->name('destroy');
        Route::post('/{generalProvidentFund}/settle', [GeneralProvidentFundController::class, 'settle'])->name('settle');
        Route::get('/{generalProvidentFund}/comments', [CommentController::class, 'indexForGpf'])->name('comments.index');
        Route::post('/{generalProvidentFund}/comments', [CommentController::class, 'storeForGpf'])->name('comments.store');
        Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');
    });
});



Route::get('/notifications', function () {
    return Inertia::render('Notifications');
})->middleware(['auth', 'verified'])->name('notifications.index');




Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';


Route::get("/test", [EmployeeAttributeController::class, "createDynamicEmployeeTest"]);



// Error page preview and testing route
Route::get('/error-preview/{code}', function ($code) {
    $validCodes = [401, 403, 404, 419, 500, 503];
    $status = in_array((int) $code, $validCodes) ? (int) $code : 404;
    return Inertia::render('Error', [
        'status' => $status,
        'message' => request('message', null),
    ])->toResponse(request())->setStatusCode($status);
})->name('error.preview');
