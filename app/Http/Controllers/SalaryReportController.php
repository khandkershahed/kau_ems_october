<?php

namespace App\Http\Controllers;

use App\Models\DepartmentOffice;
use App\Models\EmployeeInfo;
use App\Models\Grade;
use App\Models\PayrollGroup;
use App\Models\PayrollGroupType;
use App\Models\PayScale;
use App\Models\SalaryGenerate;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SalaryReportController extends Controller
{
    /**
     * Display the Salary Reports page or return JSON report data.
     */
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $monthId = (int) $request->input('monthId', date('n'));
            $year = (int) $request->input('salaryGenerationYear', date('Y'));
            $reportType = $request->input('reportType', 'Bank Advice');
            $salaryGeneratedStatus = $request->input('salaryGeneratedStatus', 'all');

            $query = $this->buildReportQuery($request, $monthId, $year, $reportType);

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
                'age',
                'salaryGenerates' => function ($q) use ($monthId, $year) {
                    $q->where('monthId', $monthId)->where('salaryGenerationYear', $year);
                },
                'bonusGenerates' => function ($q) use ($monthId, $year) {
                    $q->where('monthId', $monthId)->where('bonusGenerationYear', $year);
                },
                'generalProvidentFunds' => function ($q) use ($monthId, $year) {
                    $q->where('month', $monthId)->where('year', $year);
                },
            ]);

            // Sorting
            $sortField = $request->input('sortField', 'id');
            $sortDirection = $request->input('sortDirection', 'asc');
            $allowedSort = ['id', 'name', 'created_at'];

            if (in_array($sortField, $allowedSort)) {
                $query->orderBy($sortField, $sortDirection === 'desc' ? 'desc' : 'asc');
            } else {
                $query->orderBy('id', 'asc');
            }

            $isExportAll = filter_var($request->input('all', false), FILTER_VALIDATE_BOOLEAN);

            if ($isExportAll) {
                $users = $query->get();
                $transformed = $this->transformUsers($users, $monthId, $year, $reportType);

                return response()->json([
                    'data' => $transformed,
                    'total' => $users->count(),
                    'summary' => $this->calculateSummary($transformed),
                ]);
            }

            $perPage = (int) $request->input('perPage', 15);
            $baseQuery = clone $query;
            $paginator = $query->paginate($perPage);
            $transformed = $this->transformUsers($paginator->getCollection(), $monthId, $year, $reportType);

            // Calculate overall summary stats across all matching rows
            $allMatchingUsers = $baseQuery->get();
            $allTransformed = $this->transformUsers($allMatchingUsers, $monthId, $year, $reportType);
            $summary = $this->calculateSummary($allTransformed);

            return response()->json([
                'data' => $transformed,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'summary' => $summary,
            ]);
        }

        // Return Inertia page with reference data for dropdowns
        return Inertia::render('Reports/SalaryReports/Index', [
            'reportTypes' => [
                'Bank Advice',
                'Salary Bill',
                'Salary Fixation Report',
                'Arrear & Deduction Statement',
                'Salary Summary',
                'Bonus Bill',
                'Bonus Bank Advice',
                // 'Earn Deduction',
                // 'GPF Personal Statement',
                // 'GPF Financial Personal Balance',
                // 'GPF Financial Statement',
                // 'GPF Summary',
            ],
            'payScales' => PayScale::select('id', 'scale_name')->where('isActive', true)->orderBy('scale_name')->get(),
            'grades' => Grade::with('payScale:id,scale_name')->where('isActive', true)->orderBy('grade_name')->get(),
            'payrollGroupTypes' => PayrollGroupType::select('id', 'group_name')->orderBy('group_name')->get(),
            'userCategories' => UserCategory::select('id', 'name')->orderBy('name')->get(),
            'departmentOffices' => DepartmentOffice::select('id', 'name', 'type')->where('isActive', true)->orderBy('name')->get(),
            'currentMonth' => (int) date('n'),
            'currentYear' => (int) date('Y'),
            'officialPdfReady' => filled(config('kau_official_reports.chromium_binary')),
            'genders' => [
                ['value' => 'male', 'label' => 'Male'],
                ['value' => 'female', 'label' => 'Female'],
                ['value' => 'other', 'label' => 'Other'],
            ],
            'jobStatuses' => [
                'permanent',
                'contract',
                'ad_hoc',
                'temporary',
                'others',

            ],
        ]);
    }

    /**
     * Build query with all filters.
     */
    private function buildReportQuery(Request $request, int $monthId, int $year, string $reportType = 'Bank Advice')
    {
        $isBonusReport = in_array(strtolower(trim($reportType)), ['bonus bill', 'bonus report', 'bonus repot', 'bonus bank advice'], true);

        // Original signed Fixation Statements can include any employee with an
        // EmployeeInfo profile; older Report Factory types keep their legacy
        // role scoping without modification.
        $query = User::whereHas('employeeInfo');
        if ($reportType !== 'Salary Fixation Report') {
            $query->whereHas('roles', function ($q) {
                $q->where('role_name', 'user');
            });
        }

        // Bill / Bonus Generation Status
        $status = $request->input('salaryGeneratedStatus', 'all');
        if ($isBonusReport) {
            if ($status === 'not_generated' || $status === 'not_generate') {
                $query->whereDoesntHave('bonusGenerates', function ($q) use ($monthId, $year) {
                    $q->where('monthId', $monthId)->where('bonusGenerationYear', $year);
                });
            } else {
                // For Bonus report, default to showing employees who have generated bonus bills
                $query->whereHas('bonusGenerates', function ($q) use ($monthId, $year) {
                    $q->where('monthId', $monthId)->where('bonusGenerationYear', $year);
                });
            }
        } else {
            if ($status === 'generated') {
                $query->whereHas('salaryGenerates', function ($q) use ($monthId, $year) {
                    $q->where('monthId', $monthId)->where('salaryGenerationYear', $year);
                });
            } elseif ($status === 'not_generated' || $status === 'not_generate') {
                $query->whereDoesntHave('salaryGenerates', function ($q) use ($monthId, $year) {
                    $q->where('monthId', $monthId)->where('salaryGenerationYear', $year);
                });
            }
        }

        // Employee subset explicitly selected on the Salary Fixation page.
        // This only narrows Report Factory data; never grants new access.
        if ($reportType === 'Salary Fixation Report' && $request->filled('fixationUserIds')) {
            $rawIds = explode(',', (string) $request->input('fixationUserIds'));
            if (count($rawIds) > 500 || count($rawIds) !== count(array_unique($rawIds))
                || !collect($rawIds)->every(fn ($id) => ctype_digit(trim($id)) && (int)$id > 0)) {
                abort(422, 'Invalid or duplicate fixation employee selection (maximum 500).');
            }
            $query->whereIn('users.id', array_map('intval', $rawIds));
        }

        // Exact Employee Code
        if ($request->filled('employeeCode')) {
            $code = $request->input('employeeCode');
            $query->whereHas('employeeInfo', function ($q) use ($code) {
                $q->where('employeeCode', 'like', "%{$code}%");
            });
        }

        // Department / Office
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

        // Employee Category
        if ($request->filled('user_category_id')) {
            $catId = $request->input('user_category_id');
            $catIds = is_array($catId) ? $catId : [$catId];
            $query->whereHas('userCategories', function ($q) use ($catIds) {
                $q->whereIn('user_categories.id', $catIds);
            });
        }

        // Pay Scale
        if ($request->filled('pay_scale_id')) {
            $scaleId = $request->input('pay_scale_id');
            $query->whereHas('employeeInfo', function ($q) use ($scaleId) {
                $q->where('pay_scale_id', $scaleId);
            });
        }

        // Grade
        if ($request->filled('grade_id')) {
            $gradeId = $request->input('grade_id');
            $query->whereHas('employeeInfo.payrollGroup', function ($q) use ($gradeId) {
                $q->where('grade_id', $gradeId);
            });
        }

        // Designation / Payroll Group Type
        if ($request->filled('payroll_group_type_id')) {
            $groupTypeId = $request->input('payroll_group_type_id');
            $query->whereHas('employeeInfo.payrollGroup', function ($q) use ($groupTypeId) {
                $q->where('payroll_group_type_id', $groupTypeId);
            });
        }

        // Profile Status (isActive)
        if ($request->filled('isActive')) {
            $isActive = filter_var($request->input('isActive'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive !== null) {
                $query->where('isActive', $isActive);
            }
        }

        // Job Status
        if ($request->filled('jobStatus')) {
            $jobStatus = $request->input('jobStatus');
            $query->whereHas('employeeInfo', function ($q) use ($jobStatus) {
                $q->where('jobStatus', $jobStatus);
            });
        }

        // Gender
        if ($request->filled('gender')) {
            $query->where('gender', $request->input('gender'));
        }

        // Bank Account filter
        if ($request->filled('bankAccountFilter')) {
            $bankFilter = $request->input('bankAccountFilter');
            if ($bankFilter === 'has_account') {
                $query->whereHas('bankAccounts', function ($q) {
                    $q->whereNotNull('account_no')->where('account_no', '!=', '');
                });
            } elseif ($bankFilter === 'no_account') {
                $query->whereDoesntHave('bankAccounts', function ($q) {
                    $q->whereNotNull('account_no')->where('account_no', '!=', '');
                });
            }
        }

        // Contact / Phone filter
        if ($request->filled('phoneFilter')) {
            $phoneFilter = $request->input('phoneFilter');
            if ($phoneFilter === 'has_phone') {
                $query->whereHas('phoneNumbers', function ($q) {
                    $q->whereNotNull('phoneNumber')->where('phoneNumber', '!=', '');
                });
            } elseif ($phoneFilter === 'no_phone') {
                $query->whereDoesntHave('phoneNumbers', function ($q) {
                    $q->whereNotNull('phoneNumber')->where('phoneNumber', '!=', '');
                });
            }
        }

        // Tax filter
        if ($request->filled('taxFilter')) {
            $taxFilter = $request->input('taxFilter');
            if ($taxFilter === 'has_tax') {
                if ($isBonusReport) {
                    $query->whereHas('bonusGenerates', function ($q) use ($monthId, $year) {
                        $q->where('monthId', $monthId)->where('bonusGenerationYear', $year)->where('taxDeduction', '>', 0);
                    });
                } else {
                    $query->whereHas('salaryGenerates', function ($q) use ($monthId, $year) {
                        $q->where('monthId', $monthId)->where('salaryGenerationYear', $year)->where('taxDeduction', '>', 0);
                    });
                }
            } elseif ($taxFilter === 'no_tax') {
                if ($isBonusReport) {
                    $query->whereDoesntHave('bonusGenerates', function ($q) use ($monthId, $year) {
                        $q->where('monthId', $monthId)->where('bonusGenerationYear', $year)->where('taxDeduction', '>', 0);
                    });
                } else {
                    $query->whereDoesntHave('salaryGenerates', function ($q) use ($monthId, $year) {
                        $q->where('monthId', $monthId)->where('salaryGenerationYear', $year)->where('taxDeduction', '>', 0);
                    });
                }
            }
        }

        // GPF deduction filter
        if ($request->filled('gpfFilter')) {
            $gpfFilter = $request->input('gpfFilter');
            if ($gpfFilter === 'has_gpf') {
                $query->whereHas('salaryGenerates', function ($q) use ($monthId, $year) {
                    $q->where('monthId', $monthId)->where('salaryGenerationYear', $year)->where('gpfDeduction', '>', 0);
                });
            } elseif ($gpfFilter === 'no_gpf') {
                $query->whereDoesntHave('salaryGenerates', function ($q) use ($monthId, $year) {
                    $q->where('monthId', $monthId)->where('salaryGenerationYear', $year)->where('gpfDeduction', '>', 0);
                });
            }
        }

        // Universal search (Supports comma-separated and multiline multi-user search)
        if ($request->filled('search')) {
            $rawSearch = $request->input('search');
            $trimmed = trim($rawSearch);
            if (preg_match('/^\d+(\s+\d+)+$/', $trimmed)) {
                $terms = preg_split('/\s+/', $trimmed);
            } else {
                $terms = preg_split('/[,\n\r;\t]+/', $rawSearch);
            }

            $terms = array_values(array_filter(array_map('trim', $terms), function ($t) {
                return $t !== '';
            }));

            if (!empty($terms)) {
                $query->where(function ($q) use ($terms) {
                    foreach ($terms as $term) {
                        $q->orWhere(function ($subQ) use ($term) {
                            if (ctype_digit($term)) {
                                $subQ->where('users.id', $term)
                                    ->orWhereHas('employeeInfo', function ($sq) use ($term) {
                                        $sq->where('employeeCode', $term);
                                    });

                                if (strlen($term) >= 8) {
                                    $subQ->orWhereHas('phoneNumbers', function ($sq) use ($term) {
                                        $sq->where('phoneNumber', 'like', "%{$term}%");
                                    })
                                        ->orWhereHas('bankAccounts', function ($sq) use ($term) {
                                            $sq->where('account_no', 'like', "%{$term}%");
                                        })
                                        ->orWhereHas('nid', function ($sq) use ($term) {
                                            $sq->where('nid_no', 'like', "%{$term}%");
                                        })
                                        ->orWhereHas('tin', function ($sq) use ($term) {
                                            $sq->where('tin_no', 'like', "%{$term}%");
                                        });
                                }
                            } else {
                                $subQ->where('name', 'like', "%{$term}%")
                                    ->orWhere('email', 'like', "%{$term}%")
                                    ->orWhereHas('employeeInfo', function ($sq) use ($term) {
                                        $sq->where('employeeCode', 'like', "%{$term}%");
                                    })
                                    ->orWhereHas('phoneNumbers', function ($sq) use ($term) {
                                        $sq->where('phoneNumber', 'like', "%{$term}%");
                                    })
                                    ->orWhereHas('bankAccounts', function ($sq) use ($term) {
                                        $sq->where('account_no', 'like', "%{$term}%");
                                    })
                                    ->orWhereHas('nid', function ($sq) use ($term) {
                                        $sq->where('nid_no', 'like', "%{$term}%");
                                    })
                                    ->orWhereHas('tin', function ($sq) use ($term) {
                                        $sq->where('tin_no', 'like', "%{$term}%");
                                    });
                            }
                        });
                    }
                });
            }
        }

        return $query;
    }

    /**
     * Transform user collection into structured report items.
     */
    private function transformUsers($users, int $monthId, int $year, string $reportType = 'Bank Advice')
    {
        $isBonusReport = in_array(strtolower(trim($reportType)), ['bonus bill', 'bonus report', 'bonus repot', 'bonus bank advice'], true);

        return $users->values()->map(function ($user, $index) use ($monthId, $year, $isBonusReport, $reportType) {
            $employeeInfo = $user->employeeInfo;
            $dept = $employeeInfo?->departmentOffice?->name
                ?? $user->departmentOffices->first()?->name
                ?? 'N/A';
            $category = $user->userCategories->first()?->name ?? 'General';
            $designation = $employeeInfo?->payrollGroup?->payrollGroupType?->group_name ?? 'N/A';
            $scaleName = $employeeInfo?->payScale?->scale_name ?? 'N/A';
            $gradeName = $employeeInfo?->payrollGroup?->grade?->grade_name ?? 'N/A';

            $bankAccount = $user->bankAccounts->where('isSalaryAccount', true)->first()
                ?? $user->bankAccounts->first();
            $bankAccountNo = $bankAccount?->account_no ?? 'N/A';
            $bankAddress = $bankAccount?->address ?? ($bankAccount?->bank_name ?? 'N/A');
            $phone = $user->phoneNumbers->first()?->phoneNumber ?? 'N/A';

            // Extract basic pay from employeeInfo or grade min
            $basicPay = 0.0;
            if ($employeeInfo) {
                $rawAdditions = is_string($employeeInfo->additionObjects)
                    ? json_decode($employeeInfo->additionObjects, true)
                    : ($employeeInfo->additionObjects ?? []);
                if (is_array($rawAdditions)) {
                    foreach ($rawAdditions as $item) {
                        $name = strtolower($item['name'] ?? $item['payroll_component_type']['name'] ?? '');
                        if (str_contains($name, 'basic')) {
                            $basicPay = (float) ($item['amount'] ?? 0);
                        }
                    }
                }
            }
            if ($basicPay == 0 && $employeeInfo?->payrollGroup?->grade) {
                $basicPay = (float) ($employeeInfo->payrollGroup->grade->min ?? 0);
            }

            // Bonus Report transformation
            if ($isBonusReport) {
                $bonusRecord = $user->bonusGenerates->first();
                $isGenerated = !is_null($bonusRecord);

                $allowances = [];
                $deductions = [];
                $totalAllowance = 0.0;
                $totalDeduction = 0.0;
                $taxDeduction = 0.0;
                $netBonus = 0.0;
                $bonusTitle = $bonusRecord?->bonus_title
                    ?: ($employeeInfo?->bonusGroup?->name ?: 'Festival Bonus');

                if ($isGenerated) {
                    $totalAllowance = (float) $bonusRecord->totalAllowance;
                    $totalDeduction = (float) $bonusRecord->totalDeduction;
                    $taxDeduction = (float) $bonusRecord->taxDeduction;
                    $netBonus = (float) $bonusRecord->netBonus;

                    $rawAllowances = is_string($bonusRecord->bonusAllowanceCalcObject)
                        ? json_decode($bonusRecord->bonusAllowanceCalcObject, true)
                        : ($bonusRecord->bonusAllowanceCalcObject ?? []);
                    $rawDeductions = is_string($bonusRecord->bonusDeductionCalcObject)
                        ? json_decode($bonusRecord->bonusDeductionCalcObject, true)
                        : ($bonusRecord->bonusDeductionCalcObject ?? []);

                    $allowances = is_array($rawAllowances) ? $rawAllowances : [];
                    $deductions = is_array($rawDeductions) ? $rawDeductions : [];
                } else {
                    if ($employeeInfo) {
                        $rawAdditions = is_string($employeeInfo->bonusAdditionObjects)
                            ? json_decode($employeeInfo->bonusAdditionObjects, true)
                            : ($employeeInfo->bonusAdditionObjects ?? []);
                        $rawSubtractions = is_string($employeeInfo->bonusSubstractionObjects)
                            ? json_decode($employeeInfo->bonusSubstractionObjects, true)
                            : ($employeeInfo->bonusSubstractionObjects ?? []);

                        if (empty($rawAdditions) && empty($rawSubtractions) && $employeeInfo->bonusGroup) {
                            $rawAdditions = $employeeInfo->bonusGroup->bonusAllowanceObjects ?: [];
                            $rawSubtractions = $employeeInfo->bonusGroup->bonusDeductionObjects ?: [];
                        }

                        $allowances = is_array($rawAdditions) ? $rawAdditions : [];
                        $deductions = is_array($rawSubtractions) ? $rawSubtractions : [];

                        foreach ($allowances as $item) {
                            $calcType = strtolower($item['calcType'] ?? 'fixed');
                            $fromBasic = !empty($item['fromBasic']);
                            $rawAmt = (float) ($item['amount'] ?? 0);
                            $amt = ($calcType === 'percentage' && $fromBasic)
                                ? ($basicPay * ($rawAmt / 100.0))
                                : (($calcType === 'percentage') ? ($basicPay * ($rawAmt / 100.0)) : $rawAmt);
                            $totalAllowance += $amt;
                        }

                        foreach ($deductions as $item) {
                            $calcType = strtolower($item['calcType'] ?? 'fixed');
                            $fromBasic = !empty($item['fromBasic']);
                            $rawAmt = (float) ($item['amount'] ?? 0);
                            $amt = ($calcType === 'percentage' && $fromBasic)
                                ? ($basicPay * ($rawAmt / 100.0))
                                : (($calcType === 'percentage') ? ($basicPay * ($rawAmt / 100.0)) : $rawAmt);
                            $totalDeduction += $amt;
                            $name = strtolower($item['name'] ?? $item['display_name'] ?? '');
                            if (str_contains($name, 'tax') || !empty($item['isTaxable'])) {
                                $taxDeduction += $amt;
                            }
                        }
                    }
                    $netBonus = $totalAllowance - $totalDeduction;
                }

                if (empty($allowances) && $totalAllowance > 0) {
                    $allowances = [
                        [
                            'name' => $bonusTitle,
                            'amount' => $totalAllowance,
                            'calculated_amount' => $totalAllowance,
                        ]
                    ];
                }

                return [
                    'sl' => $index + 1,
                    'user_id' => $user->id,
                    'bonus_generate_id' => $bonusRecord?->id,
                    'employee_code' => $bonusRecord?->employeeCode ?? $employeeInfo?->employeeCode ?? (string) (10000 + $user->id),
                    'name' => $bonusRecord?->name ?? $user->name,
                    'email' => $user->email,
                    'gender' => $bonusRecord?->gender ?? $user->gender,
                    'religion' => $bonusRecord?->religion ?? $user->religion?->religion ?? 'N/A',
                    'designation' => $bonusRecord?->designation ?? $designation,
                    'department' => $dept,
                    'user_category' => $category,
                    'pay_scale' => $bonusRecord?->payScale ?? $scaleName,
                    'grade' => $bonusRecord?->grade ?? $gradeName,
                    'bank_account_no' => $bonusRecord?->bankAccountNo ?? $bankAccountNo,
                    'bank_address' => $bonusRecord?->bankAddress ?? $bankAddress,
                    'phone' => $bonusRecord?->employeePhoneNumber ?? $phone,
                    'joining_date' => $bonusRecord?->joiningDate?->format('Y-m-d') ?? $user->joiningDate?->joining_date ?? 'N/A',
                    'month_id' => $monthId,
                    'year' => $year,
                    'is_generated' => $isGenerated,
                    'bill_status' => $isGenerated ? 'generated' : 'not generated',
                    'review_status' => $bonusRecord?->reviewStatus ?? 'pending',
                    'bonus_title' => $bonusTitle,
                    'bonus_group' => $employeeInfo?->bonusGroup?->name ?? 'Unassigned',
                    'basic_pay' => $basicPay,
                    'total_allowance' => $totalAllowance,
                    'total_deduction' => $totalDeduction,
                    'tax_deduction' => $taxDeduction,
                    'gpf_deduction' => 0.0,
                    'salary_total' => $netBonus,
                    'net_bonus' => $netBonus,
                    'bonus_generation_date' => $bonusRecord?->bonusGenerationDate?->format('Y-m-d'),
                    'allowance_items' => $allowances,
                    'deduction_items' => $deductions,
                    'remarks' => $bonusRecord?->remarks,
                ];
            }

            // Regular Salary Report transformation
            $salaryRecord = $user->salaryGenerates->first();
            $isGenerated = !is_null($salaryRecord);
            if ($isGenerated) {
                // Bank Advice must use the exact frozen bank account on the bill,
                // not the first account currently flagged as salary after posting.
                $bankAccountNo = (string) $salaryRecord->bankAccountNo;
                $bankAddress = (string) $salaryRecord->bankAddress;
                $scaleName = (string) $salaryRecord->payScale;
                $gradeName = (string) $salaryRecord->grade;
            }

            // Allowances & Deductions
            $allowances = [];
            $deductions = [];
            $totalAllowance = 0.0;
            $totalDeduction = 0.0;
            $taxDeduction = 0.0;
            $gpfDeduction = 0.0;
            $basicPay = 0.0;

            if ($isGenerated) {
                $totalAllowance = (float) $salaryRecord->totalAllowance;
                $totalDeduction = (float) $salaryRecord->totalDeduction;
                $taxDeduction = (float) $salaryRecord->taxDeduction;
                $gpfDeduction = (float) $salaryRecord->gpfDeduction;
                $salaryTotal = (float) $salaryRecord->salaryTotal;

                $rawAllowances = is_string($salaryRecord->salaryAllowanceCalcObject)
                    ? json_decode($salaryRecord->salaryAllowanceCalcObject, true)
                    : ($salaryRecord->salaryAllowanceCalcObject ?? []);
                $rawDeductions = is_string($salaryRecord->salaryDeductionCalcObject)
                    ? json_decode($salaryRecord->salaryDeductionCalcObject, true)
                    : ($salaryRecord->salaryDeductionCalcObject ?? []);

                $allowances = is_array($rawAllowances) ? $rawAllowances : [];
                $deductions = is_array($rawDeductions) ? $rawDeductions : [];

                // Historical reports may only show the frozen saved Basic.
                // A percentage rate is not a Basic amount; do not recompute from
                // today's employee profile or a current Grade minimum.
                $basicPay = null;
                foreach ($allowances as $item) {
                    $name = strtolower((string) ($item['name'] ?? $item['payroll_component_type']['name'] ?? ''));
                    if (preg_match('/\bbasic\b/i', $name)) {
                        $raw = $item['calculated_amount'] ?? (strtolower((string)($item['calcType'] ?? 'fixed')) === 'percentage'
                            ? null : ($item['amount'] ?? null));
                        if (is_numeric($raw)) $basicPay = (float) $raw;
                    }
                }
            } else {
                // Parse estimated components from employeeInfo
                if ($employeeInfo) {
                    $rawAdditions = is_string($employeeInfo->additionObjects)
                        ? json_decode($employeeInfo->additionObjects, true)
                        : ($employeeInfo->additionObjects ?? []);
                    $rawSubtractions = is_string($employeeInfo->substractionObjects)
                        ? json_decode($employeeInfo->substractionObjects, true)
                        : ($employeeInfo->substractionObjects ?? []);

                    $allowances = is_array($rawAdditions) ? $rawAdditions : [];
                    $deductions = is_array($rawSubtractions) ? $rawSubtractions : [];

                    foreach ($allowances as $item) {
                        $amt = (float) ($item['amount'] ?? 0);
                        $totalAllowance += $amt;
                        $name = strtolower($item['name'] ?? $item['payroll_component_type']['name'] ?? '');
                        if (str_contains($name, 'basic')) {
                            $basicPay = $amt;
                        }
                    }

                    foreach ($deductions as $item) {
                        $amt = (float) ($item['amount'] ?? 0);
                        $totalDeduction += $amt;
                        $name = strtolower($item['name'] ?? $item['payroll_component_type']['name'] ?? '');
                        if (str_contains($name, 'tax')) {
                            $taxDeduction += $amt;
                        } elseif (str_contains($name, 'gpf') || str_contains($name, 'provident')) {
                            $gpfDeduction += $amt;
                        }
                    }
                }
                $salaryTotal = $totalAllowance - $totalDeduction;
            }

            if (!$isGenerated && $basicPay == 0 && $employeeInfo?->payrollGroup?->grade) {
                $basicPay = (float) ($employeeInfo->payrollGroup->grade->min ?? 0);
            }

            // Arrear components are embedded in the posted salary bill. Keep the
            // regular and supplemental GPF ledgers distinct in reporting.
            $arrearAllowances = array_values(array_filter($allowances, fn ($item) => (isset($item['arrear_snapshot_id']) || !empty($item['arrear_phase5']))));
            $arrearDeductions = array_values(array_filter($deductions, fn ($item) => (isset($item['arrear_snapshot_id']) || !empty($item['arrear_phase5']))));
            $arrearAddTotal = array_sum(array_map(fn ($item) => (float) ($item['calculated_amount'] ?? 0), $arrearAllowances));
            $arrearDedTotal = array_sum(array_map(fn ($item) => (float) ($item['calculated_amount'] ?? 0), $arrearDeductions));
            $arrearGpfTotal = array_sum(array_map(fn ($item) => strtolower((string)($item['arrear_code'] ?? '')) === 'additional_gpf'
                ? (($item['component_type'] ?? '') === 'deduction' ? 1 : -1) * (float) ($item['calculated_amount'] ?? 0) : 0,
                array_merge($arrearAllowances, $arrearDeductions)));
            $gpfRecord = $user->generalProvidentFunds->first();
            $gpfClosing = $gpfRecord ? (float) $gpfRecord->closingAmount : $gpfDeduction;
            $gpfOpening = $gpfRecord ? (float) $gpfRecord->openingBalance : 0.0;
            $gpfInterest = $gpfRecord ? (float) $gpfRecord->interestAmount : 0.0;
            $gpfNet = $gpfRecord ? (float) $gpfRecord->netDeposit : $gpfDeduction;
            $fixationData=null;
            if (!$isBonusReport && $reportType === 'Salary Fixation Report') {
                // Prefer immutable approved evidence; otherwise report a clearly
                // provisional saved/current calculation. Reporting never approves,
                // saves or posts any salary/arrear/GPF transactions.
                $fix = \Illuminate\Support\Facades\DB::table('salary_fixations')
                    ->where('user_id', $user->id)->orderByDesc('id')->first();
                $approved = $fix && $fix->status === 'approved' && $fix->approved_at !== null;
                $prepared = $fix ? json_decode((string) ($fix->prepared_data ?? ''), true) : null;
                $prepared = is_array($prepared) && $prepared ? $prepared : null;
                if (!$prepared) {
                    // A rejected/stale snapshot should never silently become a new
                    // approved financial value. Fresh estimates are unverified.
                    try {
                        $prepared = app(\App\Services\SalaryFixationService::class)->prepare($user);
                    } catch (\Throwable $error) {
                        \Illuminate\Support\Facades\Log::warning('Fixation report preparation failed',
                            ['user_id'=>$user->id, 'error'=>$error->getMessage()]);
                        $prepared = [];
                    }
                }
                $calc = is_array($prepared['calculation'] ?? null) ? $prepared['calculation'] : [];
                $gradeNumber = (int) ($approved ? ($fix->grade_number ?? 0) : ($prepared['grade'] ?? 0));
                $scale = config('kau_fixation_2026.'.$gradeNumber, []);
                $schedules = [];
                if ($approved) {
                    $schedules = \Illuminate\Support\Facades\DB::table('salary_fixation_pay_schedules')
                        ->where('salary_fixation_id', $fix->id)->orderBy('effective_date')
                        ->get(['effective_date','payable_basic','grade_number'])
                        ->map(fn ($v)=>['effective_date'=>$v->effective_date,'basic'=>(float)$v->payable_basic,
                            'grade'=>(int)$v->grade_number])->all();
                } elseif ($calc) {
                    foreach (['2026-07-01'=>'phase_2026_07', '2027-01-01'=>'phase_2027_01',
                        '2027-07-01'=>'phase_2027_07'] as $date=>$key) {
                        if (isset($calc[$key]) && is_numeric($calc[$key])) {
                            $schedules[] = ['effective_date'=>$date,'basic'=>(float)$calc[$key],
                                'grade'=>$gradeNumber];
                        }
                    }
                }
                $fixationData = [
                    'id'=>$fix->id ?? null,
                    'old_basic'=>isset($prepared['basic']) && is_numeric($prepared['basic'])
                        ? (float)$prepared['basic'] : ($approved && isset($fix->old_basic) ? (float)$fix->old_basic : null),
                    'grade'=>$gradeNumber ?: null,
                    'status'=>$approved ? 'approved' : 'provisional',
                    'ready'=>$approved && (bool)($prepared['ready'] ?? false),
                    'source_status'=>$fix->status ?? 'unsaved',
                    'approved_at'=>$approved ? $fix->approved_at : null,
                    'stages'=>$schedules,
                    'original_pdf_url'=>$fix && is_array(json_decode((string)($fix->prepared_data ?? ''), true))
                        ? route('salary-fixation.admin-print', ['salaryFixation'=>$fix->id])
                        : route('salary-fixation.preview', ['user'=>$user->id]),
                    'old_scale_min'=>$scale['init2015'] ?? null,
                    'old_scale_max'=>$scale['max2015'] ?? null,
                    'new_scale_min'=>$scale['init2026'] ?? null,
                    'new_scale_max'=>$scale['max2026'] ?? null,
                    'fitment_basic'=>$calc['fitment_basic'] ?? null,
                    'increment_2026'=>$calc['increment_2026'] ?? null,
                    'increment_2027'=>$calc['increment_2027'] ?? null,
                    'first_stage_basic'=>$calc['phase_2027_07_before_increment'] ?? null,
                    'rate_2026'=>$calc['rate_2026'] ?? null,
                    'rate_2027'=>$calc['rate_2027'] ?? null,
                    'source_sha256'=>$approved ? ($fix->source_sha256 ?? null) : ($prepared['source_sha256'] ?? null),
                    'warnings'=>$prepared['warnings'] ?? [],
                    'blockers'=>$prepared['blockers'] ?? [],
                ];
            }
            $phase5SupplementaryGpf = 0.0;
            if ($isGenerated && \Illuminate\Support\Facades\Schema::hasTable('salary_bill_arrear_gpf_entries')) {
                $phase5SupplementaryGpf = (float)\Illuminate\Support\Facades\DB::table('salary_bill_arrear_gpf_entries')
                    ->where('salary_generate_id',$salaryRecord->id)->whereNull('reverses_entry_id')
                    ->sum('signed_amount');
            }

            return [
                'sl' => $index + 1,
                'user_id' => $user->id,
                'employee_code' => $employeeInfo?->employeeCode ?? (string) (10000 + $user->id),
                'name' => $user->name,
                'email' => $user->email,
                'gender' => $user->gender,
                'religion' => $user->religion?->religion ?? 'N/A',
                'designation' => $designation,
                'department' => $dept,
                'user_category' => $category,
                'pay_scale' => $scaleName,
                'grade' => $gradeName,
                'bank_account_no' => $bankAccountNo,
                'bank_address' => $bankAddress,
                'phone' => $phone,
                'joining_date' => $user->joiningDate?->joining_date ?? 'N/A',
                'month_id' => $monthId,
                'year' => $year,
                'is_generated' => $isGenerated,
                'bill_status' => $isGenerated ? 'generated' : 'not generated',
                'review_status' => $salaryRecord?->reviewStatus ?? 'pending',
                'basic_pay' => $basicPay,
                'total_allowance' => $totalAllowance,
                'total_deduction' => $totalDeduction,
                'tax_deduction' => $taxDeduction,
                'gpf_deduction' => $gpfDeduction,
                'regular_gpf_deduction' => round($gpfDeduction, 2),
                'arrear_gpf_adjustment' => round($arrearGpfTotal, 2),
                'arrear_additions' => round($arrearAddTotal, 2),
                'arrear_deductions' => round($arrearDedTotal, 2),
                'arrear_net' => round($arrearAddTotal - $arrearDedTotal, 2),
                'gpf_closing_balance' => $gpfClosing,
                'phase5_supplementary_gpf_journal' => $phase5SupplementaryGpf,
                'fixation_report' => $fixationData,
                'gpf_opening_balance' => $gpfOpening,
                'gpf_interest_amount' => $gpfInterest,
                'gpf_net_deposit' => $gpfNet,
                'gpf_is_settled' => (bool) ($gpfRecord?->isSettled ?? false),
                'salary_total' => $salaryTotal,
                'salary_generation_date' => $salaryRecord?->salaryGenerationDate?->format('Y-m-d'),
                'allowance_items' => $allowances,
                'deduction_items' => $deductions,
            ];
        });
    }

    /**
     * Calculate summary aggregates for report.
     */
    private function calculateSummary($items)
    {
        $totalRecords = $items->count();
        $totalAllowance = 0.0;
        $totalDeduction = 0.0;
        $totalNet = 0.0;
        $totalTax = 0.0;
        $totalGpf = 0.0;

        foreach ($items as $item) {
            $totalAllowance += (float) ($item['total_allowance'] ?? 0);
            $totalDeduction += (float) ($item['total_deduction'] ?? 0);
            $totalNet += (float) ($item['salary_total'] ?? 0);
            $totalTax += (float) ($item['tax_deduction'] ?? 0);
            $totalGpf += (float) ($item['gpf_deduction'] ?? 0);
        }

        return [
            'total_records' => $totalRecords,
            'total_allowance' => $totalAllowance,
            'total_deduction' => $totalDeduction,
            'total_net' => $totalNet,
            'total_tax' => $totalTax,
            'total_gpf' => $totalGpf,
        ];
    }
}
