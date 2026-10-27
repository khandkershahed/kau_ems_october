<?php

namespace Tests\Unit;

use App\Services\SalaryCalculationService;
use App\Services\SalaryArrearFormulaEngine;
use PHPUnit\Framework\TestCase;

final class SalaryMeetingGPFPolicyPhase6cTest extends TestCase
{
    public function test_meeting_basic_payable_can_use_approved_june_baseline_without_september_paid_bill(): void
    {
        $months = array_fill(0, 3, ['REVISED_BASIC' => 28715, 'JUNE_BASIC' => 18530]);
        self::assertSame(30555.0, (new SalaryArrearFormulaEngine())->evaluate(
            'SUM_MONTHS(REVISED_BASIC - JUNE_BASIC)', [], $months
        ));
    }

    public function test_meeting_gpf_truncation_and_current_half_up_rule_are_different(): void
    {
        // Original meeting PDF p.3 used 2,871 and 1,946 (monthly difference 925).
        self::assertSame(2775, (2871 - 1946) * 3);
        // User's later explicit policy: 28,715 * 10% = 2,871.50 -> 2,872 HALF UP.
        $revised = (int) SalaryCalculationService::halfUpTaka(28715 * .10);
        self::assertSame(2872, $revised);
        self::assertSame(2778, ($revised - 1946) * 3);
        self::assertSame(16230, 30555 - 2790 - 8757 - 2778);
    }
}
