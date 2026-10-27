<?php

namespace Tests\Unit;

use App\Services\SalaryArrearFormulaEngine;
use App\Support\SalaryArrearTemplateCatalog;
use PHPUnit\Framework\TestCase;

final class SalaryArrearMeetingMethodTest extends TestCase
{
    public function test_approved_meeting_formulas_use_three_reference_months_without_september_bill(): void
    {
        $map = collect(SalaryArrearTemplateCatalog::definitions())->pluck('formula','code');
        $inputs = array_fill(0, 3, [
            'JUNE_BASIC' => 18530, 'REVISED_BASIC' => 28715,
            'JULY_BASIC' => 19460, 'OLD_SCALE_INCREMENT' => 930, 'PAID_SPECIAL_BENEFIT' => 2919,
            'JUNE_HOUSE_RENT' => 8757, 'OCTOBER_HOUSE_RENT' => 8757,
            'JULY_GPF' => 1947, 'JUNE_GPF' => 1947, 'OCTOBER_GPF' => 2872,
            'OCTOBER_BASIC' => 28715, 'REVISED_BASIC' => 28715,
        ]);
        $engine = new SalaryArrearFormulaEngine();
        $payable = $engine->evaluate($map['BASIC_ARREAR_GROSS'], [], $inputs);
        $increment = $engine->evaluate($map['BASIC_INCREMENT_ADJUSTMENT'], [], $inputs);
        $benefit = $engine->evaluate($map['SPECIAL_BENEFIT_RECOVERY'], [], $inputs);
        $house = $engine->evaluate($map['HOUSE_RENT_RECOVERY'], [], $inputs);
        $gpf = $engine->evaluate($map['ADDITIONAL_GPF'], [], $inputs);
        self::assertSame(30555.0, $payable);
        self::assertSame(2790.0, $increment);
        self::assertSame(8757.0, $benefit);
        self::assertSame(0.0, $house);
        self::assertSame(2775.0, $gpf);
        self::assertSame(16233.0, $payable - $increment - $benefit - $house - $gpf);
        self::assertStringContainsString('REVISED_BASIC', $map['BASIC_ARREAR_GROSS']);
        self::assertStringContainsString('JUNE_GPF', $map['ADDITIONAL_GPF']);
        self::assertStringContainsString('OLD_SCALE_INCREMENT', $map['BASIC_INCREMENT_ADJUSTMENT']);
    }

    public function test_documented_no_increment_evaluates_to_zero_without_a_july_basic(): void
    {
        $formula = collect(SalaryArrearTemplateCatalog::definitions())
            ->firstWhere('code', 'BASIC_INCREMENT_ADJUSTMENT')['formula'];
        $inputs = array_fill(0, 3, ['OLD_SCALE_INCREMENT' => 0]);
        self::assertSame(0.0, (new SalaryArrearFormulaEngine())->evaluate($formula, [], $inputs));
    }
}
