<?php

namespace Database\Factories;

use App\Models\Grade;
use App\Models\PayScale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Grade>
 */
class GradeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $grade = fake()->unique()->numberBetween(1, 20);
        $maxAmount = function () use ($grade) {
            $max = 0.00;
            $min = 0.00;

            switch ($grade) {
                case 1:
                    $min = 78000.00;
                    $max = 78000.00; // Grade 1 has a fixed basic salary in the 2015 scale
                    break;
                case 2:
                    $min = 66000.00;
                    $max = 76490.00;
                    break;
                case 3:
                    $min = 56500.00;
                    $max = 74400.00;
                    break;
                case 4:
                    $min = 50000.00;
                    $max = 71200.00;
                    break;
                case 5:
                    $min = 43000.00;
                    $max = 69850.00;
                    break;
                case 6:
                    $min = 35500.00;
                    $max = 67010.00;
                    break;
                case 7:
                    $min = 29000.00;
                    $max = 63410.00;
                    break;
                case 8:
                    $min = 23000.00;
                    $max = 55470.00;
                    break;
                case 9:
                    $min = 22000.00;
                    $max = 53060.00;
                    break;
                case 10:
                    $min = 16000.00;
                    $max = 38640.00;
                    break;
                case 11:
                    $min = 12500.00;
                    $max = 30230.00;
                    break;
                case 12:
                    $min = 11300.00;
                    $max = 27300.00;
                    break;
                case 13:
                    $min = 11000.00;
                    $max = 26590.00;
                    break;
                case 14:
                    $min = 10200.00;
                    $max = 24680.00;
                    break;
                case 15:
                    $min = 9700.00;
                    $max = 23490.00;
                    break;
                case 16:
                    $min = 9300.00;
                    $max = 22490.00;
                    break;
                case 17:
                    $min = 9000.00;
                    $max = 21800.00;
                    break;
                case 18:
                    $min = 8800.00;
                    $max = 21310.00;
                    break;
                case 19:
                    $min = 8500.00;
                    $max = 20570.00;
                    break;
                case 20:
                    $min = 8250.00;
                    $max = 20010.00;
                    break;
                default:
                    break;
            }
            return [$max, $min];
        };
        return [
            // "pay_scale_id"=>fake()->randomElement(PayScale::all()->pluck(['id'])),
            "pay_scale_id" => 1,
            "grade_name" => "Grade $grade",
            "grade_description" => "Description for the $grade",
            "isActive" => true,
            "max" => $maxAmount()[0],
            "min" => $maxAmount()[1],
        ];
    }
}
