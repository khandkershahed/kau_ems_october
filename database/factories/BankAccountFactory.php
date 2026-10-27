<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
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
            'routing_no' => fake()->bothify("##??##??##"),
            'account_no' => fake()->numerify("###########"),
            'type' => fake()->randomElement([
                "Savings Account",
                "Current Account",
                "Fixed Deposit Receipt (FDR)",
                "Recurring Deposit Account",
                "Salary Account",
                "Joint Account",
                "NRI Account"
            ]),
            'address' => fake()->address(),
            'isSalaryAccount' => true
        ];
    }
}
