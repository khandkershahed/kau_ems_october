<?php

namespace App\Http\Controllers;

use App\Mail\SalaryBillReviewMail;
use App\Models\DepartmentOffice;
use App\Models\EmployeeInfo;
use App\Models\Grade;
use App\Models\PayrollGroup;
use App\Models\PayrollGroupType;
use App\Models\PayScale;
use App\Models\SalaryGenerate;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\GeneralProvidentFundService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class SalaryGenerateController extends Controller
{
    /**
     * Display a listing of employee salary bills with search and filters,
     * or return the Inertia view.
     */
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $monthId = (int) $request->input('monthId', date('n'));
            $year = (int) $request->input('salaryGenerationYear', date('Y'));
            $salaryGeneratedStatus = $request->input('salaryGeneratedStatus', 'all'); // 'all', 'generated', 'not_generated'
            $reviewStatus = $request->input('reviewStatus', 'all'); // 'all', 'pending', 'review', 'approved'

            $baseStaffQuery = $this->buildFilteredEmployeeQuery($request);

            // Summary stats across matching staff before bill status filters
            $totalCount = (clone $baseStaffQuery)->count();
            $generatedCount = (clone $baseStaffQuery)->whereHas('salaryGenerates', function ($q) use ($monthId, $year) {
                $q->where('monthId', $monthId)->where('salaryGenerationYear', $year);
            })->count();
            $notGeneratedCount = $totalCount - $generatedCount;

            $query = clone $baseStaffQuery;

            // Filter based on salary_generates table data
            if ($salaryGeneratedStatus === 'generated') {
                $query->whereHas('salaryGenerates', function ($q) use ($monthId, $year) {
                    $q->where('monthId', $monthId)->where('salaryGenerationYear', $year);
                });
            } elseif ($salaryGeneratedStatus === 'not_generated' || $salaryGeneratedStatus === 'not_generate') {
                $query->whereDoesntHave('salaryGenerates', function ($q) use ($monthId, $year) {
                    $q->where('monthId', $monthId)->where('salaryGenerationYear', $year);
                });
            }

            if ($reviewStatus !== 'all' && !empty($reviewStatus)) {
                $query->whereHas('salaryGenerates', function ($q) use ($monthId, $year, $reviewStatus) {
                    $q->where('monthId', $monthId)
                        ->where('salaryGenerationYear', $year)
                        ->where('reviewStatus', $reviewStatus);
                });
            }

            // Filter specific salary_generate_ids if requested (e.g. for selective export)
            if ($request->filled('salary_generate_ids')) {
                $sgIds = is_array($request->input('salary_generate_ids'))
                    ? $request->input('salary_generate_ids')
                    : explode(',', $request->input('salary_generate_ids'));
                $query->whereHas('salaryGenerates', function ($q) use ($sgIds) {
                    $q->whereIn('id', $sgIds);
                });
            }

            // Eager load relational data
            $query->with([
                'employeeInfo.payScale',
                'employeeInfo.payrollGroup.grade',
                'employeeInfo.payrollGroup.payrollGroupType',
                'employeeInfo.departmentOffice',
                'departmentOffices',
                'userCategories',
                'joiningDate',
                'bankAccounts',
                'phoneNumbers',
                'age',
                'maritialStatus',
                'religion',
                'leaveStatuses',
                'salaryGenerates' => function ($q) use ($monthId, $year) {
                    $q->where('monthId', $monthId)->where('salaryGenerationYear', $year);
                },
            ]);

            // Sorting
            $sortField = $request->input('sortField', 'id');
            $sortDirection = $request->input('sortDirection', 'desc');
            $allowedSortFields = ['id', 'name', 'email', 'gender', 'created_at'];

            if (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
            } else {
                $query->orderBy('id', 'desc');
            }

            $perPage = (int) $request->input('perPage', 10);
            if ($request->boolean('all') || $request->input('perPage') === 'all' || $perPage === -1) {
                $totalCountFiltered = (clone $query)->count();
                $perPage = $totalCountFiltered > 0 ? $totalCountFiltered : 10;
            }
            $paginator = $query->paginate($perPage);

            // Transform records into unified structure
            $transformedData = $paginator->getCollection()->map(function ($user) use ($monthId, $year) {
                $salaryRecord = $user->salaryGenerates->first();
                $isGenerated = !is_null($salaryRecord);

                $employeeInfo = $user->employeeInfo;
                $dept = $employeeInfo?->departmentOffice?->name 
                    ?? $user->departmentOffices->first()?->name 
                    ?? 'N/A';
                $category = $user->userCategories->first()?->name ?? 'General';
                $designation = $employeeInfo?->payrollGroup?->payrollGroupType?->group_name ?? 'N/A';
                $scaleName = $employeeInfo?->payScale?->scale_name ?? 'N/A';
                $gradeName = $employeeInfo?->payrollGroup?->grade?->grade_name ?? 'N/A';
                $phone = $user->phoneNumbers->first()?->phoneNumber ?? 'N/A';

                // Calculate estimated amounts from additionObjects/substractionObjects if not generated yet
                $estAdditions = 0.0;
                $estDeductions = 0.0;
                $estTaxDeduction = 0.0;
                $estGpfDeduction = 0.0;
                $basicPay = 0.0;

                if ($isGenerated && $salaryRecord) {
                    $allowances = is_string($salaryRecord->salaryAllowanceCalcObject) 
                        ? json_decode($salaryRecord->salaryAllowanceCalcObject, true) 
                        : ($salaryRecord->salaryAllowanceCalcObject ?? []);
                    if (is_array($allowances)) {
                        foreach ($allowances as $item) {
                            $name = strtolower($item['name'] ?? $item['payroll_component_type']['name'] ?? '');
                            if (str_contains($name, 'basic')) {
                                $basicPay = (float) ($item['calculated_amount'] ?? $item['amount'] ?? 0);
                                break;
                            }
                        }
                    }
                }

                if (!$isGenerated && $employeeInfo) {
                    $additions = is_string($employeeInfo->additionObjects) 
                        ? json_decode($employeeInfo->additionObjects, true) 
                        : ($employeeInfo->additionObjects ?? []);
                    $deductions = is_string($employeeInfo->substractionObjects) 
                        ? json_decode($employeeInfo->substractionObjects, true) 
                        : ($employeeInfo->substractionObjects ?? []);

                    $basicAmount = 0.0;
                    if (is_array($additions)) {
                        foreach ($additions as $a) {
                            $name = strtolower($a['name'] ?? $a['payroll_component_type']['name'] ?? '');
                            if (str_contains($name, 'basic')) {
                                $basicAmount = (float) ($a['amount'] ?? 0);
                                break;
                            }
                        }
                        $basicPay = (float) $basicAmount;
                        foreach ($additions as $a) {
                            $calcType = strtolower($a['calcType'] ?? 'fixed');
                            $rawAmt = (float) ($a['amount'] ?? 0);
                            $estAdditions += ($calcType === 'percentage') ? ($basicAmount * ($rawAmt / 100.0)) : $rawAmt;
                        }
                    }
                    if (is_array($deductions)) {
                        foreach ($deductions as $d) {
                            $calcType = strtolower($d['calcType'] ?? 'fixed');
                            $rawAmt = (float) ($d['amount'] ?? 0);
                            $amt = ($calcType === 'percentage') ? ($basicAmount * ($rawAmt / 100.0)) : $rawAmt;
                            $estDeductions += $amt;

                            $name = strtolower($d['name'] ?? $d['payroll_component_type']['name'] ?? '');
                            $type = strtolower($d['component_type'] ?? '');
                            if (str_contains($name, 'tax') || $type === 'tax') {
                                $estTaxDeduction += $amt;
                            } elseif (str_contains($name, 'gpf') || str_contains($name, 'provident') || str_contains($name, 'pf') || str_contains($type, 'provident')) {
                                $estGpfDeduction += $amt;
                            }
                        }
                    }
                }

                $projected = null;
                $projectionError = null;
                if (!$isGenerated) {
                    try {
                        $projected = app(\App\Services\SalaryBillPostingService::class)
                            ->preview((int)$user->id, (int)$year, (int)$monthId);
                    } catch (\DomainException $e) {
                        $projectionError = $e->getMessage();
                    }
                }

                return [
                    'user_id' => $user->id,
                    'salary_generate_id' => $salaryRecord?->id,
                    'employee_code' => $employeeInfo?->employeeCode ?? 'N/A',
                    'name' => $user->name,
                    'email' => $user->email,
                    'gender' => $user->gender,
                    'is_active' => (bool) $user->isActive,
                    'job_status' => $employeeInfo?->jobStatus ?? 'N/A',
                    'department' => $dept,
                    'user_category' => $category,
                    'designation' => $designation,
                    'pay_scale' => $projected['pay_scale'] ?? ($isGenerated ? $salaryRecord->payScale : $scaleName),
                    'grade' => $projected['grade'] ?? ($isGenerated ? $salaryRecord->grade : $gradeName),
                    'phone' => $phone,
                    'month_id' => $monthId,
                    'year' => $year,
                    'bill_status' => $isGenerated ? 'generated' : 'not generated',
                    'review_status' => $salaryRecord?->reviewStatus ?? null,
                    'basic_pay' => $projected['regular']['basic'] ?? $basicPay,
                    'total_allowance' => $isGenerated ? (float) $salaryRecord->totalAllowance : ($projected['gross'] ?? $estAdditions),
                    'total_deduction' => $isGenerated ? (float) $salaryRecord->totalDeduction : ($projected['total_deduction'] ?? $estDeductions),
                    'tax_deduction' => $isGenerated ? (float) $salaryRecord->taxDeduction : $estTaxDeduction,
                    'gpf_deduction' => $isGenerated ? (float) $salaryRecord->gpfDeduction : ($projected['regular']['gpf_deduction'] ?? $estGpfDeduction),
                    'salary_total' => $isGenerated ? (float) $salaryRecord->salaryTotal : ($projected['net_payable'] ?? ($estAdditions-$estDeductions)),
                    'financial_preview_error' => $projectionError,
                    'arrear_net' => $projected ? round($projected['arrear_payable']-$projected['arrear_deduction'],2) : null,
                    'salary_generation_date' => $salaryRecord?->salaryGenerationDate?->format('Y-m-d'),
                    'salary_generate' => $salaryRecord,
                ];
            });

            return response()->json([
                'data' => $transformedData,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'summary' => [
                    'total' => $totalCount,
                    'generated' => $generatedCount,
                    'not_generated' => $notGeneratedCount,
                ],
            ]);
        }

        // Return Inertia page with reference data for dropdowns
        return Inertia::render('Salary/GenerateBills/Index', [
            'payScales' => PayScale::select('id', 'scale_name')->where('isActive', true)->orderBy('scale_name')->get(),
            'grades' => Grade::with('payScale:id,scale_name')->where('isActive', true)->orderBy('grade_name')->get(),
            'payrollGroupTypes' => PayrollGroupType::select('id', 'group_name')->orderBy('group_name')->get(),
            'payrollGroups' => PayrollGroup::with(['grade:id,grade_name,pay_scale_id', 'payrollGroupType:id,group_name'])->where('isActive', true)->get(),
            'userCategories' => UserCategory::select('id', 'name')->orderBy('name')->get(),
            'departmentOffices' => DepartmentOffice::select('id', 'name', 'type')->where('isActive', true)->orderBy('name')->get(),
            'currentMonth' => (int) date('n'),
            'currentYear' => (int) date('Y'),
            'religions' => [
                'Islam', 'Christianity', 'Hinduism', 'Buddhism', 'Other',
            ],
            'genders' => [
                ['value' => 'male', 'label' => 'Male'],
                ['value' => 'female', 'label' => 'Female'],
                ['value' => 'other', 'label' => 'Other'],
            ],
            'jobStatuses' => [
                'permanent', 'contract', 'temporary', 'others',
            ],
        ]);
    }

    /**
     * Generate salary based on the payload filters following step1 condition checks
     * and block comment instructions.
     *
     * @param Request $request
     */
    public function generateSalaryForEmployee(Request $request)
    {
        $userId=(int)($request->input('userId')??$request->input('user_id'));
        $year=(int)$request->input('salaryGenerationYear',date('Y'));
        $month=(int)$request->input('monthId',date('n'));
        try {
            $result=app(\App\Services\SalaryBillPostingService::class)->post($userId,$year,$month,$request->user()?->id);
            return response()->json([
                'status'=>200,'result'=>$result['result'],
                'messages'=>[$result['result']==='generated'?'Salary bill generated from authoritative calculation.':'Existing salary bill preserved (idempotent skip).'],
                'salary_generate_id'=>$result['bill_id'],'net_salary'=>$result['net_salary'],
            ]);
        } catch (\DomainException|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status'=>422,'errors'=>[$e->getMessage()]],422);
        }
    }

    /** Called exclusively while holding the employee row lock. */
    private function generateSalaryForEmployeeUnlocked(Request $request)
    {
        $userId = (int) ($request->input('userId') ?? $request->input('user_id'));
        $monthId = (int) ($request->input('monthId') ?? date('n'));
        $year = (int) ($request->input('salaryGenerationYear') ?? date('Y'));
        if ($monthId < 1 || $monthId > 12 || $year < 2020 || $year > 2100) {
            return response()->json(['status' => 422,
                'errors' => ['Valid salary month and year are required.']], 422);
        }
        $salaryDate = $request->input('salaryGenerationDate') ?? \Carbon\Carbon::createFromDate($year, $monthId, 1)->endOfMonth()->toDateString();
        try {
            \App\Services\SalaryGenerationGuards::assertSalaryDate($year, $monthId, (string) $salaryDate);
        } catch (\DomainException $e) {
            return response()->json(['status' => 422, 'errors' => [$e->getMessage()]], 422);
        }

        if (!$userId) {
            return response()->json([
                'status' => 422,
                'errors' => ['User ID is required.'],
            ], 422);
        }

        $request->merge([
            'userId' => $userId,
            'monthId' => $monthId,
            'salaryGenerationYear' => $year,
            'salaryGenerationDate' => $salaryDate,
        ]);

        $step1Conditions = [
            'check user active status' => [function () use ($request) {
                return userIsActive($request->userId);
            }, true],
            'check user payroll info' => [function () use ($request) {
                return userHasPayrollInfo($request->userId);
            }, true],
        ];

        $step1Status = testConditions($step1Conditions);

        if ($step1Status["status"] != 200) {
            return response()->json($step1Status, 200);
        }

        /**
         * 1. Fetch the record from the EmployeeInfo table where 'userId' matches $request->userId
         */
        $matchingEmployeeProfiles = EmployeeInfo::where('user_id', $userId)->get();
        if ($matchingEmployeeProfiles->count() > 1) {
            return response()->json([
                'status' => 422,
                'errors' => ['Multiple employee profiles share this user ID. Reconcile the duplicate profile before generating the salary bill.'],
            ], 422);
        }
        $employeeInfo = $matchingEmployeeProfiles->first();
        if (!$employeeInfo) {
            return response()->json([
                'status' => 500,
                'errors' => ['1. 🚫 Employee payroll record not found.'],
            ]);
        }
        // Employee-specific approved fixation, not the old global 2027 date,
        // selects revised Basic for the requested payroll month.
        $resolvedFixation = app(\App\Services\ApprovedFixationResolver::class)
            ->forUser($userId, $year, $monthId);
        if ($resolvedFixation === null) {
            try {
                \App\Services\SalaryGenerationGuards::assertLegacyGradeScale(
                    $employeeInfo->pay_scale_id === null ? null : (int) $employeeInfo->pay_scale_id,
                    $employeeInfo->payrollGroup?->grade?->pay_scale_id === null
                        ? null : (int) $employeeInfo->payrollGroup->grade->pay_scale_id,
                    $employeeInfo->payScale?->scale_name
                );
            } catch (\DomainException $e) {
                return response()->json(['status' => 422, 'errors' => [$e->getMessage()]], 422);
            }
        }

        /**
         * 2. Return graceful error if the record doesn't have any value in the 'additionObjects' or 'substractionObjects' fields.
         */
        if (empty($employeeInfo->additionObjects) && empty($employeeInfo->substractionObjects)) {
            return response()->json([
                'status' => 500,
                'errors' => ['2. 🚫 Employee record does not have any value in additionObjects or substractionObjects fields.'],
            ]);
        }

        $additionObjects = is_string($employeeInfo->additionObjects) 
            ? json_decode($employeeInfo->additionObjects, true) 
            : ($employeeInfo->additionObjects ?? []);
        $substractionObjects = is_string($employeeInfo->substractionObjects) 
            ? json_decode($employeeInfo->substractionObjects, true) 
            : ($employeeInfo->substractionObjects ?? []);

        $additionObjects = is_array($additionObjects) ? $additionObjects : [];
        $substractionObjects = is_array($substractionObjects) ? $substractionObjects : [];

        // Retain old fixed components and employee overrides; percentage rates
        // are recalculated from the approved phased Basic in existing bill math.
        $approvedFixationAmount = $resolvedFixation;
        if ($resolvedFixation !== null) {
            try {
                $projected = \App\Services\ApprovedFixationComponentProjector::project(
                    $additionObjects, $substractionObjects, $resolvedFixation);
                $additionObjects = $projected['additions'];
                $substractionObjects = $projected['deductions'];
            } catch (\DomainException $e) {
                return response()->json(['status' => 422, 'errors' => [$e->getMessage()]], 422);
            }
        }

        // Special Benefit is removed only when an approved revised basic is
        // actually applied (not on a 2015-scale salary or an unapproved preview).
        if ($approvedFixationAmount !== null) {
            $additionObjects = array_values(array_filter(
                $additionObjects,
                fn ($component) => !\App\Services\PayrollEffectivePolicy::isSpecialBenefit($component)
            ));
        }

        // Optional employee/month adjustments are explicit titled components.
        // They are never inferred from arrear meeting notes, and they are not
        // written to employee salary setup or historical payroll records.
        if (\Illuminate\Support\Facades\Schema::hasTable('salary_manual_adjustments')) {
            $manualItems = \Illuminate\Support\Facades\DB::table('salary_manual_adjustments')
                ->where('user_id', $userId)
                ->where('salary_year', $year)
                ->where('salary_month', $monthId)
                ->orderBy('id')->get();
            foreach ($manualItems as $manual) {
                if ($approvedFixationAmount && \App\Services\PayrollEffectivePolicy::isSpecialBenefit(['name' => $manual->title])) {
                    return response()->json(['status' => 422, 'errors' => ['Special Benefit manual adjustment cannot be paid on 2026 scale.']], 422);
                }
                $item = [
                    'name' => $manual->title,
                    'amount' => (float) $manual->amount,
                    'calcType' => 'fixed',
                    'component_type' => $manual->direction === 'addition' ? 'allowance' : 'deduction',
                    'manual_adjustment_id' => $manual->id,
                    'manual_reason' => $manual->reason,
                    'manual_evidence_reference' => $manual->evidence_reference,
                ];
                if ($manual->direction === 'addition') {
                    $additionObjects[] = $item;
                } else {
                    $substractionObjects[] = $item;
                }
            }
        }

        /**
         * 3. Prepare all the information based on the columns of 'salary_generates' table and create a record
         */
        $basicPayAmount = 0.0;
        foreach ($additionObjects as $item) {
            $name = strtolower($item['name'] ?? $item['payroll_component_type']['name'] ?? '');
            if (str_contains($name, 'basic') && empty($item['manual_adjustment_id'])) {
                $basicPayAmount = (float) ($item['amount'] ?? 0);
                break;
            }
        }

        // The read-only preview validates fixed Basic, unique Basic, positive amounts
        // and legitimate component types. Reuse those prerequisites in actual posting.
        // The existing salary calculation below remains unchanged for valid records.
        try {
            \App\Services\SalaryGenerationGuards::assertComponents($additionObjects, $substractionObjects);
        } catch (\DomainException $e) {
            return response()->json(['status' => 422, 'errors' => [$e->getMessage()]], 422);
        }

        // Check if percentage is used and verify basic pay
        $hasPercentage = false;
        foreach (array_merge($additionObjects, $substractionObjects) as $item) {
            if (strtolower($item['calcType'] ?? 'fixed') === 'percentage') {
                $hasPercentage = true;
                break;
            }
        }

        if ($hasPercentage && $basicPayAmount <= 0) {
            return response()->json([
                'status' => 500,
                'errors' => ["Calculation type is set to percentage for one or more components, but 'Basic pay' allowance is absent. Please add a Basic pay allowance first."],
            ]);
        }

        // A fixed-only employee is also invalid without a unique positive Basic.
        // The old percentage-only check missed this case during real generation.
        $regularBasics = array_filter($additionObjects, static function ($item) {
            $name = strtolower((string) ($item['name'] ?? $item['payroll_component_type']['name'] ?? ''));
            return str_contains($name, 'basic') && empty($item['manual_adjustment_id']);
        });
        if (count($regularBasics) !== 1 || $basicPayAmount <= 0) {
            return response()->json(['status' => 422,
                'errors' => ['Exactly one positive configured Basic Pay component is required.']], 422);
        }

        $totalAllowance = 0.0;
        foreach ($additionObjects as &$item) {
            $calcType = strtolower($item['calcType'] ?? 'fixed');
            $rawAmt = (float) ($item['amount'] ?? 0);
            $amt = ($calcType === 'percentage') ? ($basicPayAmount * ($rawAmt / 100.0)) : $rawAmt;
            $item['calculated_amount'] = $amt;
            $totalAllowance += $amt;
        }
        unset($item);

        $totalDeduction = 0.0;
        $taxDeduction = 0.0;
        $gpfDeduction = 0.0;

        foreach ($substractionObjects as &$item) {
            $calcType = strtolower($item['calcType'] ?? 'fixed');
            $rawAmt = (float) ($item['amount'] ?? 0);
            $amt = ($calcType === 'percentage') ? ($basicPayAmount * ($rawAmt / 100.0)) : $rawAmt;
            $item['calculated_amount'] = $amt;
            $totalDeduction += $amt;

            $name = strtolower($item['name'] ?? $item['payroll_component_type']['name'] ?? '');
            $type = strtolower($item['component_type'] ?? '');

            if (str_contains($name, 'tax') || $type === 'tax') {
                $taxDeduction += $amt;
            } elseif (str_contains($name, 'gpf') || str_contains($name, 'provident') || str_contains($name, 'pf') || str_contains($type, 'provident') || str_contains($name, 'pension')) {
                $gpfDeduction += $amt;
            }
        }
        unset($item);

        $salaryTotal = $totalAllowance - $totalDeduction;

        $user = User::with([
            'age',
            'joiningDate',
            'bankAccounts',
            'phoneNumbers',
            'departmentOffices',
            'userCategories',
        ])->find($userId);

        // Use the employee profile's explicit bank-account relationship.
        // Duplicate isSalaryAccount flags must not make bank selection arbitrary.
        $bankSelection = \App\Services\SalaryBankAccountResolver::resolve(
            (int) $user->id,
            $employeeInfo->bank_account_id === null ? null : (int) $employeeInfo->bank_account_id,
            $user->bankAccounts->all()
        );
        if ($bankSelection['errors']) {
            return response()->json([
                'status' => 422,
                'errors' => $bankSelection['errors'],
            ], 422);
        }
        $bankAccount = $user->bankAccounts->firstWhere('id', $bankSelection['selected_id']);

        $phoneNumber = $user->phoneNumbers->first()?->phoneNumber ?? 'N/A';

        // Prevent ambiguous salary generation when historical imports created
        // more than one bill for an employee/month.
        $matchingBills = SalaryGenerate::where('user_id', $user->id)
            ->where('monthId', $monthId)->where('salaryGenerationYear', $year)->get();
        if ($matchingBills->count() > 1) {
            return response()->json(['status' => 409,
                'errors' => ['Multiple bills exist for this employee/month; reconcile before regeneration.']], 409);
        }
        // Previously generated salary bills are historical snapshots, including
        // pending bills. Never replace them merely because fixation was revised.
        $existingBill = $matchingBills->first();
        if ($existingBill) {
            return response()->json(['status' => 409, 'errors' => [
                'Salary bill already exists for this employee and month. Preserve it and use the audited correction workflow instead of regenerating.']], 409);
        }

        // A settled GPF deposit is historical financial evidence. A rerun
        // must not silently reset settlement or replace another bill's ledger.
        $existingGpfRows = \Illuminate\Support\Facades\DB::table('general_provident_funds')
            ->where('user_id', $user->id)->where('month', $monthId)
            ->where('year', $year)->where('type', 'monthly_deduction')->get();
        if ($existingGpfRows->isNotEmpty() && $gpfDeduction <= 0) {
            return response()->json(['status' => 409,
                'errors' => ['Existing monthly GPF contribution cannot be left behind when regenerated deduction is zero. Reconcile the ledger first.']], 409);
        }
        if ($existingGpfRows->count() > 1 || $existingGpfRows->contains(function ($row) use ($existingBill) {
            return (bool) $row->isSettled || !$existingBill
                || (int) $row->salary_generate_id !== (int) $existingBill->id;
        })) {
            return response()->json(['status' => 409,
                'errors' => ['Monthly GPF ledger is settled, duplicated or linked to a different bill; reconcile before regeneration.']], 409);
        }

        $recordData = [
            'user_id' => $user->id,
            'reviewStatus' => 'pending',
            // employee info
            'name' => $user->name,
            'age' => (string) ($user->age?->age ?? 'N/A'),
            'gender' => (string) ($user->gender ?? 'N/A'),
            'payScale' => (string) ($approvedFixationAmount ? 'Govt. Pay Scale 2026' : ($employeeInfo->payScale?->scale_name ?? 'N/A')),
            'grade' => (string) ($resolvedFixation['grade'] ?? ($employeeInfo->payrollGroup?->grade?->grade_name ?? 'N/A')),
            'designation' => (string) ($employeeInfo->payrollGroup?->payrollGroupType?->group_name ?? 'N/A'),
            'employeeCode' => (string) ($employeeInfo->employeeCode ?? 'N/A'),
            'bankAccountNo' => (string) ($bankAccount?->account_no ?? 'N/A'),
            'bankAddress' => (string) ($bankAccount?->address ?? ($bankAccount?->bank_name ?? 'N/A')),
            'employeePhoneNumber' => (string) $phoneNumber,
            'joiningDate' => $user->joiningDate?->joining_date ?? now()->toDateString(),

            // Salary date related
            'salaryGenerationDate' => $salaryDate,
            'monthId' => $monthId,
            'salaryGenerationYear' => $year,

            // Calculation data that used for salary
            'salaryAllowanceCalcObject' => $additionObjects,
            'salaryDeductionCalcObject' => $substractionObjects,

            // for salary
            'totalAllowance' => $totalAllowance,
            'totalDeduction' => $totalDeduction,
            'taxDeduction' => $taxDeduction,
            'gpfDeduction' => $gpfDeduction,
            'salaryTotal' => $salaryTotal,

            // for bonus
            'bonusAllowanceCalcObject' => [],
            'bonusDeductionCalcObject' => [],
            'bonusAllowance' => 0.00,
            'bonusDeduction' => 0.00,
            'bonusTaxDeduction' => 0.00,
            'bonusTotal' => 0.00,

            // For loan if any
            'loanInstallmentDeduction' => 0.00,
            'loanInstallmentCount' => null,
            'loanInstallmentRemaining' => null,
        ];

        $salaryGenerate = SalaryGenerate::updateOrCreate(
            [
                'user_id' => $user->id,
                'monthId' => $monthId,
                'salaryGenerationYear' => $year,
            ],
            $recordData
        );

        // Record GPF contribution in general_provident_funds and sync pivot if gpfDeduction exists
        if ($gpfDeduction > 0) {
            app(GeneralProvidentFundService::class)->recordSalaryGpfContribution(
                $user,
                $salaryGenerate,
                $gpfDeduction,
                $monthId,
                $year,
                $salaryDate
            );
        }

        /**
         * 4. return graceful message on successful operation or errors
         */
        return response()->json([
            'status' => 200,
            'completed' => count($step1Conditions),
            'messages' => ["Salary bill successfully calculated and generated for {$user->name}."],
            'salaryGenerate' => $salaryGenerate,
        ]);
    }

    /** Read-only bounded pages, all using the same single-bill financial composer.
     * Optional preview_ids retain the initially selected candidate identities even
     * when another request changes the live unbilled directory between pages.
     */
    public function bulkCandidates(Request $request)
    {
        $data=$request->validate([
            'monthId'=>'required|integer|between:1,12',
            'salaryGenerationYear'=>'required|integer|between:2020,2100',
            'preview_ids'=>'sometimes|array|max:25',
            'preview_ids.*'=>'integer|min:1|distinct',
        ]);
        $month=(int)$data['monthId']; $year=(int)$data['salaryGenerationYear'];
        $query=$this->buildFilteredEmployeeQuery($request);
        $alreadyGenerated=(clone $query)->whereHas('salaryGenerates',fn($q)=>$q->where('monthId',$month)
            ->where('salaryGenerationYear',$year))->count();
        $query->whereDoesntHave('salaryGenerates',fn($q)=>$q->where('monthId',$month)
            ->where('salaryGenerationYear',$year));
        $ids=$query->orderBy('users.id')->limit(1001)->pluck('users.id')->map(fn($v)=>(int)$v)->all();
        if (count($ids)>1000) return response()->json(['message'=>'More than 1000 employees match; narrow filters.'],422);
        $requested = isset($data['preview_ids']) ? array_map('intval',$data['preview_ids']) : array_slice($ids,0,25);
        $eligible=array_fill_keys($ids,true);
        $preview=[];
        $posting=app(\App\Services\SalaryBillPostingService::class);
        foreach ($requested as $id) {
            if (!isset($eligible[$id])) {
                $preview[]=\App\Services\SalaryBulkPreviewPresenter::failure($id,'No longer an unbilled matching employee; refresh candidate selection.');
                continue;
            }
            try {
                $source=$posting->previewForPosting($id,$year,$month);
                $preview[]=\App\Services\SalaryBulkPreviewPresenter::present($id,$source);
            } catch (\DomainException|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                $preview[]=\App\Services\SalaryBulkPreviewPresenter::failure($id,$e->getMessage());
            } catch (\Throwable $e) {
                report($e);
                $preview[]=\App\Services\SalaryBulkPreviewPresenter::failure($id,'Unexpected financial preview error; inspect Laravel logs.');
            }
        }
        return response()->json(['user_ids'=>$ids,'total'=>count($ids),
            'already_generated_count'=>$alreadyGenerated,'preview'=>$preview,'preview_count'=>count($preview),
            'preview_truncated'=>count($ids)>count($preview)]);
    }

    /**
     * Bulk generate salary bills for multiple filtered users.
     */
    public function generateBulk(Request $request)
    {
        $userIds = $request->input('userIds', []);
        $monthId = (int) ($request->input('monthId') ?? date('n'));
        $year = (int) ($request->input('salaryGenerationYear') ?? date('Y'));
        $salaryDate = $request->input('salaryGenerationDate') ?? \Carbon\Carbon::createFromDate($year, $monthId, 1)->endOfMonth()->toDateString();

        if (empty($userIds) && $request->boolean('all_filtered_unbilled')) {
            $query = $this->buildFilteredEmployeeQuery($request);
            $query->whereDoesntHave('salaryGenerates', function ($q) use ($monthId, $year) {
                $q->where('monthId', $monthId)->where('salaryGenerationYear', $year);
            });
            $userIds = $query->pluck('id')->all();
        }

        // Keep the historical endpoint working for small selections only.
        // All-large-directory payroll must use the resumable Phase 5 API.
        if (count((array)$userIds) > 25) {
            return response()->json(['status'=>422,'errors'=>[
                'Please use Generate All Filtered / Phase 5 resumable batch API for more than 25 employees.'
            ]],422);
        }

        if (empty($userIds)) {
            return response()->json([
                'status' => 422,
                'errors' => ['No employees available for salary generation.'],
            ]);
        }

        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($userIds as $uId) {
            $subReq = new Request([
                'userId' => $uId,
                'monthId' => $monthId,
                'salaryGenerationYear' => $year,
                'salaryGenerationDate' => $salaryDate,
            ]);

            $res = $this->generateSalaryForEmployee($subReq);
            $data = json_decode($res->getContent(), true);

            if (($data['status'] ?? null) == 200) {
                $successCount++;
            } else {
                $failedCount++;
                $userName = User::find($uId)?->name ?? "User #{$uId}";
                $errMsg = !empty($data['errors']) ? implode(', ', $data['errors']) : 'Generation failed';
                $errors[] = "{$userName}: {$errMsg}";
            }
        }

        return response()->json([
            'status' => 200,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'errors' => $errors,
            'message' => "Salary bills generated for {$successCount} employees." . ($failedCount > 0 ? " ({$failedCount} failed)" : ""),
        ]);
    }

    /**
     * Show single generated salary bill details with particulars.
     */
    public function show(SalaryGenerate $salaryGenerate)
    {
        $salaryGenerate->load([
            'user.employeeInfo.payScale',
            'user.employeeInfo.payrollGroup.grade',
            'user.employeeInfo.departmentOffice',
            'user.bankAccounts',
            'user.phoneNumbers',
            'user.departmentOffices',
            'user.userCategories',
        ]);

        return response()->json($salaryGenerate);
    }

    /**
     * Send salary bill review email and update reviewStatus to 'review'.
     */
    public function sendReview(Request $request, SalaryGenerate $salaryGenerate)
    {
        $salaryGenerate->load('user');
        $email = $salaryGenerate->user?->email;

        if (empty($email)) {
            return response()->json([
                'status' => 422,
                'errors' => ["Employee {$salaryGenerate->name} does not have an email address configured."],
            ], 422);
        }

        try {
            Mail::to($email)->send(new SalaryBillReviewMail($salaryGenerate));

            $salaryGenerate->update([
                'reviewStatus' => 'review',
            ]);

            return response()->json([
                'status' => 200,
                'message' => "Salary bill review email sent to {$salaryGenerate->name} ({$email}) successfully.",
                'salaryGenerate' => $salaryGenerate->fresh(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 500,
                'errors' => ["Failed to send email to {$email}: " . $e->getMessage()],
            ], 500);
        }
    }

    /**
     * Send salary bill review email in bulk.
     */
    public function sendBulkReview(Request $request)
    {
        $ids = $request->input('salary_generate_ids', []);
        $allFiltered = filter_var($request->input('all_filtered', false), FILTER_VALIDATE_BOOLEAN);

        $monthId = (int) $request->input('monthId', date('n'));
        $year = (int) $request->input('salaryGenerationYear', date('Y'));

        if ($allFiltered) {
            // Find all generated bills matching the filter
            $empQuery = $this->buildFilteredEmployeeQuery($request);
            $userIds = $empQuery->pluck('users.id')->toArray();

            $bills = SalaryGenerate::with('user')
                ->where('monthId', $monthId)
                ->where('salaryGenerationYear', $year)
                ->whereIn('user_id', $userIds)
                ->get();
        } else {
            if (empty($ids) || !is_array($ids)) {
                return response()->json([
                    'status' => 422,
                    'errors' => ['Please select at least one generated salary bill to send review email.'],
                ], 422);
            }

            $bills = SalaryGenerate::with('user')
                ->whereIn('id', $ids)
                ->get();
        }

        if ($bills->isEmpty()) {
            return response()->json([
                'status' => 422,
                'errors' => ['No generated salary bills found for review dispatch.'],
            ], 422);
        }

        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($bills as $bill) {
            $email = $bill->user?->email;
            if (empty($email)) {
                $failedCount++;
                $errors[] = "Employee {$bill->name} has no email address.";
                continue;
            }

            try {
                Mail::to($email)->send(new SalaryBillReviewMail($bill));
                $bill->update(['reviewStatus' => 'review']);
                $successCount++;
            } catch (\Throwable $e) {
                $failedCount++;
                $errors[] = "Failed for {$bill->name}: " . $e->getMessage();
            }
        }

        return response()->json([
            'status' => 200,
            'message' => "Salary bill review email dispatched to {$successCount} employees." . ($failedCount > 0 ? " ({$failedCount} failed)" : ''),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'errors' => $errors,
        ]);
    }

    /**
     * Override bulk review status (e.g. approved).
     */
    public function overrideBulkReviewStatus(Request $request)
    {
        $newStatus = $request->input('reviewStatus', 'approved');
        if (!in_array($newStatus, ['approved', 'pending', 'review'])) {
            return response()->json([
                'status' => 422,
                'errors' => ['Invalid review status value.'],
            ], 422);
        }

        $allFiltered = filter_var($request->input('all_filtered', false), FILTER_VALIDATE_BOOLEAN);
        $ids = $request->input('salary_generate_ids', []);
        $monthId = (int) $request->input('monthId', date('n'));
        $year = (int) $request->input('salaryGenerationYear', date('Y'));

        if ($allFiltered) {
            $empQuery = $this->buildFilteredEmployeeQuery($request);
            $userIds = $empQuery->pluck('users.id')->toArray();

            $billsQuery = SalaryGenerate::where('monthId', $monthId)
                ->where('salaryGenerationYear', $year)
                ->whereIn('user_id', $userIds);
        } else {
            if (empty($ids) || !is_array($ids)) {
                return response()->json([
                    'status' => 422,
                    'errors' => ['Please select at least one generated salary bill to override status.'],
                ], 422);
            }

            $billsQuery = SalaryGenerate::whereIn('id', $ids);
        }

        $updatedCount = $billsQuery->update([
            'reviewStatus' => $newStatus,
        ]);

        return response()->json([
            'status' => 200,
            'message' => "Review status for {$updatedCount} salary record(s) updated to '{$newStatus}'.",
            'updated_count' => $updatedCount,
        ]);
    }

    /**
     * Override review status (e.g. approved).
     */
    public function overrideReviewStatus(Request $request, SalaryGenerate $salaryGenerate)
    {
        $newStatus = $request->input('reviewStatus', 'approved');
        if (!in_array($newStatus, ['approved', 'pending', 'review'])) {
            return response()->json([
                'status' => 422,
                'errors' => ['Invalid review status value.'],
            ], 422);
        }

        $salaryGenerate->update([
            'reviewStatus' => $newStatus,
        ]);

        return response()->json([
            'status' => 200,
            'message' => "Review status for {$salaryGenerate->name} updated to '{$newStatus}'.",
            'salaryGenerate' => $salaryGenerate->fresh(),
        ]);
    }

    /**
     * Helper to build employee base query with all search and relational filters.
     */
    private function buildFilteredEmployeeQuery(Request $request)
    {
        $query = User::whereHas('roles', function ($q) {
            $q->where('role_name', 'user');
        })->whereHas('employeeInfo');

        // 1. Exact Employee Code
        if ($request->filled('employeeCode')) {
            $code = $request->input('employeeCode');
            $query->whereHas('employeeInfo', function ($q) use ($code) {
                $q->where('employeeCode', 'like', "%{$code}%");
            });
        }

        // 2. Department / Office
        if ($request->filled('department_office_id')) {
            $deptId = $request->input('department_office_id');
            $deptIds = is_array($deptId) ? $deptId : [$deptId];
            $query->where(function ($q) use ($deptIds) {
                $q->whereHas('departmentOffices', function ($sub) use ($deptIds) {
                    $sub->whereIn('department_offices.id', $deptIds);
                })->orWhereHas('employeeInfo', function ($sub) use ($deptIds) {
                    $sub->whereIn('department_office_id', $deptIds);
                });
            });
        }

        // 3. Employee Group / Category
        if ($request->filled('user_category_id')) {
            $catId = $request->input('user_category_id');
            $catIds = is_array($catId) ? $catId : [$catId];
            $query->whereHas('userCategories', function ($q) use ($catIds) {
                $q->whereIn('user_categories.id', $catIds);
            });
        }

        // 4. Pay Scale
        if ($request->filled('pay_scale_id')) {
            $scaleId = $request->input('pay_scale_id');
            $query->whereHas('employeeInfo', function ($q) use ($scaleId) {
                $q->where('pay_scale_id', $scaleId);
            });
        }

        // 5. Grade
        if ($request->filled('grade_id')) {
            $gradeId = $request->input('grade_id');
            $query->whereHas('employeeInfo.payrollGroup', function ($q) use ($gradeId) {
                $q->where('grade_id', $gradeId);
            });
        }

        // 6. Designation / Payroll Group
        if ($request->filled('payroll_group_id')) {
            $groupId = $request->input('payroll_group_id');
            $query->whereHas('employeeInfo', function ($q) use ($groupId) {
                $q->where('payroll_group_id', $groupId);
            });
        }

        if ($request->filled('payroll_group_type_id')) {
            $groupTypeId = $request->input('payroll_group_type_id');
            $query->whereHas('employeeInfo.payrollGroup', function ($q) use ($groupTypeId) {
                $q->where('payroll_group_type_id', $groupTypeId);
            });
        }

        // 7. Status (isActive)
        if ($request->filled('isActive')) {
            $isActive = filter_var($request->input('isActive'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive !== null) {
                $query->where('isActive', $isActive);
            }
        }

        // 8. Job Status
        if ($request->filled('jobStatus')) {
            $jobStatus = $request->input('jobStatus');
            $query->whereHas('employeeInfo', function ($q) use ($jobStatus) {
                $q->where('jobStatus', $jobStatus);
            });
        }

        // 9. Gender
        if ($request->filled('gender')) {
            $query->where('gender', $request->input('gender'));
        }

        // 10. Increment Status
        if ($request->filled('holdIncrement')) {
            $hold = filter_var($request->input('holdIncrement'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($hold !== null) {
                $query->whereHas('employeeInfo', function ($q) use ($hold) {
                    $q->where('holdIncrement', $hold);
                });
            }
        }

        // 11. Leave Status
        if ($request->filled('onLeave')) {
            $onLeave = filter_var($request->input('onLeave'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($onLeave !== null) {
                $query->whereHas('leaveStatuses', function ($q) use ($onLeave) {
                    $q->where('onLeave', $onLeave);
                });
            }
        }

        // 12. Marital Status
        if ($request->filled('marital_status')) {
            $maritalStatus = $request->input('marital_status');
            $query->whereHas('maritialStatus', function ($q) use ($maritalStatus) {
                $q->where('status', $maritalStatus);
            });
        }

        // 13. Religion
        if ($request->filled('religion')) {
            $religion = $request->input('religion');
            $query->whereHas('religion', function ($q) use ($religion) {
                $q->where('religion', $religion);
            });
        }

        // Universal search (name, email, code, phone, NID)
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('employeeInfo', function ($sub) use ($search) {
                        $sub->where('employeeCode', 'like', "%{$search}%");
                    })
                    ->orWhereHas('phoneNumbers', function ($sub) use ($search) {
                        $sub->where('phoneNumber', 'like', "%{$search}%");
                    })
                    ->orWhereHas('nid', function ($sub) use ($search) {
                        $sub->where('nid_no', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }
}
