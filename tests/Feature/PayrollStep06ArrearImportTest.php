<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PayrollStep06ArrearImportTest extends TestCase
{
    private const TABLE = 'salary_monthly_component_adjustments';

    private const HEADERS = [
        'rows_parsed', 'allowances_arrear_total', 'deductions_arrear_total', 'net_pay_impact', 'inserted_or_updated_count',
    ];

    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'arrear_import_test',
            'database.connections.arrear_import_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('employee_infos', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->string('employeeCode');
        });
        Schema::create('payroll_component_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('payroll_components', function (Blueprint $table): void {
            $table->unsignedBigInteger('payroll_component_type_id');
            $table->string('component_type');
        });
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->integer('year');
            $table->integer('month');
            $table->unsignedBigInteger('payroll_component_type_id');
            $table->decimal('arrear_amount', 12, 2);
            $table->boolean('rule_override');
            $table->string('comment');
            $table->timestamps();
            $table->unique(['user_id', 'year', 'month', 'payroll_component_type_id'], 'arrears_period_employee_component');
        });

        DB::table('users')->insert(['id' => 7]);
        DB::table('employee_infos')->insert(['user_id' => 7, 'employeeCode' => '00123']);
        foreach ([
            1 => ['Special benefit', 'Allowance'],
            2 => ['General Provident Fund', 'Deduction'],
            3 => ['Revenue Stamp', 'Deduction'],
        ] as $id => [$name, $direction]) {
            DB::table('payroll_component_types')->insert(['id' => $id, 'name' => $name]);
            DB::table('payroll_components')->insert([
                'payroll_component_type_id' => $id,
                'component_type' => $direction,
            ]);
        }

        $this->csv = tempnam(sys_get_temp_dir(), 'arrears-');
        file_put_contents($this->csv, implode("\r\n", [
            'employee_code,component_name,arrear_amount,comment',
            ' 00123 , SPECIAL BENEFIT , 100.25 , October allowance ',
            '00123,General Provident Fund,-20.50,October GPF refund',
            '00123,Revenue Stamp,10.00,October stamp recovery',
            '',
        ]));
    }

    protected function tearDown(): void
    {
        if (isset($this->csv)) {
            unlink($this->csv);
        }

        parent::tearDown();
    }

    public function test_dry_run_reconciles_without_writing(): void
    {
        $this->artisan('kau:import-monthly-arrears', ['--file' => $this->csv])
            ->expectsTable(self::HEADERS, [[3, '100.25', '-10.50', '110.75', 0]])
            ->assertSuccessful();

        $this->assertDatabaseCount(self::TABLE, 0);
    }

    public function test_apply_imports_signed_arrears_and_is_idempotent(): void
    {
        $options = ['--file' => $this->csv, '--apply' => true];
        $this->artisan('kau:import-monthly-arrears', $options)
            ->expectsTable(self::HEADERS, [[3, '100.25', '-10.50', '110.75', 3]])
            ->assertSuccessful();

        foreach ([1 => [100.25, 'October allowance'], 2 => [-20.50, 'October GPF refund'], 3 => [10.00, 'October stamp recovery']] as $id => [$amount, $comment]) {
            $this->assertDatabaseHas(self::TABLE, [
                'user_id' => 7,
                'year' => 2026,
                'month' => 11,
                'payroll_component_type_id' => $id,
                'arrear_amount' => $amount,
                'comment' => $comment,
                'rule_override' => false,
            ]);
        }
        $before = DB::table(self::TABLE)->orderBy('id')->get()->all();

        $this->travel(1)->minute();
        $this->artisan('kau:import-monthly-arrears', $options)
            ->expectsTable(self::HEADERS, [[3, '100.25', '-10.50', '110.75', 0]])
            ->assertSuccessful();
        $this->assertEquals($before, DB::table(self::TABLE)->orderBy('id')->get()->all());
    }

    public function test_locked_month_is_rejected_before_opening_the_csv(): void
    {
        $this->artisan('kau:import-monthly-arrears', [
            '--year' => '2026',
            '--month' => '10',
            '--file' => $this->csv . '.missing',
            '--apply' => true,
        ])->doesntExpectOutputToContain('Cannot open arrears CSV')->assertFailed();

        $this->assertDatabaseCount(self::TABLE, 0);
    }
}
