<?php

namespace Database\Factories;

use App\Models\Nid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Nid>
 */
class NidFactory extends Factory
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
            'nid_no' => $this->faker->unique()->numerify('##########'),
            'nid_img' => $this->faker->imageUrl(),
        ];
    }
}
