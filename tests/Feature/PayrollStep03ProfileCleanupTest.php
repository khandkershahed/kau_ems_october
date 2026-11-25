<?php

namespace Tests\Feature;

use App\Console\Commands\AuditPayrollBaseline;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * STEP 03 recurring payroll profile cleanup tests.
 * Runs strictly on in-memory SQLite from phpunit.xml; never touches MySQL.
 */
final class PayrollStep03ProfileCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->buildSchema();
        $this->seedFixtures();
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
        Schema::create('payroll_groups', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('grade_id');
            $t->timestamps();
        });
        Schema::create('payroll_component_types', function (Blueprint $t) {
            $t->id();
            $t->string('name');
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
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('isActive')->default(true);
            $t->timestamps();
        });
        Schema::create('employee_infos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('pay_scale_id')->nullable();
            $t->unsignedBigInteger('payroll_group_id')->nullable();
            $t->string('employeeCode');
            $t->string('jobStatus')->default('permanent');
            $t->boolean('holdIncrement')->default(false);
            $t->longText('additionObjects')->nullable();
            $t->longText('substractionObjects')->nullable();
            $t->timestamps();
        });
        Schema::create('salary_generates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedInteger('monthId');
            $t->unsignedInteger('salaryGenerationYear');
            $t->decimal('salaryTotal', 14, 2)->default(0);
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
            ['id' => 3, 'pay_scale_id' => 4, 'grade_name' => 'Grade 10'],
        ]);
        DB::table('payroll_groups')->insert([
            ['id' => 1, 'grade_id' => 1],
            ['id' => 2, 'grade_id' => 2],
            ['id' => 3, 'grade_id' => 3],
        ]);
        DB::table('payroll_component_types')->insert([
            ['id' => 2, 'name' => 'Basic Pay'],
            ['id' => 11, 'name' => 'Special benefit'],
            ['id' => 16, 'name' => 'General Provident Fund'],
            ['id' => 17, 'name' => 'Income Tax'],
            ['id' => 18, 'name' => 'Others Deduction'],
            ['id' => 19, 'name' => 'Revenue Stamp'],
        ]);
        DB::table('user_categories')->insert([
            ['id' => 1, 'name' => 'Officer'],
            ['id' => 2, 'name' => 'Class 3'],
            ['id' => 3, 'name' => 'Teacher'],
            ['id' => 4, 'name' => 'Class 4'],
        ]);

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Tanvir Ahmed', 'isActive' => 1], // 30235 - deactivate
            ['id' => 2, 'name' => 'Tanvir Ahmed', 'isActive' => 1], // 40033 - MUST stay active + double-encoded + dup RS
            ['id' => 3, 'name' => 'Martuja Azad', 'isActive' => 1], // 30133 - missing Class 3 link
            ['id' => 4, 'name' => 'Ronjon Debnath', 'isActive' => 1], // 40140 - missing Class 4 link
            ['id' => 5, 'name' => 'VC User', 'isActive' => 1], // 20082 - GPF 7649 -> 0.00
            ['id' => 6, 'name' => 'Treasurer User', 'isActive' => 1], // 20137 - no GPF -> explicit 0.00
            ['id' => 7, 'name' => 'Tax Teacher', 'isActive' => 1], // 10028 - tax 1000 -> 2500
            ['id' => 8, 'name' => 'Ad Hoc Class 3', 'isActive' => 1], // 30066 - remove 940.50 + lock GPF 0
            ['id' => 9, 'name' => 'Shekhore Biswas', 'isActive' => 1], // 20070 - Grade 10 LWP -> 15% = 2646.00
            ['id' => 10, 'name' => 'Grade 6 LWP', 'isActive' => 1], // 10002 - Grade 6 LWP -> 10% = 3728.00
            ['id' => 11, 'name' => 'Open Item 10010', 'isActive' => 1], // 10010 - protected open item
        ]);

        // Double-encoded JSON with ID-only component + duplicate Revenue Stamp for 40033
        $doubleAdd40033 = json_encode(json_encode([
            ['payroll_component_type_id' => 2, 'amount' => '10000', 'calcType' => 'fixed'],
        ]));
        $doubleDed40033 = json_encode(json_encode([
            ['payroll_component_type_id' => 19, 'name' => 'Revenue Stamp', 'amount' => '0', 'calcType' => 'fixed'],
            ['payroll_component_type_id' => 19, 'name' => 'Revenue Stamp - 1', 'amount' => '10', 'calcType' => 'fixed'],
        ]));

        DB::table('employee_infos')->insert([
            [
                'id' => 1, 'user_id' => 1, 'pay_scale_id' => 1, 'payroll_group_id' => 2, 'employeeCode' => '30235',
                'additionObjects' => json_encode([['name' => 'Basic Pay', 'amount' => 13380, 'calcType' => 'fixed']]),
                'substractionObjects' => json_encode([['name' => 'Revenue Stamp', 'amount' => 10, 'calcType' => 'fixed']]),
            ],
            [
                'id' => 2, 'user_id' => 2, 'pay_scale_id' => 1, 'payroll_group_id' => 2, 'employeeCode' => '40033',
                'additionObjects' => $doubleAdd40033,
                'substractionObjects' => $doubleDed40033,
            ],
            [
                'id' => 3, 'user_id' => 3, 'pay_scale_id' => 1, 'payroll_group_id' => 2, 'employeeCode' => '30133',
                'additionObjects' => json_encode([['name' => 'Basic Pay', 'amount' => 11890, 'calcType' => 'fixed']]),
                'substractionObjects' => json_encode([]), // missing Revenue Stamp
            ],
            [
                'id' => 4, 'user_id' => 4, 'pay_scale_id' => 1, 'payroll_group_id' => 2, 'employeeCode' => '40140',
                'additionObjects' => json_encode([['name' => 'Basic Pay', 'amount' => 11250, 'calcType' => 'fixed']]),
                'substractionObjects' => json_encode([['name' => 'Revenue Stamp', 'amount' => 10, 'calcType' => 'fixed']]),
            ],
            [
                'id' => 5, 'user_id' => 5, 'pay_scale_id' => 1, 'payroll_group_id' => 1, 'employeeCode' => '20082',
                'additionObjects' => json_encode([['name' => 'Basic Pay', 'amount' => 78000, 'calcType' => 'fixed']]),
                'substractionObjects' => json_encode([
                    ['payroll_component_type_id' => 16, 'name' => 'General Provident Fund', 'amount' => '7649', 'calculated_amount' => 7649, 'calcType' => 'fixed'],
                    ['payroll_component_type_id' => 19, 'name' => 'Revenue Stamp', 'amount' => '10', 'calculated_amount' => 10, 'calcType' => 'fixed'],
                ]),
            ],
            [
                'id' => 6, 'user_id' => 6, 'pay_scale_id' => 1, 'payroll_group_id' => 1, 'employeeCode' => '20137',
                'additionObjects' => json_encode([['name' => 'Basic Pay', 'amount' => 78000, 'calcType' => 'fixed']]),
                'substractionObjects' => json_encode([
                    ['payroll_component_type_id' => 19, 'name' => 'Revenue Stamp', 'amount' => '10', 'calculated_amount' => 10, 'calcType' => 'fixed'],
                ]),
            ],
            [
                'id' => 7, 'user_id' => 7, 'pay_scale_id' => 1, 'payroll_group_id' => 1, 'employeeCode' => '10028',
                'additionObjects' => json_encode([['name' => 'Basic Pay', 'amount' => 60770, 'calcType' => 'fixed']]),
                'substractionObjects' => json_encode([
                    ['payroll_component_type_id' => 17, 'name' => 'Income Tax', 'amount' => '1000', 'calculated_amount' => 1000, 'calcType' => 'fixed'],
                    ['payroll_component_type_id' => 19, 'name' => 'Revenue Stamp', 'amount' => '10', 'calculated_amount' => 10, 'calcType' => 'fixed'],
                ]),
            ],
            [
                'id' => 8, 'user_id' => 8, 'pay_scale_id' => 1, 'payroll_group_id' => 2, 'employeeCode' => '30066',
                'additionObjects' => json_encode([['name' => 'Basic Pay', 'amount' => 11320, 'calcType' => 'fixed']]),
                'substractionObjects' => json_encode([
                    ['name' => 'Others Deduction', 'amount' => 940.5, 'calculated_amount' => 940.5, 'calcType' => 'fixed'],
                    ['name' => 'Revenue Stamp', 'amount' => 10, 'calculated_amount' => 10, 'calcType' => 'fixed'],
                ]),
            ],
            [
                'id' => 9, 'user_id' => 9, 'pay_scale_id' => 1, 'payroll_group_id' => 2, 'employeeCode' => '20070',
                'additionObjects' => json_encode([
                    ['payroll_component_type_id' => 2, 'name' => 'Basic Pay', 'amount' => '17640', 'calculated_amount' => 17640, 'calcType' => 'fixed'],
                    ['payroll_component_type_id' => 11, 'name' => 'Special benefit', 'amount' => '1764', 'calculated_amount' => 1764, 'calcType' => 'fixed'],
                ]),
                'substractionObjects' => json_encode([
                    ['payroll_component_type_id' => 19, 'name' => 'Revenue Stamp', 'amount' => '10', 'calculated_amount' => 10, 'calcType' => 'fixed'],
                ]),
            ],
            [
                'id' => 10, 'user_id' => 10, 'pay_scale_id' => 1, 'payroll_group_id' => 1, 'employeeCode' => '10002',
                'additionObjects' => json_encode([
                    ['payroll_component_type_id' => 2, 'name' => 'Basic Pay', 'amount' => '37280', 'calculated_amount' => 37280, 'calcType' => 'fixed'],
                    ['payroll_component_type_id' => 11, 'name' => 'Special benefit', 'amount' => '5592', 'calculated_amount' => 5592, 'calcType' => 'fixed'],
                ]),
                'substractionObjects' => json_encode([
                    ['payroll_component_type_id' => 19, 'name' => 'Revenue Stamp', 'amount' => '10', 'calculated_amount' => 10, 'calcType' => 'fixed'],
                ]),
            ],
            [
                'id' => 11, 'user_id' => 11, 'pay_scale_id' => 1, 'payroll_group_id' => 1, 'employeeCode' => '10010',
                'additionObjects' => json_encode([
                    ['payroll_component_type_id' => 2, 'name' => 'Basic Pay', 'amount' => '35500', 'calculated_amount' => 35500, 'calcType' => 'fixed'],
                    ['payroll_component_type_id' => 11, 'name' => 'Special benefit', 'amount' => '5325', 'calculated_amount' => 5325, 'calcType' => 'fixed'],
                ]),
                'substractionObjects' => json_encode([
                    ['payroll_component_type_id' => 19, 'name' => 'Revenue Stamp', 'amount' => '10', 'calculated_amount' => 10, 'calcType' => 'fixed'],
                ]),
            ],
        ]);

        DB::table('salary_generates')->insert([
            ['id' => 1, 'user_id' => 1, 'monthId' => 10, 'salaryGenerationYear' => 2026, 'salaryTotal' => 25000.00],
            ['id' => 2, 'user_id' => 7, 'monthId' => 10, 'salaryGenerationYear' => 2026, 'salaryTotal' => 88000.00],
        ]);
    }

    public function test_expected_sb_rate_policy_rules(): void
    {
        $this->assertSame(0, AuditPayrollBaseline::resolveExpectedSbRate('Govt. Pay Scale 2026', 'Grade 10'));
        $this->assertSame(0, AuditPayrollBaseline::resolveExpectedSbRate('Govt. Pay Scale 2026', 'Grade 1'));
        $this->assertSame(10, AuditPayrollBaseline::resolveExpectedSbRate('Govt. Pay Scale 2015', 'Grade 1'));
        $this->assertSame(10, AuditPayrollBaseline::resolveExpectedSbRate('Govt. Pay Scale 2015', 'Grade 9'));
        $this->assertSame(15, AuditPayrollBaseline::resolveExpectedSbRate('Govt. Pay Scale 2015', 'Grade 10'));
        $this->assertSame(15, AuditPayrollBaseline::resolveExpectedSbRate('National Pay Scale 2015', 'Grade 20'));
        $this->assertNull(AuditPayrollBaseline::resolveExpectedSbRate('National Pay Scale 2015', 'Honorarium'));
    }

    public function test_dry_run_makes_zero_db_writes(): void
    {
        $beforeProfiles = DB::table('employee_infos')->orderBy('id')->get()->toJson();
        $beforeUsers = DB::table('users')->orderBy('id')->get()->toJson();
        $beforeCats = DB::table('user_user_categories')->orderBy('id')->get()->toJson();
        $beforeBills = DB::table('salary_generates')->orderBy('id')->get()->toJson();

        $exit = Artisan::call('kau:cleanup-recurring-profiles');
        $this->assertSame(0, $exit);

        $this->assertSame($beforeProfiles, DB::table('employee_infos')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeUsers, DB::table('users')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeCats, DB::table('user_user_categories')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeBills, DB::table('salary_generates')->orderBy('id')->get()->toJson());
    }

    public function test_apply_is_idempotent_protects_40033_fixes_20070_and_leaves_salary_generates_untouched(): void
    {
        $beforeBills = DB::table('salary_generates')->orderBy('id')->get()->toJson();

        // 1st --apply run
        $exit1 = Artisan::call('kau:cleanup-recurring-profiles', ['--apply' => true]);
        $this->assertSame(0, $exit1);

        // 30235 deactivated; 40033 MUST stay active
        $this->assertSame(0, (int) DB::table('users')->where('id', 1)->value('isActive'));
        $this->assertSame(1, (int) DB::table('users')->where('id', 2)->value('isActive'));

        // 40033 JSON normalized (single-encoded), missing name filled, duplicate Revenue Stamp deduplicated to 1x 10.00
        $p40033 = DB::table('employee_infos')->where('employeeCode', '40033')->first();
        $adds40033 = json_decode($p40033->additionObjects, true);
        $this->assertIsArray($adds40033);
        $this->assertSame('Basic Pay', $adds40033[0]['name']);
        $deds40033 = json_decode($p40033->substractionObjects, true);
        $this->assertCount(1, $deds40033);
        $this->assertSame('Revenue Stamp', $deds40033[0]['name']);
        $this->assertEquals(10.00, (float) $deds40033[0]['amount']);

        // Category links inserted for 30133 (Class 3 -> 2) and 40140 (Class 4 -> 4)
        $this->assertTrue(DB::table('user_user_categories')->where(['user_id' => 3, 'user_category_id' => 2])->exists());
        $this->assertTrue(DB::table('user_user_categories')->where(['user_id' => 4, 'user_category_id' => 4])->exists());

        // Missing Revenue Stamp added for active 30133
        $deds30133 = json_decode(DB::table('employee_infos')->where('employeeCode', '30133')->value('substractionObjects'), true);
        $this->assertCount(1, $deds30133);
        $this->assertEquals(10.00, (float) $deds30133[0]['amount']);

        // GPF locked to 0.00 for VC (20082), Treasurer (20137), and Ad Hoc (30066)
        foreach (['20082', '20137', '30066'] as $code) {
            $deds = json_decode(DB::table('employee_infos')->where('employeeCode', $code)->value('substractionObjects'), true);
            $gpf = array_values(array_filter($deds, fn ($r) => str_contains(strtolower($r['name'] ?? ''), 'provident')));
            $this->assertCount(1, $gpf, "Expected explicit 0.00 GPF entry for {$code}");
            $this->assertEquals(0.00, (float) $gpf[0]['amount']);
            $this->assertSame('fixed', $gpf[0]['calcType']);
        }

        // 10028 Income Tax set to 2500.00
        $deds10028 = json_decode(DB::table('employee_infos')->where('employeeCode', '10028')->value('substractionObjects'), true);
        $tax = array_values(array_filter($deds10028, fn ($r) => str_contains(strtolower($r['name'] ?? ''), 'tax')));
        $this->assertEquals(2500.00, (float) $tax[0]['amount']);

        // 30066 erroneous 940.50 Others Deduction removed
        $deds30066 = json_decode(DB::table('employee_infos')->where('employeeCode', '30066')->value('substractionObjects'), true);
        $others = array_values(array_filter($deds30066, fn ($r) => str_contains(strtolower($r['name'] ?? ''), 'others')));
        $this->assertCount(0, $others);

        // 20070 (Grade 10) gets 15% = 2646.00; 10002 (Grade 6) gets 10% = 3728.00; open item 10010 untouched (stays 5325)
        $adds20070 = json_decode(DB::table('employee_infos')->where('employeeCode', '20070')->value('additionObjects'), true);
        $sb20070 = array_values(array_filter($adds20070, fn ($r) => str_contains(strtolower($r['name'] ?? ''), 'special')));
        $this->assertEquals(2646.00, (float) $sb20070[0]['amount']);

        $adds10002 = json_decode(DB::table('employee_infos')->where('employeeCode', '10002')->value('additionObjects'), true);
        $sb10002 = array_values(array_filter($adds10002, fn ($r) => str_contains(strtolower($r['name'] ?? ''), 'special')));
        $this->assertEquals(3728.00, (float) $sb10002[0]['amount']);

        $adds10010 = json_decode(DB::table('employee_infos')->where('employeeCode', '10010')->value('additionObjects'), true);
        $sb10010 = array_values(array_filter($adds10010, fn ($r) => str_contains(strtolower($r['name'] ?? ''), 'special')));
        $this->assertEquals(5325.00, (float) $sb10010[0]['amount']);

        // salary_generates completely untouched
        $this->assertSame($beforeBills, DB::table('salary_generates')->orderBy('id')->get()->toJson());

        // 2nd --apply run must be completely idempotent (0 changes)
        $afterFirstProfiles = DB::table('employee_infos')->orderBy('id')->get()->toJson();
        $exit2 = Artisan::call('kau:cleanup-recurring-profiles', ['--apply' => true]);
        $out2 = Artisan::output();
        $this->assertSame(0, $exit2);
        $this->assertMatchesRegularExpression('/profile_rows_to_update\s*\|\s*0\b/', $out2);
        $this->assertMatchesRegularExpression('/category_links_added\s*\|\s*0\b/', $out2);
        $this->assertMatchesRegularExpression('/users_deactivated_30235\s*\|\s*0\b/', $out2);
        $this->assertSame($afterFirstProfiles, DB::table('employee_infos')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeBills, DB::table('salary_generates')->orderBy('id')->get()->toJson());
    }
}
