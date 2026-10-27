<?php

namespace Tests\Unit;

use App\Support\SalaryArrearRuleReadiness;
use PHPUnit\Framework\TestCase;

final class SalaryArrearRuleReadinessStep08Test extends TestCase
{
    private function rule(array $values = []): array
    {
        return array_replace(['code'=>'BASIC_ARREAR_GROSS',
            'formula'=>'SUM_MONTHS(REVISED_BASIC - JUNE_BASIC)',
            'selected_months'=>['2026-07','2026-08','2026-09'],
            'mode'=>'formula','published'=>1,'archived'=>0],$values);
    }
    public function test_verified_reference_template_is_not_flagged(): void
    {
        $this->assertSame([],SalaryArrearRuleReadiness::inspect($this->rule())['issues']);
    }
    public function test_legacy_october_rule_is_flagged_without_editing_it(): void
    {
        $result=SalaryArrearRuleReadiness::inspect($this->rule([
            'formula'=>'SUM_MONTHS(OCTOBER_BASIC - JUNE_BASIC) * 3',
            'selected_months'=>'[]']));
        $this->assertContains('DOUBLE_MONTH_MULTIPLIER',$result['issues']);
        $this->assertContains('WRONG_ARREAR_PERIOD',$result['issues']);
    }
    public function test_independent_formula_edit_requires_review(): void
    {
        $result=SalaryArrearRuleReadiness::inspect($this->rule([
            'code'=>'BASIC_INCREMENT_ADJUSTMENT',
            'formula'=>'SUM_MONTHS(JULY_INCREMENT)']));
        $this->assertContains('FORMULA_DIFFERS_FROM_REFERENCE',$result['issues']);
    }
}
