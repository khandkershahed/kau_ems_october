<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\BankAccount;
use App\Models\Bid;
use App\Models\DepartmentOffice;
use App\Models\EmployeeAttribute;
use App\Models\Grade;
use App\Models\JoiningDate;
use App\Models\Nid;
use App\Models\TIN;
use App\Models\PayrollComponent;
use App\Models\PayrollComponentType;
use App\Models\PayrollGroup;
use App\Models\PayrollGroupPayScale;
use App\Models\PayrollGroupType;
use App\Models\PayScale;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberUser;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\UserRole;
use Database\Factories\GradeFactory;
use Database\Factories\PayrollComponentFactory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {


        $demoUser = User::firstOrCreate(
            ['email' => 'superadmin@kau.ac.bd'],
            [
                'name' => 'Superadmin',
                'isActive' => true,
                'gender' => "male",
                "password" => Hash::make("superadmin@kau.ac.bd"),
            ]
        );

        $superadminRole = Role::firstOrCreate(
            ["role_name" => "superadmin"],
            [
                "role_description" => "Superadmin has all privilage to view all options access all features",
                "is_admin" => true,
                "isActive" => true,
            ]
        );

        // $userRole = Role::firstOrCreate(
        //     ["role_name" => "user"],
        //     [
        //         "role_description" => "Standard employee user role",
        //         "is_admin" => false,
        //         "isActive" => true,
        //     ]
        // );

        // UserRole::firstOrCreate([
        //     "user_id" => $demoUser->id,
        //     "role_id" => $superadminRole->id,
        // ]);



        PayScale::factory(1)->create();
        Grade::factory(20)->create();




        // Enable or disable bulk data seeding

        // if (env("APP_DEBUG", false)) {

        //     Address::factory(5)->create();
        //     BankAccount::factory(5)->create();
        //     EmployeeAttribute::factory(1)->create();
        //     Nid::factory(5)->create();
        //     Bid::factory(5)->create();
        //     TIN::factory(5)->create();
        //     DepartmentOffice::factory(5)->create();
        //     JoiningDate::factory(5)->create();


        //     PayScale::factory(5)->create();

        //     Grade::factory(10)->create();

        //     PayrollGroupType::factory(76)->create();
        //     PayrollGroup::factory(5)->create();


        //     PayrollComponentType::factory(10)->create();
        //     PayrollComponent::factory(10)->create();

        //     PhoneNumber::factory(10)->create();

        //     PhoneNumberUser::factory(3)->create();

        //     PayrollGroupPayScale::factory(5)->create();

        //     if (UserCategory::count() === 0) {
        //         UserCategory::firstOrCreate(['name' => 'Teacher'], ['description' => 'Faculty / Teaching Staff']);
        //         UserCategory::firstOrCreate(['name' => 'Officer'], ['description' => 'Administrative Officer']);
        //         UserCategory::firstOrCreate(['name' => 'Staff'], ['description' => 'Support Staff']);
        //     }

        //     $this->call(EmployeeSeeder::class, false, ['count' => 150]);
        // }


        // Seeding Departmets and offices
        $departmentOffice = json_decode(file_get_contents(__DIR__ . "/officeDepartment.json"));
        for ($i = 0; $i < count($departmentOffice); $i++) {
            DepartmentOffice::factory()->create([
                'name' => $departmentOffice[$i]->name,
                'type' => $departmentOffice[$i]->type,
                'description' => $departmentOffice[$i]->description,
                'isActive' => $departmentOffice[$i]->isActive,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }


        // Seeding Payroll Groups / Designations
        $payrollGroupType = json_decode(file_get_contents(__DIR__ . "/payrollGroupType.json"));
        for ($i = 0; $i < count($payrollGroupType); $i++) {
            PayrollGroupType::factory()->create(
                [
                    "group_name" => $payrollGroupType[$i]->group_name,
                    "group_description" => $payrollGroupType[$i]->group_description,
                ]
            );
        }

        // Payroll Component types
        $payrollComponentTypes = json_decode(file_get_contents(__DIR__ . "/payrollComponentTypes.json"));
        for ($i = 0; $i < count($payrollComponentTypes); $i++) {
            PayrollComponentType::factory()->create(
                [
                    "name" => $payrollComponentTypes[$i]->name,
                    "component_description" => $payrollComponentTypes[$i]->component_description,
                ]
            );
        }

        // Create User Category / Groups
        $userCategory = json_decode(file_get_contents(__DIR__ . "/userCategory.json"));
        for ($i = 0; $i < count($userCategory); $i++) {
            UserCategory::factory()->create(
                [
                    'name' => $userCategory[$i]->name,
                    'description' =>  $userCategory[$i]->description,
                ]
            );
        }

        // Seed Designation Salary Components & Assign to suitable Payroll Groups
        $this->call(DesignationSalaryComponentSeeder::class);

        // Employee Info
        // $employeeInfo = json_decode(file_get_contents(__DIR__ . "/employeeInfo.json"));

        // for ($i = 0; $i < count($employeeInfo); $i++) {
        //     echo "seeding... " . $employeeInfo[$i]->userName . "  :  " . $i . "\n";
        //     seedEmployeeRecord($employeeInfo[$i]);
        // }
    }
}
