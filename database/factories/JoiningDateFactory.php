<?php

namespace Database\Factories;

use App\Models\JoiningDate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JoiningDate>
 */
class JoiningDateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fake()->randomElement(User::all()->pluck(['id'])),
            'joining_date' => $this->faker->date(),
        ];
    }
}
