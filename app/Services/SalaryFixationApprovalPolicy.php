<?php

namespace App\Services;

/**
 * HR/Superadmin may approve a calculated fixation at any workflow stage.
 * Only the presence of actual numerical pay results is mandatory: employee
 * confirmation, historical report matching, and review status are advisory.
 * This class never changes the separate regular payroll activation policy.
 */
final class SalaryFixationApprovalPolicy
{
    public const EFFECTIVE_PHASES = [
        '2026-07-01' => 'phase_2026_07',
        '2027-01-01' => 'phase_2027_01',
        '2027-07-01' => 'phase_2027_07',
    ];

    public static function isCalculable(array $prepared): bool
    {
        $grade = $prepared['grade'] ?? null;
        $calculation = $prepared['calculation'] ?? null;
        if (!is_numeric($grade) || (int) $grade < 1 || (int) $grade > 20 || !is_array($calculation)) {
            return false;
        }
        foreach (array_values(self::EFFECTIVE_PHASES) as $key) {
            $value = $calculation[$key] ?? null;
            if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0) {
                return false;
            }
        }
        return true;
    }

    public static function mayApproveStatus(string $status): bool
    {
        return in_array($status, ['pending_review', 'sent', 'employee_confirmed', 'employee_disputed', 'approved'], true);
    }

    public static function sameCalculation(array $saved, array $fresh): bool
    {
        foreach (['fitment_basic', 'increment_2026', 'increment_2027',
            'phase_2026_07', 'phase_2027_01', 'phase_2027_07'] as $key) {
            if (!isset($saved[$key], $fresh[$key]) || !is_numeric($saved[$key]) || !is_numeric($fresh[$key])
                || abs((float) $saved[$key] - (float) $fresh[$key]) >= 0.005) {
                return false;
            }
        }
        return true;
    }
}
