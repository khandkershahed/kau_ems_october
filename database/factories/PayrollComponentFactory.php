<?php

namespace Database\Factories;

use App\Models\PayrollComponent;
use App\Models\PayrollComponentType;
use App\Models\PayrollGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollComponent>
 */
class PayrollComponentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
       

        
        return [
            "payroll_group_id" => fake()->randomElement(PayrollGroup::all()->pluck(['id'])),
            "payroll_component_type_id" => fake()->randomElement(PayrollComponentType::all()->pluck(['id'])),
            "component_type" => fake()->randomElement(["allowance", "deduction"]),
            "calcType" => fake()->randomElement(["fixed", "percentage"]),
            "is_taxable" => fake()->boolean(),
            "isActive" => true,
            "amount" => fake()->numerify("####")
        ];
    }
}
