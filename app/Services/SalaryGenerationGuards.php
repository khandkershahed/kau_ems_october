<?php

namespace App\Services;

use DateTimeImmutable;
use DomainException;

/** Shared non-mutating prerequisites for real salary generation and staging rehearsal. */
final class SalaryGenerationGuards
{
    public static function assertSalaryDate(int $year, int $month, string $salaryDate): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $salaryDate);
        if (!$date || $date->format('Y-m-d') !== $salaryDate
            || $date->format('Y-m') !== sprintf('%04d-%02d', $year, $month)) {
            throw new DomainException('Salary generation date must be a valid date within the selected salary month.');
        }
    }

    /**
     * Mirrors the employee-grade relationship verified by kau:audit-payroll-release.
     * This applies only to pre-activation regular 2015-scale payroll.
     */
    public static function assertLegacyGradeScale(
        ?int $employeeScaleId,
        ?int $gradeScaleId,
        ?string $employeeScaleName
    ): void {
        if (!$employeeScaleId || !$gradeScaleId || $employeeScaleId !== $gradeScaleId) {
            throw new DomainException('GRADE_SCALE_RELATION_MISMATCH: HR must verify the employee Pay Scale and Grade before generation.');
        }
        if (!str_contains((string) $employeeScaleName, '2015')) {
            throw new DomainException('OCTOBER_REQUIRES_2015_SCALE: future scales must not activate early.');
        }
    }

    /** Prevent preview/real-generation drift without changing valid legacy rates. */
    public static function assertComponents(array $additions, array $deductions): void
    {
        PayrollPreviewCalculator::calculate($additions, $deductions);
    }
}
