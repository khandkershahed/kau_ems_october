<?php

namespace App\Http\Controllers;

use App\Mail\BonusEstimationReviewMail;
use App\Models\BonusComponent;
use App\Models\BonusGroup;
use App\Models\DepartmentOffice;
use App\Models\EmployeeInfo;
use App\Models\Grade;
use App\Models\PayrollComponentType;
use App\Models\PayrollGroup;
use App\Models\PayrollGroupType;
use App\Models\PayScale;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class BonusSetupController extends Controller
{
    /**
     * Display a listing of employee bonus setups or search/filter results.
     */
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $query = $this->buildFilteredEmployeeQuery($request);

            // Sorting
            $sortField = $request->input('sortField', 'id');
            $sortDirection = $request->input('sortDirection', 'desc');
            $allowedSortFields = ['id', 'name', 'email', 'isActive', 'gender', 'created_at'];

            if (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
            } else {
                $query->orderBy('id', 'desc');
            }

            $perPage = (int) $request->input('perPage', 10);
            $paginator = $query->paginate($perPage);

            // Transform records into unified structure with bonus calculation details
            $transformedData = $paginator->getCollection()->map(function ($user) {
                $info = $user->employeeInfo;
                $dept = $info?->departmentOffice?->name 
                    ?? $user->departmentOffices->first()?->name 
                    ?? 'N/A';
                $category = $user->userCategories->first()?->name ?? 'General';
                $designation = $info?->payrollGroup?->payrollGroupType?->group_name ?? 'N/A';
                $scaleName = $info?->payScale?->scale_name ?? 'N/A';
                $gradeName = $info?->payrollGroup?->grade?->grade_name ?? 'N/A';
                $phone = $user->phoneNumbers->first()?->phoneNumber ?? 'N/A';
                $religionName = $user->religion?->religion ?? 'N/A';

                // Extract basic pay from salary additionObjects
                $basicPayAmount = $this->extractBasicPayAmount($info);

                // Extract bonus components
                $bonusAllowances = $this->parseJsonArray($info?->bonusAdditionObjects);
                $bonusDeductions = $this->parseJsonArray($info?->bonusSubstractionObjects);

                // If empty but has bonus_group_id, fallback to bonus group objects
                if (empty($bonusAllowances) && empty($bonusDeductions) && $info?->bonusGroup) {
                    $bonusAllowances = $info->bonusGroup->bonusAllowanceObjects ?: [];
                    $bonusDeductions = $info->bonusGroup->bonusDeductionObjects ?: [];
                }

                $totalAllowance = 0.0;
                $totalDeduction = 0.0;

                foreach ($bonusAllowances as $a) {
                    $calcType = strtolower($a['calcType'] ?? 'fixed');
                    $fromBasic = !empty($a['fromBasic']);
                    $rawAmt = (float) ($a['amount'] ?? 0);
                    $amt = ($calcType === 'percentage' && $fromBasic)
                        ? ($basicPayAmount * ($rawAmt / 100.0))
                        : (($calcType === 'percentage') ? ($basicPayAmount * ($rawAmt / 100.0)) : $rawAmt);
                    $totalAllowance += $amt;
                }

                foreach ($bonusDeductions as $d) {
                    $calcType = strtolower($d['calcType'] ?? 'fixed');
                    $fromBasic = !empty($d['fromBasic']);
                    $rawAmt = (float) ($d['amount'] ?? 0);
                    $amt = ($calcType === 'percentage' && $fromBasic)
                        ? ($basicPayAmount * ($rawAmt / 100.0))
                        : (($calcType === 'percentage') ? ($basicPayAmount * ($rawAmt / 100.0)) : $rawAmt);
                    $totalDeduction += $amt;
                }

                $netBonus = $totalAllowance - $totalDeduction;
                $isConfigured = !empty($info?->bonus_group_id) || !empty($info?->bonusAdditionObjects);

                return [
                    'id' => $user->id,
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'gender' => $user->gender,
                    'isActive' => (bool) $user->isActive,
                    'employee_code' => $info?->employeeCode ?? 'N/A',
                    'department' => $dept,
                    'user_category' => $category,
                    'designation' => $designation,
                    'pay_scale' => $scaleName,
                    'grade' => $gradeName,
                    'religion' => $religionName,
                    'phone' => $phone,
                    'joining_date' => !empty($user->joiningDate?->joining_date) ? (is_string($user->joiningDate->joining_date) ? substr($user->joiningDate->joining_date, 0, 10) : $user->joiningDate->joining_date->format('Y-m-d')) : 'N/A',
                    'basic_pay' => $basicPayAmount,
                    'bonus_group_id' => $info?->bonus_group_id,
                    'bonus_group_name' => $info?->bonusGroup?->name ?? 'Unassigned',
                    'is_configured' => $isConfigured,
                    'total_allowance' => $totalAllowance,
                    'total_deduction' => $totalDeduction,
                    'net_bonus' => $netBonus,
                    'employee_info' => $info,
                ];
            });

            return response()->json([
                'data' => $transformedData,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]);
        }

        // Return Inertia page with reference data for dropdowns
        return Inertia::render('Bonus/Setup/Index', [
            'payScales' => PayScale::where('isActive', true)->orderBy('scale_name')->get(),
            'grades' => Grade::with('payScale')->where('isActive', true)->orderBy('grade_name')->get(),
            'payrollGroupTypes' => PayrollGroupType::all(),
            'payrollGroups' => PayrollGroup::with(['grade', 'payrollGroupType'])->get(),
            'userCategories' => UserCategory::all(),
            'departmentOffices' => DepartmentOffice::where('isActive', true)->get(),
            'bonusGroups' => BonusGroup::orderBy('name')->get(),
            'bonusComponents' => BonusComponent::orderBy('id', 'desc')->get(),
            'religions' => [
                'Islam',
                'Christianity',
                'Hinduism',
                'Buddhism',
                'Sikhism',
                'Judaism',
                'Jainism',
                'Other',
            ],
            'genders' => [
                ['value' => 'male', 'label' => 'Male'],
                ['value' => 'female', 'label' => 'Female'],
                ['value' => 'other', 'label' => 'Other'],
            ],
        ]);
    }

    /**
     * Show single employee bonus setup details.
     */
    public function show(User $user)
    {
        $user->load([
            'employeeInfo.payScale',
            'employeeInfo.payrollGroup.grade',
            'employeeInfo.payrollGroup.payrollGroupType',
            'employeeInfo.departmentOffice',
            'employeeInfo.bonusGroup',
            'departmentOffices',
            'userCategories',
            'joiningDate',
            'religion',
            'bankAccounts',
        ]);

        $basicPayAmount = $this->extractBasicPayAmount($user->employeeInfo);

        $bonusAllowances = $this->parseJsonArray($user->employeeInfo?->bonusAdditionObjects);
        $bonusDeductions = $this->parseJsonArray($user->employeeInfo?->bonusSubstractionObjects);

        if (empty($bonusAllowances) && empty($bonusDeductions) && $user->employeeInfo?->bonusGroup) {
            $bonusAllowances = $user->employeeInfo->bonusGroup->bonusAllowanceObjects ?: [];
            $bonusDeductions = $user->employeeInfo->bonusGroup->bonusDeductionObjects ?: [];
        }

        return response()->json([
            'user' => $user,
            'basic_pay' => $basicPayAmount,
            'bonus_allowances' => $bonusAllowances,
            'bonus_deductions' => $bonusDeductions,
            'bonus_group' => $user->employeeInfo?->bonusGroup,
        ]);
    }

    /**
     * Update bonus calculation components for a single user.
     */
    public function updateBonusCalculationForUser(Request $request)
    {
        $request->validate([
            'userId' => 'required|integer|exists:users,id',
            'bonus_group_id' => 'nullable|integer|exists:bonus_groups,id',
        ]);

        $employeeInfo = EmployeeInfo::where('user_id', $request->userId)->first();
        if (!$employeeInfo) {
            return response()->json([
                'status' => 500,
                'errors' => ['Employee payroll profile not found.'],
            ]);
        }

        $basicPayAmount = $this->extractBasicPayAmount($employeeInfo);

        $allowances = $request->input('allowances', []);
        $deductions = $request->input('deductions', []);

        if (is_string($allowances)) $allowances = json_decode($allowances, true);
        if (is_string($deductions)) $deductions = json_decode($deductions, true);

        $allowances = is_array($allowances) ? array_values($allowances) : [];
        $deductions = is_array($deductions) ? array_values($deductions) : [];

        // Check if percentage calculation is used
        $hasPercentageWithBasic = false;
        foreach (array_merge($allowances, $deductions) as $item) {
            $calcType = strtolower($item['calcType'] ?? 'fixed');
            $fromBasic = !empty($item['fromBasic']);
            if ($calcType === 'percentage' && $fromBasic) {
                $hasPercentageWithBasic = true;
                break;
            }
        }

        if ($hasPercentageWithBasic && $basicPayAmount <= 0) {
            return response()->json([
                'status' => 500,
                'errors' => ["Calculation type is set to percentage from basic pay for one or more bonus components, but 'Basic pay' allowance is absent in employee salary setup."],
            ]);
        }

        $formatComponent = function ($item, $defaultType) use ($basicPayAmount) {
            $calcType = strtolower($item['calcType'] ?? 'fixed');
            $fromBasic = !empty($item['fromBasic']);
            $rawAmount = (float) ($item['amount'] ?? 0);
            $effective = ($calcType === 'percentage' && $fromBasic)
                ? ($basicPayAmount * ($rawAmount / 100.0))
                : (($calcType === 'percentage') ? ($basicPayAmount * ($rawAmount / 100.0)) : $rawAmount);

            return [
                'id' => isset($item['id']) ? (int) $item['id'] : null,
                'componentType' => $item['componentType'] ?? $defaultType,
                'calcType' => $calcType,
                'amount' => isset($item['amount']) ? (string) $item['amount'] : '0.00',
                'fromBasic' => $fromBasic,
                'isTaxable' => !empty($item['isTaxable']),
                'name' => $item['name'] ?? ($item['display_name'] ?? ucfirst($defaultType)),
                'display_name' => $item['display_name'] ?? null,
                'calculated_amount' => $effective,
                'ruleOver' => !empty($item['ruleOver']),
                'updated_at' => now()->toISOString(),
            ];
        };

        $cleanedAllowances = array_map(fn($item) => $formatComponent($item, 'allowance'), $allowances);
        $cleanedDeductions = array_map(fn($item) => $formatComponent($item, 'deduction'), $deductions);

        $totalAllowances = array_reduce($cleanedAllowances, fn($sum, $item) => $sum + (float) $item['calculated_amount'], 0.0);
        $totalDeductions = array_reduce($cleanedDeductions, fn($sum, $item) => $sum + (float) $item['calculated_amount'], 0.0);

        if ($totalAllowances < $totalDeductions) {
            return response()->json([
                'status' => 500,
                'errors' => ['Total bonus allowances cannot be less than total bonus deductions.'],
            ]);
        }

        $updateData = [
            'bonusAdditionObjects' => $cleanedAllowances,
            'bonusSubstractionObjects' => $cleanedDeductions,
        ];

        if ($request->has('bonus_group_id')) {
            $updateData['bonus_group_id'] = $request->bonus_group_id;
        }

        $employeeInfo->update($updateData);

        return response()->json([
            'status' => 200,
            'message' => 'Bonus calculation components updated successfully.',
            'employeeInfo' => $employeeInfo->fresh('bonusGroup'),
        ]);
    }

    /**
     * Assign a Bonus Group to single, selected, or all filtered employees.
     */
    public function assignBonusGroup(Request $request)
    {
        $request->validate([
            'bonus_group_id' => 'required|integer|exists:bonus_groups,id',
        ]);

        $bonusGroup = BonusGroup::findOrFail($request->bonus_group_id);
        $allFiltered = filter_var($request->input('all_filtered', false), FILTER_VALIDATE_BOOLEAN);
        $userIds = $request->input('user_ids', []);

        if ($allFiltered) {
            $query = $this->buildFilteredEmployeeQuery($request);
            $userIds = $query->pluck('id')->all();
        }

        if (empty($userIds) || !is_array($userIds)) {
            return response()->json([
                'status' => 422,
                'errors' => ['Please select at least one employee to assign the bonus group.'],
            ], 422);
        }

        $employees = EmployeeInfo::whereIn('user_id', $userIds)->get();
        $updatedCount = 0;

        foreach ($employees as $info) {
            $basicPayAmount = $this->extractBasicPayAmount($info);

            // Copy default objects from bonus group and compute initial amounts
            $allowances = $bonusGroup->bonusAllowanceObjects ?: [];
            $deductions = $bonusGroup->bonusDeductionObjects ?: [];

            $computedAllowances = array_map(function ($item) use ($basicPayAmount) {
                $calcType = strtolower($item['calcType'] ?? 'fixed');
                $fromBasic = !empty($item['fromBasic']);
                $rawAmount = (float) ($item['amount'] ?? 0);
                $effective = ($calcType === 'percentage' && $fromBasic)
                    ? ($basicPayAmount * ($rawAmount / 100.0))
                    : (($calcType === 'percentage') ? ($basicPayAmount * ($rawAmount / 100.0)) : $rawAmount);

                $item['calculated_amount'] = $effective;
                return $item;
            }, $allowances);

            $computedDeductions = array_map(function ($item) use ($basicPayAmount) {
                $calcType = strtolower($item['calcType'] ?? 'fixed');
                $fromBasic = !empty($item['fromBasic']);
                $rawAmount = (float) ($item['amount'] ?? 0);
                $effective = ($calcType === 'percentage' && $fromBasic)
                    ? ($basicPayAmount * ($rawAmount / 100.0))
                    : (($calcType === 'percentage') ? ($basicPayAmount * ($rawAmount / 100.0)) : $rawAmount);

                $item['calculated_amount'] = $effective;
                return $item;
            }, $deductions);

            $info->update([
                'bonus_group_id' => $bonusGroup->id,
                'bonusAdditionObjects' => $computedAllowances,
                'bonusSubstractionObjects' => $computedDeductions,
            ]);

            $updatedCount++;
        }

        return response()->json([
            'status' => 200,
            'message' => "Successfully assigned bonus group '{$bonusGroup->name}' to {$updatedCount} employee(s).",
            'updated_count' => $updatedCount,
        ]);
    }

    /**
     * Recalculate default bonus configuration from the employee's assigned bonus group.
     */
    public function calculateDefault(Request $request)
    {
        $userId = (int) $request->input('userId');
        if (!$userId) {
            return response()->json([
                'status' => 422,
                'errors' => ['User ID is required.'],
            ], 422);
        }

        $info = EmployeeInfo::with('bonusGroup')->where('user_id', $userId)->first();
        if (!$info) {
            return response()->json([
                'status' => 422,
                'errors' => ['Employee record not found.'],
            ], 422);
        }

        if (!$info->bonusGroup) {
            return response()->json([
                'status' => 422,
                'errors' => ['No bonus group assigned to this employee. Please assign a bonus group first.'],
            ], 422);
        }

        $basicPayAmount = $this->extractBasicPayAmount($info);
        $bonusGroup = $info->bonusGroup;

        $allowances = array_map(function ($item) use ($basicPayAmount) {
            $calcType = strtolower($item['calcType'] ?? 'fixed');
            $fromBasic = !empty($item['fromBasic']);
            $rawAmount = (float) ($item['amount'] ?? 0);
            $effective = ($calcType === 'percentage' && $fromBasic)
                ? ($basicPayAmount * ($rawAmount / 100.0))
                : (($calcType === 'percentage') ? ($basicPayAmount * ($rawAmount / 100.0)) : $rawAmount);

            $item['calculated_amount'] = $effective;
            return $item;
        }, $bonusGroup->bonusAllowanceObjects ?: []);

        $deductions = array_map(function ($item) use ($basicPayAmount) {
            $calcType = strtolower($item['calcType'] ?? 'fixed');
            $fromBasic = !empty($item['fromBasic']);
            $rawAmount = (float) ($item['amount'] ?? 0);
            $effective = ($calcType === 'percentage' && $fromBasic)
                ? ($basicPayAmount * ($rawAmount / 100.0))
                : (($calcType === 'percentage') ? ($basicPayAmount * ($rawAmount / 100.0)) : $rawAmount);

            $item['calculated_amount'] = $effective;
            return $item;
        }, $bonusGroup->bonusDeductionObjects ?: []);

        $info->update([
            'bonusAdditionObjects' => $allowances,
            'bonusSubstractionObjects' => $deductions,
        ]);

        return response()->json([
            'status' => 200,
            'message' => "Bonus components reset to default from group '{$bonusGroup->name}' successfully.",
            'employeeInfo' => $info->fresh('bonusGroup'),
        ]);
    }

    /**
     * Send estimated bonus breakdown email for a single employee.
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
            'employeeInfo.bonusGroup',
            'departmentOffices',
            'religion',
        ]);

        $hasConfiguredBonus = !empty($user->employeeInfo?->bonusAdditionObjects) || !empty($user->employeeInfo?->bonus_group_id);
        if (!$hasConfiguredBonus) {
            return response()->json([
                'status' => 422,
                'errors' => ["Employee {$user->name} has pending bonus setup. Please configure bonus before sending review."],
            ], 422);
        }

        $breakdown = $this->buildBonusBreakdownForUser($user);

        try {
            Mail::to($user->email)->send(
                new BonusEstimationReviewMail($user, $breakdown)
            );

            return response()->json([
                'status' => 200,
                'message' => "Estimated bonus breakdown sent to {$user->name} ({$user->email}) successfully.",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 500,
                'errors' => ["Failed to send email to {$user->email}: " . $e->getMessage()],
            ], 500);
        }
    }

    /**
     * Send estimated bonus breakdown email in bulk.
     */
    public function sendBulkReview(Request $request)
    {
        $userIds = $request->input('user_ids', []);
        $allFiltered = filter_var($request->input('all_filtered', false), FILTER_VALIDATE_BOOLEAN);

        $query = User::whereHas('roles', function ($q) {
            $q->where('role_name', 'user');
        })->whereNotNull('email')->where('email', '!=', '')
        ->whereHas('employeeInfo', function ($q) {
            $q->whereNotNull('bonus_group_id')
              ->orWhere(function ($sub) {
                  $sub->whereNotNull('bonusAdditionObjects')
                      ->where('bonusAdditionObjects', '!=', '[]')
                      ->where('bonusAdditionObjects', '!=', '');
              });
        });

        if ($allFiltered) {
            $query = $this->buildFilteredEmployeeQuery($request);
            $query->whereNotNull('email')->where('email', '!=', '')
                  ->whereHas('employeeInfo', function ($q) {
                      $q->whereNotNull('bonus_group_id')
                        ->orWhere(function ($sub) {
                            $sub->whereNotNull('bonusAdditionObjects')
                                ->where('bonusAdditionObjects', '!=', '[]')
                                ->where('bonusAdditionObjects', '!=', '');
                        });
                  });
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
            'employeeInfo.bonusGroup',
            'departmentOffices',
            'religion',
        ])->get();

        if ($users->isEmpty()) {
            return response()->json([
                'status' => 422,
                'errors' => ['No eligible employees with configured bonus setup and email addresses found.'],
            ], 422);
        }

        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($users as $user) {
            try {
                $breakdown = $this->buildBonusBreakdownForUser($user);
                Mail::to($user->email)->send(
                    new BonusEstimationReviewMail($user, $breakdown)
                );
                $successCount++;
            } catch (\Throwable $e) {
                $failedCount++;
                $errors[] = "Failed for {$user->name}: " . $e->getMessage();
            }
        }

        return response()->json([
            'status' => 200,
            'message' => "Estimated bonus breakdown review email dispatched to {$successCount} employees." . ($failedCount > 0 ? " ({$failedCount} failed)" : ''),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'errors' => $errors,
        ]);
    }

    /**
     * Helper to build filtered employee query matching UI filters.
     */
    private function buildFilteredEmployeeQuery(Request $request)
    {
        $query = User::whereHas('roles', function ($q) {
            $q->where('role_name', 'user');
        })->with([
            'employeeInfo.payScale',
            'employeeInfo.payrollGroup.grade',
            'employeeInfo.payrollGroup.payrollGroupType',
            'employeeInfo.departmentOffice',
            'employeeInfo.bonusGroup',
            'departmentOffices',
            'userCategories',
            'joiningDate',
            'religion',
            'bankAccounts',
            'phoneNumbers',
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
            $query->whereHas('employeeInfo', function ($q) use ($scaleId) {
                $q->where('pay_scale_id', $scaleId);
            });
        }

        // 4. Grades filter
        if ($request->filled('grade_id')) {
            $gradeId = $request->input('grade_id');
            $query->whereHas('employeeInfo.payrollGroup', function ($q) use ($gradeId) {
                $q->where('grade_id', $gradeId);
            });
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

        // 7. Designation filter
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

        // 8. Joining Date filter
        if ($request->filled('joining_date')) {
            $joiningDate = $request->input('joining_date');
            $query->whereHas('joiningDate', function ($q) use ($joiningDate) {
                $q->whereDate('joining_date', $joiningDate);
            });
        }

        // 9. Religion filter
        if ($request->filled('religion')) {
            $religion = $request->input('religion');
            $query->whereHas('religion', function ($q) use ($religion) {
                $q->where('religion', $religion);
            });
        }

        // 10. Bonus Group filter
        if ($request->filled('bonus_group_id')) {
            $bonusGroupId = $request->input('bonus_group_id');
            if ($bonusGroupId === 'unassigned') {
                $query->whereHas('employeeInfo', function ($q) {
                    $q->whereNull('bonus_group_id');
                });
            } else {
                $query->whereHas('employeeInfo', function ($q) use ($bonusGroupId) {
                    $q->where('bonus_group_id', $bonusGroupId);
                });
            }
        }

        // 11. Department filter
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

        // Universal search (name, email, code)
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('employeeInfo', function ($sub) use ($search) {
                        $sub->where('employeeCode', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    /**
     * Helper to extract basic pay amount from employee's salary additionObjects.
     */
    private function extractBasicPayAmount(?EmployeeInfo $info): float
    {
        if (!$info || empty($info->additionObjects)) {
            return 0.0;
        }

        $additions = $this->parseJsonArray($info->additionObjects);
        foreach ($additions as $item) {
            $name = strtolower($item['name'] ?? $item['payroll_component_type']['name'] ?? '');
            if (str_contains($name, 'basic')) {
                return (float) ($item['amount'] ?? 0);
            }
        }

        return 0.0;
    }

    /**
     * Helper to safely decode json array.
     */
    private function parseJsonArray($data): array
    {
        if (empty($data)) return [];
        if (is_array($data)) return $data;
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * Helper to construct bonus breakdown for email.
     */
    private function buildBonusBreakdownForUser(User $user): array
    {
        $info = $user->employeeInfo;
        $basicPay = $this->extractBasicPayAmount($info);

        $allowances = $this->parseJsonArray($info?->bonusAdditionObjects);
        $deductions = $this->parseJsonArray($info?->bonusSubstractionObjects);

        if (empty($allowances) && empty($deductions) && $info?->bonusGroup) {
            $allowances = $info->bonusGroup->bonusAllowanceObjects ?: [];
            $deductions = $info->bonusGroup->bonusDeductionObjects ?: [];
        }

        $calcEffective = function (&$list) use ($basicPay) {
            $total = 0.0;
            foreach ($list as &$item) {
                $calcType = strtolower($item['calcType'] ?? 'fixed');
                $fromBasic = !empty($item['fromBasic']);
                $rawAmt = (float) ($item['amount'] ?? 0);
                $effective = ($calcType === 'percentage' && $fromBasic)
                    ? ($basicPay * ($rawAmt / 100.0))
                    : (($calcType === 'percentage') ? ($basicPay * ($rawAmt / 100.0)) : $rawAmt);
                $item['calculated_amount'] = $effective;
                $total += $effective;
            }
            unset($item);
            return $total;
        };

        $totalAllowance = $calcEffective($allowances);
        $totalDeduction = $calcEffective($deductions);
        $netBonus = $totalAllowance - $totalDeduction;

        $dept = $info?->departmentOffice?->name 
            ?? $user->departmentOffices->first()?->name 
            ?? 'N/A';

        $designation = $info?->payrollGroup?->payrollGroupType?->group_name ?? 'N/A';
        $religionName = $user->religion?->religion ?? 'N/A';
        $bonusGroupName = $info?->bonusGroup?->name ?? 'N/A';

        return [
            'employee_code' => $info?->employeeCode ?? 'N/A',
            'department' => $dept,
            'designation' => $designation,
            'religion' => $religionName,
            'bonus_group' => $bonusGroupName,
            'basic_pay' => $basicPay,
            'allowances' => $allowances,
            'deductions' => $deductions,
            'total_allowance' => $totalAllowance,
            'total_deduction' => $totalDeduction,
            'net_bonus' => $netBonus,
        ];
    }
}
