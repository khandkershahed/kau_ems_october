<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * STEP 07 / Micro-Task 4B: Report Factory Standardization for Nov 2026+.
 * Verifies separate Charge Allowance & Revenue Stamp columns, exact 2-decimal
 * formatting (e.g. 1815.50), Nov 2026+ data source & pre-joining exclusion,
 * and Salary Summary Revenue Stamp count == payable bill count.
 */
final class PayrollStep07ReportFormatTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->buildSchema();
        $this->seedFixtures();
        $this->admin = User::findOrFail(1);
    }

    private function buildSchema(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('role_name');
            $t->timestamps();
        });
        Schema::create('user_roles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('role_id');
            $t->timestamps();
        });
        Schema::create('pay_scales', function (Blueprint $t) {
            $t->id();
            $t->string('scale_name');
            $t->boolean('isActive')->default(true);
            $t->timestamps();
        });
        Schema::create('grades', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('pay_scale_id');
            $t->string('grade_name');
            $t->decimal('min', 12, 2)->default(0);
            $t->boolean('isActive')->default(true);
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
            $t->string('type')->default('department');
            $t->boolean('isActive')->default(true);
            $t->timestamps();
        });
        Schema::create('department_office_users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('department_office_id');
            $t->timestamps();
        });
        Schema::create('user_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('user_user_categories', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('user_category_id');
            $t->timestamps();
        });
        Schema::create('bonus_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name');
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
            $t->string('gender')->nullable();
            $t->boolean('isActive')->default(true);
            $t->timestamps();
        });
        Schema::create('employee_infos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('department_office_id')->nullable();
            $t->unsignedBigInteger('pay_scale_id')->nullable();
            $t->unsignedBigInteger('payroll_group_id')->nullable();
            $t->unsignedBigInteger('bonus_group_id')->nullable();
            $t->unsignedBigInteger('bank_account_id')->nullable();
            $t->string('employeeCode');
            $t->string('jobStatus')->default('permanent');
            $t->boolean('holdIncrement')->default(false);
            $t->longText('additionObjects')->nullable();
            $t->longText('substractionObjects')->nullable();
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
            $t->string('account_no')->nullable();
            $t->string('bank_name')->nullable();
            $t->string('address')->nullable();
            $t->boolean('isSalaryAccount')->default(false);
            $t->timestamps();
        });
        Schema::create('phone_numbers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('phoneNumber')->nullable();
            $t->timestamps();
        });
        Schema::create('phone_number_users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('phone_number_id');
            $t->timestamps();
        });
        Schema::create('religions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('religion')->nullable();
            $t->timestamps();
        });
        Schema::create('ages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->integer('age')->nullable();
            $t->timestamps();
        });
        Schema::create('salary_generates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('employeeCode')->nullable();
            $t->string('name')->nullable();
            $t->string('designation')->nullable();
            $t->string('grade')->nullable();
            $t->string('payScale')->nullable();
            $t->string('bankAccountNo')->nullable();
            $t->string('bankAddress')->nullable();
            $t->unsignedTinyInteger('monthId');
            $t->unsignedSmallInteger('salaryGenerationYear');
            $t->date('salaryGenerationDate')->nullable();
            $t->decimal('totalAllowance', 12, 2)->default(0);
            $t->decimal('totalDeduction', 12, 2)->default(0);
            $t->decimal('taxDeduction', 12, 2)->default(0);
            $t->decimal('gpfDeduction', 12, 2)->default(0);
            $t->decimal('salaryTotal', 12, 2)->default(0);
            $t->longText('salaryAllowanceCalcObject')->nullable();
            $t->longText('salaryDeductionCalcObject')->nullable();
            $t->string('reviewStatus')->default('approved');
            $t->timestamps();
        });
        Schema::create('bonus_generates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedTinyInteger('monthId');
            $t->unsignedSmallInteger('bonusGenerationYear');
            $t->timestamps();
        });
        Schema::create('general_provident_funds', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedTinyInteger('month');
            $t->unsignedSmallInteger('year');
            $t->decimal('openingBalance', 12, 2)->default(0);
            $t->decimal('interestAmount', 12, 2)->default(0);
            $t->decimal('netDeposit', 12, 2)->default(0);
            $t->decimal('closingAmount', 12, 2)->default(0);
            $t->boolean('isSettled')->default(false);
            $t->timestamps();
        });
        Schema::create('general_provident_fund_users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('general_provident_fund_id');
            $t->timestamps();
        });
        Schema::create('salary_monthly_component_adjustments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedSmallInteger('year');
            $t->unsignedTinyInteger('month');
            $t->unsignedBigInteger('payroll_component_type_id');
            $t->decimal('arrear_amount', 12, 2)->default(0.00);
            $t->boolean('rule_override')->default(false);
            $t->decimal('override_current_amount', 12, 2)->nullable();
            $t->text('comment')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
    }

    private function seedFixtures(): void
    {
        DB::table('roles')->insert([
            ['id' => 1, 'role_name' => 'admin'],
            ['id' => 2, 'role_name' => 'user'],
        ]);
        DB::table('pay_scales')->insert([
            ['id' => 1, 'scale_name' => 'Govt. Pay Scale 2015', 'isActive' => 1],
        ]);
        DB::table('grades')->insert([
            ['id' => 1, 'pay_scale_id' => 1, 'grade_name' => 'Grade 6', 'min' => 35500, 'isActive' => 1],
        ]);
        DB::table('payroll_group_types')->insert([
            ['id' => 1, 'group_name' => 'Associate Professor'],
        ]);
        DB::table('payroll_groups')->insert([
            ['id' => 1, 'grade_id' => 1, 'payroll_group_type_id' => 1],
        ]);
        DB::table('department_offices')->insert([
            ['id' => 1, 'name' => 'Agronomy', 'type' => 'department', 'isActive' => 1],
        ]);
        DB::table('user_categories')->insert([
            ['id' => 1, 'name' => 'Teacher'],
        ]);

        DB::table('payroll_component_types')->insert([
            ['id' => 2, 'name' => 'Basic Pay'],
            ['id' => 4, 'name' => 'Charge Allowance'],
            ['id' => 5, 'name' => 'Education Allowance'],
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
            ['id' => 10, 'name' => 'Dr. Posted Bill', 'email' => 'u10@kau.ac.bd', 'isActive' => 1],
            ['id' => 11, 'name' => 'Dr. Setup Preview', 'email' => 'u11@kau.ac.bd', 'isActive' => 1],
            ['id' => 12, 'name' => 'Mr. Pre Joining', 'email' => 'u12@kau.ac.bd', 'isActive' => 1],
        ]);
        DB::table('user_roles')->insert([
            ['user_id' => 1, 'role_id' => 1],
            ['user_id' => 10, 'role_id' => 2],
            ['user_id' => 11, 'role_id' => 2],
            ['user_id' => 12, 'role_id' => 2],
        ]);
        DB::table('user_user_categories')->insert([
            ['user_id' => 10, 'user_category_id' => 1],
            ['user_id' => 11, 'user_category_id' => 1],
            ['user_id' => 12, 'user_category_id' => 1],
        ]);

        // Employee 10: joined before Nov 2026, has a posted Nov 2026 bill with
        // Charge Allowance = 1815.50, Revenue Stamp = 10.00, Others Deduction = 250.00.
        DB::table('joining_dates')->insert([
            ['user_id' => 10, 'joining_date' => '2022-01-15'],
            ['user_id' => 11, 'joining_date' => '2023-06-01'],
            // Employee 12 joined AFTER November 2026 ended (pre-joining employee).
            ['user_id' => 12, 'joining_date' => '2026-12-10'],
        ]);

        DB::table('employee_infos')->insert([
            [
                'id' => 10,
                'user_id' => 10,
                'department_office_id' => 1,
                'pay_scale_id' => 1,
                'payroll_group_id' => 1,
                'employeeCode' => '10010',
                'jobStatus' => 'permanent',
                'holdIncrement' => 0,
                'additionObjects' => json_encode([
                    ['payroll_component_type_id' => 2, 'name' => 'Basic Pay', 'calcType' => 'fixed', 'amount' => 35500.00],
                    ['payroll_component_type_id' => 4, 'name' => 'Charge Allowance', 'calcType' => 'fixed', 'amount' => 1815.50],
                ]),
                'substractionObjects' => json_encode([
                    ['payroll_component_type_id' => 16, 'name' => 'General Provident Fund', 'calcType' => 'percentage', 'amount' => 10],
                    ['payroll_component_type_id' => 18, 'name' => 'Others Deduction', 'calcType' => 'fixed', 'amount' => 250.00],
                    ['payroll_component_type_id' => 19, 'name' => 'Revenue Stamp', 'calcType' => 'fixed', 'amount' => 10.00],
                ]),
            ],
            [
                'id' => 11,
                'user_id' => 11,
                'department_office_id' => 1,
                'pay_scale_id' => 1,
                'payroll_group_id' => 1,
                'employeeCode' => '10011',
                'jobStatus' => 'permanent',
                'holdIncrement' => 0,
                'additionObjects' => json_encode([
                    ['payroll_component_type_id' => 2, 'name' => 'Basic Pay', 'calcType' => 'fixed', 'amount' => 30000.00],
                    ['payroll_component_type_id' => 7, 'name' => 'House Rent Allowance', 'calcType' => 'fixed', 'amount' => 12000.00],
                    ['payroll_component_type_id' => 8, 'name' => 'Medical Allowance', 'calcType' => 'fixed', 'amount' => 1500.00],
                ]),
                'substractionObjects' => json_encode([
                    ['payroll_component_type_id' => 16, 'name' => 'General Provident Fund', 'calcType' => 'percentage', 'amount' => 10],
                    ['payroll_component_type_id' => 19, 'name' => 'Revenue Stamp', 'calcType' => 'fixed', 'amount' => 10.00],
                ]),
            ],
            [
                'id' => 12,
                'user_id' => 12,
                'department_office_id' => 1,
                'pay_scale_id' => 1,
                'payroll_group_id' => 1,
                'employeeCode' => '10012',
                'jobStatus' => 'permanent',
                'holdIncrement' => 0,
                'additionObjects' => json_encode([
                    ['payroll_component_type_id' => 2, 'name' => 'Basic Pay', 'calcType' => 'fixed', 'amount' => 22000.00],
                ]),
                'substractionObjects' => json_encode([
                    ['payroll_component_type_id' => 19, 'name' => 'Revenue Stamp', 'calcType' => 'fixed', 'amount' => 10.00],
                ]),
            ],
        ]);

        // Posted Nov 2026 bill for Employee 10:
        // Allowances: Basic 35500.00 + HRA 14200.00 + MA 1500.00 + Charge Allowance 1815.50 = 53015.50
        // Deductions: GPF 3550.00 + Others Deduction 250.00 + Revenue Stamp 10.00 = 3810.00
        // Net: 53015.50 - 3810.00 = 49205.50
        DB::table('salary_generates')->insert([
            'id' => 100,
            'user_id' => 10,
            'employeeCode' => '10010',
            'name' => 'Dr. Posted Bill',
            'designation' => 'Associate Professor',
            'grade' => 'Grade 6',
            'payScale' => 'Govt. Pay Scale 2015',
            'bankAccountNo' => '0011223344',
            'bankAddress' => 'Sonali Bank KAU',
            'monthId' => 11,
            'salaryGenerationYear' => 2026,
            'salaryGenerationDate' => '2026-11-25',
            'totalAllowance' => 53015.50,
            'totalDeduction' => 3810.00,
            'taxDeduction' => 0.00,
            'gpfDeduction' => 3550.00,
            'salaryTotal' => 49205.50,
            'salaryAllowanceCalcObject' => json_encode([
                ['name' => 'Basic Pay', 'calcType' => 'fixed', 'amount' => 35500.00, 'calculated_amount' => 35500.00],
                ['name' => 'House Rent Allowance', 'calcType' => 'fixed', 'amount' => 14200.00, 'calculated_amount' => 14200.00],
                ['name' => 'Medical Allowance', 'calcType' => 'fixed', 'amount' => 1500.00, 'calculated_amount' => 1500.00],
                ['name' => 'Charge Allowance', 'calcType' => 'fixed', 'amount' => 1815.50, 'calculated_amount' => 1815.50],
            ]),
            'salaryDeductionCalcObject' => json_encode([
                ['name' => 'General Provident Fund', 'calcType' => 'percentage', 'amount' => 10, 'calculated_amount' => 3550.00],
                ['name' => 'Others Deduction', 'calcType' => 'fixed', 'amount' => 250.00, 'calculated_amount' => 250.00],
                ['name' => 'Revenue Stamp', 'calcType' => 'fixed', 'amount' => 10.00, 'calculated_amount' => 10.00],
            ]),
            'reviewStatus' => 'approved',
        ]);
    }

    public function test_horizontal_bill_exposes_charge_allowance_and_revenue_stamp_separately_with_two_decimals_and_nov2026_source(): void
    {
        $response = $this->actingAs($this->admin)->getJson(
            '/dashboard/reports/salary-official?ids=10,11,12&year=2026&month=11&kind=bulk&format=json&mode=preview'
        );

        $response->assertOk();
        $payload = $response->json();

        // Pre-joining employee 12 (joined 2026-12-10) must be excluded; never DRAFT_REGULAR_ONLY_PREVIEW.
        $records = $payload['records'];
        $this->assertCount(2, $records);
        $userIds = array_column($records, 'user_id');
        $this->assertEqualsCanonicalizing([10, 11], $userIds);
        foreach ($records as $row) {
            $this->assertNotSame('DRAFT_REGULAR_ONLY_PREVIEW', $row['source']);
        }

        // Verify separate column definitions for Charge Allowance and Revenue Stamp.
        $columnKeys = array_column($payload['horizontal_columns'], 'key');
        $columnLabels = array_column($payload['horizontal_columns'], 'label', 'key');
        $this->assertContains('charge_allowance', $columnKeys);
        $this->assertContains('revenue_stamp', $columnKeys);
        $this->assertSame('Charge A.', $columnLabels['charge_allowance']);
        $this->assertSame('Rev. Stamp', $columnLabels['revenue_stamp']);

        $emp10 = collect($records)->firstWhere('user_id', 10);
        $emp11 = collect($records)->firstWhere('user_id', 11);

        // Employee 10 came from salary_generates; Charge Allowance = 1815.50 (NOT in Others A.),
        // Revenue Stamp = 10.00 (NOT merged into Others D. = 250.00).
        $this->assertSame('LEGACY_POSTED_BILL', $emp10['source']);
        $this->assertSame('1815.50', $emp10['horizontal_formatted']['charge_allowance']);
        $this->assertSame('0.00', $emp10['horizontal_formatted']['others_allowance']);
        $this->assertSame('10.00', $emp10['horizontal_formatted']['revenue_stamp']);
        $this->assertSame('250.00', $emp10['horizontal_formatted']['others_deduction']);
        $this->assertSame('49205.50', $emp10['horizontal_formatted']['net_payable']);

        // Employee 11 came from MonthlySalarySetupService preview (never DRAFT_REGULAR_ONLY_PREVIEW).
        $this->assertSame('MONTHLY_SALARY_SETUP_PREVIEW', $emp11['source']);
        $this->assertSame('10.00', $emp11['horizontal_formatted']['revenue_stamp']);

        // Totals preserve exact 2 decimal places.
        $this->assertSame('1815.50', $payload['horizontal_totals_formatted']['charge_allowance']);
        $this->assertSame('20.00', $payload['horizontal_totals_formatted']['revenue_stamp']);

        // CSV output also preserves exact 2-decimal precision and separate columns.
        $csvResponse = $this->actingAs($this->admin)->get(
            '/dashboard/reports/salary-official?ids=10,11,12&year=2026&month=11&kind=bulk&format=csv&mode=preview'
        );
        $csvResponse->assertOk();
        $csv = $csvResponse->getContent();
        $this->assertStringContainsString('"Charge A."', $csv);
        $this->assertStringContainsString('"Rev. Stamp"', $csv);
        $this->assertStringContainsString('"1815.50"', $csv);
        $this->assertStringNotContainsString('DRAFT_REGULAR_ONLY_PREVIEW', $csv);
    }

    public function test_salary_summary_revenue_stamp_count_equals_payable_bill_count_with_two_decimal_totals(): void
    {
        $response = $this->actingAs($this->admin)->getJson(
            '/dashboard/reports/salary-reports?reportType=Salary+Summary&monthId=11&salaryGenerationYear=2026&salaryGeneratedStatus=all&all=true'
        );

        $response->assertOk();
        $summary = $response->json('summary');

        // 2 eligible payable employees (10 posted + 11 setup preview); pre-joining employee 12 excluded.
        $this->assertSame(2, $summary['payable_bill_count']);
        $this->assertSame(2, $summary['revenue_stamp_count']);
        $this->assertTrue($summary['revenue_stamp_matches_payable_bills']);
        $this->assertSame(20.0, (float) $summary['revenue_stamp_amount']);
        $this->assertSame('20.00', $summary['revenue_stamp_amount_formatted']);

        // Exact 2-decimal formatting on summary totals (Employee 10 has .50).
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $summary['total_allowance_formatted']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $summary['total_deduction_formatted']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $summary['total_net_formatted']);
        $this->assertStringEndsWith('.50', $summary['total_net_formatted']);
    }
}
