<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\BankAccount;
use App\Models\Bid;
use App\Models\ChildCount;
use App\Models\DepartmentOffice;
use App\Models\Dormitory;
use App\Models\EmployeeAttribute;
use App\Models\EmployeeInfo;
use App\Models\Grade;
use App\Models\JoiningDate;
use App\Models\LeaveStatus;
use App\Models\MaritialStatus;
use App\Models\Nid;
use App\Models\PayScale;
use App\Models\PayrollComponent;
use App\Models\PayrollGroup;
use App\Models\PayrollGroupType;
use App\Models\PhoneNumber;
use App\Models\Promotion;
use App\Models\Religion;
use App\Models\Role;
use App\Models\TIN;
use App\Models\User;
use App\Models\UserCategory;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @param int $count Number of employees to generate (default 500)
     */
    public function run(int $count = 500): void
    {
        // Ensure user role exists
        $userRole = Role::firstOrCreate(
            ['role_name' => 'user'],
            [
                'role_description' => 'User has access to personalized features',
                'is_admin' => false,
                'isActive' => true,
            ]
        );

        // Fetch or create departments
        $departments = DepartmentOffice::all();
        if ($departments->isEmpty()) {
            $departments = collect([
                DepartmentOffice::firstOrCreate(
                    ['name' => 'Department of Agriculture'],
                    ['type' => 'department', 'description' => 'Faculty of Agriculture', 'isActive' => true]
                ),
                DepartmentOffice::firstOrCreate(
                    ['name' => 'Department of Fisheries and Marine Bioscience'],
                    ['type' => 'department', 'description' => 'Faculty of Fisheries', 'isActive' => true]
                ),
                DepartmentOffice::firstOrCreate(
                    ['name' => 'Office of the Registrar'],
                    ['type' => 'office', 'description' => 'Administrative Office', 'isActive' => true]
                ),
                DepartmentOffice::firstOrCreate(
                    ['name' => 'General Administration'],
                    ['type' => 'office', 'description' => 'General Office & Human Resources', 'isActive' => true]
                ),
                DepartmentOffice::firstOrCreate(
                    ['name' => 'Department of Veterinary Medicine'],
                    ['type' => 'department', 'description' => 'Faculty of Animal Science and Veterinary Medicine', 'isActive' => true]
                ),
            ]);
        }

        // Fetch or fallback for PayScale and PayrollGroup
        $payScales = PayScale::all();
        if ($payScales->isEmpty()) {
            $defaultScale = PayScale::create([
                'scale_name' => 'National Pay Scale 2015',
                'scale_description' => 'Bangladesh National Pay Scale',
                'isActive' => true,
            ]);
            $payScales = collect([$defaultScale]);
        }

        $payrollGroups = PayrollGroup::with(['grade', 'payrollGroupType'])->get();
        if ($payrollGroups->isEmpty()) {
            $firstScale = $payScales->first();
            $grade = Grade::create([
                'pay_scale_id' => $firstScale->id,
                'grade_name' => 'Grade 1',
                'grade_description' => 'Top Senior Grade',
                'min' => 78000,
                'max' => 78000,
                'isActive' => true,
            ]);
            $groupType = PayrollGroupType::firstOrCreate(
                ['group_name' => 'Professor'],
                ['group_description' => 'Faculty Member']
            );
            $defaultGroup = PayrollGroup::create([
                'grade_id' => $grade->id,
                'payroll_group_type_id' => $groupType->id,
                'isActive' => true,
            ]);
            $payrollGroups = collect([$defaultGroup->load(['grade', 'payrollGroupType'])]);
        }

        // Fetch or fallback for UserCategory
        $userCategories = UserCategory::all();
        if ($userCategories->isEmpty()) {
            $userCategories = collect([
                UserCategory::firstOrCreate(['name' => 'Teacher'], ['description' => 'Faculty / Teaching Staff']),
                UserCategory::firstOrCreate(['name' => 'Officer'], ['description' => 'Administrative Officer']),
                UserCategory::firstOrCreate(['name' => 'Staff'], ['description' => 'Support Staff']),
            ]);
        }

        // Pre-cache allowances and deductions for each payroll group
        $payrollGroupComponents = [];
        foreach ($payrollGroups as $pg) {
            $allowances = PayrollComponent::where('payroll_group_id', $pg->id)
                ->where('component_type', 'allowance')
                ->get();
            $deductions = PayrollComponent::where('payroll_group_id', $pg->id)
                ->where('component_type', 'deduction')
                ->get();
            $payrollGroupComponents[$pg->id] = [
                'addition' => $allowances->isNotEmpty() ? json_encode($allowances) : null,
                'substraction' => $deductions->isNotEmpty() ? json_encode($deductions) : null,
            ];
        }

        // Cache password hash once to avoid repeated bcrypt overhead
        $passwordHash = Hash::make('password123');
        $startEmployeeCode = (EmployeeInfo::max('employeeCode') ?? 10000) + 1;
        $startUserId = (User::max('id') ?? 0) + 1;
        $startPhoneId = (PhoneNumber::max('id') ?? 0) + 1;
        $startBankId = (BankAccount::max('id') ?? 0) + 1;
        $startNidId = (Nid::max('id') ?? 0) + 1;
        $startBidId = (Bid::max('id') ?? 0) + 1;
        $startTinId = (TIN::max('id') ?? 0) + 1;

        // Process generation in chunks for memory and transaction efficiency
        $chunkSize = 50;
        for ($chunkStart = 0; $chunkStart < $count; $chunkStart += $chunkSize) {
            $currentChunk = min($chunkSize, $count - $chunkStart);

            DB::transaction(function () use (
                $chunkStart,
                $currentChunk,
                $userRole,
                $departments,
                $payScales,
                $payrollGroups,
                $payrollGroupComponents,
                $userCategories,
                $passwordHash,
                $startEmployeeCode,
                $startUserId,
                $startPhoneId,
                $startBankId,
                $startNidId,
                $startBidId,
                $startTinId
            ) {
                for ($offset = 0; $offset < $currentChunk; $offset++) {
                    $i = $chunkStart + $offset;
                    $employeeCode = $startEmployeeCode + $i;
                    $userSeqId = $startUserId + $i;

                    $gender = fake()->randomElement(['male', 'female']);
                    $name = fake()->name($gender);
                    $email = strtolower('emp.' . $userSeqId . '.' . fake()->unique()->userName() . '@kau.edu.bd');

                    $dob = fake()->dateTimeBetween('-62 years', '-24 years')->format('Y-m-d');
                    $age = Carbon::parse($dob)->age;
                    $joiningDate = fake()->dateTimeBetween('-12 years', '-1 month')->format('Y-m-d');
                    $maritalStatus = fake()->randomElement(['married', 'married', 'single', 'divorced']);
                    $boy = $maritalStatus === 'single' ? 0 : fake()->numberBetween(0, 2);
                    $girl = $maritalStatus === 'single' ? 0 : fake()->numberBetween(0, 2);

                    // Create User
                    $user = User::create([
                        'name' => $name,
                        'email' => $email,
                        'password' => $passwordHash,
                        'gender' => $gender,
                        'isActive' => fake()->boolean(92),
                    ]);

                    // Attach User Role
                    $user->roles()->attach($userRole->id);

                    // HasOne Relations
                    $nid = $user->nid()->create([
                        'nid_no' => sprintf('19%02d%013d', fake()->numberBetween(60, 99), $startNidId + $i),
                        'nid_img' => null,
                    ]);

                    $bid = $user->bid()->create([
                        'bid_no' => sprintf('20%02d%013d', fake()->numberBetween(0, 24), $startBidId + $i),
                        'date_of_birth' => $dob,
                        'bidImg' => null,
                    ]);

                    $tin = $user->tin()->create([
                        'tin_no' => sprintf('%012d', 100000000000 + $startTinId + $i),
                        'tin_img' => null,
                    ]);

                    $jDate = $user->joiningDate()->create([
                        'joining_date' => $joiningDate,
                    ]);

                    $user->childCount()->create([
                        'childCount' => $boy + $girl,
                        'boy' => $boy,
                        'girl' => $girl,
                    ]);

                    $user->maritialStatus()->create([
                        'status' => $maritalStatus,
                    ]);

                    $user->religion()->create([
                        'religion' => fake()->randomElement(['Islam', 'Hinduism', 'Christianity', 'Buddhism', 'Other']),
                    ]);

                    $user->age()->create([
                        'age' => $age,
                    ]);

                    $user->dormitory()->create([
                        'dorm_name' => 'Quarter ' . fake()->randomElement(['A-', 'B-', 'C-', 'Staff-']) . fake()->numberBetween(1, 30),
                        'dorm_type' => fake()->randomElement(['Teacher Quarter', 'Faculty Housing', 'Officer Quarter', 'Staff Quarter', 'Residential Flat']),
                        'dorm_address' => 'KAU Campus, Khulna',
                    ]);

                    $employeeAttr = $user->employeeAttribute()->create([
                        'name' => fake()->randomElement([
                            'Department Head',
                            'Senior Researcher',
                            'Administrative Officer',
                            'Assistant Proctor',
                            'Internal Auditor',
                            'Committee Member',
                            'Lab In-charge',
                            'Course Coordinator',
                        ]),
                        'description' => fake()->sentence(6),
                        'isActive' => fake()->boolean(60),
                    ]);

                    // Bank Account
                    $bankAccount = $user->bankAccounts()->create([
                        'routing_no' => fake()->numerify('1########'),
                        'account_no' => sprintf('205011%08d', $startBankId + $i),
                        'type' => fake()->randomElement(['Savings', 'Current']),
                        'address' => fake()->randomElement([
                            'Sonali Bank, KAU Campus Branch',
                            'Janata Bank, Gollamari Branch',
                            'Agrani Bank, Daulatpur Branch',
                            'Rupali Bank, Khulna Corporate Branch',
                        ]),
                        'isSalaryAccount' => true,
                    ]);

                    // Phone Number
                    $phone = PhoneNumber::firstOrCreate([
                        'phoneNumber' => sprintf('+88017%08d', 10000000 + $startPhoneId + $i),
                    ]);
                    $user->phoneNumbers()->syncWithoutDetaching([$phone->id]);

                    // Address
                    $address = Address::firstOrCreate([
                        'address' => fake()->streetAddress() . ', ' . fake()->city(),
                    ]);
                    $user->addresses()->syncWithoutDetaching([$address->id]);

                    // Department Office
                    $dept = $departments->get($i % $departments->count());
                    $assignedDeptIds = [$dept->id];
                    if (fake()->boolean(20) && $departments->count() > 1) {
                        $secondaryDept = $departments->where('id', '!=', $dept->id)->random();
                        $assignedDeptIds[] = $secondaryDept->id;
                    }
                    $user->departmentOffices()->attach($assignedDeptIds);

                    // User Categories
                    if ($userCategories->isNotEmpty()) {
                        $catCount = fake()->randomElement([1, 1, 2]);
                        $assignedCats = $userCategories->random(min($catCount, $userCategories->count()))->pluck('id')->toArray();
                        $user->userCategories()->attach($assignedCats);
                    }

                    // Leave Status
                    $user->leaveStatuses()->create([
                        'onLeave' => fake()->boolean(10),
                        'reason' => fake()->randomElement([
                            'Annual Leave',
                            'Maternity Leave',
                            'Casual Leave',
                            'Earned Leave',
                            'Medical Leave',
                            'Study Leave',
                        ]),
                        'isPaid' => fake()->boolean(80),
                        'isActive' => fake()->boolean(30),
                        'duration' => fake()->numberBetween(0, 30),
                    ]);

                    // Promotion & Payroll
                    $payrollGroup = $payrollGroups->get($i % $payrollGroups->count());
                    $payScaleId = $payrollGroup->grade?->pay_scale_id ?? $payScales->first()->id;
                    $payrollGroupId = $payrollGroup->id;

                    $user->promotions()->create([
                        'pay_scale_id' => $payScaleId,
                        'payroll_group_id' => $payrollGroupId,
                    ]);

                    // Employee Info
                    $user->employeeInfo()->create([
                        'employeeCode' => $employeeCode,
                        'jobStatus' => fake()->randomElement(['permanent', 'contract', 'temporary', 'others']),
                        'incrementDate' => '2026-07-01',
                        'holdIncrement' => fake()->boolean(10),
                        'department_office_id' => $dept->id,
                        'phone_number_id' => $phone->id,
                        'address_id' => $address->id,
                        'bank_account_id' => $bankAccount->id,
                        'joining_date_id' => $jDate->id,
                        'nid_id' => $nid->id,
                        'bid_id' => $bid->id,
                        't_i_n_s_id' => $tin->id,
                        'employee_attribute_id' => $employeeAttr->id,
                        'pay_scale_id' => $payScaleId,
                        'payroll_group_id' => $payrollGroupId,
                        'additionObjects' => $payrollGroupComponents[$payrollGroupId]['addition'] ?? null,
                        'substractionObjects' => $payrollGroupComponents[$payrollGroupId]['substraction'] ?? null,
                    ]);
                }
            });
        }
    }
}
