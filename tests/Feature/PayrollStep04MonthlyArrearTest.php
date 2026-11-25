<?php

namespace Tests\Feature;

use App\Exceptions\HistoricalSalaryImmutableException;
use App\Models\SalaryMonthlyComponentAdjustment;
use App\Models\User;
use App\Services\MonthlySalarySetupService;
use App\Services\SalaryBillCompositionService;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * STEP 04 / Micro-Task 2B: Month-scoped component arrears & rule overrides.
 * Runs strictly on in-memory SQLite from phpunit.xml; never touches MySQL.
 */
final class PayrollStep04MonthlyArrearTest extends TestCase
{
    private MonthlySalarySetupService $setupService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->buildSchema();
        $this->seedFixtures();
        $this->setupService = app(MonthlySalarySetupService::class);
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
        Schema::create('joining_dates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->date('joining_date');
            $t->timestamps();
        });
        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('account_no');
            $t->string('bank_name')->nullable();
            $t->string('address')->nullable();
            $t->boolean('isActive')->default(true);
            $t->timestamps();
        });
        Schema::create('employee_infos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('pay_scale_id')->nullable();
            $t->unsignedBigInteger('payroll_group_id')->nullable();
            $t->unsignedBigInteger('bank_account_id')->nullable();
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
        Schema::create('salary_calculation_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedSmallInteger('salary_year');
            $t->unsignedTinyInteger('salary_month');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('fixation_id')->nullable();
            $t->longText('additions')->nullable();
            $t->longText('deductions')->nullable();
            $t->longText('calculated_result')->nullable();
            $t->longText('arrear_snapshot')->nullable();
            $t->longText('baseline_result')->nullable();
            $t->longText('gpf_override')->nullable();
            $t->string('snapshot_hash')->nullable();
            $t->string('component_override_hash')->nullable();
            $t->timestamps();
        });
        Schema::create('salary_arrear_rules', function (Blueprint $t) {
            $t->id();
            $t->string('code')->nullable();
            $t->string('title');
            $t->string('direction');
            $t->string('mode')->default('formula');
            $t->string('formula')->nullable();
            $t->boolean('published')->default(false);
            $t->unsignedSmallInteger('pay_year')->nullable();
            $t->unsignedTinyInteger('pay_month')->nullable();
            $t->timestamps();
        });
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
    }

    private function seedFixtures(): void
    {
        DB::table('pay_scales')->insert([
            ['id' => 1, 'scale_name' => 'Govt. Pay Scale 2015'],
            ['id' => 4, 'scale_name' => 'Govt. Pay Scale 2026'],
        ]);
        DB::table('grades')->insert([
            ['id' => 1, 'pay_scale_id' => 1, 'grade_name' => 'Grade 6'],
            ['id' => 2, 'pay_scale_id' => 1, 'grade_name' => 'Grade 10'],
        ]);
        DB::table('payroll_group_types')->insert([
            ['id' => 1, 'group_name' => 'Associate Professor'],
        ]);
        DB::table('payroll_groups')->insert([
            ['id' => 1, 'grade_id' => 1, 'payroll_group_type_id' => 1],
        ]);

        // All 19 master components: 14 allowances (IDs 1..14) + 5 deductions (IDs 15..19).
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
            ['id' => 12, 'name' => 'Employee 10012', 'isActive' => 1],
        ]);
        DB::table('joining_dates')->insert([
            ['id' => 1, 'user_id' => 12, 'joining_date' => '2020-01-01'],
        ]);
        DB::table('bank_accounts')->insert([
            ['id' => 100, 'user_id' => 12, 'account_no' => '0123456789', 'bank_name' => 'Sonali Bank', 'address' => 'KAU Branch', 'isActive' => 1],
        ]);

        // Employee 10012: 2015 PayScale, Grade 6, Basic 37280.00
        // Regular allowances: Basic 37280 + HR 15700 + Medical 1500 + SB (10% = 3728) = 58208.00
        // Regular deductions: GPF (10% = 3728) + Revenue Stamp (10) = 3738.00
        // Regular Net = 58208.00 - 3738.00 = 54470.00
        DB::table('employee_infos')->insert([
            'id' => 12,
            'user_id' => 12,
            'pay_scale_id' => 1,
            'payroll_group_id' => 1,
            'bank_account_id' => 100,
            'employeeCode' => '10012',
            'jobStatus' => 'permanent',
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

    public function test_employee_10012_november_sb_arrear_calculation_and_composition(): void
    {
        $user = User::findOrFail(12);

        $setup = $this->setupService->saveForMonth($user, 2026, 11, [
            [
                'payroll_component_type_id' => 11, // Special benefit
                'arrear_amount' => 3728.00,
                'rule_override' => false,
                'comment' => 'Oct 2026 Special Benefit arrear settled in Nov 2026',
            ],
        ], 1);

        $this->assertCount(14, $setup['allowances']);
        $this->assertCount(5, $setup['deductions']);

        $this->assertEquals(58208.00, $setup['regular_allowances']);
        $this->assertEquals(3728.00, $setup['arrear_allowances']);
        $this->assertEquals(61936.00, $setup['total_allowances']);
        $this->assertEquals(3738.00, $setup['regular_deductions']);
        $this->assertEquals(0.00, $setup['arrear_deductions']);
        $this->assertEquals(3738.00, $setup['total_deductions']);
        $this->assertEquals(54470.00, $setup['regular_net']);
        $this->assertEquals(3728.00, $setup['net_arrear']);
        $this->assertEquals(58198.00, $setup['net_payable']);

        // Verify SalaryBillCompositionService::compose() wires the saved Nov arrear cleanly.
        $composed = app(SalaryBillCompositionService::class)->compose(12, 2026, 11);
        $this->assertEquals(54470.00, $composed['regular']['net_salary']);
        $this->assertEquals(3728.00, $composed['arrear_payable']);
        $this->assertEquals(0.00, $composed['arrear_deduction']);
        $this->assertEquals(61936.00, $composed['gross']);
        $this->assertEquals(3738.00, $composed['total_deduction']);
        $this->assertEquals(58198.00, $composed['net_payable']);
    }

    public function test_negative_gpf_arrear_reduces_deductions_and_increases_net_payable(): void
    {
        $user = User::findOrFail(12);

        $baseline = $this->setupService->getSetupForEmployee($user, 2026, 11);
        $this->assertEquals(3738.00, $baseline['total_deductions']);
        $this->assertEquals(54470.00, $baseline['net_payable']);

        $updated = $this->setupService->saveForMonth($user, 2026, 11, [
            [
                'payroll_component_type_id' => 16, // General Provident Fund
                'arrear_amount' => -10025.00,
                'rule_override' => false,
                'comment' => 'GPF over-deduction refund',
            ],
        ], 1);

        $this->assertEquals(-10025.00, $updated['arrear_deductions']);
        $this->assertEquals(round(3738.00 - 10025.00, 2), $updated['total_deductions']);
        $this->assertEquals(10025.00, $updated['net_arrear']);
        $this->assertEquals(round(54470.00 + 10025.00, 2), $updated['net_payable']);

        // Verify composition engine handles negative GPF deduction arrear identically.
        $composed = app(SalaryBillCompositionService::class)->compose(12, 2026, 11);
        $this->assertEquals(-10025.00, $composed['arrear_deduction']);
        $this->assertEquals(-10025.00, $composed['arrear_gpf']);
        $this->assertEquals(round(3738.00 - 10025.00, 2), $composed['total_deduction']);
        $this->assertEquals(round(54470.00 + 10025.00, 2), $composed['net_payable']);
    }

    public function test_december_does_not_carry_forward_november_arrears_or_rule_overrides(): void
    {
        $user = User::findOrFail(12);

        $this->setupService->saveForMonth($user, 2026, 11, [
            [
                'payroll_component_type_id' => 11, // Special benefit
                'arrear_amount' => 3728.00,
                'rule_override' => true,
                'override_current_amount' => 4000.00,
                'comment' => 'Nov one-off override + arrear',
            ],
            [
                'payroll_component_type_id' => 16, // GPF
                'arrear_amount' => -10025.00,
                'rule_override' => false,
                'comment' => 'Nov GPF refund',
            ],
        ], 1);

        $december = $this->setupService->getSetupForEmployee($user, 2026, 12);

        foreach (array_merge($december['allowances'], $december['deductions']) as $row) {
            $this->assertEquals(0.00, $row['arrear_amount'], "December arrear must be 0.00 for {$row['name']}");
            $this->assertFalse($row['rule_override'], "December rule_override must be false for {$row['name']}");
        }
        $this->assertEquals(0.00, $december['arrear_allowances']);
        $this->assertEquals(0.00, $december['arrear_deductions']);
        $this->assertEquals(0.00, $december['net_arrear']);
        $this->assertEquals(54470.00, $december['regular_net']);
        $this->assertEquals(54470.00, $december['net_payable']);
    }

    public function test_saving_adjustments_for_october_2026_throws_historical_immutable_exception_422(): void
    {
        $user = User::findOrFail(12);

        try {
            $this->setupService->saveForMonth($user, 2026, 10, [
                [
                    'payroll_component_type_id' => 11,
                    'arrear_amount' => 3728.00,
                ],
            ]);
            $this->fail('Expected HistoricalSalaryImmutableException for 2026-10.');
        } catch (HistoricalSalaryImmutableException $e) {
            $response = $e->render(Request::create('/test', 'POST'));
            $this->assertSame(422, $response->getStatusCode());
            $this->assertStringContainsString('2026-10', $e->getMessage());
        }

        // Model-level guard also blocks direct Eloquent creation for 2026-10.
        $this->expectException(HistoricalSalaryImmutableException::class);
        SalaryMonthlyComponentAdjustment::create([
            'user_id' => 12,
            'year' => 2026,
            'month' => 10,
            'payroll_component_type_id' => 11,
            'arrear_amount' => 100.00,
        ]);
    }

    public function test_manual_basic_pay_rule_override_requires_comment(): void
    {
        $user = User::findOrFail(12);

        // Missing / blank comment must be rejected.
        try {
            $this->setupService->saveForMonth($user, 2026, 11, [
                [
                    'payroll_component_type_id' => 2, // Basic Pay
                    'rule_override' => true,
                    'override_current_amount' => 39000.00,
                    'comment' => '   ',
                ],
            ]);
            $this->fail('Expected DomainException when Basic Pay rule_override has no comment.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('comment', strtolower($e->getMessage()));
        }

        $this->assertSame(0, SalaryMonthlyComponentAdjustment::count());

        // With a non-empty comment, Basic Pay override is accepted.
        $saved = $this->setupService->saveForMonth($user, 2026, 11, [
            [
                'payroll_component_type_id' => 2, // Basic Pay
                'rule_override' => true,
                'override_current_amount' => 39000.00,
                'comment' => 'Approved manual Basic Pay adjustment for Nov 2026',
            ],
        ], 1);

        $basicRow = collect($saved['allowances'])->firstWhere('payroll_component_type_id', 2);
        $this->assertTrue($basicRow['rule_override']);
        $this->assertEquals(39000.00, $basicRow['current_amount']);
        $this->assertSame('Approved manual Basic Pay adjustment for Nov 2026', $basicRow['comment']);
    }
}
