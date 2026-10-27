<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    public function create()
    {
        return Inertia::render('Employee/Create', [
            'departmentOffices' => \App\Models\DepartmentOffice::orderBy('name', 'asc')->get(),
            'payScales' => \App\Models\PayScale::all(),
            'grades' => \App\Models\Grade::all(),
            'payrollGroups' => \App\Models\PayrollGroup::with(['grade', 'payrollGroupType'])->get(),
            'userCategories' => \App\Models\UserCategory::all(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:' . User::class,
            'password' => 'required|string|min:8',
            'gender' => 'required|string|in:male,female,other',
            'employeeCode' => 'required|numeric|unique:employee_infos,employeeCode',
            'isActive' => 'boolean',
            'pay_scale_id' => 'required|exists:pay_scales,id',
            'payroll_group_id' => 'required|exists:payroll_groups,id',
            'department_offices' => 'required|array|min:1',
            'department_offices.*.department_office_id' => 'required|exists:department_offices,id',
            'user_categories' => 'nullable|array',
            'user_categories.*.user_category_id' => 'nullable|exists:user_categories,id',
            'date_of_birth' => 'nullable|date',
            'joining_date' => 'nullable|date',
            'incrementDate' => 'nullable|date',
            'promotions' => 'nullable|array',
            'promotions.*.promoted_to' => 'nullable|string|max:255',
            'promotions.*.pay_scale_id' => 'nullable|exists:pay_scales,id',
            'promotions.*.payroll_group_id' => 'nullable|exists:payroll_groups,id',
            'bank_accounts' => 'nullable|array',
            'bank_accounts.*.bank_account_no' => 'nullable|string|max:255',
            'leave_statuses' => 'nullable|array',
            'leave_statuses.*.leave_reason' => 'nullable|string|max:255',
        ], [
            'department_offices.required' => 'Please select at least one department/office.',
            'department_offices.min' => 'Please select at least one department/office.',
            'department_offices.*.department_office_id.required' => 'The department field is required.',
            'department_offices.*.department_office_id.exists' => 'The selected department is invalid.',
            'pay_scale_id.required' => 'Please select a pay scale.',
            'pay_scale_id.exists' => 'The selected pay scale is invalid.',
            'payroll_group_id.required' => 'Please select a payroll group.',
            'payroll_group_id.exists' => 'The selected payroll group is invalid.',
            'employeeCode.required' => 'The employee code is required.',
            'employeeCode.unique' => 'This employee code is already taken.',
        ]);

        DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'gender' => $request->gender,
                'isActive' => $request->has('isActive') ? (bool)$request->isActive : true,
            ]);

            $userRole = Role::firstOrCreate(
                ['role_name' => 'user'],
                [
                    'role_description' => 'User has access to personalized features',
                    'is_admin' => false,
                    'isActive' => true,
                ]
            );
            $user->roles()->syncWithoutDetaching([$userRole->id]);


            // Relations that have one
            $nid = $user->nid()->create(['nid_no' => $request->nid_no ?? 'N/A', 'nid_img' => $request->nid_img]);
            $bid = $user->bid()->create(['date_of_birth' => $request->date_of_birth, 'bid_no' => $request->bid_no ?? 'N/A', 'bidImg' => $request->bidImg]);
            $tin = $user->tin()->create(['tin_no' => $request->tin_no ?? 'N/A', 'tin_img' => $request->tin_img]);
            $joiningDate = $user->joiningDate()->create(['joining_date' => $request->joining_date ?? now()]);

            if ($request->filled('childCount') || $request->filled('boy') || $request->filled('girl')) {
                $user->childCount()->create(['childCount' => $request->childCount ?? 0, 'boy' => $request->boy ?? 0, 'girl' => $request->girl ?? 0]);
            }
            if ($request->filled('maritial_status')) {
                $user->maritialStatus()->create(['status' => $request->maritial_status]);
            }
            if ($request->filled('religion')) {
                $user->religion()->create(['religion' => $request->religion]);
            }
            if ($request->filled('age')) {
                $user->age()->create(['age' => $request->age]);
            }
            if ($request->filled('dorm_name')) {
                $user->dormitory()->create(['dorm_name' => $request->dorm_name, 'dorm_type' => $request->dorm_type, 'dorm_address' => $request->dorm_address]);
            }

            $employeeAttrId = null;
            if ($request->filled('emp_attr_name')) {
                $ea = $user->employeeAttribute()->create(['name' => $request->emp_attr_name, 'description' => $request->emp_attr_description, 'isActive' => $request->emp_attr_action ?? false]);
                $employeeAttrId = $ea->id;
            }

            // Many relations
            $primaryBankAccountId = null;
            if ($request->has('bank_accounts') && is_array($request->bank_accounts)) {
                foreach ($request->bank_accounts as $idx => $ba) {
                    if (!empty($ba['bank_account_no'])) {
                        $bankAccount = $user->bankAccounts()->create([
                            'routing_no' => $ba['bank_routing_no'] ?? 'N/A',
                            'account_no' => $ba['bank_account_no'],
                            'type' => $ba['bank_type'] ?? 'N/A',
                            'address' => $ba['bank_address'] ?? 'N/A',
                            'isSalaryAccount' => $ba['isSalaryAccount'] ?? false
                        ]);
                        if ($idx === 0) {
                            $primaryBankAccountId = $bankAccount->id;
                        }
                    }
                }
            }
            if (!$primaryBankAccountId) {
                // fallback to satisfy constraint
                $bankAccount = $user->bankAccounts()->create([
                    'routing_no' => 'N/A',
                    'account_no' => 'N/A',
                    'type' => 'N/A',
                    'address' => 'N/A',
                    'isSalaryAccount' => false
                ]);
                $primaryBankAccountId = $bankAccount->id;
            }

            if ($request->has('leave_statuses') && is_array($request->leave_statuses)) {
                foreach ($request->leave_statuses as $ls) {
                    if (!empty($ls['leave_reason'])) {
                        $user->leaveStatuses()->create([
                            'onLeave' => $ls['onLeave'] ?? false,
                            'reason' => $ls['leave_reason'],
                            'isPaid' => $ls['isPaid'] ?? false,
                            'isActive' => $ls['leave_isActive'] ?? false,
                            'duration' => $ls['leave_duration'] ?? 0
                        ]);
                    }
                }
            }

            $payScaleId = $request->pay_scale_id;
            $payrollGroupId = $request->payroll_group_id;

            if ($request->has('promotions') && is_array($request->promotions)) {
                foreach ($request->promotions as $promo) {
                    if (!empty($promo['promoted_to']) || !empty($promo['pay_scale_id']) || !empty($promo['payroll_group_id'])) {
                        $user->promotions()->create([
                            'promoted_to' => $promo['promoted_to'] ?? null,
                            'pay_scale_id' => !empty($promo['pay_scale_id']) ? $promo['pay_scale_id'] : $payScaleId,
                            'payroll_group_id' => !empty($promo['payroll_group_id']) ? $promo['payroll_group_id'] : $payrollGroupId
                        ]);
                    }
                }
            }

            $primaryPhoneNumberId = null;
            if ($request->has('phone_numbers') && is_array($request->phone_numbers)) {
                foreach ($request->phone_numbers as $idx => $phone) {
                    if (!empty($phone['phoneNumber'])) {
                        $pn = \App\Models\PhoneNumber::firstOrCreate(['phoneNumber' => $phone['phoneNumber']]);
                        $user->phoneNumbers()->syncWithoutDetaching([$pn->id]);
                        if ($idx === 0) {
                            $primaryPhoneNumberId = $pn->id;
                        }
                    }
                }
            }
            if (!$primaryPhoneNumberId) {
                $pn = \App\Models\PhoneNumber::firstOrCreate(['phoneNumber' => 'N/A']);
                $user->phoneNumbers()->syncWithoutDetaching([$pn->id]);
                $primaryPhoneNumberId = $pn->id;
            }

            // Sync Departments
            $primaryDeptId = null;
            if ($request->has('department_offices') && is_array($request->department_offices)) {
                $deptIds = [];
                foreach ($request->department_offices as $idx => $dept) {
                    if (!empty($dept['department_office_id'])) {
                        $deptIds[] = $dept['department_office_id'];
                        if ($idx === 0) {
                            $primaryDeptId = $dept['department_office_id'];
                        }
                    }
                }
                $user->departmentOffices()->sync($deptIds);
            }
            if (!$primaryDeptId) {
                $firstDept = \App\Models\DepartmentOffice::first() ?? \App\Models\DepartmentOffice::firstOrCreate(
                    ['name' => 'General Administration'],
                    ['type' => 'office', 'description' => 'Default Department/Office', 'isActive' => true]
                );
                $primaryDeptId = $firstDept->id;
                $user->departmentOffices()->syncWithoutDetaching([$primaryDeptId]);
            }

            // Sync User Categories
            if ($request->has('user_categories') && is_array($request->user_categories)) {
                $categoryIds = [];
                foreach ($request->user_categories as $cat) {
                    $catId = is_array($cat) ? ($cat['user_category_id'] ?? $cat['id'] ?? null) : $cat;
                    if (!empty($catId)) {
                        $categoryIds[] = (int) $catId;
                    }
                }
                $user->userCategories()->sync(array_unique($categoryIds));
            }

            $primaryAddressId = null;
            if ($request->has('addresses') && is_array($request->addresses)) {
                foreach ($request->addresses as $idx => $addr) {
                    if (!empty($addr['address'])) {
                        $ad = \App\Models\Address::firstOrCreate(['address' => $addr['address']]);
                        $user->addresses()->syncWithoutDetaching([$ad->id]);
                        if ($idx === 0) {
                            $primaryAddressId = $ad->id;
                        }
                    }
                }
            }
            if (!$primaryAddressId) {
                $ad = \App\Models\Address::firstOrCreate(['address' => 'N/A']);
                $user->addresses()->syncWithoutDetaching([$ad->id]);
                $primaryAddressId = $ad->id;
            }

            if ($request->filled('employeeCode')) {
                $user->employeeInfo()->create([
                    'employeeCode' => $request->employeeCode,
                    'jobStatus' => $request->jobStatus ?? 'permanent',
                    'incrementDate' => $request->incrementDate ?? now(),
                    'holdIncrement' => $request->has('holdIncrement') ? (bool)$request->holdIncrement : false,
                    'department_office_id' => $primaryDeptId,
                    'phone_number_id' => $primaryPhoneNumberId,
                    'address_id' => $primaryAddressId,
                    'bank_account_id' => $primaryBankAccountId,
                    'joining_date_id' => $joiningDate->id,
                    'nid_id' => $nid->id,
                    'bid_id' => $bid->id,
                    't_i_n_s_id' => $tin->id,
                    'employee_attribute_id' => $employeeAttrId,
                    'pay_scale_id' => $payScaleId,
                    'payroll_group_id' => $payrollGroupId,
                ]);

                // Automatically initialize salary calculation components
                if (function_exists('updateUserSalaryCalculationObjects')) {
                    updateUserSalaryCalculationObjects($user->id);
                }
            }
        });

        return back()->with('success', 'Employee created successfully.');
    }

    public function edit(Request $request)
    {
        if ($request->wantsJson()) {
            $query = User::where(function ($q) {
                $q->whereHas('roles', function ($rq) {
                    $rq->where('role_name', 'user');
                })->orWhereHas('employeeInfo');
            })->with([
                'nid',
                'bid',
                'childCount',
                'maritialStatus',
                'religion',
                'joiningDate',
                'age',
                'dormitory',
                'employeeAttribute',
                'tin',
                'bankAccounts',
                'leaveStatuses',
                'promotions',
                'phoneNumbers',
                'addresses',
                'employeeInfo.payScale',
                'employeeInfo.payrollGroup.grade',
                'employeeInfo.payrollGroup.payrollGroupType',
                'employeeInfo.departmentOffice',
                'departmentOffices',
                'userCategories',
            ]);

            // General Search (Name, Email, Employee Code, Phone Number, NID, TIN)
            // Supports comma-separated, newline, semicolon, or space-separated multi-user search
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
                                    // When searching numeric codes/IDs (e.g. 10094, 10119), match employeeCode or user id exactly.
                                    // This prevents false positives from matching substrings in other employees' emails (e.g. sanjida101190@gmail.com).
                                    $subQ->where('users.id', $term)
                                        ->orWhereHas('employeeInfo', function ($sq) use ($term) {
                                            $sq->where('employeeCode', $term);
                                        });

                                    // If term has 8 or more digits, it could also be a Phone Number, NID, or TIN
                                    if (strlen($term) >= 8) {
                                        $subQ->orWhereHas('phoneNumbers', function ($sq) use ($term) {
                                            $sq->where('phoneNumber', 'like', "%{$term}%");
                                        })->orWhereHas('nid', function ($sq) use ($term) {
                                            $sq->where('nid_no', 'like', "%{$term}%");
                                        })->orWhereHas('tin', function ($sq) use ($term) {
                                            $sq->where('tin_no', 'like', "%{$term}%");
                                        });
                                    }
                                } else {
                                    // Text/email/name/phone search
                                    $subQ->where('name', 'like', "%{$term}%")
                                        ->orWhere('email', 'like', "%{$term}%")
                                        ->orWhereHas('employeeInfo', function ($sq) use ($term) {
                                            $sq->where('employeeCode', 'like', "%{$term}%");
                                        })
                                        ->orWhereHas('phoneNumbers', function ($sq) use ($term) {
                                            $sq->where('phoneNumber', 'like', "%{$term}%");
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

            // Employee Code filter
            if ($request->filled('employeeCode')) {
                $code = trim($request->input('employeeCode'));
                $query->whereHas('employeeInfo', function ($q) use ($code) {
                    if (ctype_digit($code)) {
                        $q->where('employeeCode', $code);
                    } else {
                        $q->where('employeeCode', 'like', "%{$code}%");
                    }
                });
            }

            // Specific user IDs filter (for bulk operations or selected export)
            if ($request->filled('ids')) {
                $ids = is_array($request->input('ids')) ? $request->input('ids') : explode(',', $request->input('ids'));
                $query->whereIn('users.id', $ids);
            }

            // Department / Office filter
            $deptId = $request->input('department_office_id') ?: $request->input('department_id');
            if (!empty($deptId)) {
                $query->where(function ($q) use ($deptId) {
                    $q->whereHas('departmentOffices', function ($sq) use ($deptId) {
                        $sq->where('department_offices.id', $deptId);
                    })->orWhereHas('employeeInfo', function ($sq) use ($deptId) {
                        $sq->where('department_office_id', $deptId);
                    });
                });
            }

            // Employee Group / User Category filter
            if ($request->filled('user_category_id')) {
                $catId = $request->input('user_category_id');
                $query->whereHas('userCategories', function ($q) use ($catId) {
                    $q->where('user_categories.id', $catId);
                });
            }

            // Pay Scale filter
            if ($request->filled('pay_scale_id')) {
                $scaleId = $request->input('pay_scale_id');
                $query->whereHas('employeeInfo', function ($q) use ($scaleId) {
                    $q->where('pay_scale_id', $scaleId);
                });
            }

            // Grade filter
            if ($request->filled('grade_id')) {
                $gradeId = $request->input('grade_id');
                $query->whereHas('employeeInfo.payrollGroup', function ($q) use ($gradeId) {
                    $q->where('grade_id', $gradeId);
                });
            }

            // Designation / Payroll Group filter
            if ($request->filled('payroll_group_id')) {
                $groupId = $request->input('payroll_group_id');
                $query->whereHas('employeeInfo', function ($q) use ($groupId) {
                    $q->where('payroll_group_id', $groupId);
                });
            }

            // Job Status filter
            if ($request->filled('jobStatus')) {
                $jobStatus = $request->input('jobStatus');
                $query->whereHas('employeeInfo', function ($q) use ($jobStatus) {
                    $q->where('jobStatus', $jobStatus);
                });
            }

            // Gender filter
            if ($request->filled('gender')) {
                $query->where('gender', $request->input('gender'));
            }

            // Account status filter (isActive)
            if ($request->filled('isActive')) {
                $isActive = filter_var($request->input('isActive'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isActive !== null) {
                    $query->where('isActive', $isActive);
                }
            }

            // Hold Increment filter
            if ($request->filled('holdIncrement')) {
                $hold = filter_var($request->input('holdIncrement'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($hold !== null) {
                    $query->whereHas('employeeInfo', function ($q) use ($hold) {
                        $q->where('holdIncrement', $hold);
                    });
                }
            }

            // Marital status filter
            if ($request->filled('maritial_status')) {
                $status = $request->input('maritial_status');
                $query->whereHas('maritialStatus', function ($q) use ($status) {
                    $q->where('status', $status);
                });
            }

            // Religion filter
            if ($request->filled('religion')) {
                $rel = $request->input('religion');
                $query->whereHas('religion', function ($q) use ($rel) {
                    $q->where('religion', $rel);
                });
            }

            // Leave status filter (onLeave)
            if ($request->filled('onLeave')) {
                $onLeave = filter_var($request->input('onLeave'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($onLeave !== null) {
                    if ($onLeave) {
                        $query->whereHas('leaveStatuses', function ($q) {
                            $q->where('onLeave', true);
                        });
                    } else {
                        $query->whereDoesntHave('leaveStatuses', function ($q) {
                            $q->where('onLeave', true);
                        });
                    }
                }
            }

            $sortField = $request->input('sortField', 'id');
            $sortDirection = $request->input('sortDirection', 'desc');
            $allowedSortFields = ['id', 'name', 'email', 'isActive', 'gender'];

            if ($sortField === 'employeeCode') {
                $query->orderBy(
                    \App\Models\EmployeeInfo::select('employeeCode')
                        ->whereColumn('employee_infos.user_id', 'users.id')
                        ->limit(1),
                    $sortDirection === 'asc' ? 'asc' : 'desc'
                );
            } elseif (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
            } else {
                $query->orderBy('id', 'desc');
            }

            $isExportAll = filter_var($request->input('all', false), FILTER_VALIDATE_BOOLEAN);
            if ($isExportAll) {
                return response()->json([
                    'data' => $query->get(),
                    'total' => $query->count()
                ]);
            }

            $perPage = (int) $request->input('perPage', 10);
            return response()->json(
                $query->paginate($perPage)
            );
        }

        return Inertia::render('Employee/Edit', [
            'departmentOffices' => \App\Models\DepartmentOffice::orderBy('name', 'asc')->get(),
            'payScales' => \App\Models\PayScale::all(),
            'grades' => \App\Models\Grade::all(),
            'payrollGroups' => \App\Models\PayrollGroup::with(['grade', 'payrollGroupType'])->get(),
            'userCategories' => \App\Models\UserCategory::all(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:users,email,' . $user->id,
            'gender' => 'required|string|in:male,female,other',
            'employeeCode' => 'required|numeric|unique:employee_infos,employeeCode,' . ($user->employeeInfo ? $user->employeeInfo->id : ''),
            'isActive' => 'boolean',
            'pay_scale_id' => 'required|exists:pay_scales,id',
            'payroll_group_id' => 'required|exists:payroll_groups,id',
            'department_offices' => 'required|array|min:1',
            'department_offices.*.department_office_id' => 'required|exists:department_offices,id',
            'user_categories' => 'nullable|array',
            'user_categories.*.user_category_id' => 'nullable|exists:user_categories,id',
            'date_of_birth' => 'nullable|date',
            'joining_date' => 'nullable|date',
            'incrementDate' => 'nullable|date',
            'promotions' => 'nullable|array',
            'promotions.*.promoted_to' => 'nullable|string|max:255',
            'promotions.*.pay_scale_id' => 'nullable|exists:pay_scales,id',
            'promotions.*.payroll_group_id' => 'nullable|exists:payroll_groups,id',
            'bank_accounts' => 'nullable|array',
            'bank_accounts.*.bank_account_no' => 'nullable|string|max:255',
            'leave_statuses' => 'nullable|array',
            'leave_statuses.*.leave_reason' => 'nullable|string|max:255',
        ], [
            'department_offices.required' => 'Please select at least one department/office.',
            'department_offices.min' => 'Please select at least one department/office.',
            'department_offices.*.department_office_id.required' => 'The department field is required.',
            'department_offices.*.department_office_id.exists' => 'The selected department is invalid.',
            'pay_scale_id.required' => 'Please select a pay scale.',
            'pay_scale_id.exists' => 'The selected pay scale is invalid.',
            'payroll_group_id.required' => 'Please select a payroll group.',
            'payroll_group_id.exists' => 'The selected payroll group is invalid.',
            'employeeCode.required' => 'The employee code is required.',
            'employeeCode.unique' => 'This employee code is already taken.',
        ]);

        DB::transaction(function () use ($request, $user, $validated) {
            $user->update($validated);

            $nid = $user->nid()->updateOrCreate([], ['nid_no' => $request->nid_no ?? 'N/A', 'nid_img' => $request->nid_img]);
            $bid = $user->bid()->updateOrCreate([], ['date_of_birth' => $request->date_of_birth, 'bid_no' => $request->bid_no ?? 'N/A', 'bidImg' => $request->bidImg]);
            $tin = $user->tin()->updateOrCreate([], ['tin_no' => $request->tin_no ?? 'N/A', 'tin_img' => $request->tin_img]);
            $joiningDate = $user->joiningDate()->updateOrCreate([], ['joining_date' => $request->joining_date ?? now()]);

            if ($request->filled('childCount') || $request->filled('boy') || $request->filled('girl')) {
                $user->childCount()->updateOrCreate([], ['childCount' => $request->childCount ?? 0, 'boy' => $request->boy ?? 0, 'girl' => $request->girl ?? 0]);
            }
            if ($request->filled('maritial_status')) {
                $user->maritialStatus()->updateOrCreate([], ['status' => $request->maritial_status]);
            }
            if ($request->filled('religion')) {
                $user->religion()->updateOrCreate([], ['religion' => $request->religion]);
            }
            if ($request->filled('age')) {
                $user->age()->updateOrCreate([], ['age' => $request->age]);
            }
            if ($request->filled('dorm_name')) {
                $user->dormitory()->updateOrCreate([], ['dorm_name' => $request->dorm_name, 'dorm_type' => $request->dorm_type, 'dorm_address' => $request->dorm_address]);
            }

            $employeeAttrId = null;
            if ($request->filled('emp_attr_name')) {
                $ea = $user->employeeAttribute()->updateOrCreate([], ['name' => $request->emp_attr_name, 'description' => $request->emp_attr_description, 'isActive' => $request->emp_attr_action ?? false]);
                $employeeAttrId = $ea->id;
            }

            // Sync phone numbers
            $primaryPhoneNumberId = null;
            if ($request->has('phone_numbers') && is_array($request->phone_numbers)) {
                $phoneIds = [];
                foreach ($request->phone_numbers as $idx => $phone) {
                    if (!empty($phone['phoneNumber'])) {
                        $pn = \App\Models\PhoneNumber::firstOrCreate(['phoneNumber' => $phone['phoneNumber']]);
                        $phoneIds[] = $pn->id;
                        if ($idx === 0) $primaryPhoneNumberId = $pn->id;
                    }
                }
                $user->phoneNumbers()->sync($phoneIds);
            }
            if (!$primaryPhoneNumberId) {
                $primaryPhoneNumberId = $user->phoneNumbers()->first()?->id;
            }

            // Sync addresses
            $primaryAddressId = null;
            if ($request->has('addresses') && is_array($request->addresses)) {
                $addressIds = [];
                foreach ($request->addresses as $idx => $addr) {
                    if (!empty($addr['address'])) {
                        $ad = \App\Models\Address::firstOrCreate(['address' => $addr['address']]);
                        $addressIds[] = $ad->id;
                        if ($idx === 0) $primaryAddressId = $ad->id;
                    }
                }
                $user->addresses()->sync($addressIds);
            }
            if (!$primaryAddressId) {
                $primaryAddressId = $user->addresses()->first()?->id;
            }

            // Sync Departments
            $primaryDeptId = null;
            if ($request->has('department_offices') && is_array($request->department_offices)) {
                $deptIds = [];
                foreach ($request->department_offices as $idx => $dept) {
                    if (!empty($dept['department_office_id'])) {
                        $deptIds[] = $dept['department_office_id'];
                        if ($idx === 0) {
                            $primaryDeptId = $dept['department_office_id'];
                        }
                    }
                }
                $user->departmentOffices()->sync($deptIds);
            }
            if (!$primaryDeptId) {
                $firstDept = \App\Models\DepartmentOffice::first() ?? \App\Models\DepartmentOffice::firstOrCreate(
                    ['name' => 'General Administration'],
                    ['type' => 'office', 'description' => 'Default Department/Office', 'isActive' => true]
                );
                $primaryDeptId = $firstDept->id;
                $user->departmentOffices()->syncWithoutDetaching([$primaryDeptId]);
            }

            // Sync User Categories
            if ($request->has('user_categories') && is_array($request->user_categories)) {
                $categoryIds = [];
                foreach ($request->user_categories as $cat) {
                    $catId = is_array($cat) ? ($cat['user_category_id'] ?? $cat['id'] ?? null) : $cat;
                    if (!empty($catId)) {
                        $categoryIds[] = (int) $catId;
                    }
                }
                $user->userCategories()->sync(array_unique($categoryIds));
            }

            // Sync Bank Accounts
            $primaryBankAccountId = null;
            $keptBankAccountIds = [];
            if ($request->has('bank_accounts') && is_array($request->bank_accounts)) {
                foreach ($request->bank_accounts as $idx => $ba) {
                    if (!empty($ba['bank_account_no'])) {
                        $bankAccount = $user->bankAccounts()->updateOrCreate(
                            ['account_no' => $ba['bank_account_no']],
                            [
                                'routing_no' => $ba['bank_routing_no'] ?? 'N/A',
                                'type' => $ba['bank_type'] ?? 'N/A',
                                'address' => $ba['bank_address'] ?? 'N/A',
                                'isSalaryAccount' => $ba['isSalaryAccount'] ?? false
                            ]
                        );
                        $keptBankAccountIds[] = $bankAccount->id;
                        if ($idx === 0) $primaryBankAccountId = $bankAccount->id;
                    }
                }
            }
            if (!$primaryBankAccountId) {
                $first = $user->bankAccounts()->first();
                if ($first) {
                    $primaryBankAccountId = $first->id;
                    $keptBankAccountIds[] = $first->id;
                }
            }

            // Sync Leave Statuses
            if ($request->has('leave_statuses') && is_array($request->leave_statuses)) {
                $user->leaveStatuses()->delete();
                foreach ($request->leave_statuses as $ls) {
                    if (!empty($ls['leave_reason'])) {
                        $user->leaveStatuses()->create([
                            'onLeave' => $ls['onLeave'] ?? false,
                            'reason' => $ls['leave_reason'],
                            'isPaid' => $ls['isPaid'] ?? false,
                            'isActive' => $ls['leave_isActive'] ?? false,
                            'duration' => $ls['leave_duration'] ?? 0
                        ]);
                    }
                }
            }

            // Sync Promotions
            $payScaleId = $request->pay_scale_id;
            $payrollGroupId = $request->payroll_group_id;

            if ($request->has('promotions') && is_array($request->promotions)) {
                $user->promotions()->delete();
                foreach ($request->promotions as $promo) {
                    if (!empty($promo['promoted_to']) || !empty($promo['pay_scale_id']) || !empty($promo['payroll_group_id'])) {
                        $user->promotions()->create([
                            'promoted_to' => $promo['promoted_to'] ?? null,
                            'pay_scale_id' => !empty($promo['pay_scale_id']) ? $promo['pay_scale_id'] : $payScaleId,
                            'payroll_group_id' => !empty($promo['payroll_group_id']) ? $promo['payroll_group_id'] : $payrollGroupId
                        ]);
                    }
                }
            }

            if ($request->filled('employeeCode')) {
                $user->employeeInfo()->updateOrCreate([], [
                    'employeeCode' => $request->employeeCode,
                    'jobStatus' => $request->jobStatus ?? 'permanent',
                    'incrementDate' => $request->incrementDate ?? now(),
                    'holdIncrement' => $request->has('holdIncrement') ? (bool)$request->holdIncrement : false,
                    'department_office_id' => $primaryDeptId,
                    'phone_number_id' => $primaryPhoneNumberId,
                    'address_id' => $primaryAddressId,
                    'bank_account_id' => $primaryBankAccountId,
                    'joining_date_id' => $joiningDate->id,
                    'nid_id' => $nid->id,
                    'bid_id' => $bid->id,
                    't_i_n_s_id' => $tin->id,
                    'employee_attribute_id' => $employeeAttrId,
                    'pay_scale_id' => $payScaleId,
                    'payroll_group_id' => $payrollGroupId,
                ]);

                // Automatically update salary calculation components
                if (function_exists('updateUserSalaryCalculationObjects')) {
                    updateUserSalaryCalculationObjects($user->id);
                }
            }

            // Cleanup old bank accounts that were removed, safely after employeeInfo is updated
            if (isset($keptBankAccountIds)) {
                $user->bankAccounts()->whereNotIn('id', $keptBankAccountIds)->delete();
            }
        });

        $user->load([
            'nid',
            'bid',
            'childCount',
            'maritialStatus',
            'religion',
            'joiningDate',
            'age',
            'dormitory',
            'employeeAttribute',
            'tin',
            'bankAccounts',
            'leaveStatuses',
            'promotions',
            'phoneNumbers',
            'addresses',
            'employeeInfo',
            'departmentOffices',
            'userCategories',
        ]);

        return response()->json([
            'message' => 'Employee updated successfully',
            'data' => $user
        ]);
    }

    public function destroy(User $user)
    {
        $user->delete();

        return response()->json([
            'message' => 'Employee deleted successfully'
        ]);
    }

    public function bulkProfileUpdateSingle(Request $request, User $user)
    {
        try {
            $validated = $request->validate([
                'fields' => 'required|array|min:1',
                'isActive' => 'nullable|boolean',
                'gender' => 'nullable|string|in:male,female,other',
                'jobStatus' => 'nullable|string|in:permanent,contract,temporary,others',
                'pay_scale_id' => 'nullable|exists:pay_scales,id',
                'payroll_group_id' => 'nullable|exists:payroll_groups,id',
                'incrementDate' => 'nullable|date',
                'holdIncrement' => 'nullable|boolean',
                'department_office_id' => 'nullable|exists:department_offices,id',
                'user_category_id' => 'nullable|exists:user_categories,id',
                'maritial_status' => 'nullable|string|max:50',
                'religion' => 'nullable|string|max:50',
                'onLeave' => 'nullable',
                'onLeave.onLeave' => 'nullable|boolean',
                'onLeave.reason' => 'nullable|string|max:255',
                'onLeave.isPaid' => 'nullable|boolean',
                'onLeave.duration' => 'nullable|numeric',
            ]);

            $fields = $request->input('fields', []);
            $changesSummary = [];

            DB::transaction(function () use ($request, $user, $fields, &$changesSummary) {
                $shouldUpdateSalaryCalc = false;

                // 1. User table core fields
                $userUpdates = [];
                if (in_array('isActive', $fields) && $request->has('isActive')) {
                    $active = filter_var($request->isActive, FILTER_VALIDATE_BOOLEAN);
                    $userUpdates['isActive'] = $active;
                    $changesSummary[] = 'Status: ' . ($active ? 'Active' : 'Inactive');
                }
                if (in_array('gender', $fields) && $request->filled('gender')) {
                    $userUpdates['gender'] = $request->gender;
                    $changesSummary[] = 'Gender: ' . ucfirst($request->gender);
                }
                if (!empty($userUpdates)) {
                    $user->update($userUpdates);
                }

                // 2. Department / Office
                if (in_array('department_office_id', $fields) && $request->filled('department_office_id')) {
                    $deptId = (int) $request->department_office_id;
                    $dept = \App\Models\DepartmentOffice::find($deptId);
                    $user->departmentOffices()->sync([$deptId]);
                    if ($user->employeeInfo) {
                        $user->employeeInfo->update(['department_office_id' => $deptId]);
                    } else {
                        $user->employeeInfo()->create([
                            'employeeCode' => (string) (1000 + $user->id),
                            'department_office_id' => $deptId,
                        ]);
                    }
                    $changesSummary[] = 'Department: ' . ($dept?->name ?? "ID #{$deptId}");
                    $shouldUpdateSalaryCalc = true;
                }

                // 3. User Category / Employee Group
                if (in_array('user_category_id', $fields) && $request->filled('user_category_id')) {
                    $catId = (int) $request->user_category_id;
                    $cat = \App\Models\UserCategory::find($catId);
                    $user->userCategories()->sync([$catId]);
                    $changesSummary[] = 'Group: ' . ($cat?->name ?? "ID #{$catId}");
                }

                // 4. Leave Status
                if (in_array('onLeave', $fields)) {
                    $onLeaveInput = $request->input('onLeave');
                    $isOnLeave = false;
                    $reason = 'Administrative Leave';
                    $isPaid = true;
                    $duration = 0;

                    if (is_array($onLeaveInput)) {
                        $isOnLeave = filter_var($onLeaveInput['onLeave'] ?? false, FILTER_VALIDATE_BOOLEAN);
                        $reason = !empty($onLeaveInput['reason']) ? $onLeaveInput['reason'] : $reason;
                        $isPaid = filter_var($onLeaveInput['isPaid'] ?? true, FILTER_VALIDATE_BOOLEAN);
                        $duration = (int) ($onLeaveInput['duration'] ?? 0);
                    } else {
                        $isOnLeave = filter_var($onLeaveInput, FILTER_VALIDATE_BOOLEAN);
                    }

                    if ($isOnLeave) {
                        $activeLeave = $user->leaveStatuses()->where('isActive', true)->first();
                        if ($activeLeave) {
                            $activeLeave->update([
                                'onLeave' => true,
                                'reason' => $reason,
                                'isPaid' => $isPaid,
                                'duration' => $duration,
                            ]);
                        } else {
                            $user->leaveStatuses()->create([
                                'onLeave' => true,
                                'reason' => $reason,
                                'isPaid' => $isPaid,
                                'duration' => $duration,
                                'isActive' => true,
                            ]);
                        }
                        $changesSummary[] = 'Leave: On Leave (' . $reason . ')';
                    } else {
                        $user->leaveStatuses()->update([
                            'onLeave' => false,
                            'isActive' => false,
                        ]);
                        $changesSummary[] = 'Leave: Normal (Not on Leave)';
                    }
                }

                // 5. Employee Info fields
                $empInfoUpdates = [];
                if (in_array('jobStatus', $fields) && $request->filled('jobStatus')) {
                    $empInfoUpdates['jobStatus'] = $request->jobStatus;
                    $changesSummary[] = 'Job: ' . ucfirst($request->jobStatus);
                }
                if (in_array('incrementDate', $fields) && $request->filled('incrementDate')) {
                    $empInfoUpdates['incrementDate'] = $request->incrementDate;
                    $changesSummary[] = 'Increment Date: ' . $request->incrementDate;
                    $shouldUpdateSalaryCalc = true;
                }
                if (in_array('holdIncrement', $fields) && $request->has('holdIncrement')) {
                    $hold = filter_var($request->holdIncrement, FILTER_VALIDATE_BOOLEAN);
                    $empInfoUpdates['holdIncrement'] = $hold;
                    $changesSummary[] = 'Increment: ' . ($hold ? 'Withheld' : 'Normal/Released');
                    $shouldUpdateSalaryCalc = true;
                }
                if (in_array('pay_scale_id', $fields) && $request->filled('pay_scale_id')) {
                    $empInfoUpdates['pay_scale_id'] = $request->pay_scale_id;
                    $scale = \App\Models\PayScale::find($request->pay_scale_id);
                    $changesSummary[] = 'Pay Scale: ' . ($scale?->scale_name ?? "Scale #{$request->pay_scale_id}");
                    $shouldUpdateSalaryCalc = true;
                }
                if (in_array('payroll_group_id', $fields) && $request->filled('payroll_group_id')) {
                    $empInfoUpdates['payroll_group_id'] = $request->payroll_group_id;
                    $pg = \App\Models\PayrollGroup::with('payrollGroupType')->find($request->payroll_group_id);
                    $desig = $pg?->payrollGroupType?->group_name || "Group #{$request->payroll_group_id}";
                    $changesSummary[] = 'Designation: ' . $desig;
                    $shouldUpdateSalaryCalc = true;
                }

                if (!empty($empInfoUpdates)) {
                    if ($user->employeeInfo) {
                        $user->employeeInfo->update($empInfoUpdates);
                    } else {
                        $defaultDept = \App\Models\DepartmentOffice::first();
                        $defaultScale = \App\Models\PayScale::first();
                        $defaultGroup = \App\Models\PayrollGroup::first();
                        $defaultPhone = $user->phoneNumbers()->first() ?? \App\Models\PhoneNumber::firstOrCreate(['phoneNumber' => 'N/A']);
                        $defaultAddress = $user->addresses()->first() ?? \App\Models\Address::firstOrCreate(['address' => 'N/A']);
                        $defaultBank = $user->bankAccounts()->first() ?? $user->bankAccounts()->create(['routing_no' => 'N/A', 'account_no' => 'N/A', 'type' => 'N/A', 'address' => 'N/A', 'isSalaryAccount' => false]);
                        $defaultJoining = $user->joiningDate ?? $user->joiningDate()->create(['joining_date' => now()]);
                        $defaultNid = $user->nid ?? $user->nid()->create(['nid_no' => 'N/A']);
                        $defaultBid = $user->bid ?? $user->bid()->create(['date_of_birth' => now(), 'bid_no' => 'N/A']);
                        $defaultTin = $user->tin ?? $user->tin()->create(['tin_no' => 'N/A']);

                        $fullDefaults = [
                            'employeeCode' => (string) (1000 + $user->id),
                            'department_office_id' => $defaultDept?->id ?? 1,
                            'phone_number_id' => $defaultPhone->id,
                            'address_id' => $defaultAddress->id,
                            'bank_account_id' => $defaultBank->id,
                            'joining_date_id' => $defaultJoining->id,
                            'nid_id' => $defaultNid->id,
                            'bid_id' => $defaultBid->id,
                            't_i_n_s_id' => $defaultTin->id,
                            'pay_scale_id' => $defaultScale?->id ?? 1,
                            'payroll_group_id' => $defaultGroup?->id ?? 1,
                            'incrementDate' => now(),
                            'holdIncrement' => false,
                            'jobStatus' => 'permanent',
                        ];

                        $user->employeeInfo()->create(array_merge($fullDefaults, $empInfoUpdates));
                    }
                }

                // 6. Demographics
                if (in_array('maritial_status', $fields) && $request->filled('maritial_status')) {
                    $user->maritialStatus()->updateOrCreate([], ['status' => $request->maritial_status]);
                    $changesSummary[] = 'Marital: ' . ucfirst($request->maritial_status);
                }
                if (in_array('religion', $fields) && $request->filled('religion')) {
                    $user->religion()->updateOrCreate([], ['religion' => $request->religion]);
                    $changesSummary[] = 'Religion: ' . $request->religion;
                }

                // 7. Salary calculation refresh
                if ($shouldUpdateSalaryCalc && function_exists('updateUserSalaryCalculationObjects')) {
                    try {
                        updateUserSalaryCalculationObjects($user->id);
                        $changesSummary[] = 'Salary components recalculated';
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning("Salary recalculation warning for user #{$user->id}: " . $e->getMessage());
                    }
                }
            });

            $employeeCode = $user->employeeInfo?->employeeCode ?? 'N/A';

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'Successfully updated ' . count($changesSummary) . ' setting(s).',
                'changes' => $changesSummary,
                'employee' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'employeeCode' => $employeeCode,
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $ve) {
            $allErrors = [];
            foreach ($ve->errors() as $fieldErrors) {
                foreach ($fieldErrors as $err) {
                    $allErrors[] = $err;
                }
            }
            return response()->json([
                'status' => 422,
                'success' => false,
                'message' => 'Validation error: ' . implode('; ', $allErrors),
                'errors' => $ve->errors(),
                'employee' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'employeeCode' => $user->employeeInfo?->employeeCode ?? 'N/A',
                ]
            ], 422);
        } catch (\Throwable $th) {
            \Illuminate\Support\Facades\Log::error("Bulk profile update failed for user #{$user->id}: " . $th->getMessage(), [
                'trace' => $th->getTraceAsString()
            ]);

            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'Database error: ' . $th->getMessage(),
                'employee' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'employeeCode' => $user->employeeInfo?->employeeCode ?? 'N/A',
                ]
            ], 500);
        }
    }

    public function history()
    {
        return Inertia::render('Employee/History');
    }
}
