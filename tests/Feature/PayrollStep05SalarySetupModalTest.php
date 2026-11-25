<?php

namespace Tests\Feature;

use App\Models\EmployeeInfo;
use App\Models\SalaryMonthlyComponentAdjustment;
use App\Models\User;
use App\Services\MonthlySalarySetupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * STEP 05 / Micro-Task 3A: Monthly Salary Setup Modal HTTP endpoints & accounting invariants.
 * Runs strictly on in-memory SQLite from phpunit.xml; never touches MySQL.
 */
final class PayrollStep05SalarySetupModalTest extends TestCase
{
    private User $admin;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->buildSchema();
        $this->seedFixtures();
        $this->admin = User::findOrFail(1);
        $this->employee = User::findOrFail(12);
    }

    private function buildSchema(): void
    {
        Schema::create('pay_scales', function (Blueprint $t) {
            $t->id();
            $t->string('scale_name');
            $t->timestamps();
        });
        Schema::create('grades', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('pay_scale_id');
            $t->string('grade_name');
            $t->timestamps();
        });
        Schema::create('payroll_group_types', function (Blueprint $t) {
            $t->id();
            $t->string('group_name');
            $t->timestamps();
        });
        Schema::create('payroll_groups', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('grade_id');
            $t->unsignedBigInteger('payroll_group_type_id')->nullable();
            $t->timestamps();
        });
        Schema::create('department_offices', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('isActive')->default(true);
            $t->timestamps();
        });
        Schema::create('payroll_component_types', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->text('component_description')->nullable();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->boolean('isActive')->default(true);
            $t->timestamps();
        });
        Schema::create('employee_infos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('department_office_id')->nullable();
            $t->unsignedBigInteger('pay_scale_id')->nullable();
            $t->unsignedBigInteger('payroll_group_id')->nullable();
            $t->string('employeeCode');
            $t->string('jobStatus')->default('permanent');
            $t->boolean('holdIncrement')->default(false);
            $t->longText('additionObjects')->nullable();
            $t->longText('substractionObjects')->nullable();
            $t->timestamps();
        });
        Schema::create('salary_monthly_component_adjustments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedSmallInteger('year');
            $t->unsignedTinyInteger('month');
            $t->unsignedBigInteger('payroll_component_type_id')->index();
            $t->decimal('arrear_amount', 12, 2)->default(0.00);
            $t->boolean('rule_override')->default(false);
            $t->decimal('override_current_amount', 12, 2)->nullable();
            $t->text('comment')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'year', 'month', 'payroll_component_type_id'], 'smca_user_ym_component_unique');
        });
    }

    private function seedFixtures(): void
    {
        DB::table('pay_scales')->insert([
            ['id' => 1, 'scale_name' => 'Govt. Pay Scale 2015'],
        ]);
        DB::table('grades')->insert([
            ['id' => 1, 'pay_scale_id' => 1, 'grade_name' => 'Grade 6'],
        ]);
        DB::table('payroll_group_types')->insert([
            ['id' => 1, 'group_name' => 'Associate Professor'],
        ]);
        DB::table('payroll_groups')->insert([
            ['id' => 1, 'grade_id' => 1, 'payroll_group_type_id' => 1],
        ]);
        DB::table('department_offices')->insert([
            ['id' => 1, 'name' => 'Agronomy', 'isActive' => 1],
        ]);

        // 19 master components: 14 allowances + 5 deductions.
        DB::table('payroll_component_types')->insert([
            ['id' => 1, 'name' => 'Bangla New Year Allowance'],
            ['id' => 2, 'name' => 'Basic Pay'],
            ['id' => 3, 'name' => 'Book Allowance'],
            ['id' => 4, 'name' => 'Charge Allowance'],
            ['id' => 5, 'name' => 'Education Allowance'],
            ['id' => 6, 'name' => 'Festival Allowance'],
            ['id' => 7, 'name' => 'House Rent Allowance'],
            ['id' => 8, 'name' => 'Medical Allowance'],
            ['id' => 9, 'name' => 'Mobile Allowance'],
            ['id' => 10, 'name' => 'Others Allowance'],
            ['id' => 11, 'name' => 'Special benefit'],
            ['id' => 12, 'name' => 'Telephone call allowance'],
            ['id' => 13, 'name' => 'Tiffin Allowance'],
            ['id' => 14, 'name' => 'Travel allowance'],
            ['id' => 15, 'name' => 'Deduction (for use of office vehicles)'],
            ['id' => 16, 'name' => 'General Provident Fund'],
            ['id' => 17, 'name' => 'Income Tax'],
            ['id' => 18, 'name' => 'Others Deduction'],
            ['id' => 19, 'name' => 'Revenue Stamp'],
        ]);

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'HR Admin', 'email' => 'hr@kau.ac.bd', 'isActive' => 1],
            ['id' => 12, 'name' => 'Dr. Test Employee', 'email' => 'emp12@kau.ac.bd', 'isActive' => 1],
        ]);

        DB::table('employee_infos')->insert([
            'id' => 12,
            'user_id' => 12,
            'department_office_id' => 1,
            'pay_scale_id' => 1,
            'payroll_group_id' => 1,
            'employeeCode' => '10012',
            'jobStatus' => 'permanent',
            'holdIncrement' => 1,
            'additionObjects' => json_encode([
                ['payroll_component_type_id' => 2, 'name' => 'Basic Pay', 'calcType' => 'fixed', 'amount' => 37280.00],
                ['payroll_component_type_id' => 7, 'name' => 'House Rent Allowance', 'calcType' => 'fixed', 'amount' => 15700.00],
                ['payroll_component_type_id' => 8, 'name' => 'Medical Allowance', 'calcType' => 'fixed', 'amount' => 1500.00],
                ['payroll_component_type_id' => 11, 'name' => 'Special benefit', 'calcType' => 'fixed', 'amount' => 3728.00],
            ]),
            'substractionObjects' => json_encode([
                ['payroll_component_type_id' => 16, 'name' => 'General Provident Fund', 'calcType' => 'percentage', 'amount' => 10],
                ['payroll_component_type_id' => 19, 'name' => 'Revenue Stamp', 'calcType' => 'fixed', 'amount' => 10.00],
            ]),
        ]);
    }

    public function test_get_setup_returns_19_components_totals_lock_flag_and_employee_metadata(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/salary-setup/12?year=2026&month=11');

        $response->assertOk()
            ->assertJsonPath('user_id', 12)
            ->assertJsonPath('year', 2026)
            ->assertJsonPath('month', 11)
            ->assertJsonPath('is_locked', false)
            ->assertJsonPath('employee.code', '10012')
            ->assertJsonPath('employee.name', 'Dr. Test Employee')
            ->assertJsonPath('employee.designation', 'Associate Professor')
            ->assertJsonPath('employee.grade', 'Grade 6')
            ->assertJsonPath('employee.pay_scale_year', '2015')
            ->assertJsonPath('employee.badges.increment_hold', true)
            ->assertJsonCount(14, 'allowances')
            ->assertJsonCount(5, 'deductions');

        $json = $response->json();
        $this->assertEquals(58208.00, $json['regular_allowances']);
        $this->assertEquals(3738.00, $json['regular_deductions']);
        $this->assertEquals(54470.00, $json['regular_net']);
        $this->assertEquals(0.00, $json['net_arrear']);
        $this->assertEquals(54470.00, $json['net_payable']);

        // Locked historical month 2026-10 must report is_locked = true.
        $octResponse = $this->actingAs($this->admin)
            ->getJson('/salary-setup/12?year=2026&month=10');
        $octResponse->assertOk()->assertJsonPath('is_locked', true);
    }

    public function test_post_month_persists_november_arrear_and_keeps_december_at_zero(): void
    {
        $saveResponse = $this->actingAs($this->admin)
            ->postJson('/salary-setup/12/month', [
                'year' => 2026,
                'month' => 11,
                'components' => [
                    [
                        'payroll_component_type_id' => 11, // Special benefit
                        'arrear_amount' => 3728.00,
                        'rule_override' => false,
                        'comment' => 'Oct 2026 Special Benefit arrear',
                    ],
                    [
                        'payroll_component_type_id' => 16, // GPF refund
                        'arrear_amount' => -1000.00,
                        'rule_override' => false,
                        'comment' => 'GPF refund adjustment',
                    ],
                ],
            ]);

        $saveResponse->assertOk();
        $nov = $saveResponse->json();
        $this->assertEquals(3728.00, $nov['arrear_allowances']);
        $this->assertEquals(-1000.00, $nov['arrear_deductions']);
        $this->assertEquals(4728.00, $nov['net_arrear']);
        $this->assertEquals(59198.00, $nov['net_payable']);

        // December must start fresh with 0.00 arrears.
        $decResponse = $this->actingAs($this->admin)
            ->getJson('/salary-setup/12?year=2026&month=12');
        $decResponse->assertOk();
        $dec = $decResponse->json();
        $this->assertEquals(0.00, $dec['arrear_allowances']);
        $this->assertEquals(0.00, $dec['arrear_deductions']);
        $this->assertEquals(0.00, $dec['net_arrear']);
        $this->assertEquals(54470.00, $dec['net_payable']);
    }

    public function test_post_month_blocks_locked_october_2026_with_http_422_and_requires_comment_for_basic_override(): void
    {
        // 1. Locked month 2026-10 returns HTTP 422.
        $lockedResponse = $this->actingAs($this->admin)
            ->postJson('/salary-setup/12/month', [
                'year' => 2026,
                'month' => 10,
                'components' => [
                    [
                        'payroll_component_type_id' => 11,
                        'arrear_amount' => 3728.00,
                        'rule_override' => false,
                    ],
                ],
            ]);

        $lockedResponse->assertStatus(422)
            ->assertJsonPath('is_locked', true);
        $this->assertSame(0, SalaryMonthlyComponentAdjustment::count());

        // 2. Basic Pay rule_override without comment returns HTTP 422.
        $noCommentResponse = $this->actingAs($this->admin)
            ->postJson('/salary-setup/12/month', [
                'year' => 2026,
                'month' => 11,
                'components' => [
                    [
                        'payroll_component_type_id' => 2, // Basic Pay
                        'arrear_amount' => 0,
                        'rule_override' => true,
                        'override_current_amount' => 39000.00,
                        'comment' => '   ',
                    ],
                ],
            ]);

        $noCommentResponse->assertStatus(422);
        $this->assertSame(0, SalaryMonthlyComponentAdjustment::count());
    }

    public function test_post_recurring_updates_profile_without_copying_arrear_or_rule_override(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/salary-setup/12/recurring', [
                'year' => 2026,
                'month' => 11,
                'components' => [
                    [
                        'payroll_component_type_id' => 2, // Basic Pay
                        'calcType' => 'fixed',
                        'amount' => 38000.00,
                        'arrear_amount' => 5000.00, // must be ignored
                        'rule_override' => true,     // must be ignored
                    ],
                    [
                        'payroll_component_type_id' => 7, // House Rent
                        'calcType' => 'fixed',
                        'amount' => 16000.00,
                    ],
                    [
                        'payroll_component_type_id' => 8, // Medical
                        'calcType' => 'fixed',
                        'amount' => 1500.00,
                    ],
                    [
                        'payroll_component_type_id' => 16, // GPF
                        'calcType' => 'percentage',
                        'amount' => 10,
                    ],
                    [
                        'payroll_component_type_id' => 19, // Revenue Stamp
                        'calcType' => 'fixed',
                        'amount' => 10.00,
                    ],
                ],
            ]);

        $response->assertOk();

        $profile = EmployeeInfo::where('user_id', 12)->firstOrFail();
        $additions = $profile->additionObjects;
        $basic = collect($additions)->firstWhere('payroll_component_type_id', 2);

        $this->assertNotNull($basic);
        $this->assertEquals(38000.00, $basic['amount']);
        $this->assertArrayNotHasKey('arrear_amount', $basic);
        $this->assertArrayNotHasKey('rule_override', $basic);

        // No monthly adjustment rows should be created by recurring save.
        $this->assertSame(0, SalaryMonthlyComponentAdjustment::count());
        $this->assertEquals(0.00, $response->json('net_arrear'));
    }

    public function test_estimation_index_accepts_november_2026_and_marks_october_2026_as_locked(): void
    {
        // November 2026: open month, active employee visible with live totals.
        $novResponse = $this->actingAs($this->admin)
            ->get('/dashboard/salary/estimation?year=2026&month=11');

        $novResponse->assertOk();
        $novProps = $novResponse->viewData('page')['props'];
        $this->assertSame(2026, $novProps['year']);
        $this->assertSame(11, $novProps['month']);
        $this->assertFalse($novProps['isLocked']);
        $this->assertSame(1, $novProps['summary']['active_eligible']);
        $this->assertEquals(54470.00, $novProps['summary']['regular_net_total']);
        $this->assertEquals(54470.00, $novProps['summary']['net_payable_total']);

        // October 2026: locked historical month.
        $octResponse = $this->actingAs($this->admin)
            ->get('/dashboard/salary/estimation?year=2026&month=10');

        $octResponse->assertOk();
        $octProps = $octResponse->viewData('page')['props'];
        $this->assertSame(2026, $octProps['year']);
        $this->assertSame(10, $octProps['month']);
        $this->assertTrue($octProps['isLocked']);
    }

    public function test_default_employee_types_include_payable_profiles_and_count_the_whole_roster(): void
    {
        $profile = (array) DB::table('employee_infos')->where('id', 12)->first();
        $employees = [
            37 => ['10037', 'permanent', true],
            23 => ['20023', 'temporary', true],
            90 => ['30090', 'temporary', true],
            91 => ['30091', 'contract', true],
            92 => ['30092', 'suspend', true],
            93 => ['30093', 'permanent', false],
            94 => ['30094', 'permanent', true],
            95 => ['30095', 'permanent', true],
        ];
        foreach ($employees as $id => [$code, $status, $active]) {
            DB::table('users')->insert(['id' => $id, 'name' => "Employee {$code}", 'isActive' => $active]);
            DB::table('employee_infos')->insert(array_replace($profile, [
                'id' => $id,
                'user_id' => $id,
                'employeeCode' => $code,
                'jobStatus' => $status,
            ]));
        }
        Schema::create('leave_statuses', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id');
            $t->boolean('onLeave');
            $t->boolean('isPaid');
            $t->boolean('isActive');
        });
        DB::table('leave_statuses')->insert([
            ['user_id' => 37, 'onLeave' => true, 'isPaid' => true, 'isActive' => true],
            ['user_id' => 94, 'onLeave' => true, 'isPaid' => false, 'isActive' => true],
            ['user_id' => 95, 'onLeave' => true, 'isPaid' => true, 'isActive' => true],
        ]);
        DB::table('salary_monthly_component_adjustments')->insert([
            'user_id' => 23,
            'year' => 2026,
            'month' => 11,
            'payroll_component_type_id' => 11,
            'arrear_amount' => 2821.50,
        ]);

        $url = '/dashboard/salary/estimation?year=2026&month=11';
        $response = $this->actingAs($this->admin)->get($url)->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame(
            ['Active', 'Ad Hoc', 'Contractual', 'Study Leave With Pay', 'Temporary'],
            $props['filters']['employee_types']
        );
        $this->assertSame(6, $props['summary']['active_eligible']);
        $this->assertEquals(2821.50, $props['summary']['net_arrear_total']);
        $this->assertSame([
            10012 => 'Study Leave With Pay',
            10037 => 'Active',
            20023 => 'Ad Hoc',
            30090 => 'Temporary',
            30091 => 'Contractual',
            30095 => 'Study Leave With Pay',
        ], collect($props['exportRows'])->pluck('employee_type', 'employee_code')->all());

        $adHoc = $this->get($url . '&employee_types[]=Ad+Hoc&search=20023')->assertOk()->viewData('page')['props'];
        $this->assertSame(['20023'], collect($adHoc['exportRows'])->pluck('employee_code')->all());
        $this->assertEquals([
            'Active' => 1,
            'Ad Hoc' => 1,
            'Contractual' => 1,
            'Inactive' => 1,
            'Study Leave With Pay' => 2,
            'Study Leave Without Pay' => 1,
            'Suspend' => 1,
            'Temporary' => 1,
        ], collect($adHoc['filterOptions']['employee_types'])->pluck('count', 'value')->all());
    }

    public function test_leave_with_pay_10012_keeps_2015_june_basic_and_employee_type_filter_works(): void
    {
        // Create fixation tables and insert an approved 2026 fixation schedule for 10012 (52,208.00)
        // to prove Leave-With-Pay-Without-Increment stays on 2015 June Basic (37,280.00).
        Schema::create('salary_fixations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('status')->default('approved');
            $t->timestamps();
        });
        Schema::create('salary_fixation_pay_schedules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('salary_fixation_id');
            $t->unsignedBigInteger('user_id');
            $t->date('effective_date');
            $t->unsignedTinyInteger('grade_number');
            $t->decimal('payable_basic', 12, 2);
            $t->timestamps();
        });
        Schema::create('salary_generates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('reviewStatus')->default('pending');
            $t->string('name');
            $t->string('age')->nullable();
            $t->string('gender')->nullable();
            $t->string('payScale')->nullable();
            $t->string('grade')->nullable();
            $t->string('designation')->nullable();
            $t->string('employeeCode')->nullable();
            $t->string('bankAccountNo')->nullable();
            $t->string('bankAddress')->nullable();
            $t->string('employeePhoneNumber')->nullable();
            $t->date('joiningDate')->nullable();
            $t->date('salaryGenerationDate')->nullable();
            $t->unsignedTinyInteger('monthId');
            $t->unsignedSmallInteger('salaryGenerationYear');
            $t->longText('salaryAllowanceCalcObject')->nullable();
            $t->longText('salaryDeductionCalcObject')->nullable();
            $t->decimal('totalAllowance', 12, 2)->default(0);
            $t->decimal('totalDeduction', 12, 2)->default(0);
            $t->decimal('taxDeduction', 12, 2)->default(0);
            $t->decimal('gpfDeduction', 12, 2)->default(0);
            $t->decimal('salaryTotal', 12, 2)->default(0);
            $t->longText('bonusAllowanceCalcObject')->nullable();
            $t->longText('bonusDeductionCalcObject')->nullable();
            $t->decimal('bonusAllowance', 12, 2)->default(0);
            $t->decimal('bonusDeduction', 12, 2)->default(0);
            $t->decimal('bonusTaxDeduction', 12, 2)->default(0);
            $t->decimal('bonusTotal', 12, 2)->default(0);
            $t->decimal('loanInstallmentDeduction', 12, 2)->default(0);
            $t->integer('loanInstallmentCount')->nullable();
            $t->integer('loanInstallmentRemaining')->nullable();
            $t->timestamps();
        });

        DB::table('salary_fixations')->insert([
            ['id' => 1, 'user_id' => 12, 'status' => 'approved'],
        ]);
        DB::table('salary_fixation_pay_schedules')->insert([
            [
                'id' => 1,
                'salary_fixation_id' => 1,
                'user_id' => 12,
                'effective_date' => '2026-07-01',
                'grade_number' => 6,
                'payable_basic' => 52208.00,
            ],
        ]);

        // Add Nov 2026 Special Benefit arrear (+3,728.00) for 10012.
        DB::table('salary_monthly_component_adjustments')->insert([
            'user_id' => 12,
            'year' => 2026,
            'month' => 11,
            'payroll_component_type_id' => 11,
            'arrear_amount' => 3728.00,
            'rule_override' => 0,
            'comment' => 'Oct 2026 SB arrear',
        ]);

        // Add a Grade 13 LWP employee (20070) to verify 15% Special Benefit (2,646.00) on 17,640.00 Basic.
        DB::table('grades')->insert([
            ['id' => 2, 'pay_scale_id' => 1, 'grade_name' => 'Grade 13'],
        ]);
        DB::table('payroll_groups')->insert([
            ['id' => 2, 'grade_id' => 2, 'payroll_group_type_id' => 1],
        ]);
        DB::table('users')->insert([
            ['id' => 70, 'name' => 'SHEKHORE BISWAS', 'email' => 'emp70@kau.ac.bd', 'isActive' => 1],
            ['id' => 99, 'name' => 'Regular Active Employee', 'email' => 'emp99@kau.ac.bd', 'isActive' => 1],
            ['id' => 100, 'name' => 'Inactive Employee', 'email' => 'emp100@kau.ac.bd', 'isActive' => 0],
        ]);
        DB::table('employee_infos')->insert([
            [
                'id' => 70,
                'user_id' => 70,
                'department_office_id' => 1,
                'pay_scale_id' => 1,
                'payroll_group_id' => 2,
                'employeeCode' => '20070',
                'jobStatus' => 'permanent',
                'holdIncrement' => 1,
                'additionObjects' => json_encode([
                    ['payroll_component_type_id' => 2, 'name' => 'Basic Pay', 'calcType' => 'fixed', 'amount' => 17640.00],
                ]),
                'substractionObjects' => json_encode([
                    ['payroll_component_type_id' => 16, 'name' => 'General Provident Fund', 'calcType' => 'percentage', 'amount' => 10],
                    ['payroll_component_type_id' => 19, 'name' => 'Revenue Stamp', 'calcType' => 'fixed', 'amount' => 10.00],
                ]),
            ],
            [
                'id' => 99,
                'user_id' => 99,
                'department_office_id' => 1,
                'pay_scale_id' => 1,
                'payroll_group_id' => 1,
                'employeeCode' => '10099',
                'jobStatus' => 'permanent',
                'holdIncrement' => 0,
                'additionObjects' => json_encode([
                    ['payroll_component_type_id' => 2, 'name' => 'Basic Pay', 'calcType' => 'fixed', 'amount' => 35500.00],
                ]),
                'substractionObjects' => json_encode([
                    ['payroll_component_type_id' => 16, 'name' => 'General Provident Fund', 'calcType' => 'percentage', 'amount' => 10],
                    ['payroll_component_type_id' => 19, 'name' => 'Revenue Stamp', 'calcType' => 'fixed', 'amount' => 10.00],
                ]),
            ],
            [
                'id' => 100,
                'user_id' => 100,
                'department_office_id' => 1,
                'pay_scale_id' => 1,
                'payroll_group_id' => 1,
                'employeeCode' => '30235',
                'jobStatus' => 'permanent',
                'holdIncrement' => 0,
                'additionObjects' => json_encode([
                    ['payroll_component_type_id' => 2, 'name' => 'Basic Pay', 'calcType' => 'fixed', 'amount' => 20000.00],
                ]),
                'substractionObjects' => json_encode([]),
            ],
        ]);

        // 1. Verify 10012 gets 2015 Basic 37,280.00 (NOT 52,208.00), Regular Net 54,470.00, Net Arrear +3,728.00, Net Payable 58,198.00.
        $setup10012 = $this->actingAs($this->admin)->getJson('/salary-setup/12?year=2026&month=11');
        $setup10012->assertOk();
        $j12 = $setup10012->json();
        $basic12 = collect($j12['allowances'])->firstWhere('payroll_component_type_id', 2);
        $this->assertEquals(37280.00, $basic12['current_amount']);
        $this->assertNotEquals(52208.00, $basic12['current_amount']);
        $this->assertEquals(54470.00, $j12['regular_net']);
        $this->assertEquals(3728.00, $j12['net_arrear']);
        $this->assertEquals(58198.00, $j12['net_payable']);

        // 2. Verify 20070 (Grade 13) gets 15% Special Benefit = 2,646.00.
        $setup20070 = $this->actingAs($this->admin)->getJson('/salary-setup/70?year=2026&month=11');
        $setup20070->assertOk();
        $sb70 = collect($setup20070->json('allowances'))->firstWhere('payroll_component_type_id', 11);
        $this->assertEquals(2646.00, $sb70['current_amount']);

        // 3. Verify Employee Type multi-select filter works.
        $lwpOnly = $this->actingAs($this->admin)
            ->get('/dashboard/salary/estimation?year=2026&month=11&employee_types[]=Study+Leave+With+Pay');
        $lwpOnly->assertOk();
        $lwpCodes = collect($lwpOnly->viewData('page')['props']['rows']['data'])->pluck('employee_code')->all();
        $this->assertSame(['10012', '20070'], $lwpCodes);

        $activeAndInactive = $this->actingAs($this->admin)
            ->get('/dashboard/salary/estimation?year=2026&month=11&employee_types[]=Active&employee_types[]=Inactive');
        $activeAndInactive->assertOk();
        $aiCodes = collect($activeAndInactive->viewData('page')['props']['rows']['data'])->pluck('employee_code')->all();
        $this->assertSame(['10099', '30235'], $aiCodes);

        // 4. Verify Bulk Generate persists into salary_generates for 2026-11 and blocks 2026-10.
        $blockedBulk = $this->actingAs($this->admin)
            ->postJson('/dashboard/salary/estimation/generate-bulk', [
                'year' => 2026,
                'month' => 10,
                'user_ids' => [12],
            ]);
        $blockedBulk->assertStatus(422)->assertJsonPath('is_locked', true);
        $this->assertSame(0, DB::table('salary_generates')->count());

        $okBulk = $this->actingAs($this->admin)
            ->postJson('/dashboard/salary/estimation/generate-bulk', [
                'year' => 2026,
                'month' => 11,
                'user_ids' => [12],
            ]);
        $okBulk->assertOk()->assertJsonPath('success_count', 1);
        $bill = DB::table('salary_generates')->where('user_id', 12)->where('salaryGenerationYear', 2026)->where('monthId', 11)->first();
        $this->assertNotNull($bill);
        $this->assertEquals(58198.00, (float) $bill->salaryTotal);
    }

    public function test_november_uses_posted_regular_components_without_carrying_october_arrears(): void
    {
        Schema::create('salary_generates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->integer('salaryGenerationYear');
            $table->integer('monthId');
            $table->json('salaryAllowanceCalcObject');
            $table->json('salaryDeductionCalcObject');
        });

        DB::table('pay_scales')->where('id', 1)->update(['scale_name' => 'Govt. Pay Scale 2026']);
        $component = static fn ($name, $amount) => ['name' => $name, 'calcType' => 'fixed', 'amount' => $amount];
        $profile = EmployeeInfo::findOrFail(12);
        $profile->update([
            'employeeCode' => '20028',
            'additionObjects' => [
                $component('Basic Pay', 36396),
                $component('House Rent Allowance', 17268),
                $component('Medical Allowance', 0),
            ],
            'substractionObjects' => [$component('General Provident Fund', 0), $component('Income Tax', 0)],
        ]);
        DB::table('salary_generates')->insert([
            'user_id' => 12,
            'salaryGenerationYear' => 2026,
            'monthId' => 10,
            'salaryAllowanceCalcObject' => json_encode([
                $component('House Rent Allowance', 16444),
                $component('Medical Allowance', 1500),
                $component('Special Benefit', 100),
                $component('Charge Allowance', 4500),
                $component('Charge Allowance', 600) + ['arrear_phase5' => true],
            ]),
            'salaryDeductionCalcObject' => json_encode([
                $component('General Provident Fund (GPF)', 3640),
                $component('Income Tax', 300),
                $component('Others Deduction', 500) + ['arrear_snapshot_id' => 1],
            ]),
        ]);

        $setup = app(MonthlySalarySetupService::class);
        $november = $setup->getSetupForEmployee($this->employee, 2026, 11);
        $bulk = $setup->getSummariesForUsers([12], 2026, 11)[12];
        $this->assertEquals(58840.00, $november['regular_allowances']);
        $this->assertEquals(3949.60, $november['regular_deductions']);
        $this->assertSame(3639.60, collect($november['deductions'])->firstWhere('payroll_component_type_id', 16)['current_amount']);
        $this->assertEquals(0.00, $november['net_arrear']);
        $this->assertSame($november['allowances'], $bulk['allowances']);
        $this->assertSame($november['deductions'], $bulk['deductions']);

        $december = $setup->getSetupForEmployee($this->employee, 2026, 12);
        $this->assertEquals(53664.00, $december['regular_allowances']);
        $this->assertEquals(3649.60, $december['regular_deductions']);

        $profile->update(['substractionObjects' => []]);
        $novemberWithoutGpfRow = $setup->getSetupForEmployee($this->employee, 2026, 11);
        $this->assertSame($november['deductions'], $novemberWithoutGpfRow['deductions']);
    }

    public function test_november_applies_the_verified_ad_hoc_and_gpf_exceptions(): void
    {
        $profile = EmployeeInfo::findOrFail(12);
        $profile->update([
            'employeeCode' => '30043',
            'holdIncrement' => false,
            'additionObjects' => [['name' => 'Basic Pay', 'calcType' => 'fixed', 'amount' => 38956]],
            'substractionObjects' => [['name' => 'Others Deduction', 'calcType' => 'fixed', 'amount' => 1270.50]],
        ]);
        $setup = app(MonthlySalarySetupService::class);
        $november = $setup->getSummariesForUsers([12], 2026, 11)[12];
        $this->assertEquals(10.00, $november['regular_deductions']);

        DB::table('payroll_group_types')->where('id', 1)->update(['group_name' => 'PS TO VC']);
        $profile->update(['employeeCode' => '20045', 'substractionObjects' => []]);
        $november = $setup->getSummariesForUsers([12], 2026, 11)[12];
        $this->assertEquals(3895.60, collect($november['deductions'])->firstWhere('payroll_component_type_id', 16)['current_amount']);

        $profile->update(['employeeCode' => '20083']);
        $november = $setup->getSummariesForUsers([12], 2026, 11)[12];
        $this->assertEquals(0.00, collect($november['deductions'])->firstWhere('payroll_component_type_id', 16)['current_amount']);
        $december = $setup->getSummariesForUsers([12], 2026, 12)[12];
        $this->assertEquals(0.00, collect($december['deductions'])->firstWhere('payroll_component_type_id', 16)['current_amount']);
    }
}
