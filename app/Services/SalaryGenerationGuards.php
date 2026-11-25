<?php

namespace App\Services;

use App\Exceptions\HistoricalSalaryImmutableException;
use DateTimeImmutable;
use DomainException;

/** Shared non-mutating prerequisites for real salary generation and staging rehearsal. */
final class SalaryGenerationGuards
{
    /** Salary months up to and including 2026-10 are finalized (YYYYMM). */
    public const LOCKED_THROUGH_YEAR_MONTH = 202610;

    public static function isMonthLocked(int $year, int $month): bool
    {
        return $year * 100 + $month <= self::LOCKED_THROUGH_YEAR_MONTH;
    }

    /** @throws HistoricalSalaryImmutableException when the month is finalized. */
    public static function assertMonthMutable(int $year, int $month, string $action = 'changed'): void
    {
        if (self::isMonthLocked($year, $month)) {
            throw HistoricalSalaryImmutableException::forMonth($year, $month, $action);
        }
    }

    /** True when the employee joined after the last day of the salary month. */
    public static function joinedAfterPeriodEnd(mixed $joiningDate, int $year, int $month): bool
    {
        if ($joiningDate === null || trim((string) $joiningDate) === '') return false;
        $joined = substr(is_object($joiningDate) && method_exists($joiningDate, 'format')
            ? $joiningDate->format('Y-m-d') : (string) $joiningDate, 0, 10);
        $periodEnd = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('Y-m-t');
        return $joined > $periodEnd;
    }

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
