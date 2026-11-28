<?php

namespace Tests\Feature;

use App\Models\DepartmentOffice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EmployeeBankAccountEditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate')->assertSuccessful();

        DB::table('pay_scales')->insert(['id' => 1, 'scale_name' => 'Test Scale', 'scale_description' => 'Test']);
        DB::table('grades')->insert(['id' => 1, 'pay_scale_id' => 1, 'grade_name' => 'Test Grade', 'grade_description' => 'Test']);
        DB::table('payroll_group_types')->insert(['id' => 1, 'group_name' => 'Test Group', 'group_description' => 'Test']);
        DB::table('payroll_groups')->insert(['id' => 1, 'grade_id' => 1, 'payroll_group_type_id' => 1]);
    }

    public function test_employee_profile_update_accepts_own_accounts_and_rejects_another_employees_account(): void
    {
        $employee = User::factory()->create(['email' => 'employee@example.test']);
        $otherEmployee = User::factory()->create();
        $department = DepartmentOffice::factory()->create();

        $account = static fn (int $userId, string $number) => [
            'user_id' => $userId, 'account_no' => $number, 'routing_no' => 'N/A',
            'type' => 'Savings', 'address' => 'N/A', 'isSalaryAccount' => false,
        ];
        DB::table('bank_accounts')->insert([
            $account($employee->id, '1234567890'),
            $account($otherEmployee->id, '9876543210'),
        ]);

        $payload = [
            'name' => 'Updated Employee',
            'email' => $employee->email,
            'gender' => 'female',
            'employeeCode' => 10046,
            'jobStatus' => 'temporary',
            'pay_scale_id' => 1,
            'payroll_group_id' => 1,
            'department_offices' => [['department_office_id' => $department->id]],
            'phone_numbers' => [['phoneNumber' => '01700000000']],
            'addresses' => [['address' => 'Work address']],
            'bank_accounts' => [
                ['bank_account_no' => '1234567890', 'isSalaryAccount' => true],
                ['bank_account_no' => '123-456-7890', 'isSalaryAccount' => false],
            ],
        ];

        $url = route('employees.update', $employee);
        $initial = array_replace($payload, [
            'name' => 'Original Employee',
            'jobStatus' => 'permanent',
            'bank_accounts' => [['bank_account_no' => '1234567890', 'isSalaryAccount' => false]],
        ]);
        $this->actingAs($otherEmployee)->putJson($url, $initial)->assertOk();

        // A legacy profile can carry alternate formatting for the employee's own account.
        $this->putJson($url, $payload)->assertOk();
        $this->assertDatabaseHas('users', ['id' => $employee->id, 'name' => 'Updated Employee']);
        $this->assertDatabaseHas('employee_infos', ['user_id' => $employee->id, 'jobStatus' => 'temporary']);
        $this->assertSame(2, $employee->bankAccounts()->count());

        $details = $this->getJson(route('employees.edit', ['profile_user_id' => $employee->id]));
        $details->assertOk()->assertJsonPath('employee.id', $employee->id)
            ->assertJsonCount(2, 'employee.bank_accounts')->assertJsonCount(1, 'options.payrollGroups');

        $jobStatusValues = collect($details->json('options.jobStatuses'))->pluck('value')->all();
        $this->assertContains('ad_hoc', $jobStatusValues);
        $this->assertContains('study_leave_with_pay', $jobStatusValues);
        $this->assertContains('study_leave_with_pay_with_increment', $jobStatusValues);

        foreach (['ad_hoc', 'study_leave_with_pay', 'study_leave_with_pay_with_increment'] as $status) {
            $this->putJson($url, array_replace($payload, ['jobStatus' => $status]))->assertOk();
            $this->assertDatabaseHas('employee_infos', ['user_id' => $employee->id, 'jobStatus' => $status]);
        }

        $this->putJson($url, $payload)->assertOk();

        $this->putJson($url, array_replace($payload, [
            'jobStatus' => 'study_leave_with_pay_with_increment',
            'holdIncrement' => true,
            'gpf_exempt' => true,
        ]))->assertOk();
        $this->assertDatabaseHas('employee_infos', [
            'user_id' => $employee->id,
            'jobStatus' => 'study_leave_with_pay_with_increment',
            'holdIncrement' => 1,
            'gpf_exempt' => 1,
        ]);

        $this->getJson(route('employees.edit', ['profile_user_id' => $employee->id]))
            ->assertOk()
            ->assertJsonPath('employee.employee_info.holdIncrement', true)
            ->assertJsonPath('employee.employee_info.gpf_exempt', true);

        $this->putJson($url, array_replace($payload, ['gpf_exempt' => 'invalid']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gpf_exempt']);

        $this->patchJson(route('employees.bulkProfileUpdateSingle', $employee), [
            'fields' => ['jobStatus', 'holdIncrement', 'gpf_exempt'],
            'jobStatus' => 'study_leave_with_pay',
            'holdIncrement' => false,
            'gpf_exempt' => false,
        ])->assertOk();
        $this->assertDatabaseHas('employee_infos', [
            'user_id' => $employee->id,
            'jobStatus' => 'study_leave_with_pay',
            'holdIncrement' => 0,
            'gpf_exempt' => 0,
        ]);

        $this->patchJson(route('employees.bulkProfileUpdateSingle', $employee), [
            'fields' => ['gpf_exempt'],
            'gpf_exempt' => 'not-a-boolean',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['gpf_exempt']);

        $this->putJson($url, $payload)->assertOk();

        $payload['name'] = 'Should Roll Back';
        $payload['bank_accounts'][0]['bank_account_no'] = '987-654-3210';
        $this->putJson($url, $payload)->assertUnprocessable()
            ->assertJsonPath('errors.account_no.0', 'This bank account number is already registered to an employee.');
        $this->assertDatabaseHas('users', ['id' => $employee->id, 'name' => 'Updated Employee']);
        $this->assertDatabaseHas('bank_accounts', ['user_id' => $otherEmployee->id, 'account_no' => '9876543210']);
        $this->assertSame(2, $employee->bankAccounts()->count());
    }

    public static function updateRoutes(): array
    {
        return [
            'profile update' => [false],
            'bulk profile update' => [true],
        ];
    }

    #[DataProvider('updateRoutes')]
    public function test_removing_the_first_account_keeps_the_remaining_primary_account(bool $bulk): void
    {
        $employee = User::factory()->create(['email' => 'bank-edit@example.test']);
        $department = DepartmentOffice::factory()->create();
        $this->actingAs(User::factory()->create());

        $profileUrl = route('employees.update', $employee);
        $profile = [
            'name' => $employee->name,
            'email' => $employee->email,
            'gender' => 'female',
            'employeeCode' => 10046,
            'pay_scale_id' => 1,
            'payroll_group_id' => 1,
            'department_offices' => [['department_office_id' => $department->id]],
            'phone_numbers' => [['phoneNumber' => '01700000000']],
            'addresses' => [['address' => 'Work address']],
        ];
        $this->putJson($profileUrl, $profile + ['bank_accounts' => [
            ['bank_account_no' => 'STAMP', 'isSalaryAccount' => false],
            ['bank_account_no' => '1234567890', 'isSalaryAccount' => true],
        ]])->assertOk();
        [$stamp, $primary] = $employee->bankAccounts()->orderBy('id')->get()->all();
        $employee->employeeInfo()->update(['bank_account_id' => $stamp->id]);

        $save = fn (array $accounts) => $bulk
            ? $this->patchJson(route('employees.bulkProfileUpdateSingle', $employee), [
                'fields' => ['bank_accounts'],
                'bank_accounts' => $accounts,
            ])
            : $this->putJson($profileUrl, $profile + ['bank_accounts' => $accounts]);

        $save([['id' => $primary->id, 'bank_account_no' => '1234567890', 'isSalaryAccount' => true]])->assertOk();
        $this->assertDatabaseMissing('bank_accounts', ['id' => $stamp->id]);
        $this->assertDatabaseHas('bank_accounts', ['id' => $primary->id, 'isSalaryAccount' => 1]);
        $this->assertDatabaseHas('employee_infos', ['user_id' => $employee->id, 'bank_account_id' => $primary->id]);

        $save([['id' => $primary->id, 'bank_account_no' => '2345678901', 'isSalaryAccount' => true]])->assertOk();
        $this->assertDatabaseHas('bank_accounts', ['id' => $primary->id, 'account_no' => '2345678901']);

        $save([])->assertOk();
        $this->assertDatabaseMissing('bank_accounts', ['id' => $primary->id]);
        $placeholder = $employee->bankAccounts()->sole();
        $this->assertSame('N/A', $placeholder->account_no);
        $this->assertFalse((bool) $placeholder->isSalaryAccount);
        $this->assertDatabaseHas('employee_infos', ['user_id' => $employee->id, 'bank_account_id' => $placeholder->id]);

        $save([['id' => null, 'bank_account_no' => '1234567890', 'isSalaryAccount' => true]])->assertOk();
        $newPrimary = $employee->bankAccounts()->sole();
        $source = [
            'user_id' => $employee->id,
            'salary_generate_id' => 1,
            'salary_year' => 2026,
            'salary_month' => 10,
            'bank_account_id' => $newPrimary->id,
            'source_hash' => str_repeat('a', 64),
            'source_snapshot' => '{}',
            'regular_net' => 100,
            'arrear_payable' => 0,
            'arrear_deduction' => 0,
            'net_payable' => 100,
        ];
        DB::table('salary_bill_source_links')->insert($source);

        $response = $save([])->assertUnprocessable();
        $this->assertStringContainsString('HISTORICAL_SALARY_IMMUTABLE', $response->json('message'));
        $this->assertDatabaseHas('salary_bill_source_links', $source);
        $this->assertDatabaseHas('employee_infos', ['user_id' => $employee->id, 'bank_account_id' => $newPrimary->id]);
        $this->assertSame($newPrimary->id, $employee->bankAccounts()->sole()->id);
    }
}
