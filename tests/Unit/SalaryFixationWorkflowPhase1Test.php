<?php

namespace Tests\Unit;

use App\Services\SalaryFixationApprovalPolicy;
use App\Services\SalaryFixationWorkflowService;
use PHPUnit\Framework\TestCase;

class SalaryFixationWorkflowPhase1Test extends TestCase
{
    public function test_ordinary_employee_displays_actual_increment(): void
    {
        self::assertSame(['amount' => 1100.0, 'label' => 'Increment applicable'],
            SalaryFixationWorkflowService::incrementDisplay([
                'grade' => 20, 'special_eligibility_category' => 'ordinary',
                'calculation' => ['increment_2026' => 1100],
            ]));
    }

    public function test_study_leave_exception_displays_allowed_increment(): void
    {
        self::assertSame('Study Leave — increment allowed',
            SalaryFixationWorkflowService::incrementDisplay([
                'grade' => 12, 'special_eligibility_category' => 'study_leave_exception',
                'calculation' => ['increment_2026' => 1800],
            ])['label']);
    }

    /** @dataProvider categoriesWithoutIncrement */
    public function test_special_category_displays_status_not_zero(string $category, string $expected): void
    {
        $actual = SalaryFixationWorkflowService::incrementDisplay([
            'grade' => 15, 'special_eligibility_category' => $category,
            'calculation' => ['increment_2026' => 0],
        ]);
        self::assertNull($actual['amount']);
        self::assertSame($expected, $actual['label']);
    }

    public static function categoriesWithoutIncrement(): array
    {
        return [
            'adhoc' => ['adhoc', 'AD HOC — no increment'],
            'hold' => ['increment_hold', 'Increment Hold'],
            'study leave' => ['study_leave_restricted', 'Study Leave — no increment'],
        ];
    }

    public function test_grade_one_and_last_stage_are_explained(): void
    {
        self::assertSame('Grade 1 — no increment', SalaryFixationWorkflowService::incrementDisplay([
            'grade' => 1, 'calculation' => ['increment_2026' => 0],
        ])['label']);
        self::assertSame('Last pay stage', SalaryFixationWorkflowService::incrementDisplay([
            'grade' => 12, 'calculation' => ['increment_2026' => 0, 'at_last_stage' => true],
        ])['label']);
    }

    public function test_numerical_fixation_can_be_approved_without_employee_review(): void
    {
        self::assertTrue(SalaryFixationApprovalPolicy::isCalculable([
            'grade' => 10, 'ready' => false,
            'calculation' => [
                'phase_2026_07' => 28715,
                'phase_2027_01' => 31000,
                'phase_2027_07' => 35500,
            ],
        ]));
    }
}
