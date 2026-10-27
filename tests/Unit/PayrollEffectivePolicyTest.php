<?php

namespace Tests\Unit;

use App\Services\PayrollEffectivePolicy;
use PHPUnit\Framework\TestCase;

class PayrollEffectivePolicyTest extends TestCase
{
    public function test_october_2026_must_not_activate_2026_scale(): void
    {
        $this->assertFalse(PayrollEffectivePolicy::uses2026PayScale(2026, 10));
        $this->assertFalse(PayrollEffectivePolicy::uses2026PayScale(2027, 6));
    }

    public function test_2026_scale_activates_on_july_2027(): void
    {
        $this->assertTrue(PayrollEffectivePolicy::uses2026PayScale(2027, 7));
        $this->assertTrue(PayrollEffectivePolicy::uses2026PayScale(2028, 1));
    }

    public function test_special_benefit_is_distinguished_from_other_allowances(): void
    {
        $this->assertTrue(PayrollEffectivePolicy::isSpecialBenefit(['name' => 'Special benefit']));
        $this->assertFalse(PayrollEffectivePolicy::isSpecialBenefit(['name' => 'House Rent Allowance']));
    }
}
