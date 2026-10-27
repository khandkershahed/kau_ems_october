<?php

namespace Database\Factories;

use App\Models\PhoneNumber;
use App\Models\PhoneNumberUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhoneNumberUser>
 */
class PhoneNumberUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
        "user_id" => fake()->randomElement(User::all()->pluck(['id'])), 
        "phone_number_id" => fake()->randomElement(PhoneNumber::all()->pluck(['id'])), 
        ];
    }
}
