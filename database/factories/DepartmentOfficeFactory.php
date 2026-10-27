<?php

namespace Database\Factories;

use App\Models\DepartmentOffice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepartmentOffice>
 */
class DepartmentOfficeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company() . ' Department',
            'type' => fake()->randomElement(['department', 'office', 'others']),
            'description' => fake()->sentence(),
            'isActive' => true,
        ];
    }
}
