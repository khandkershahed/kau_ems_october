<?php

namespace Tests\Unit;

use App\Console\Commands\PreparePayScale2026;
use PHPUnit\Framework\TestCase;

class PayScale2026PreparationPolicyTest extends TestCase
{
    public function test_special_benefit_is_excluded_even_with_different_spelling(): void
    {
        $this->assertTrue(PreparePayScale2026::isSpecialBenefit('Special benefit'));
        $this->assertTrue(PreparePayScale2026::isSpecialBenefit('Unrelated', 'special_benefit'));
        $this->assertTrue(PreparePayScale2026::isSpecialBenefit('Special Bonus'));
    }

    public function test_other_component_types_are_kept_as_inactive_templates(): void
    {
        $this->assertFalse(PreparePayScale2026::isSpecialBenefit('Basic Pay'));
        $this->assertFalse(PreparePayScale2026::isSpecialBenefit('House Rent Allowance'));
        $this->assertFalse(PreparePayScale2026::isSpecialBenefit('General Provident Fund'));
    }
}
