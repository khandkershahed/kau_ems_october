<?php

namespace Database\Seeders;

use App\Models\Grade;
use App\Models\PayScale;
use Illuminate\Database\Seeder;

/**
 * Optional: register the new 2026 scale in existing payroll configuration.
 * Does not reassign employees, payroll groups, salaries or historical bills.
 */
class PayScale2026Seeder extends Seeder
{
    public function run(): void
    {
        $scale = PayScale::firstOrCreate(
            ['scale_name' => 'Govt. Pay Scale 2026'],
            [
                'scale_description' => '2026 national pay scale; stage data from the supplied 17 September 2026 gazette (SRO 347/2026), pending official payroll sign-off.',
                'isActive' => false,
            ]
        );
        foreach (config('kau_fixation_2026', []) as $number => $row) {
            Grade::firstOrCreate(
                [
                    'pay_scale_id' => $scale->id,
                    'grade_name' => 'Grade '.$number,
                ],
                [
                    'grade_description' => '2026 stage range for Grade '.$number.'; staff conversion requires review.',
                    'min' => $row['init2026'],
                    'max' => $row['max2026'],
                    'isActive' => false,
                ]
            );
        }
    }
}
