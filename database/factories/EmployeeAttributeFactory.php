<?php

namespace Database\Factories;

use App\Models\EmployeeAttribute;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeAttribute>
 */
class EmployeeAttributeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [ 
            'user_id'=> fake()->randomElement(User::all()->pluck(['id'])),
         'name'=> fake()->randomElement(["vc", "is_proctor", "is_librarian"]),
         'description'=> fake()->text(40),
         'isActive'=> true
        ];
    }
}
