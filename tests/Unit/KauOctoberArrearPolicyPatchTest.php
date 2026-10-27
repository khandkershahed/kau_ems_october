<?php

namespace Tests\Unit;

use App\Services\SalaryArrearFormulaEngine;
use App\Support\SalaryArrearTemplateCatalog;
use PHPUnit\Framework\TestCase;

final class KauOctoberArrearPolicyPatchTest extends TestCase
{
    public function test_exact_one_addition_four_deductions_without_double_multiplier(): void
    {
        $rules = SalaryArrearTemplateCatalog::definitions();
        $this->assertCount(5, $rules);
        $this->assertSame(['payable','deduction','deduction','deduction','deduction'],
            array_column($rules, 'direction'));
        foreach ($rules as $rule) {
            $this->assertStringStartsWith('SUM_MONTHS(', $rule['formula']);
            $this->assertStringNotContainsString('JUNE_INCREMENT', $rule['formula']);
            $this->assertDoesNotMatchRegularExpression('/\)\s*\*\s*3\s*$/', $rule['formula']);
        }
    }

    public function test_three_month_arrear_formula_with_verified_inputs(): void
    {
        $formula = new SalaryArrearFormulaEngine();
        $months = array_fill(0,3,[
            'OCTOBER_BASIC'=>28715, 'REVISED_BASIC'=>28715, 'JUNE_BASIC'=>18530,
            'OLD_SCALE_INCREMENT'=>930, 'PAID_SPECIAL_BENEFIT'=>2919,
            'OCTOBER_HOUSE_RENT'=>10012.5, 'JUNE_HOUSE_RENT'=>8757,
            'OCTOBER_GPF'=>4631, 'JUNE_GPF'=>1853,
        ]);
        $rules = array_column(SalaryArrearTemplateCatalog::definitions(),'formula','code');
        $amounts = [];
        foreach ($rules as $code => $expression) {
            $amounts[$code] = $formula->evaluate($expression,$months[0],$months);
        }
        $this->assertSame(30555.0,$amounts['BASIC_ARREAR_GROSS']);
        $this->assertSame(2790.0,$amounts['BASIC_INCREMENT_ADJUSTMENT']);
        $this->assertSame(8757.0,$amounts['SPECIAL_BENEFIT_RECOVERY']);
        $this->assertSame(3766.5,$amounts['HOUSE_RENT_RECOVERY']);
        $this->assertSame(8334.0,$amounts['ADDITIONAL_GPF']);
        $this->assertSame(6907.5,round($amounts['BASIC_ARREAR_GROSS'] - array_sum(array_slice(array_values($amounts),1)),2));
    }

    public function test_missing_history_fails_instead_of_verified_zero(): void
    {
        $this->expectException(\DomainException::class);
        (new SalaryArrearFormulaEngine())->evaluate('SUM_MONTHS(OCTOBER_GPF - JUNE_GPF)',
            ['OCTOBER_GPF'=>0], [['OCTOBER_GPF'=>0]]);
    }
}
