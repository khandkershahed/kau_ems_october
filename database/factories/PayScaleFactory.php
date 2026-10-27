<?php

namespace Database\Factories;

use App\Models\PayScale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayScale>
 */
class PayScaleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {

        $scale = fake()->unique()->randomElement(["Govt. Pay Scale 2015"]);

        return [
            "scale_name" => $scale,
            "scale_description" => "Govt. notice for the pay scale of version $scale",
            "isActive" => true,
        ];
    }
}
