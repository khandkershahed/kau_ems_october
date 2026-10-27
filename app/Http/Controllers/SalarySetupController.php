<?php

namespace App\Http\Controllers;

use App\Mail\SalaryEstimationReviewMail;
use App\Models\DepartmentOffice;
use App\Models\EmployeeInfo;
use App\Models\Grade;
use App\Models\PayrollComponent;
use App\Models\PayrollComponentType;
use App\Models\PayrollGroup;
use App\Models\PayrollGroupType;
use App\Models\PayScale;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\ApprovedFixationResolver;
use App\Services\ApprovedFixationComponentProjector;
use App\Services\PayrollPreviewCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class SalarySetupController extends Controller
{
    /**
     * Display a listing of employee salary setups or search/filter results.
     */
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $period = $request->validate([
                'salary_year' => 'nullable|integer|between:2020,2100',
                'salary_month' => 'nullable|integer|between:1,12',
            ]);
            $year = (int) ($period['salary_year'] ?? now()->year);
            $month = (int) ($period['salary_month'] ?? now()->month);
            $resolver = app(ApprovedFixationResolver::class);
            $query = User::whereHas('roles', function ($q) {
                $q->where('role_name', 'user');
            })->with([
                'employeeInfo.payScale',
                'employeeInfo.payrollGroup.grade',
                'employeeInfo.payrollGroup.payrollGroupType',
                'employeeInfo.payrollGroup.payrollComponents.payrollComponentType',
                'employeeInfo.departmentOffice',
                'departmentOffices',
                'userCategories',
                'joiningDate',
                'religion',
                'bankAccounts',
            ]);

            // 1. Employee Code filter
            if ($request->filled('employeeCode')) {
                $code = $request->input('employeeCode');
                $query->whereHas('employeeInfo', function ($q) use ($code) {
                    $q->where('employeeCode', 'like', "%{$code}%");
                });
            }

            // 2. Employee Category filter
            if ($request->filled('user_category_id')) {
                $categoryId = $request->input('user_category_id');
                $categoryIds = is_array($categoryId) ? $categoryId : [$categoryId];
                $query->whereHas('userCategories', function ($q) use ($categoryIds) {
                    $q->whereIn('user_categories.id', $categoryIds);
                });
            }

            // 3. Pay Scale filter
            if ($request->filled('pay_scale_id')) {
                $scaleId = $request->input('pay_scale_id');
                $selected = PayScale::find($scaleId);
                if ($selected && str_contains((string) $selected->scale_name, '2026')) {
                    $validUsers = \App\Models\SalaryFixationPaySchedule::query()
                        ->whereDate('effective_date', '<=', ApprovedFixationResolver::cutoff($year, $month))
                        ->whereHas('fixation', fn ($q) => $q->where('status', 'approved'))
                        ->pluck('user_id');
                    $query->whereIn('id', $validUsers);
                } else {
                    $query->whereHas('employeeInfo', fn ($q) => $q->where('pay_scale_id', $scaleId));
                    $approvedIds = \App\Models\SalaryFixationPaySchedule::query()
                        ->whereDate('effective_date', '<=', ApprovedFixationResolver::cutoff($year, $month))
                        ->whereHas('fixation', fn ($q) => $q->where('status', 'approved'))
                        ->pluck('user_id');
                    $query->whereNotIn('id', $approvedIds);
                }
            }

            // 4. Grades filter
            if ($request->filled('grade_id')) {
                $gradeId = $request->input('grade_id');
                $selectedGrade = Grade::find($gradeId);
                $revisedScale = $selectedGrade?->payScale?->scale_name;
                if (str_contains((string) $revisedScale, '2026')
                    && preg_match('/Grade\s+(\d+)/i', (string) $selectedGrade->grade_name, $gradeMatch)) {
                    $eligibleIds = \App\Models\SalaryFixationPaySchedule::query()
                        ->whereDate('effective_date', '<=', ApprovedFixationResolver::cutoff($year, $month))
                        ->where('grade_number', (int) $gradeMatch[1])
                        ->whereHas('fixation', fn ($q) => $q->where('status', 'approved'))
                        ->pluck('user_id');
                    $query->whereIn('id', $eligibleIds);
                } else {
                    $query->whereHas('employeeInfo.payrollGroup', fn ($q) => $q->where('grade_id', $gradeId));
                    $approvedIds = \App\Models\SalaryFixationPaySchedule::query()
                        ->whereDate('effective_date', '<=', ApprovedFixationResolver::cutoff($year, $month))
                        ->whereHas('fixation', fn ($q) => $q->where('status', 'approved'))
                        ->pluck('user_id');
                    $query->whereNotIn('id', $approvedIds);
                }
            }

            // 5. Gender filter
            if ($request->filled('gender')) {
                $query->where('gender', $request->input('gender'));
            }

            // 6. Profile Active Status filter
            if ($request->filled('isActive')) {
                $isActive = filter_var($request->input('isActive'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isActive !== null) {
                    $query->where('isActive', $isActive);
                }
            }

            // 7. Designation (Payroll Group & Payroll Group Type) filter
            if ($request->filled('payroll_group_id')) {
                $payrollGroupId = $request->input('payroll_group_id');
                $query->whereHas('employeeInfo', function ($q) use ($payrollGroupId) {
                    $q->where('payroll_group_id', $payrollGroupId);
                });
            }

            if ($request->filled('payroll_group_type_id')) {
                $groupTypeId = $request->input('payroll_group_type_id');
                $query->whereHas('employeeInfo.payrollGroup', function ($q) use ($groupTypeId) {
                    $q->where('payroll_group_type_id', $groupTypeId);
                });
            }

            // 8. Payroll Component Type filter
            if ($request->filled('payroll_component_type_id')) {
                $compTypeId = $request->input('payroll_component_type_id');
                $query->whereHas('employeeInfo.payrollGroup.payrollComponents', function ($q) use ($compTypeId) {
                    $q->where('payroll_component_type_id', $compTypeId);
                });
            }

            // 9. Joining Date filter
            if ($request->filled('joining_date')) {
                $joiningDate = $request->input('joining_date');
                $query->whereHas('joiningDate', function ($q) use ($joiningDate) {
                    $q->whereDate('joining_date', $joiningDate);
                });
            }

            // 10. Religion filter
            if ($request->filled('religion')) {
                $religion = $request->input('religion');
                $query->whereHas('religion', function ($q) use ($religion) {
                    $q->where('religion', $religion);
                });
            }

            // General text search (name, email)
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('employeeInfo', function ($sub) use ($search) {
                            $sub->where('employeeCode', 'like', "%{$search}%");
                        });
                });
            }

            // Sorting
            $sortField = $request->input('sortField', 'id');
            $sortDirection = $request->input('sortDirection', 'desc');
            $allowedSortFields = ['id', 'name', 'email', 'isActive', 'gender', 'created_at'];

            if (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
            } else {
                $query->orderBy('id', 'desc');
            }

            $perPage = min(100, max(1, (int) $request->input('perPage', 10)));
            $page = $query->paginate($perPage);
            $ids = $page->getCollection()->pluck('id')->all();
            $fixations = $resolver->forUsers($ids, $year, $month);
            $profileCounts = EmployeeInfo::whereIn('user_id', $ids)
                ->selectRaw('user_id, COUNT(*) as total_profiles')->groupBy('user_id')
                ->pluck('total_profiles', 'user_id');
            $manualByUser = Schema::hasTable('salary_manual_adjustments')
                ? DB::table('salary_manual_adjustments')->whereIn('user_id', $ids)
                    ->where('salary_year', $year)->where('salary_month', $month)->get()->groupBy('user_id')
                : collect();
            $page->getCollection()->transform(function ($user) use ($fixations, $manualByUser, $profileCounts, $year, $month) {
                $effective = $this->effectivePayroll(
                    $user->employeeInfo, $fixations[$user->id] ?? null, $year, $month,
                    $manualByUser->get($user->id, collect())->all());
                if (($profileCounts[$user->id] ?? 0) !== 1) {
                    $effective['error'] = 'Duplicate or missing employee salary profile; HR must reconcile identity.';
                    unset($effective['gross'], $effective['net']);
                }
                $user->setAttribute('effective_payroll', $effective);
                return $user;
            });
            return response()->json($page);
        }

        $payrollComponents = PayrollComponent::with([
            'payrollComponentType',
            'payrollGroup.payrollGroupType',
            'payrollGroup.grade',
        ])->get();

        // Return Inertia page with reference data for dropdowns
        return Inertia::render('Salary/Setup/Index', [
            'payScales' => PayScale::all(),
            'grades' => Grade::with('payScale')->get(),
            'payrollGroupTypes' => PayrollGroupType::all(),
            'payrollComponentTypes' => PayrollComponentType::all(),
            'payrollGroups' => PayrollGroup::with(['grade', 'payrollGroupType'])->get(),
            'payrollComponents' => $payrollComponents,
            'allPayrollComponents' => $payrollComponents,
            'userCategories' => UserCategory::all(),
            'departmentOffices' => DepartmentOffice::all(),
            'religions' => [
                'Islam',
                'Christianity',
                'Hinduism',
                'Buddhism',
                'Sikhism',
                'Judaism',
                'Jainism',
                'Shinto',
                'Taoism',
                'Zoroastrianism',
                'Atheism',
                'Agnosticism',
                'Other'
            ],
            'genders' => [
                ['value' => 'male', 'label' => 'Male'],
                ['value' => 'female', 'label' => 'Female'],
                ['value' => 'other', 'label' => 'Other']
            ]
        ]);
    }

    /**
     * Show single employee salary setup details.
     */
    public function show(Request $request, User $user)
    {
        $user->load([
            'employeeInfo.payScale',
            'employeeInfo.payrollGroup.grade',
            'employeeInfo.payrollGroup.payrollGroupType',
            'employeeInfo.payrollGroup.payrollComponents.payrollComponentType',
            'employeeInfo.departmentOffice',
            'departmentOffices',
            'userCategories',
            'joiningDate',
            'religion',
            'bankAccounts',
            'leaveStatuses',
            'promotions.payScale',
            'promotions.payrollGroup',
        ]);

        $period = $request->validate([
            'salary_year' => 'nullable|integer|between:2020,2100',
            'salary_month' => 'nullable|integer|between:1,12',
        ]);
        $year = (int) ($period['salary_year'] ?? now()->year);
        $month = (int) ($period['salary_month'] ?? now()->month);
        $fixation = app(ApprovedFixationResolver::class)->forUser($user->id, $year, $month);
        $manual = Schema::hasTable('salary_manual_adjustments')
            ? DB::table('salary_manual_adjustments')->where('user_id', $user->id)
                ->where('salary_year', $year)->where('salary_month', $month)->get()->all()
            : [];
        $effective = $this->effectivePayroll($user->employeeInfo, $fixation, $year, $month, $manual);
        if (EmployeeInfo::where('user_id', $user->id)->count() !== 1) {
            $effective['error'] = 'Duplicate or missing employee profile; HR must reconcile identity.';
            unset($effective['gross'], $effective['net']);
        }
        $user->setAttribute('effective_payroll', $effective);
        return response()->json($user)->header('Cache-Control', 'private, no-store');
    }

    private function effectivePayroll(?EmployeeInfo $employee, ?array $fixation, int $year, int $month, array $manual = []): array
    {
        $legacy = [
            'source' => 'legacy_employee_setup',
            'pay_scale' => $employee?->payScale?->scale_name ?? 'Unconfigured',
            'grade' => $employee?->payrollGroup?->grade?->grade_name ?? 'Unconfigured',
            'basic' => null,
            'effective_date' => null,
            'salary_fixation_id' => null,
            'fixation_version' => null,
        ];
        $additions = \App\Services\SalaryComponentNormalizer::rows($employee?->additionObjects);
        $deductions = \App\Services\SalaryComponentNormalizer::rows($employee?->substractionObjects);
        try {
            if ($employee) {
                [$additions,$deductions] = app(\App\Services\SalaryComponentCatalog::class)->forEmployee($employee, $additions, $deductions, $year, $month, $fixation);
            }
            if ($fixation) {
                $projected = ApprovedFixationComponentProjector::project($additions, $deductions, $fixation);
                $legacy = array_merge($legacy, $fixation);
                $additions = $projected['additions'];
                $deductions = $projected['deductions'];
            }
            foreach ($manual as $row) {
                if ($fixation && \App\Services\PayrollEffectivePolicy::isSpecialBenefit(['name' => $row->title])) {
                    throw new \DomainException('Special Benefit cannot be a regular 2026 manual salary addition/deduction.');
                }
                $item = ['name' => $row->title, 'amount' => (float) $row->amount,
                    'calcType' => 'fixed', 'manual_adjustment_id' => $row->id,
                    'component_type' => $row->direction === 'addition' ? 'allowance' : 'deduction'];
                if ($row->direction === 'addition') $additions[] = $item;
                else $deductions[] = $item;
            }
            $optionalGpf = collect($deductions)->contains(fn ($row) => \App\Services\SalaryCalculationService::isGpf($row)) ? null : ['type'=>'disabled','amount'=>0];
            $financial = app(\App\Services\SalaryCalculationService::class)->calculate($additions, $deductions, null, $optionalGpf);
            // An approved workspace version overrides the automatic estimate
            // for this employee/month only. It never updates employee_infos.
            $workspace = app(\App\Services\ApprovedSalaryCalculationVersionResolver::class)
                ->forUser((int) $employee->user_id, $year, $month, $fixation);
            if ($workspace) {
                $financial = $workspace['financial'];
                $legacy['workspace_version'] = $workspace['version'];
            }
            $legacy['basic'] = $financial['basic'];
            // Explicit effective component projection for the Details modal.
            // employee_info.additionObjects remains the unchanged 2015 setup.
            $legacy['effective_allowances'] = $financial['allowances'];
            $legacy['effective_deductions'] = $financial['deductions'];
            $legacy['gross'] = $financial['total_allowance'];
            $legacy['deductions'] = $financial['total_deduction'];
            $legacy['net'] = $financial['net_salary'];
            $legacy['statement'] = \App\Services\SalaryStatementBreakdown::build($financial, []);
            $legacy['warnings'] = $fixation ? [
                'Existing configured fixed allowance/deduction rates are carried forward; inactive 2026 templates are not used.',
                'Special Benefit is excluded from revised regular pay; review any separately approved arrear recovery.',
                'GPF preview follows the effective employee-specific subscription or published rule; prior saved bills are unchanged.',
            ] : [];
        } catch (\DomainException $e) {
            $legacy = array_merge($legacy, $fixation ?? []);
            $legacy['error'] = $e->getMessage();
        }
        $legacy['salary_year'] = $year;
        $legacy['salary_month'] = $month;
        return $legacy;
    }

    /**
     * Update salary component calculation objects for user
     */
    public function updateSalaryCalculationComponentsForUser(Request $request)
    {
        $request->validate([
            'userId' => 'required|integer|exists:users,id',
            'salary_year' => 'nullable|integer|between:2020,2100',
            'salary_month' => 'nullable|integer|between:1,12',
        ]);
        if ($request->filled('salary_year') && $request->filled('salary_month')
            && app(ApprovedFixationResolver::class)->forUser((int) $request->userId,
                (int) $request->salary_year, (int) $request->salary_month)) {
            return response()->json(['status' => 422, 'errors' =>
                'Use Employee Salary Calculator to adjust approved-scale amounts without rewriting the 2015 setup.'], 422);
        }

        $profiles = EmployeeInfo::where('user_id', $request->userId)->limit(2)->get();
        if ($profiles->count() > 1) {
            return response()->json(['status' => 422, 'errors' =>
                'Duplicate employee profiles require reconciliation before updating salary components.'], 422);
        }
        $employeeInfo = $profiles->first();
        if (!$employeeInfo) {
            return response()->json([
                'status' => 500,
                'errors' => 'Employee salary setup profile not found.'
            ]);
        }

        $allowances = $request->input('allowances');
        $deductions = $request->input('deductions');

        if (is_string($allowances)) {
            $allowances = json_decode($allowances, true);
        }
        if (is_string($deductions)) {
            $deductions = json_decode($deductions, true);
        }

        $allowances = is_array($allowances) ? array_values($allowances) : [];
        $deductions = is_array($deductions) ? array_values($deductions) : [];

        $formatComponent = function ($item, $defaultType) {
            $compType = isset($item['payroll_component_type_id'])
                ? PayrollComponentType::find($item['payroll_component_type_id'])
                : null;

            $typeName = $item['name']
                ?? ($compType ? $compType->name : ($item['payroll_component_type']['name'] ?? null));

            $defaultCalcType = $item['default_calc_type'] ?? null;
            if (!$defaultCalcType && !empty($item['id'])) {
                $baseComp = PayrollComponent::find($item['id']);
                $defaultCalcType = $baseComp?->calcType;
            }
            $defaultCalcType = $defaultCalcType ?? ($item['calcType'] ?? 'fixed');

            $currentCalcType = strtolower($item['calcType'] ?? $defaultCalcType ?? 'fixed');
            $defaultCalcType = strtolower($defaultCalcType);

            $isRuleOver = !empty($item['ruleOver']) && ($item['ruleOver'] === true || $item['ruleOver'] === 'true' || $item['ruleOver'] === 1 || $item['ruleOver'] === '1');
            if (!$isRuleOver) {
                $amtChanged = isset($item['default_amount']) && (float) $item['amount'] !== (float) $item['default_amount'];
                $calcTypeChanged = $currentCalcType !== $defaultCalcType;
                $isRuleOver = $amtChanged || $calcTypeChanged;
            }

            $formatted = [
                'id' => isset($item['id']) ? (int) $item['id'] : null,
                'payroll_group_id' => isset($item['payroll_group_id']) ? (int) $item['payroll_group_id'] : null,
                'payroll_component_type_id' => isset($item['payroll_component_type_id']) ? (int) $item['payroll_component_type_id'] : null,
                'name' => $typeName,
                'component_type' => $item['component_type'] ?? $defaultType,
                'calcType' => $currentCalcType,
                'default_calc_type' => $defaultCalcType,
                'is_taxable' => !empty($item['is_taxable']) ? 1 : 0,
                'isActive' => isset($item['isActive']) ? (int) $item['isActive'] : 1,
                'amount' => isset($item['amount']) ? (string) $item['amount'] : '0.00',
                'default_amount' => isset($item['default_amount']) ? (string) $item['default_amount'] : null,
                'ruleOver' => $isRuleOver ? true : false,
                'created_at' => $item['created_at'] ?? now()->toISOString(),
                'updated_at' => now()->toISOString(),
            ];

            if (isset($item['payroll_component_type']) && is_array($item['payroll_component_type'])) {
                $formatted['payroll_component_type'] = [
                    'id' => $item['payroll_component_type']['id'] ?? ($compType ? $compType->id : null),
                    'name' => $item['payroll_component_type']['name'] ?? ($compType ? $compType->name : null),
                    'component_description' => $item['payroll_component_type']['component_description'] ?? ($compType ? $compType->component_description : null),
                ];
            } elseif ($compType) {
                $formatted['payroll_component_type'] = [
                    'id' => $compType->id,
                    'name' => $compType->name,
                    'component_description' => $compType->component_description,
                ];
            }

            return $formatted;
        };

        $cleanedAllowances = array_map(fn($item) => $formatComponent($item, 'allowance'), $allowances);
        $cleanedDeductions = array_map(fn($item) => $formatComponent($item, 'deduction'), $deductions);

        // Find Basic pay allowance item
        $basicPayItem = null;
        foreach ($cleanedAllowances as $allowance) {
            $name = strtolower($allowance['name'] ?? $allowance['payroll_component_type']['name'] ?? '');
            if (str_contains($name, 'basic')) {
                $basicPayItem = $allowance;
                break;
            }
        }

        $basicPayAmount = $basicPayItem ? (float) ($basicPayItem['amount'] ?? 0) : 0.0;

        // Check if any component is set to percentage
        $hasPercentage = false;
        foreach (array_merge($cleanedAllowances, $cleanedDeductions) as $comp) {
            if (strtolower($comp['calcType'] ?? 'fixed') === 'percentage') {
                $hasPercentage = true;
                break;
            }
        }

        // If percentage is used, verify a Basic pay allowance is present with valid amount
        if ($hasPercentage && (!$basicPayItem || $basicPayAmount <= 0)) {
            return response()->json([
                'status' => 500,
                'errors' => "Calculation type is set to percentage for one or more components, but 'Basic pay' allowance is absent. Please add a Basic pay allowance first."
            ]);
        }

        // Calculate totals dynamically using percentage from basic pay when applicable
        $computeComponentAmount = function ($item) use ($basicPayAmount) {
            $calcType = strtolower($item['calcType'] ?? 'fixed');
            $rawAmount = (float) ($item['amount'] ?? 0);
            if ($calcType === 'percentage') {
                return $basicPayAmount * ($rawAmount / 100.0);
            }
            return $rawAmount;
        };

        $totalAdditions = array_reduce($cleanedAllowances, fn($sum, $item) => $sum + $computeComponentAmount($item), 0.0);
        $totalDeductions = array_reduce($cleanedDeductions, fn($sum, $item) => $sum + $computeComponentAmount($item), 0.0);

        if ($totalAdditions < $totalDeductions) {
            return response()->json([
                'status' => 500,
                'errors' => 'Total allowances cannot be less than total deductions.'
            ]);
        }

        $employeeInfo->update([
            'additionObjects' => json_encode($cleanedAllowances),
            'substractionObjects' => json_encode($cleanedDeductions),
        ]);

        return response()->json([
            'status' => 200,
            'messages' => ['Salary components and calculation objects updated successfully.'],
            'employeeInfo' => $employeeInfo
        ]);
    }

    /**
     * Send currently estimated salary breakdown via email for a single employee.
     */
    public function sendReview(Request $request, User $user)
    {
        if (empty($user->email)) {
            return response()->json([
                'status' => 422,
                'errors' => ["Employee {$user->name} does not have an email address configured."],
            ], 422);
        }

        $user->load([
            'employeeInfo.payScale',
            'employeeInfo.payrollGroup.grade',
            'employeeInfo.payrollGroup.payrollGroupType',
            'employeeInfo.departmentOffice',
            'departmentOffices',
            'userCategories',
        ]);

        $period = $request->validate([
            'salary_year' => 'nullable|integer|between:2020,2100',
            'salary_month' => 'nullable|integer|between:1,12',
        ]);
        $breakdown = $this->buildSalaryBreakdownForUser($user,
            (int) ($period['salary_year'] ?? now()->year),
            (int) ($period['salary_month'] ?? now()->month));

        try {
            Mail::to($user->email)->send(
                new SalaryEstimationReviewMail($user, $breakdown)
            );

            return response()->json([
                'status' => 200,
                'message' => "Estimated salary breakdown sent to {$user->name} ({$user->email}) successfully.",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 500,
                'errors' => ["Failed to send email to {$user->email}: " . $e->getMessage()],
            ], 500);
        }
    }

    /**
     * Send currently estimated salary breakdown via email in bulk.
     */
    public function sendBulkReview(Request $request)
    {
        $userIds = $request->input('user_ids', []);
        $allFiltered = filter_var($request->input('all_filtered', false), FILTER_VALIDATE_BOOLEAN);

        $query = User::whereHas('roles', function ($q) {
            $q->where('role_name', 'user');
        })->whereNotNull('email')->where('email', '!=', '');

        if ($allFiltered) {
            // Apply current filters matching index()
            if ($request->filled('employeeCode')) {
                $code = $request->input('employeeCode');
                $query->whereHas('employeeInfo', function ($q) use ($code) {
                    $q->where('employeeCode', 'like', "%{$code}%");
                });
            }
            if ($request->filled('user_category_id')) {
                $categoryId = $request->input('user_category_id');
                $categoryIds = is_array($categoryId) ? $categoryId : [$categoryId];
                $query->whereHas('userCategories', function ($q) use ($categoryIds) {
                    $q->whereIn('user_categories.id', $categoryIds);
                });
            }
            if ($request->filled('pay_scale_id')) {
                $scaleId = $request->input('pay_scale_id');
                $query->whereHas('employeeInfo', function ($q) use ($scaleId) {
                    $q->where('pay_scale_id', $scaleId);
                });
            }
            if ($request->filled('grade_id')) {
                $gradeId = $request->input('grade_id');
                $query->whereHas('employeeInfo.payrollGroup', function ($q) use ($gradeId) {
                    $q->where('grade_id', $gradeId);
                });
            }
            if ($request->filled('gender')) {
                $query->where('gender', $request->input('gender'));
            }
            if ($request->filled('isActive')) {
                $isActive = filter_var($request->input('isActive'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isActive !== null) {
                    $query->where('isActive', $isActive);
                }
            }
            if ($request->filled('payroll_group_id')) {
                $payrollGroupId = $request->input('payroll_group_id');
                $query->whereHas('employeeInfo', function ($q) use ($payrollGroupId) {
                    $q->where('payroll_group_id', $payrollGroupId);
                });
            }
            if ($request->filled('payroll_group_type_id')) {
                $groupTypeId = $request->input('payroll_group_type_id');
                $query->whereHas('employeeInfo.payrollGroup', function ($q) use ($groupTypeId) {
                    $q->where('payroll_group_type_id', $groupTypeId);
                });
            }
            if ($request->filled('payroll_component_type_id')) {
                $compTypeId = $request->input('payroll_component_type_id');
                $query->whereHas('employeeInfo.payrollGroup.payrollComponents', function ($q) use ($compTypeId) {
                    $q->where('payroll_component_type_id', $compTypeId);
                });
            }
            if ($request->filled('joining_date')) {
                $joiningDate = $request->input('joining_date');
                $query->whereHas('joiningDate', function ($q) use ($joiningDate) {
                    $q->whereDate('joining_date', $joiningDate);
                });
            }
            if ($request->filled('religion')) {
                $religion = $request->input('religion');
                $query->whereHas('religion', function ($q) use ($religion) {
                    $q->where('religion', $religion);
                });
            }
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('employeeInfo', function ($sub) use ($search) {
                            $sub->where('employeeCode', 'like', "%{$search}%");
                        });
                });
            }
        } else {
            if (empty($userIds) || !is_array($userIds)) {
                return response()->json([
                    'status' => 422,
                    'errors' => ['Please select at least one employee to send review email.'],
                ], 422);
            }
            $query->whereIn('id', $userIds);
        }

        $users = $query->with([
            'employeeInfo.payScale',
            'employeeInfo.payrollGroup.grade',
            'employeeInfo.payrollGroup.payrollGroupType',
            'employeeInfo.departmentOffice',
            'departmentOffices',
            'userCategories',
        ])->get();

        if ($users->isEmpty()) {
            return response()->json([
                'status' => 422,
                'errors' => ['No eligible employees with configured email addresses found.'],
            ], 422);
        }

        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        $period = $request->validate([
            'salary_year' => 'nullable|integer|between:2020,2100',
            'salary_month' => 'nullable|integer|between:1,12',
        ]);
        $year = (int) ($period['salary_year'] ?? now()->year);
        $month = (int) ($period['salary_month'] ?? now()->month);
        foreach ($users as $user) {
            try {
                $breakdown = $this->buildSalaryBreakdownForUser($user, $year, $month);
                Mail::to($user->email)->send(
                    new SalaryEstimationReviewMail($user, $breakdown)
                );
                $successCount++;
            } catch (\Throwable $e) {
                $failedCount++;
                $errors[] = "Failed for {$user->name}: " . $e->getMessage();
            }
        }

        return response()->json([
            'status' => 200,
            'message' => "Estimated salary breakdown review email dispatched to {$successCount} employees." . ($failedCount > 0 ? " ({$failedCount} failed)" : ''),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'errors' => $errors,
        ]);
    }

    /**
     * Helper to construct salary breakdown array for email.
     */
    private function buildSalaryBreakdownForUser(User $user, int $year, int $month): array
    {
        $info = $user->employeeInfo;
        $allowances = [];
        $deductions = [];

        if ($info) {
            $parse = function ($raw) {
                if (empty($raw)) return [];
                if (is_array($raw)) return $raw;
                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    return is_array($decoded) ? $decoded : [];
                }
                return [];
            };

            $allowances = $parse($info->additionObjects);
            $deductions = $parse($info->substractionObjects);
        }

        if (EmployeeInfo::where('user_id', $user->id)->count() !== 1) {
            throw new \DomainException('Duplicate or missing employee profile cannot receive a certified estimation.');
        }
        $fixation = app(ApprovedFixationResolver::class)->forUser($user->id, $year, $month);
        if ($fixation) {
            $projected = ApprovedFixationComponentProjector::project($allowances, $deductions, $fixation);
            $allowances = $projected['additions'];
            $deductions = $projected['deductions'];
        }
        if (Schema::hasTable('salary_manual_adjustments')) {
            $manual = DB::table('salary_manual_adjustments')->where('user_id', $user->id)
                ->where('salary_year', $year)->where('salary_month', $month)->get();
            foreach ($manual as $row) {
                if ($fixation && \App\Services\PayrollEffectivePolicy::isSpecialBenefit(['name' => $row->title])) {
                    throw new \DomainException('Revised regular pay cannot contain a Special Benefit manual item.');
                }
                $item = ['name' => $row->title, 'amount' => (float) $row->amount,
                    'calcType' => 'fixed', 'manual_adjustment_id' => $row->id];
                if ($row->direction === 'addition') $allowances[] = $item;
                else $deductions[] = $item;
            }
        }
        $financial = PayrollPreviewCalculator::calculate($allowances, $deductions);
        $allowances = $financial['allowances'];
        $deductions = $financial['deductions'];
        $totalAllowance = $financial['total_allowance'];
        $totalDeduction = $financial['total_deduction'];
        $netSalary = $financial['net_salary'];

        $dept = $info?->departmentOffice?->name 
            ?? $user->departmentOffices->first()?->name 
            ?? 'N/A';

        $cat = $user->userCategories->first()?->name ?? 'N/A';
        $payScale = $fixation['pay_scale'] ?? ($info?->payScale?->scale_name ?? 'N/A');
        $grade = $fixation['grade'] ?? ($info?->payrollGroup?->grade?->grade_name ?? 'N/A');
        $designation = $info?->payrollGroup?->payrollGroupType?->group_name ?? 'N/A';

        return [
            'employee_code' => $info?->employeeCode ?? 'N/A',
            'department' => $dept,
            'user_category' => $cat,
            'pay_scale' => $payScale,
            'grade' => $grade,
            'designation' => $designation,
            'allowances' => $allowances,
            'deductions' => $deductions,
            'total_allowance' => $totalAllowance,
            'total_deduction' => $totalDeduction,
            'net_salary' => $netSalary,
        ];
    }
}
