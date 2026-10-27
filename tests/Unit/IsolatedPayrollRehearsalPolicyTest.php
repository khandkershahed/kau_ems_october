<?php

namespace Tests\Unit;

use App\Services\IsolatedPayrollRehearsalPolicy as Policy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class IsolatedPayrollRehearsalPolicyTest extends TestCase
{
    public function test_dedicated_local_test_database_is_accepted(): void
    {
        Policy::assertAllowed(true, 'local', 'mysql', 'kau_step10_test', '127.0.0.1');
        $this->assertTrue(true);
    }

    public function test_real_payroll_database_is_always_refused(): void
    {
        $this->expectException(DomainException::class);
        Policy::assertAllowed(true, 'local', 'mysql', 'kau-app', '127.0.0.1');
    }

    public function test_production_environment_is_refused_even_with_cloned_name(): void
    {
        $this->expectException(DomainException::class);
        Policy::assertAllowed(true, 'production', 'mysql', 'kau_step10_test', '127.0.0.1');
    }

    public function test_missing_explicit_confirmation_is_refused(): void
    {
        $this->expectException(DomainException::class);
        Policy::assertAllowed(false, 'local', 'mysql', 'kau_step10_test', '127.0.0.1');
    }

    public function test_remote_mysql_host_is_refused(): void
    {
        $this->expectException(DomainException::class);
        Policy::assertAllowed(true, 'testing', 'mysql', 'kau_step10_test', 'db.production.internal');
    }
}
