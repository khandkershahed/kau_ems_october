<?php
namespace Database\Seeders;
use App\Models\Role;
use Illuminate\Database\Seeder;
class SalaryFixationHrRoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(['role_name' => 'payroll_hr'], [
            'role_description' => 'Authorized HR / Accounts officers for 2026 salary fixation review',
            'is_admin' => false,
            'isActive' => true,
        ]);
        // Do not automatically assign this confidential permission to anybody.
    }
}
