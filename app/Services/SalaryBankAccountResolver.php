<?php

namespace App\Services;

/**
 * Resolve the account explicitly linked by an employee profile, rather than
 * arbitrarily selecting the first of several salary-marked bank accounts.
 * This service is read-only and never changes account flags or details.
 */
final class SalaryBankAccountResolver
{
    /** @param array<int, array<string, mixed>|object> $accounts */
    public static function resolve(int $userId, ?int $linkedAccountId, array $accounts): array
    {
        $owned = [];
        foreach ($accounts as $account) {
            $id = (int) self::field($account, 'id');
            if ($id > 0 && (int) self::field($account, 'user_id') === $userId) {
                $owned[$id] = $account;
            }
        }
        $salary = array_filter($owned, fn ($account) => (bool) self::field($account, 'isSalaryAccount'));
        $warnings = [];
        $errors = [];
        $selectedId = null;

        if ($linkedAccountId !== null && $linkedAccountId > 0) {
            if (!isset($owned[$linkedAccountId])) {
                $errors[] = 'LINKED_BANK_ACCOUNT_MISSING_OR_WRONG_OWNER';
            } else {
                $selectedId = $linkedAccountId;
                if (!(bool) self::field($owned[$linkedAccountId], 'isSalaryAccount')) {
                    $warnings[] = 'LINKED_BANK_ACCOUNT_NOT_MARKED_FOR_SALARY';
                }
                if (count($salary) > 1) {
                    $warnings[] = 'MULTIPLE_SALARY_BANK_FLAGS_CHECK_LINKED_ACCOUNT';
                }
            }
        } elseif (count($salary) === 1) {
            $selectedId = (int) array_key_first($salary);
            $warnings[] = 'EMPLOYEE_BANK_LINK_MISSING_USING_UNIQUE_SALARY_ACCOUNT';
        } elseif (count($salary) > 1) {
            $errors[] = 'MULTIPLE_SALARY_BANK_ACCOUNTS_WITHOUT_LINK';
        } elseif (count($owned) === 1) {
            $selectedId = (int) array_key_first($owned);
            $warnings[] = 'EMPLOYEE_BANK_LINK_AND_SALARY_FLAG_MISSING';
        } elseif (count($owned) === 0) {
            $errors[] = 'NO_BANK_ACCOUNT_FOR_EMPLOYEE';
        } else {
            $errors[] = 'MULTIPLE_UNMARKED_BANK_ACCOUNTS_WITHOUT_LINK';
        }

        return [
            'selected_id' => $selectedId,
            'errors' => $errors,
            'warnings' => $warnings,
            'salary_flagged_count' => count($salary),
            'owned_count' => count($owned),
        ];
    }

    private static function field(array|object $account, string $key): mixed
    {
        return is_array($account) ? ($account[$key] ?? null) : ($account->{$key} ?? null);
    }
}
