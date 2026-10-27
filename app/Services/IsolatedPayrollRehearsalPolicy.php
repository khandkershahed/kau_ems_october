<?php

namespace App\Services;

use DomainException;

/** No financial write, even transiently, may run on the actual KAU database. */
final class IsolatedPayrollRehearsalPolicy
{
    public static function assertAllowed(
        bool $confirmed,
        string $environment,
        string $driver,
        string $databaseName,
        string $host
    ): void {
        if (!$confirmed || !in_array($environment, ['local', 'testing'], true)
            || $driver !== 'mysql'
            || !preg_match('/^kau_step10_(test|staging)[a-z0-9_]*$/i', $databaseName)
            || !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new DomainException(
                'Refused: use only a local disposable kau_step10_test/staging MySQL clone, '
                .'APP_ENV=local/testing, and the explicit --confirm-isolated flag.'
            );
        }
    }
}
