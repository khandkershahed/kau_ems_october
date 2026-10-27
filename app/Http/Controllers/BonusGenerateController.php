<?php

namespace App\Http\Controllers;

use App\Mail\BonusBillReviewMail;
use App\Models\BonusGenerate;
use App\Models\BonusGroup;
use App\Models\DepartmentOffice;
use App\Models\EmployeeInfo;
use App\Models\Grade;
use App\Models\PayrollGroup;
use App\Models\PayrollGroupType;
use App\Models\PayScale;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class BonusGenerateController extends Controller
{
    /**
     * Display a listing of employee bonus bills with search and filters,
     * or return the Inertia view.
     */
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $monthId = (int) $request->input('monthId', date('n'));
            $year = (int) $request->input('bonusGenerationYear', date('Y'));
            $bonusGroupId = $request->input('bonus_group_id', 'all');
            $bonusGeneratedStatus = $request->input('bonusGeneratedStatus') 
                ?? $request->input('generatedStatus') 
                ?? $request->input('salaryGeneratedStatus') 
                ?? 'all'; // 'all', 'generated', 'not_generated'
            $reviewStatus = $request->input('reviewStatus', 'all'); // 'all', 'pending', 'review', 'approved'

            $baseStaffQuery = $this->buildFilteredEmployeeQuery($request);

            // Filter base staff by assigned bonus group if specified
            if ($bonusGroupId !== 'all' && !empty($bonusGroupId)) {
                $baseStaffQuery->whereHas('employeeInfo', function ($q) use ($bonusGroupId) {
                    $q->where('bonus_group_id', $bonusGroupId);
                });
            }

            // Summary stats across matching staff before bill status filters
            $totalCount = (clone $baseStaffQuery)->count();
            $generatedCount = (clone $baseStaffQuery)->whereHas('bonusGenerates', function ($q) use ($monthId, $year, $bonusGroupId) {
                $q->where('monthId', $monthId)->where('bonusGenerationYear', $year);
                if ($bonusGroupId !== 'all' && !empty($bonusGroupId)) {
                    $q->where('bonus_group_id', $bonusGroupId);
                }
            })->count();
            $notGeneratedCount = $totalCount - $generatedCount;

            $query = clone $baseStaffQuery;

            // Filter based on bonus_generates table data
            if ($bonusGeneratedStatus === 'generated') {
                $query->whereHas('bonusGenerates', function ($q) use ($monthId, $year, $bonusGroupId) {
                    $q->where('monthId', $monthId)->where('bonusGenerationYear', $year);
                    if ($bonusGroupId !== 'all' && !empty($bonusGroupId)) {
                        $q->where('bonus_group_id', $bonusGroupId);
                    }
                });
            } elseif ($bonusGeneratedStatus === 'not_generated' || $bonusGeneratedStatus === 'not_generate') {
                $query->whereDoesntHave('bonusGenerates', function ($q) use ($monthId, $year, $bonusGroupId) {
                    $q->where('monthId', $monthId)->where('bonusGenerationYear', $year);
                    if ($bonusGroupId !== 'all' && !empty($bonusGroupId)) {
                        $q->where('bonus_group_id', $bonusGroupId);
                    }
                });
            }

            if ($reviewStatus !== 'all' && !empty($reviewStatus)) {
                $query->whereHas('bonusGenerates', function ($q) use ($monthId, $year, $bonusGroupId, $reviewStatus) {
                    $q->where('monthId', $monthId)
                        ->where('bonusGenerationYear', $year)
                        ->where('reviewStatus', $reviewStatus);
                    if ($bonusGroupId !== 'all' && !empty($bonusGroupId)) {
                        $q->where('bonus_group_id', $bonusGroupId);
                    }
                });
            }

            // Eager load relational data
            $query->with([
                'employeeInfo.payScale',
                'employeeInfo.payrollGroup.grade',
                'employeeInfo.payrollGroup.payrollGroupType',
                'employeeInfo.departmentOffice',
                'employeeInfo.bonusGroup',
                'departmentOffices',
                'userCategories',
                'joiningDate',
                'bankAccounts',
                'phoneNumbers',
                'religion',
                'bonusGenerates' => function ($q) use ($monthId, $year, $bonusGroupId) {
                    $q->where('monthId', $monthId)->where('bonusGenerationYear', $year);
                    if ($bonusGroupId !== 'all' && !empty($bonusGroupId)) {
                        $q->where('bonus_group_id', $bonusGroupId);
                    }
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
            $paginator = $query->paginate($perPage);

            // Transform records into unified structure
            $transformedData = $paginator->getCollection()->map(function ($user) use ($monthId, $year) {
                $bonusRecord = $user->bonusGenerates->first();
                $isGenerated = !is_null($bonusRecord);

                $employeeInfo = $user->employeeInfo;
                $dept = $employeeInfo?->departmentOffice?->name 
                    ?? $user->departmentOffices->first()?->name 
                    ?? 'N/A';
                $category = $user->userCategories->first()?->name ?? 'General';
                $designation = $employeeInfo?->payrollGroup?->payrollGroupType?->group_name ?? 'N/A';
                $scaleName = $employeeInfo?->payScale?->scale_name ?? 'N/A';
                $gradeName = $employeeInfo?->payrollGroup?->grade?->grade_name ?? 'N/A';
                $phone = $user->phoneNumbers->first()?->phoneNumber ?? 'N/A';
                $religionName = $user->religion?->religion ?? 'N/A';
                $bonusGroupName = $employeeInfo?->bonusGroup?->name ?? 'Unassigned';

                // Calculate estimated amounts from employeeInfo if not generated yet
                $estAdditions = 0.0;
                $estDeductions = 0.0;

                if (!$isGenerated && $employeeInfo) {
                    $basicPayAmount = $this->extractBasicPayAmount($employeeInfo);

                    $additions = $this->parseJsonArray($employeeInfo->bonusAdditionObjects);
                    $deductions = $this->parseJsonArray($employeeInfo->bonusSubstractionObjects);

                    if (empty($additions) && empty($deductions) && $employeeInfo->bonusGroup) {
                        $additions = $employeeInfo->bonusGroup->bonusAllowanceObjects ?: [];
                        $deductions = $employeeInfo->bonusGroup->bonusDeductionObjects ?: [];
                    }

                    foreach ($additions as $a) {
                        $calcType = strtolower($a['calcType'] ?? 'fixed');
                        $fromBasic = !empty($a['fromBasic']);
                        $rawAmt = (float) ($a['amount'] ?? 0);
                        $estAdditions += ($calcType === 'percentage' && $fromBasic)
                            ? ($basicPayAmount * ($rawAmt / 100.0))
                            : (($calcType === 'percentage') ? ($basicPayAmount * ($rawAmt / 100.0)) : $rawAmt);
                    }

                    foreach ($deductions as $d) {
                        $calcType = strtolower($d['calcType'] ?? 'fixed');
                        $fromBasic = !empty($d['fromBasic']);
                        $rawAmt = (float) ($d['amount'] ?? 0);
                        $estDeductions += ($calcType === 'percentage' && $fromBasic)
                            ? ($basicPayAmount * ($rawAmt / 100.0))
                            : (($calcType === 'percentage') ? ($basicPayAmount * ($rawAmt / 100.0)) : $rawAmt);
                    }
                }

                return [
                    'user_id' => $user->id,
                    'bonus_generate_id' => $bonusRecord?->id,
                    'employee_code' => $employeeInfo?->employeeCode ?? 'N/A',
                    'name' => $user->name,
                    'email' => $user->email,
                    'gender' => $user->gender,
                    'is_active' => (bool) $user->isActive,
                    'job_status' => $employeeInfo?->jobStatus ?? 'N/A',
                    'department' => $dept,
                    'user_category' => $category,
                    'designation' => $designation,
                    'pay_scale' => $scaleName,
                    'grade' => $gradeName,
                    'phone' => $phone,
                    'religion' => $religionName,
                    'bonus_group' => $bonusGroupName,
                    'bonus_title' => $bonusRecord?->bonus_title ?? $bonusGroupName,
                    'month_id' => $monthId,
                    'year' => $year,
                    'bill_status' => $isGenerated ? 'generated' : 'not generated',
                    'review_status' => $bonusRecord?->reviewStatus ?? null,
                    'total_allowance' => $isGenerated ? (float) $bonusRecord->totalAllowance : $estAdditions,
                    'total_deduction' => $isGenerated ? (float) $bonusRecord->totalDeduction : $estDeductions,
                    'tax_deduction' => $isGenerated ? (float) $bonusRecord->taxDeduction : 0.0,
                    'net_bonus' => $isGenerated ? (float) $bonusRecord->netBonus : ($estAdditions - $estDeductions),
                    'bonus_generation_date' => $bonusRecord?->bonusGenerationDate?->format('Y-m-d'),
                    'bonus_generate' => $bonusRecord,
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
        return Inertia::render('Bonus/GenerateBills/Index', [
            'payScales' => PayScale::select('id', 'scale_name')->where('isActive', true)->orderBy('scale_name')->get(),
            'grades' => Grade::with('payScale:id,scale_name')->where('isActive', true)->orderBy('grade_name')->get(),
            'payrollGroupTypes' => PayrollGroupType::select('id', 'group_name')->orderBy('group_name')->get(),
            'payrollGroups' => PayrollGroup::with(['grade:id,grade_name,pay_scale_id', 'payrollGroupType:id,group_name'])->where('isActive', true)->get(),
            'userCategories' => UserCategory::select('id', 'name')->orderBy('name')->get(),
            'departmentOffices' => DepartmentOffice::select('id', 'name', 'type')->where('isActive', true)->orderBy('name')->get(),
            'bonusGroups' => BonusGroup::select('id', 'name', 'description')->orderBy('name')->get(),
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
     * Validate and dynamically calculate bonus components for an employee.
     *
     * Returns array with 'valid' (bool), 'error' (?string), and 'data' (?array).
     */
    public function calculateEmployeeBonus(User $user, ?int $bonusGroupId = null): array
    {
        // 1. Check user active status
        if (!userIsActive($user->id)) {
            return [
                'valid' => false,
                'error' => "Employee '{$user->name}' is inactive or disabled.",
                'data' => null,
            ];
        }

        // 2. Check payroll info
        if (!userHasPayrollInfo($user->id)) {
            return [
                'valid' => false,
                'error' => "Employee '{$user->name}' does not have complete payroll information.",
                'data' => null,
            ];
        }

        // 3. Check EmployeeInfo record
        $employeeInfo = EmployeeInfo::with([
            'bonusGroup',
            'payScale',
            'payrollGroup.grade',
            'payrollGroup.payrollGroupType',
            'departmentOffice',
        ])->where('user_id', $user->id)->first();

        if (!$employeeInfo) {
            return [
                'valid' => false,
                'error' => "Payroll record not found for employee '{$user->name}'.",
                'data' => null,
            ];
        }

        // 4. Check bonus configuration / assigned group
        $hasBonusConfig = !empty($employeeInfo->bonusAdditionObjects) || !empty($employeeInfo->bonus_group_id);
        if (!$hasBonusConfig && empty($bonusGroupId)) {
            return [
                'valid' => false,
                'error' => "Employee '{$user->name}' has no bonus group assigned or bonus components configured. Please configure in Bonus Estimation first.",
                'data' => null,
            ];
        }

        // 5. Target bonus group
        $targetBonusGroup = null;
        if (!empty($bonusGroupId)) {
            $targetBonusGroup = BonusGroup::find($bonusGroupId);
        }
        if (!$targetBonusGroup) {
            $targetBonusGroup = $employeeInfo->bonusGroup;
        }

        $basicPayAmount = $this->extractBasicPayAmount($employeeInfo);

        $additionObjects = $this->parseJsonArray($employeeInfo->bonusAdditionObjects);
        $substractionObjects = $this->parseJsonArray($employeeInfo->bonusSubstractionObjects);

        if (empty($additionObjects) && empty($substractionObjects) && $targetBonusGroup) {
            $additionObjects = $targetBonusGroup->bonusAllowanceObjects ?: [];
            $substractionObjects = $targetBonusGroup->bonusDeductionObjects ?: [];
        }

        if (empty($additionObjects) && empty($substractionObjects)) {
            return [
                'valid' => false,
                'error' => "Employee '{$user->name}' has no bonus components configured.",
                'data' => null,
            ];
        }

        // 6. Check if percentage calculation is used and verify basic pay
        $hasPercentageWithBasic = false;
        foreach (array_merge($additionObjects, $substractionObjects) as $item) {
            $calcType = strtolower($item['calcType'] ?? 'fixed');
            $fromBasic = !empty($item['fromBasic']);
            if ($calcType === 'percentage' && ($fromBasic || !isset($item['fromBasic']))) {
                $hasPercentageWithBasic = true;
                break;
            }
        }

        if ($hasPercentageWithBasic && $basicPayAmount <= 0) {
            return [
                'valid' => false,
                'error' => "Calculation failed for employee '{$user->name}': bonus component(s) require percentage calculation from Basic pay, but 'Basic pay' allowance is absent or zero in salary setup.",
                'data' => null,
            ];
        }

        // 7. Dynamic calculation of allowances
        $totalAllowance = 0.0;
        foreach ($additionObjects as &$item) {
            $calcType = strtolower($item['calcType'] ?? 'fixed');
            $fromBasic = !empty($item['fromBasic']);
            $rawAmt = (float) ($item['amount'] ?? 0);
            $amt = ($calcType === 'percentage' && $fromBasic)
                ? ($basicPayAmount * ($rawAmt / 100.0))
                : (($calcType === 'percentage') ? ($basicPayAmount * ($rawAmt / 100.0)) : $rawAmt);

            if (is_nan($amt) || is_infinite($amt)) {
                return [
                    'valid' => false,
                    'error' => "Calculation resulted in an invalid number for employee '{$user->name}' on allowance component '{$item['name']}'.",
                    'data' => null,
                ];
            }

            $item['calculated_amount'] = $amt;
            $totalAllowance += $amt;
        }
        unset($item);

        // 8. Dynamic calculation of deductions
        $totalDeduction = 0.0;
        $taxDeduction = 0.0;
        foreach ($substractionObjects as &$item) {
            $calcType = strtolower($item['calcType'] ?? 'fixed');
            $fromBasic = !empty($item['fromBasic']);
            $rawAmt = (float) ($item['amount'] ?? 0);
            $amt = ($calcType === 'percentage' && $fromBasic)
                ? ($basicPayAmount * ($rawAmt / 100.0))
                : (($calcType === 'percentage') ? ($basicPayAmount * ($rawAmt / 100.0)) : $rawAmt);

            if (is_nan($amt) || is_infinite($amt)) {
                return [
                    'valid' => false,
                    'error' => "Calculation resulted in an invalid number for employee '{$user->name}' on deduction component '{$item['name']}'.",
                    'data' => null,
                ];
            }

            $item['calculated_amount'] = $amt;
            $totalDeduction += $amt;

            $name = strtolower($item['name'] ?? $item['display_name'] ?? '');
            if (str_contains($name, 'tax') || !empty($item['isTaxable'])) {
                $taxDeduction += $amt;
            }
        }
        unset($item);

        $netBonus = $totalAllowance - $totalDeduction;
        $bonusTitle = $targetBonusGroup?->name ?: 'Festival Bonus';

        return [
            'valid' => true,
            'error' => null,
            'data' => [
                'employeeInfo' => $employeeInfo,
                'targetBonusGroup' => $targetBonusGroup,
                'basicPayAmount' => $basicPayAmount,
                'additionObjects' => $additionObjects,
                'substractionObjects' => $substractionObjects,
                'totalAllowance' => $totalAllowance,
                'totalDeduction' => $totalDeduction,
                'taxDeduction' => $taxDeduction,
                'netBonus' => $netBonus,
                'bonusTitle' => $bonusTitle,
            ],
        ];
    }

    /**
     * Generate bonus bill for a single employee.
     */
    public function generateBonusForEmployee(Request $request)
    {
        $userId = (int) ($request->input('userId') ?? $request->input('user_id'));
        $monthId = (int) ($request->input('monthId') ?? date('n'));
        $year = (int) ($request->input('bonusGenerationYear') ?? date('Y'));
        $bonusDate = $request->input('bonusGenerationDate') ?? date('Y-m-d');
        $bonusGroupId = $request->input('bonus_group_id');
        $effectiveGroupId = ($bonusGroupId !== 'all' && !empty($bonusGroupId)) ? (int) $bonusGroupId : null;

        if (!$userId) {
            return response()->json([
                'status' => 422,
                'errors' => ['User ID is required.'],
            ], 422);
        }

        $user = User::with([
            'age',
            'joiningDate',
            'bankAccounts',
            'phoneNumbers',
            'departmentOffices',
            'userCategories',
            'religion',
        ])->find($userId);

        if (!$user) {
            return response()->json([
                'status' => 422,
                'errors' => ['Employee user not found.'],
            ], 422);
        }

        $calc = $this->calculateEmployeeBonus($user, $effectiveGroupId);
        if (!$calc['valid']) {
            return response()->json([
                'status' => 422,
                'message' => $calc['error'],
                'errors' => [$calc['error']],
            ], 422);
        }

        $calcData = $calc['data'];
        $employeeInfo = $calcData['employeeInfo'];
        $targetBonusGroup = $calcData['targetBonusGroup'];

        $bankAccount = $user->bankAccounts->where('isSalaryAccount', true)->first() 
            ?? $user->bankAccounts->first();
        $phoneNumber = $user->phoneNumbers->first()?->phoneNumber ?? 'N/A';
        $religionName = $user->religion?->religion ?? 'N/A';

        $recordData = [
            'user_id' => $user->id,
            'bonus_group_id' => $targetBonusGroup?->id,
            'reviewStatus' => 'pending',
            // employee info
            'name' => $user->name,
            'age' => (string) ($user->age?->age ?? 'N/A'),
            'gender' => (string) ($user->gender ?? 'N/A'),
            'payScale' => (string) ($employeeInfo->payScale?->scale_name ?? 'N/A'),
            'grade' => (string) ($employeeInfo->payrollGroup?->grade?->grade_name ?? 'N/A'),
            'designation' => (string) ($employeeInfo->payrollGroup?->payrollGroupType?->group_name ?? 'N/A'),
            'employeeCode' => (string) ($employeeInfo->employeeCode ?? 'N/A'),
            'bankAccountNo' => (string) ($bankAccount?->account_no ?? 'N/A'),
            'bankAddress' => (string) ($bankAccount?->address ?? ($bankAccount?->bank_name ?? 'N/A')),
            'employeePhoneNumber' => (string) $phoneNumber,
            'joiningDate' => $user->joiningDate?->joining_date ?? now()->toDateString(),
            'religion' => (string) $religionName,

            // Bonus date details
            'bonusGenerationDate' => $bonusDate,
            'monthId' => $monthId,
            'bonusGenerationYear' => $year,
            'bonus_title' => $calcData['bonusTitle'],

            // Calculation objects
            'bonusAllowanceCalcObject' => $calcData['additionObjects'],
            'bonusDeductionCalcObject' => $calcData['substractionObjects'],

            // Totals
            'totalAllowance' => $calcData['totalAllowance'],
            'totalDeduction' => $calcData['totalDeduction'],
            'taxDeduction' => $calcData['taxDeduction'],
            'netBonus' => $calcData['netBonus'],
        ];

        $matchCriteria = [
            'user_id' => $user->id,
            'monthId' => $monthId,
            'bonusGenerationYear' => $year,
        ];

        if ($targetBonusGroup) {
            $matchCriteria['bonus_group_id'] = $targetBonusGroup->id;
        }

        $bonusGenerate = DB::transaction(function () use ($matchCriteria, $recordData) {
            return BonusGenerate::updateOrCreate($matchCriteria, $recordData);
        });

        return response()->json([
            'status' => 200,
            'message' => "Bonus bill successfully calculated and generated for {$user->name}.",
            'bonusGenerate' => $bonusGenerate,
        ]);
    }

    /**
     * Bulk generate bonus bills for multiple filtered users.
     * Validates dynamic calculations for ALL users first; stops immediately if any user fails.
     */
    public function generateBulk(Request $request)
    {
        $userIds = $request->input('userIds', []);
        $monthId = (int) ($request->input('monthId') ?? date('n'));
        $year = (int) ($request->input('bonusGenerationYear') ?? date('Y'));
        $bonusDate = $request->input('bonusGenerationDate') ?? date('Y-m-d');
        $bonusGroupId = $request->input('bonus_group_id');
        $effectiveGroupId = ($bonusGroupId !== 'all' && !empty($bonusGroupId)) ? (int) $bonusGroupId : null;

        if (empty($userIds) && $request->boolean('all_filtered_unbilled')) {
            $query = $this->buildFilteredEmployeeQuery($request);
            $query->whereDoesntHave('bonusGenerates', function ($q) use ($monthId, $year, $bonusGroupId) {
                $q->where('monthId', $monthId)->where('bonusGenerationYear', $year);
                if (!empty($bonusGroupId) && $bonusGroupId !== 'all') {
                    $q->where('bonus_group_id', $bonusGroupId);
                }
            });
            $userIds = $query->pluck('id')->all();
        }

        if (empty($userIds)) {
            return response()->json([
                'status' => 422,
                'errors' => ['No employees available for bonus bill generation.'],
            ], 422);
        }

        // -------------------------------------------------------------------------
        // 1. PRE-VALIDATION & DYNAMIC CALCULATION PHASE:
        // Calculate dynamic bonus for ALL target employees first.
        // If dynamic bonus calculation fails for ANY user, STOP immediately and show the error!
        // Do NOT proceed with partial generation.
        // -------------------------------------------------------------------------
        $users = User::with([
            'age',
            'joiningDate',
            'bankAccounts',
            'phoneNumbers',
            'departmentOffices',
            'userCategories',
            'religion',
        ])->whereIn('id', $userIds)->get()->keyBy('id');

        $validatedCalculations = [];
        $calculationErrors = [];

        foreach ($userIds as $uId) {
            $user = $users->get($uId);
            if (!$user) {
                $calculationErrors[] = "User #{$uId} not found.";
                continue;
            }

            $calc = $this->calculateEmployeeBonus($user, $effectiveGroupId);
            if (!$calc['valid']) {
                $calculationErrors[] = $calc['error'];
            } else {
                $validatedCalculations[$uId] = [
                    'user' => $user,
                    'calc' => $calc['data'],
                ];
            }
        }

        // If ANY employee calculation fails, STOP and return error immediately before generating any bill!
        if (!empty($calculationErrors)) {
            return response()->json([
                'status' => 422,
                'message' => 'Dynamic bonus calculation failed for one or more employees. Generation stopped to prevent errors.',
                'errors' => $calculationErrors,
            ], 422);
        }

        // -------------------------------------------------------------------------
        // 2. GENERATION PHASE (Atomic via DB Transaction):
        // All calculations succeeded. Now safely generate all bills.
        // -------------------------------------------------------------------------
        $successCount = 0;

        DB::transaction(function () use ($validatedCalculations, $monthId, $year, $bonusDate, &$successCount) {
            foreach ($validatedCalculations as $uId => $item) {
                $user = $item['user'];
                $calcData = $item['calc'];
                $employeeInfo = $calcData['employeeInfo'];
                $targetBonusGroup = $calcData['targetBonusGroup'];

                $bankAccount = $user->bankAccounts->where('isSalaryAccount', true)->first() 
                    ?? $user->bankAccounts->first();
                $phoneNumber = $user->phoneNumbers->first()?->phoneNumber ?? 'N/A';
                $religionName = $user->religion?->religion ?? 'N/A';

                $recordData = [
                    'user_id' => $user->id,
                    'bonus_group_id' => $targetBonusGroup?->id,
                    'reviewStatus' => 'pending',
                    // employee info
                    'name' => $user->name,
                    'age' => (string) ($user->age?->age ?? 'N/A'),
                    'gender' => (string) ($user->gender ?? 'N/A'),
                    'payScale' => (string) ($employeeInfo->payScale?->scale_name ?? 'N/A'),
                    'grade' => (string) ($employeeInfo->payrollGroup?->grade?->grade_name ?? 'N/A'),
                    'designation' => (string) ($employeeInfo->payrollGroup?->payrollGroupType?->group_name ?? 'N/A'),
                    'employeeCode' => (string) ($employeeInfo->employeeCode ?? 'N/A'),
                    'bankAccountNo' => (string) ($bankAccount?->account_no ?? 'N/A'),
                    'bankAddress' => (string) ($bankAccount?->address ?? ($bankAccount?->bank_name ?? 'N/A')),
                    'employeePhoneNumber' => (string) $phoneNumber,
                    'joiningDate' => $user->joiningDate?->joining_date ?? now()->toDateString(),
                    'religion' => (string) $religionName,

                    // Bonus date details
                    'bonusGenerationDate' => $bonusDate,
                    'monthId' => $monthId,
                    'bonusGenerationYear' => $year,
                    'bonus_title' => $calcData['bonusTitle'],

                    // Calculation objects
                    'bonusAllowanceCalcObject' => $calcData['additionObjects'],
                    'bonusDeductionCalcObject' => $calcData['substractionObjects'],

                    // Totals
                    'totalAllowance' => $calcData['totalAllowance'],
                    'totalDeduction' => $calcData['totalDeduction'],
                    'taxDeduction' => $calcData['taxDeduction'],
                    'netBonus' => $calcData['netBonus'],
                ];

                $matchCriteria = [
                    'user_id' => $user->id,
                    'monthId' => $monthId,
                    'bonusGenerationYear' => $year,
                ];

                if ($targetBonusGroup) {
                    $matchCriteria['bonus_group_id'] = $targetBonusGroup->id;
                }

                BonusGenerate::updateOrCreate(
                    $matchCriteria,
                    $recordData
                );

                $successCount++;
            }
        });

        return response()->json([
            'status' => 200,
            'success_count' => $successCount,
            'failed_count' => 0,
            'errors' => [],
            'message' => "Bonus bills successfully generated for {$successCount} employees.",
        ]);
    }

    /**
     * Show single generated bonus bill details.
     */
    public function show(BonusGenerate $bonusGenerate)
    {
        $bonusGenerate->load([
            'user.employeeInfo.payScale',
            'user.employeeInfo.payrollGroup.grade',
            'user.employeeInfo.departmentOffice',
            'user.bankAccounts',
            'user.phoneNumbers',
            'user.departmentOffices',
            'user.userCategories',
            'bonusGroup',
        ]);

        return response()->json($bonusGenerate);
    }

    /**
     * Send bonus bill review email and update reviewStatus to 'review'.
     */
    public function sendReview(Request $request, BonusGenerate $bonusGenerate)
    {
        $bonusGenerate->load('user');
        $email = $bonusGenerate->user?->email;

        if (empty($email)) {
            return response()->json([
                'status' => 422,
                'errors' => ["Employee {$bonusGenerate->name} does not have an email address configured."],
            ], 422);
        }

        try {
            Mail::to($email)->send(new BonusBillReviewMail($bonusGenerate));

            $bonusGenerate->update([
                'reviewStatus' => 'review',
            ]);

            return response()->json([
                'status' => 200,
                'message' => "Bonus bill review email sent to {$bonusGenerate->name} ({$email}) successfully.",
                'bonusGenerate' => $bonusGenerate->fresh(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 500,
                'errors' => ["Failed to send email to {$email}: " . $e->getMessage()],
            ], 500);
        }
    }

    /**
     * Send bonus bill review email in bulk.
     */
    public function sendBulkReview(Request $request)
    {
        $ids = $request->input('bonus_generate_ids', []);
        $allFiltered = filter_var($request->input('all_filtered', false), FILTER_VALIDATE_BOOLEAN);

        $monthId = (int) $request->input('monthId', date('n'));
        $year = (int) $request->input('bonusGenerationYear', date('Y'));
        $bonusGroupId = $request->input('bonus_group_id');

        if ($allFiltered) {
            $empQuery = $this->buildFilteredEmployeeQuery($request);
            $userIds = $empQuery->pluck('users.id')->toArray();

            $billsQuery = BonusGenerate::with('user')
                ->where('monthId', $monthId)
                ->where('bonusGenerationYear', $year)
                ->whereIn('user_id', $userIds);

            if (!empty($bonusGroupId) && $bonusGroupId !== 'all') {
                $billsQuery->where('bonus_group_id', $bonusGroupId);
            }

            $bills = $billsQuery->get();
        } else {
            if (empty($ids) || !is_array($ids)) {
                return response()->json([
                    'status' => 422,
                    'errors' => ['Please select at least one generated bonus bill to send review email.'],
                ], 422);
            }

            $bills = BonusGenerate::with('user')->whereIn('id', $ids)->get();
        }

        if ($bills->isEmpty()) {
            return response()->json([
                'status' => 422,
                'errors' => ['No generated bonus bills found for review dispatch.'],
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
                Mail::to($email)->send(new BonusBillReviewMail($bill));
                $bill->update(['reviewStatus' => 'review']);
                $successCount++;
            } catch (\Throwable $e) {
                $failedCount++;
                $errors[] = "Failed for {$bill->name}: " . $e->getMessage();
            }
        }

        return response()->json([
            'status' => 200,
            'message' => "Bonus bill review email dispatched to {$successCount} employees." . ($failedCount > 0 ? " ({$failedCount} failed)" : ''),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'errors' => $errors,
        ]);
    }

    /**
     * Override review status for a single bonus bill (e.g. approved).
     */
    public function overrideReviewStatus(Request $request, BonusGenerate $bonusGenerate)
    {
        $newStatus = $request->input('reviewStatus', 'approved');
        if (!in_array($newStatus, ['approved', 'pending', 'review'])) {
            return response()->json([
                'status' => 422,
                'errors' => ['Invalid review status value.'],
            ], 422);
        }

        $bonusGenerate->update([
            'reviewStatus' => $newStatus,
        ]);

        return response()->json([
            'status' => 200,
            'message' => "Review status for {$bonusGenerate->name} updated to '{$newStatus}'.",
            'bonusGenerate' => $bonusGenerate->fresh(),
        ]);
    }

    /**
     * Override review status in bulk (e.g. approved).
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
        $ids = $request->input('bonus_generate_ids', []);
        $monthId = (int) $request->input('monthId', date('n'));
        $year = (int) $request->input('bonusGenerationYear', date('Y'));
        $bonusGroupId = $request->input('bonus_group_id');

        if ($allFiltered) {
            $empQuery = $this->buildFilteredEmployeeQuery($request);
            $userIds = $empQuery->pluck('users.id')->toArray();

            $billsQuery = BonusGenerate::where('monthId', $monthId)
                ->where('bonusGenerationYear', $year)
                ->whereIn('user_id', $userIds);

            if (!empty($bonusGroupId) && $bonusGroupId !== 'all') {
                $billsQuery->where('bonus_group_id', $bonusGroupId);
            }
        } else {
            if (empty($ids) || !is_array($ids)) {
                return response()->json([
                    'status' => 422,
                    'errors' => ['Please select at least one generated bonus bill to override status.'],
                ], 422);
            }

            $billsQuery = BonusGenerate::whereIn('id', $ids);
        }

        $updatedCount = $billsQuery->update([
            'reviewStatus' => $newStatus,
        ]);

        return response()->json([
            'status' => 200,
            'message' => "Review status for {$updatedCount} bonus record(s) updated to '{$newStatus}'.",
            'updated_count' => $updatedCount,
        ]);
    }

    /**
     * Helper to build employee base query with search and relational filters.
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

        // 14. Assigned Bonus Group filter on EmployeeInfo
        if ($request->filled('assigned_bonus_group_id')) {
            $bGroupId = $request->input('assigned_bonus_group_id');
            $query->whereHas('employeeInfo', function ($q) use ($bGroupId) {
                $q->where('bonus_group_id', $bGroupId);
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

    /**
     * Extract basic pay from employee salary additionObjects.
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
}
