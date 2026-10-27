<?php

namespace Database\Factories;

use App\Models\PayrollGroup;
use App\Models\PayrollGroupPayScale;
use App\Models\PayScale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollGroupPayScale>
 */
class PayrollGroupPayScaleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            "pay_scale_id" => fake()->randomElement(PayScale::all()->pluck(['id'])), 
            "payroll_group_id"=> fake()->randomElement(PayrollGroup::all()->pluck(['id']))
        ];
    }
}
