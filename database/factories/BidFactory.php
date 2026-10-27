<?php

namespace Database\Factories;

use App\Models\Bid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bid>
 */
class BidFactory extends Factory
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
            'date_of_birth' => $this->faker->date(),
            'bid_no' => $this->faker->unique()->numerify('#################'),
            'bidImg' => $this->faker->imageUrl(),
        ];
    }
}
