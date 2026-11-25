<?php

namespace Tests\Feature;

use App\Models\EmployeeInfo;
use App\Models\SalaryMonthlyComponentAdjustment;
use App\Models\User;
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
}
