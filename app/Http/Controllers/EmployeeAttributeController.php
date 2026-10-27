<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\BankAccount;
use App\Models\Bid;
use App\Models\ChildCount;
use App\Models\DepartmentOffice;
use App\Models\EmployeeInfo;
use App\Models\Grade;
use App\Models\JoiningDate;
use App\Models\Nid;
use App\Models\PayrollGroup;
use App\Models\PayrollGroupType;
use App\Models\PayScale;
use App\Models\PhoneNumber;
use App\Models\Role;
use App\Models\TIN;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EmployeeAttributeController extends Controller
{
    /**
     * Seed or test dynamic employee record from employeeInfo.json.
     *
     * @param Request|null $request
     * @param object|array|null $userInfo
     * @return JsonResponse
     */
    public function createDynamicEmployeeTest(?Request $request = null, object|array|null $userInfo = null): JsonResponse
    {
        $request = $request ?? request();
        if ($userInfo === null) {
            $jsonPath = database_path("seeders/employeeInfo.json");
            if (!file_exists($jsonPath)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'employeeInfo.json file not found at ' . $jsonPath,
                ], 404);
            }

            $employees = json_decode(file_get_contents($jsonPath));
            if (!is_array($employees) || empty($employees)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'employeeInfo.json is empty or contains invalid JSON.',
                ], 400);
            }

            $index = $request ? (int) $request->input('index', 0) : 0;
            $employeeCode = $request ? $request->input('employeeCode') : null;

            if ($employeeCode !== null) {
                $matched = array_values(array_filter($employees, function ($item) use ($employeeCode) {
                    return ((string)($item->employeeID ?? '')) === (string)$employeeCode;
                }));
                $userInfo = $matched[0] ?? $employees[0];
            } else {
                $userInfo = $employees[$index] ?? $employees[0];
            }
        }

        $result = $this->seedEmployee($userInfo);

        return response()->json($result, 200);
    }

    /**
     * Seed data in related tables for a given userInfo object using firstOrCreate.
     *
     * @param object|array $userInfo
     * @return array
     */
    public function seedEmployee(object|array $userInfo): array
    {
        $info = (object) $userInfo;

        $employeeID = !empty(trim((string)($info->employeeID ?? ''))) ? trim((string)$info->employeeID) : (string) mt_rand(10000, 99999);
        $userName = !empty(trim((string)($info->userName ?? ''))) ? trim((string)$info->userName) : "Employee {$employeeID}";
        $userEmail = !empty(trim((string)($info->userEmail ?? ''))) ? trim((string)$info->userEmail) : "emp_{$employeeID}@kau.edu.bd";
        $userGender = in_array(strtolower(trim((string)($info->userGender ?? ''))), ['male', 'female', 'other'])
            ? strtolower(trim((string)$info->userGender))
            : 'male';

        $roleName = !empty(trim((string)($info->roleName ?? ''))) ? trim((string)$info->roleName) : 'user';
        $departmentName = !empty(trim((string)($info->departmentName ?? ''))) ? trim((string)$info->departmentName) : 'General Administration';
        $payScaleName = !empty(trim((string)($info->payScaleName ?? ''))) ? trim((string)$info->payScaleName) : 'Govt. Pay Scale 2015';
        $gradeName = !empty(trim((string)($info->gradeName ?? ''))) ? trim((string)$info->gradeName) : 'Grade 1';
        $payrollGroupTypeName = !empty(trim((string)($info->payrollGroupTypeName ?? ''))) ? trim((string)$info->payrollGroupTypeName) : 'General Staff';
        $userCategoryName = !empty(trim((string)($info->userCategoryName ?? ''))) ? trim((string)$info->userCategoryName) : 'Staff';

        $passwordHash = !empty(trim((string)($info->passwordHash ?? ''))) ? trim((string)$info->passwordHash) : 'password123';
        $phoneNumber = !empty(trim((string)($info->phoneNumber ?? ''))) ? trim((string)$info->phoneNumber) : 'N/A';
        $bankAcountNo = !empty(trim((string)($info->bankAcountNo ?? ''))) ? trim((string)$info->bankAcountNo) : 'N/A';

        $validJobStatuses = ['permanent', 'contract', 'temporary', 'others'];
        $rawJobStatus = strtolower(trim((string)($info->userJobStatus ?? '')));
        $userJobStatus = in_array($rawJobStatus, $validJobStatuses) ? $rawJobStatus : 'permanent';

        $userJoiningDate = !empty(trim((string)($info->userJoiningDate ?? ''))) ? trim((string)$info->userJoiningDate) : now()->format('Y-m-d');
        $userChildCount = max(0, (int)($info->userChildCount ?? 0));
        $userIsActive = filter_var($info->userIsActive ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($userIsActive === null) {
            $userIsActive = ((string)($info->userIsActive ?? '1')) === '1';
        }

        $userNidNo = !empty(trim((string)($info->userNidNo ?? ''))) ? trim((string)$info->userNidNo) : 'N/A';
        $userDob = !empty(trim((string)($info->userDob ?? ''))) ? trim((string)$info->userDob) : null;
        $userTinCertNo = !empty(trim((string)($info->userTinCertNo ?? ''))) ? trim((string)$info->userTinCertNo) : 'N/A';
        $addressText = !empty(trim((string)($info->address ?? ''))) ? trim((string)$info->address) : 'Main Campus, Khulna Agricultural University, Khulna';

        return DB::transaction(function () use (
            $employeeID,
            $userName,
            $userEmail,
            $userGender,
            $roleName,
            $departmentName,
            $payScaleName,
            $gradeName,
            $payrollGroupTypeName,
            $userCategoryName,
            $passwordHash,
            $phoneNumber,
            $bankAcountNo,
            $userJobStatus,
            $userJoiningDate,
            $userChildCount,
            $userIsActive,
            $userNidNo,
            $userDob,
            $userTinCertNo,
            $addressText
        ) {
            // 1. Role
            $userRole = Role::firstOrCreate(
                ['role_name' => $roleName],
                [
                    'role_description' => "Role for {$roleName}",
                    'is_admin' => in_array(strtolower($roleName), ['superadmin', 'admin']),
                    'isActive' => true,
                ]
            );

            // 2. Department / Office
            $department = DepartmentOffice::firstOrCreate(
                ['name' => $departmentName],
                [
                    'type' => 'department',
                    'description' => "Department / Office of {$departmentName}",
                    'isActive' => true,
                ]
            );

            // 3. Pay Scale
            $payScale = PayScale::firstOrCreate(
                ['scale_name' => "Govt. Pay Scale 2015"],
                [
                    'scale_description' => "Pay Scale 2015",
                    'isActive' => true,
                ]
            );

            // 4. Grade
            $grade = Grade::firstOrCreate(
                [
                    'pay_scale_id' => $payScale->id,
                    'grade_name' => $gradeName,
                ],
                [
                    'grade_description' => "Grade {$gradeName}",
                    'isActive' => true,
                    'min' => 10000.00,
                    'max' => 20000.00,
                ]
            );

            // 5. Payroll Group Type
            $payrollGroupType = PayrollGroupType::firstOrCreate(
                ['group_name' => $payrollGroupTypeName],
                ['group_description' => "Designation / Payroll Group Type {$payrollGroupTypeName}"]
            );

            // 6. Payroll Group
            $payrollGroup = PayrollGroup::firstOrCreate(
                [
                    'grade_id' => $grade->id,
                    'payroll_group_type_id' => $payrollGroupType->id,
                ],
                [
                    'isActive' => true,
                ]
            );

            // 7. User Category
            $userCategory = UserCategory::firstOrCreate(
                ['name' => $userCategoryName],
                ['description' => "User Category: {$userCategoryName}"]
            );

            // 8. Phone Number
            $userPhoneNumber = PhoneNumber::firstOrCreate([
                'phoneNumber' => $phoneNumber,
            ]);

            // 9. Address
            $address = Address::firstOrCreate([
                'address' => $addressText,
            ]);

            // 10. User: Check by existing employeeCode or by unique email
            $existingEmployeeInfo = EmployeeInfo::where('employeeCode', (int)$employeeID)->first();
            if ($existingEmployeeInfo && $existingEmployeeInfo->user) {
                $user = $existingEmployeeInfo->user;
            } else {
                // If userEmail is already taken by a different employee, generate a unique email
                $existingUserByEmail = User::where('email', $userEmail)->first();
                if ($existingUserByEmail && $existingUserByEmail->employeeInfo && (string)$existingUserByEmail->employeeInfo->employeeCode !== (string)$employeeID) {
                    $userEmail = "emp_{$employeeID}@kau.edu.bd";
                    $counter = 1;
                    while (User::where('email', $userEmail)->whereDoesntHave('employeeInfo', fn($q) => $q->where('employeeCode', (int)$employeeID))->exists()) {
                        $userEmail = "emp_{$employeeID}_{$counter}@kau.edu.bd";
                        $counter++;
                    }
                }

                $user = User::firstOrCreate(
                    ['email' => $userEmail],
                    [
                        'name' => $userName,
                        'password' => Hash::make($passwordHash),
                        'isActive' => $userIsActive,
                        'gender' => $userGender,
                    ]
                );
            }

            // Sync pivot relations safely
            $user->roles()->syncWithoutDetaching([$userRole->id]);
            $user->departmentOffices()->syncWithoutDetaching([$department->id]);
            $user->userCategories()->syncWithoutDetaching([$userCategory->id]);
            $user->phoneNumbers()->syncWithoutDetaching([$userPhoneNumber->id]);
            $user->addresses()->syncWithoutDetaching([$address->id]);

            // 11. Bank Account
            $userBankAccount = BankAccount::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'account_no' => $bankAcountNo,
                ],
                [
                    'routing_no' => '666666',
                    'type' => 'Savings',
                    'address' => 'Sonali Bank PLC., Khulna Branch',
                    'isSalaryAccount' => true,
                ]
            );

            // 12. Joining Date
            $jDate = JoiningDate::firstOrCreate(
                ['user_id' => $user->id],
                ['joining_date' => $userJoiningDate]
            );

            // 13. Child Count
            $childCount = ChildCount::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'childCount' => $userChildCount,
                    'boy' => $userChildCount,
                    'girl' => 0,
                ]
            );

            // 14. Bid
            $bid = Bid::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'bid_no' => 'N/A',
                    'date_of_birth' => $userDob,
                    'bidImg' => null,
                ]
            );

            // 15. Nid
            $nid = Nid::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'nid_no' => $userNidNo,
                    'nid_img' => null,
                ]
            );

            // 16. TIN
            $tin = TIN::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'tin_no' => $userTinCertNo,
                    'tin_img' => null,
                ]
            );

            // 17. Employee Info
            $employeeInfo = EmployeeInfo::firstOrCreate(
                ['employeeCode' => (int)$employeeID],
                [
                    'user_id' => $user->id,
                    'jobStatus' => $userJobStatus,
                    'incrementDate' => date('Y-m-d'),
                    'holdIncrement' => false,
                    'department_office_id' => $department->id,
                    'phone_number_id' => $userPhoneNumber->id,
                    'address_id' => $address->id,
                    'bank_account_id' => $userBankAccount->id,
                    'joining_date_id' => $jDate->id,
                    'nid_id' => $nid->id,
                    'bid_id' => $bid->id,
                    't_i_n_s_id' => $tin->id,
                    'employee_attribute_id' => null,
                    'pay_scale_id' => $payScale->id,
                    'payroll_group_id' => $payrollGroup->id,
                    'additionObjects' => null,
                    'substractionObjects' => null,
                ]
            );

            // 18. Initialize salary calculation components if basic component exists
            if (function_exists('updateUserSalaryCalculationObjects')) {
                updateUserSalaryCalculationObjects($user->id);
            }

            return [
                'status' => 'success',
                'message' => 'Employee record processed successfully',
                'employeeCode' => (int)$employeeID,
                'user_id' => $user->id,
                'user' => $user,
                'employeeInfo' => $employeeInfo,
                'department' => $department,
                'role' => $userRole,
                'payScale' => $payScale,
                'grade' => $grade,
                'payrollGroup' => $payrollGroup,
                'userCategory' => $userCategory,
                'bankAccount' => $userBankAccount,
                'phoneNumber' => $userPhoneNumber,
                'address' => $address,
                'joiningDate' => $jDate,
                'childCount' => $childCount,
                'bid' => $bid,
                'nid' => $nid,
                'tin' => $tin,
            ];
        });
    }
}
