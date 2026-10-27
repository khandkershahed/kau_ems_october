<?php
namespace App\Services;

use DomainException;

/** Compares financial source identities without introducing a new pay formula. */
final class SalaryBulkSourceGuard
{
    public static function assertMatches(?string $expected, string $actual): void
    {
        // Older already-started runs do not have preview hashes.
        if ($expected === null) return;
        if (!preg_match('/^[a-f0-9]{64}$/', $expected) || !preg_match('/^[a-f0-9]{64}$/', $actual)
            || !hash_equals($expected, $actual)) {
            throw new DomainException('Salary source changed since bulk preview; recalculate this employee before generating.');
        }
    }
}
