<?php

namespace Tests\Unit;

use App\Services\SalaryBankAccountResolver;
use PHPUnit\Framework\TestCase;

final class SalaryBankAccountResolverTest extends TestCase
{
    public function test_explicit_link_wins_over_duplicate_salary_flags(): void
    {
        $result = SalaryBankAccountResolver::resolve(10, 22, [
            ['id' => 21, 'user_id' => 10, 'isSalaryAccount' => 1],
            ['id' => 22, 'user_id' => 10, 'isSalaryAccount' => 1],
        ]);
        $this->assertSame(22, $result['selected_id']);
        $this->assertSame([], $result['errors']);
        $this->assertContains('MULTIPLE_SALARY_BANK_FLAGS_CHECK_LINKED_ACCOUNT', $result['warnings']);
    }

    public function test_wrong_owner_for_explicit_link_is_blocked(): void
    {
        $result = SalaryBankAccountResolver::resolve(10, 99, [
            ['id' => 99, 'user_id' => 11, 'isSalaryAccount' => 1],
        ]);
        $this->assertNull($result['selected_id']);
        $this->assertContains('LINKED_BANK_ACCOUNT_MISSING_OR_WRONG_OWNER', $result['errors']);
    }

    public function test_ambiguous_unlinked_salary_accounts_are_blocked(): void
    {
        $result = SalaryBankAccountResolver::resolve(10, null, [
            ['id' => 21, 'user_id' => 10, 'isSalaryAccount' => 1],
            ['id' => 22, 'user_id' => 10, 'isSalaryAccount' => 1],
        ]);
        $this->assertNull($result['selected_id']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_unique_salary_account_without_explicit_link_needs_review(): void
    {
        $result = SalaryBankAccountResolver::resolve(10, null, [
            ['id' => 21, 'user_id' => 10, 'isSalaryAccount' => 1],
        ]);
        $this->assertSame(21, $result['selected_id']);
        $this->assertEmpty($result['errors']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_single_unmarked_account_preserves_legacy_fallback_with_warning(): void
    {
        $result = SalaryBankAccountResolver::resolve(10, null, [
            ['id' => 21, 'user_id' => 10, 'isSalaryAccount' => 0],
        ]);
        $this->assertSame(21, $result['selected_id']);
        $this->assertEmpty($result['errors']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_explicit_link_with_no_salary_flag_needs_review(): void
    {
        $result = SalaryBankAccountResolver::resolve(10, 21, [
            (object) ['id' => 21, 'user_id' => 10, 'isSalaryAccount' => 0],
        ]);
        $this->assertSame(21, $result['selected_id']);
        $this->assertEmpty($result['errors']);
        $this->assertNotEmpty($result['warnings']);
    }
}
