<?php

namespace Tests\Unit;

use App\Support\SalaryArrearRuleView;
use PHPUnit\Framework\TestCase;

final class SalaryArrearRuleViewPhase6bTest extends TestCase
{
    public function test_mysql_integer_flag_is_real_boolean_for_vue_checkbox(): void
    {
        $checked=SalaryArrearRuleView::normalize((object) ['id'=>5,'published'=>1,'recurring'=>0,'revision'=>'4']);
        $this->assertSame(true,$checked['published']);
        $this->assertSame(false,$checked['recurring']);
        $this->assertSame(4,$checked['revision']);
        $this->assertSame(false,SalaryArrearRuleView::normalize(['published'=>0])['published']);
    }

    public function test_publishing_new_rule_invalidates_old_draft_but_not_manual_rows(): void
    {
        $saved=[['rule_id'=>1,'title'=>'Basic Arrear','direction'=>'payable','mode'=>'formula','formula'=>'A-B','months'=>['2026-07'],'calculated_amount'=>100,'error'=>null],
            ['rule_id'=>null,'title'=>'Manual allowance','direction'=>'payable','final_amount'=>50]];
        $live=[['rule_id'=>1,'title'=>'Basic Arrear','direction'=>'payable','mode'=>'formula','formula'=>'A-B','months'=>['2026-07'],'calculated_amount'=>100,'error'=>null]];
        $this->assertFalse(SalaryArrearRuleView::hasPublishedChanges($saved,$live));
        $live[]=['rule_id'=>2,'title'=>'Recovery','direction'=>'deduction','mode'=>'formula','formula'=>'C','months'=>['2026-07'],'calculated_amount'=>null,'error'=>'Missing paid history'];
        $this->assertTrue(SalaryArrearRuleView::hasPublishedChanges($saved,$live));
        $saved[]=$live[1];
        $this->assertFalse(SalaryArrearRuleView::hasPublishedChanges($saved,$live));
    }

    public function test_changed_paid_source_or_formula_requires_new_calculation_version(): void
    {
        $source=['rule_id'=>1,'title'=>'Basic','direction'=>'payable','mode'=>'formula','formula'=>'REVISED_BASIC-JUNE_BASIC','months'=>['2026-07'],'calculated_amount'=>10185,'error'=>null];
        $this->assertFalse(SalaryArrearRuleView::hasPublishedChanges([$source],[$source]));
        $modified=$source; $modified['calculated_amount']=10186;
        $this->assertTrue(SalaryArrearRuleView::hasPublishedChanges([$source],[$modified]));
        $modified=$source; $modified['formula']='REVISED_BASIC-PAID_BASIC';
        $this->assertTrue(SalaryArrearRuleView::hasPublishedChanges([$source],[$modified]));
    }
    public function test_editing_display_title_does_not_change_canonical_arrear_identity(): void
    {
        $original=['rule_id'=>15,'title'=>'Basic Arrear', 'direction'=>'payable',
            'mode'=>'formula','formula'=>'REVISED_BASIC-OLD_BASIC',
            'months'=>['2026-07','2026-08','2026-09'],
            'calculated_amount'=>27765,'error'=>null];
        $renamed=$original;
        $renamed['title']='Revised basic differential (display label)';
        self::assertFalse(SalaryArrearRuleView::hasPublishedChanges([$original],[$renamed]));
        $renamed['formula']='REVISED_BASIC-PAID_BASIC';
        self::assertTrue(SalaryArrearRuleView::hasPublishedChanges([$original],[$renamed]));
    }

}
