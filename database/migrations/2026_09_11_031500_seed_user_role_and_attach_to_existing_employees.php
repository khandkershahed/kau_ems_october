<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $userRole = Role::firstOrCreate(
            ['role_name' => 'user'],
            [
                'role_description' => 'User has access to personalized features',
                'is_admin' => false,
                'isActive' => true,
            ]
        );

        // Ensure all users with employeeInfo records have the 'user' role attached
        $employees = User::whereHas('employeeInfo')->get();
        foreach ($employees as $employee) {
            $employee->roles()->syncWithoutDetaching([$userRole->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Non-destructive: maintain role assignments
    }
};
