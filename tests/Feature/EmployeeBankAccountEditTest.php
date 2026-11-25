<?php

namespace Tests\Feature;

use App\Models\DepartmentOffice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EmployeeBankAccountEditTest extends TestCase
{
    public function test_employee_profile_update_accepts_own_accounts_and_rejects_another_employees_account(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate')->assertSuccessful();

        $employee = User::factory()->create(['email' => 'employee@example.test']);
        $otherEmployee = User::factory()->create();
        $department = DepartmentOffice::factory()->create();
        DB::table('pay_scales')->insert(['id' => 1, 'scale_name' => 'Test Scale', 'scale_description' => 'Test']);
        DB::table('grades')->insert(['id' => 1, 'pay_scale_id' => 1, 'grade_name' => 'Test Grade', 'grade_description' => 'Test']);
        DB::table('payroll_group_types')->insert(['id' => 1, 'group_name' => 'Test Group', 'group_description' => 'Test']);
        DB::table('payroll_groups')->insert(['id' => 1, 'grade_id' => 1, 'payroll_group_type_id' => 1]);

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

        $payload['name'] = 'Should Roll Back';
        $payload['bank_accounts'][0]['bank_account_no'] = '987-654-3210';
        $this->putJson($url, $payload)->assertUnprocessable()
            ->assertJsonPath('errors.account_no.0', 'This bank account number is already registered to an employee.');
        $this->assertDatabaseHas('users', ['id' => $employee->id, 'name' => 'Updated Employee']);
        $this->assertDatabaseHas('bank_accounts', ['user_id' => $otherEmployee->id, 'account_no' => '9876543210']);
        $this->assertSame(2, $employee->bankAccounts()->count());
    }
}
