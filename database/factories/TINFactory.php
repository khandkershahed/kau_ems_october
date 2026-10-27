<?php

namespace Database\Factories;

use App\Models\TIN;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TIN>
 */
class TINFactory extends Factory
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
            'tin_no' => $this->faker->unique()->numerify('############'),
            'tin_img' => $this->faker->imageUrl(),
        ];
    }
}
