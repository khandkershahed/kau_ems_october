<?php

namespace Database\Seeders;

use App\Models\Grade;
use App\Models\PayScale;
use App\Models\PayrollComponent;
use App\Models\PayrollComponentType;
use App\Models\PayrollGroup;
use App\Models\PayrollGroupType;
use Illuminate\Database\Seeder;

class DesignationSalaryComponentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $dscPath = __DIR__ . '/archive/designation_salary_components.json';
        if (!file_exists($dscPath)) {
            $this->command?->error("File not found: {$dscPath}");
            return;
        }

        $dscData = json_decode(file_get_contents($dscPath), true);
        if (!is_array($dscData)) {
            $this->command?->error("Invalid JSON in: {$dscPath}");
            return;
        }

        // 1. Build dynamic designation to grades mapping from all archive files
        $designationGrades = $this->extractDesignationGradesFromArchive();

        // 2. Ensure default PayScale and fallback Grade exist
        $defaultPayScale = PayScale::firstOrCreate(
            ['scale_name' => 'National Pay Scale 2015'],
            ['scale_description' => 'National Pay Scale 2015', 'isActive' => true]
        );

        $allGrades = Grade::all();
        if ($allGrades->isEmpty()) {
            for ($g = 1; $g <= 20; $g++) {
                Grade::create([
                    'pay_scale_id' => $defaultPayScale->id,
                    'grade_name' => "Grade {$g}",
                    'grade_description' => "Standard Grade {$g}",
                    'min' => 10000 + ($g * 1000),
                    'max' => 20000 + ($g * 2000),
                    'isActive' => true,
                ]);
            }
            $allGrades = Grade::all();
        }

        // Cache component types by name (case-insensitive)
        $componentTypes = PayrollComponentType::all()->keyBy(function ($item) {
            return strtolower(trim($item->name));
        });

        // 3. Iterate over each designation in designation_salary_components.json
        foreach ($dscData as $item) {
            $designationName = trim($item['designation'] ?? '');
            if (!$designationName) {
                continue;
            }

            // Ensure PayrollGroupType exists
            $groupType = PayrollGroupType::firstOrCreate(
                ['group_name' => $designationName],
                ['group_description' => "Post of {$designationName}"]
            );

            // Determine suitable Grade(s)
            $matchedGradeNumbers = $designationGrades[$designationName] ?? [];
            if (empty($matchedGradeNumbers)) {
                // Fallback to Grade 9 or Grade 10 if none detected
                $matchedGradeNumbers = [9];
            }

            foreach ($matchedGradeNumbers as $gradeNum) {
                // Find grade matching Grade X or Grade 0X
                $grade = $allGrades->first(function ($g) use ($gradeNum) {
                    $name = strtolower($g->grade_name);
                    return $name === "grade {$gradeNum}" || $name === sprintf("grade %02d", $gradeNum);
                });

                if (!$grade) {
                    $grade = $allGrades->first();
                }

                // Ensure PayrollGroup exists
                $payrollGroup = PayrollGroup::firstOrCreate(
                    [
                        'grade_id' => $grade->id,
                        'payroll_group_type_id' => $groupType->id,
                    ],
                    [
                        'isActive' => true,
                    ]
                );

                // Assign allowances
                foreach ($item['allowances'] ?? [] as $allowanceName) {
                    $compType = $componentTypes->get(strtolower(trim($allowanceName)));
                    if (!$compType) {
                        $compType = PayrollComponentType::firstOrCreate(
                            ['name' => $allowanceName],
                            [
                                'component_description' => str_replace(' ', '_', strtolower($allowanceName)),
                            ]
                        );
                        $componentTypes->put(strtolower(trim($allowanceName)), $compType);
                    }

                    PayrollComponent::firstOrCreate(
                        [
                            'payroll_group_id' => $payrollGroup->id,
                            'payroll_component_type_id' => $compType->id,
                        ],
                        [
                            'component_type' => 'allowance',
                            'calcType' => 'fixed',
                            'is_taxable' => false,
                            'isActive' => true,
                            'amount' => 0.00,
                        ]
                    );
                }

                // Assign deductions
                foreach ($item['deductions'] ?? [] as $deductionName) {
                    $compType = $componentTypes->get(strtolower(trim($deductionName)));
                    if (!$compType) {
                        $compType = PayrollComponentType::firstOrCreate(
                            ['name' => $deductionName],
                            [
                                'component_description' => str_replace(' ', '_', strtolower($deductionName)),
                            ]
                        );
                        $componentTypes->put(strtolower(trim($deductionName)), $compType);
                    }

                    PayrollComponent::firstOrCreate(
                        [
                            'payroll_group_id' => $payrollGroup->id,
                            'payroll_component_type_id' => $compType->id,
                        ],
                        [
                            'component_type' => 'deduction',
                            'calcType' => 'fixed',
                            'is_taxable' => false,
                            'isActive' => true,
                            'amount' => 0.00,
                        ]
                    );
                }
            }
        }
    }

    /**
     * Scan archive folders to map designations to Grade numbers.
     */
    private function extractDesignationGradesFromArchive(): array
    {
        $archiveFiles = glob(__DIR__ . '/archive/*/*/*.json');
        $mapping = [];

        foreach ($archiveFiles as $file) {
            $content = @file_get_contents($file);
            if (!$content) {
                continue;
            }
            $data = json_decode($content, true);
            if (!is_array($data)) {
                continue;
            }

            foreach ($data as $record) {
                $des = trim($record['employee_info']['designation'] ?? '');
                $scaleStr = trim($record['employee_info']['pay_scale'] ?? '');

                if ($des && $scaleStr) {
                    // Extract grade number from string like "Grade 06 (35500 - 67010)"
                    if (preg_match('/Grade\s*0?(\d+)/i', $scaleStr, $matches)) {
                        $gradeNum = (int) $matches[1];
                        if (!isset($mapping[$des])) {
                            $mapping[$des] = [];
                        }
                        if (!in_array($gradeNum, $mapping[$des], true)) {
                            $mapping[$des][] = $gradeNum;
                        }
                    }
                }
            }
        }

        return $mapping;
    }
}
