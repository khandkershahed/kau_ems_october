<?php

namespace Tests\Unit;

use App\Services\SalaryGenerationGuards;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SalaryGenerationGuardsTest extends TestCase
{
    public function test_october_2015_grade_scale_match_is_accepted(): void
    {
        SalaryGenerationGuards::assertLegacyGradeScale(1, 1, 'Govt. Pay Scale 2015');
        $this->assertTrue(true);
    }

    public function test_grade_scale_mismatch_cannot_bypass_read_only_audit(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('GRADE_SCALE_RELATION_MISMATCH');
        SalaryGenerationGuards::assertLegacyGradeScale(2, 1, 'National Pay Scale 2015');
    }

    public function test_new_scale_is_not_eligible_for_october_regular_payroll(): void
    {
        $this->expectException(DomainException::class);
        SalaryGenerationGuards::assertLegacyGradeScale(9, 9, 'Govt. Pay Scale 2026');
    }

    public function test_salary_date_must_match_selected_month(): void
    {
        SalaryGenerationGuards::assertSalaryDate(2026, 10, '2026-10-31');
        $this->expectException(DomainException::class);
        SalaryGenerationGuards::assertSalaryDate(2026, 10, '2026-09-30');
    }

    public function test_impossible_calendar_date_rejected(): void
    {
        $this->expectException(DomainException::class);
        SalaryGenerationGuards::assertSalaryDate(2026, 10, '2026-10-32');
    }

    public function test_positive_fixed_basic_and_gpf_are_accepted(): void
    {
        SalaryGenerationGuards::assertComponents([
            ['name' => 'Basic Pay', 'calcType' => 'fixed', 'amount' => 19460],
            ['name' => 'House Rent', 'calcType' => 'fixed', 'amount' => 8757],
        ], [['name' => 'GPF', 'calcType' => 'percentage', 'amount' => 10]]);
        $this->assertTrue(true);
    }

    public function test_negative_allowance_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        SalaryGenerationGuards::assertComponents([
            ['name' => 'Basic Pay', 'calcType' => 'fixed', 'amount' => 19460],
            ['name' => 'Medical', 'calcType' => 'fixed', 'amount' => -200],
        ], []);
    }

    public function test_percentage_basic_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        SalaryGenerationGuards::assertComponents([
            ['name' => 'Basic Pay', 'calcType' => 'percentage', 'amount' => 10],
        ], []);
    }
}
