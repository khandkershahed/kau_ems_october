<?php

namespace Database\Factories;

use App\Models\PayrollComponentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollComponentType>
 */
class PayrollComponentTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {

        $nameOfAllowances = [
            "Basic Pay",
            "House Rent Allowance",
            "Mobile Allowance",
            "Medical Allowance",
            "Special benefit",
            "Education Allowance ",
            "Bangla New Year Allowance",
            "Charge Allowance",
            "Festival Allowance",
            "Book Allowance",
            "Others Allowance ",
            "General Provident Fund Loan",
            "PFL Loan Interest Installment",
            "Group Insurance subscription",
            "Society subscription",
            "Club subscription",
            "Welfare Fund subscription",
            "House Building Loan",
            "Wholesale loan",
            "General Provident Fund ",
            "Benevolent Fund ",
            "Income Tax ",
            "Teachers' Association Subscription",
            "Others Deduction",
            "Deduction(Transport)",
            "Revenue Stamp"
        ];

        $picktype = fake()->unique()->randomElement($nameOfAllowances);
        return [
            "name" => $picktype,
            "component_description" => "Description for the $picktype",
        ];
    }
}
