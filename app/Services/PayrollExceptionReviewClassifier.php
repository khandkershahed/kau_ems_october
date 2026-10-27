<?php

namespace App\Services;

/**
 * Deterministic, read-only classification of the Step 9 payroll exception audit.
 * Actions are human-review categories, NEVER automatic database changes.
 */
final class PayrollExceptionReviewClassifier
{
    public static function actions(string $issues): array
    {
        $actions = [];
        if (str_contains($issues, 'DUPLICATE_USER_PROFILES')) {
            $actions[] = 'HR_RECONCILE_DUPLICATE_EMPLOYEE_PROFILES';
        }
        if (str_contains($issues, 'GRADE_SCALE_RELATION_MISMATCH')
            || str_contains($issues, 'OCTOBER_REQUIRES_2015_SCALE')) {
            $actions[] = 'HR_VERIFY_PAY_SCALE_AND_GRADE_LINK';
        }
        if (str_contains($issues, 'USER_INACTIVE')) {
            $actions[] = 'HR_VERIFY_OCTOBER_EMPLOYMENT_ELIGIBILITY';
        }
        if (str_contains($issues, 'Basic Pay is missing')
            || str_contains($issues, 'exactly one non-manual Basic Pay')) {
            $actions[] = 'HR_RECONCILE_FULL_SALARY_COMPONENT_SETUP';
        }
        if (str_contains($issues, 'BANK:')) {
            $actions[] = 'HR_VERIFY_EXPLICIT_LINKED_BANK_ACCOUNT_AND_FLAGS';
        }
        return $actions ?: ['HR_REVIEW_UNCLASSIFIED_EXCEPTION'];
    }

    /** Read a possibly double-encoded legacy salary component JSON value. */
    public static function decodeItems(mixed $value): array
    {
        for ($i = 0; $i < 3 && is_string($value); ++$i) {
            $value = json_decode($value, true);
        }
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /** Historical approved salary is a review reference, not a new entitlement. */
    public static function historicalBasic(mixed $serializedItems): ?float
    {
        $found = [];
        foreach (self::decodeItems($serializedItems) as $item) {
            if (!is_array($item)) continue;
            $name = strtolower(trim((string) ($item['name'] ?? $item['payroll_component_type']['name'] ?? '')));
            if ($name !== 'basic pay') continue;
            $amount = $item['calculated_amount'] ?? $item['amount'] ?? null;
            if (!is_numeric($amount) || (float) $amount <= 0) return null;
            $found[] = (float) $amount;
        }
        return count($found) === 1 ? $found[0] : null;
    }
}
