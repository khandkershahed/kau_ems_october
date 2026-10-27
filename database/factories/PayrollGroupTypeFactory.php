<?php

namespace Database\Factories;

use App\Models\PayrollGroupType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollGroupType>
 */
class PayrollGroupTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {

        $possibleTypes = [
            // Statutory & Executive Leadership
            'Vice-Chancellor',
            'Pro-Vice-Chancellor',
            'Treasurer',

            // Academic Administration
            'Dean',
            'Chairman / Head of Department',
            'Director (Institute / Center / IQAC)',
            'Proctor',
            'Assistant Proctor',
            'Provost',
            'Assistant Provost / House Tutor',
            'Director (Students Welfare)',

            // Academic / Faculty
            'Professor Emeritus',
            'Professor',
            'Associate Professor',
            'Assistant Professor',
            'Senior Lecturer',
            'Lecturer',
            'Adjunct Faculty',
            'Teaching Assistant',
            'Research Fellow',
            'Research Associate',
            'Research Assistant',

            // Administrative Officers
            'Registrar',
            'Additional Registrar',
            'Deputy Registrar',
            'Assistant Registrar',
            'Section Officer',
            'Administrative Officer',
            'PS to Vice-Chancellor',

            // Examination & Records
            'Controller of Examinations',
            'Deputy Controller of Examinations',
            'Assistant Controller of Examinations',

            // Finance & Accounts
            'Director (Finance & Accounts)',
            'Deputy Director (Finance & Accounts)',
            'Accounts Officer',
            'Audit Officer',
            'Accountant',
            'Cashier',

            // Library Staff
            'Librarian',
            'Deputy Librarian',
            'Assistant Librarian',
            'Library Officer',
            'Library Assistant',

            // ICT & Technical Staff
            'Director (ICT Cell)',
            'System Administrator',
            'Network Engineer',
            'Programmer',
            'Assistant Programmer',
            'Hardware Engineer',
            'Technical Officer',
            'Lab Assistant',
            'Lab Attendant',

            // Planning, Development & Engineering
            'Chief Engineer',
            'Executive Engineer',
            'Assistant Engineer',
            'Sub-Assistant Engineer',
            'Planning & Development Officer',
            'Estate Officer',
            'Transport Officer',
            'Security Officer',

            // Medical & Student Services
            'Chief Medical Officer',
            'Senior Medical Officer',
            'Medical Officer',
            'Student Counselor / Psychologist',
            'Director (Physical Education)',
            'Physical Instructor',

            // Support Staff (Class III & IV / MLSS)
            'Office Assistant cum Computer Typist',
            'Data Entry Operator',
            'Store Keeper',
            'Electrician',
            'Plumber',
            'Driver',
            'Security Guard',
            'Office Peon / Attendant',
            'Gardener (Mali)',
            'Cleaner',
        ];

        $pickType = fake()->unique()->randomElement($possibleTypes);
        return [
            "group_name" => $pickType,
            "group_description" => "Description for $pickType",
        ];
    }
}
