<?php

namespace Database\Factories;

use App\Models\Grade;
use App\Models\PayrollGroup;
use App\Models\PayrollGroupType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollGroup>
 */
class PayrollGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            "grade_id" => fake()->randomElement(Grade::all()->pluck(['id'])),
            "payroll_group_type_id" => fake()->randomElement(PayrollGroupType::all()->pluck(['id'])),
            "isActive" => true,
        ];
    }
}
