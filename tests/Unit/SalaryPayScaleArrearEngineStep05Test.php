<?php

namespace Tests\Unit;

use App\Services\SalaryPayScaleArrearEngine;
use App\Services\SalaryArrearCalculator;
use App\Support\SalaryArrearTemplateCatalog;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SalaryPayScaleArrearEngineStep05Test extends TestCase
{
    private static function fixture(): array
    {
        return array_fill_keys(SalaryPayScaleArrearEngine::MONTHS, [
            'june_basic' => 18530, 'old_scale_basic' => 19460,
            'entitled_basic' => 28715, 'paid_special_benefit' => 2919,
            'paid_house_rent' => 8757, 'entitled_house_rent' => 8757,
            'paid_gpf' => 1947, 'required_gpf' => 2872,
        ]);
    }

    public function test_three_month_old_increment_is_only_deducted_once(): void
    {
        $v = SalaryPayScaleArrearEngine::reconcile(self::fixture());
        self::assertSame(30555.0, $v['totals']['basic_gross']);
        self::assertSame(2790.0, $v['totals']['old_increment']);
        self::assertSame(27765.0, $v['totals']['basic_net']);
        self::assertSame(8757.0, $v['totals']['special_recovery']);
        self::assertSame(2775.0, $v['totals']['additional_gpf']);
        self::assertSame(16233.0, $v['net_arrear']);
        self::assertSame('ESTIMATED_RECONCILIATION', $v['status']);
    }

    public function test_monthwise_changes_are_not_flat_three_times(): void
    {
        $m = self::fixture();
        $m['2026-08']['entitled_basic'] = 30000;
        $m['2026-09']['old_scale_basic'] = 20000;
        $v = SalaryArrearCalculator::reconcileScaleMonths($m);
        self::assertSame(10540.0, $v['months']['2026-08']['basic_net']);
        self::assertSame(8715.0, $v['months']['2026-09']['basic_net']);
        self::assertSame(28510.0, $v['totals']['basic_net']);
    }

    public function test_missing_special_payment_remains_unknown_without_zero_recovery(): void
    {
        $m=self::fixture(); unset($m['2026-09']['paid_special_benefit']);
        $v=SalaryPayScaleArrearEngine::reconcile($m);
        self::assertNull($v['months']['2026-09']['special_recovery']);
        self::assertNull($v['net_arrear']);
        self::assertSame('PROVISIONAL_MISSING_PAYMENT_EVIDENCE', $v['status']);
        self::assertContains('2026-09:paid_special_benefit', $v['missing_evidence']);
    }

    public function test_zero_is_distinct_from_missing_evidence(): void
    {
        $m=self::fixture(); $m['2026-09']['paid_special_benefit']=0;
        $v=SalaryPayScaleArrearEngine::reconcile($m);
        self::assertSame(0.0,$v['months']['2026-09']['special_recovery']);
        self::assertSame(16233+2919.0,$v['net_arrear']);
    }

    public function test_positive_house_rent_is_payable_and_negative_is_recovered(): void
    {
        self::assertSame(['direction'=>'payable','amount'=>1255.5], SalaryPayScaleArrearEngine::directionalHouseRent(1255.5));
        self::assertSame(['direction'=>'deduction','amount'=>1255.5], SalaryPayScaleArrearEngine::directionalHouseRent(-1255.5));
        $m=self::fixture(); $m['2026-07']['entitled_house_rent']=8500;
        self::assertSame(-257.0,SalaryPayScaleArrearEngine::reconcile($m)['months']['2026-07']['house_adjustment']);
    }

    public function test_hold_and_adhoc_have_no_first_increment(): void
    {
        $stages=[18530,19460,20440];
        foreach ([['hold_increment'=>true],['special_eligibility_category'=>'adhoc'],
                  ['special_eligibility_category'=>'study_leave_restricted']] as $state) {
            $r=SalaryPayScaleArrearEngine::oldScaleIncrement($stages,18530,$state);
            self::assertSame(0.0,$r['amount']);
        }
    }

    public function test_study_leave_allowed_increment_is_reconstructed_not_paid(): void
    {
        $r=SalaryPayScaleArrearEngine::oldScaleIncrement([18530,19460,20440],18530,
            ['special_eligibility_category'=>'study_leave_exception','first_increment_exception'=>true]);
        self::assertSame(930.0,$r['amount']);
        self::assertFalse($r['payment_verified']);
        self::assertSame('RECONSTRUCTED_2015_STAGE_NOT_PAYMENT',$r['status']);
    }

    public function test_missing_stage_never_invents_increment(): void
    {
        self::assertNull(SalaryPayScaleArrearEngine::oldScaleIncrement([18000,18900],18530,[])['amount']);
    }

    public function test_phased_rates_and_dates_are_grade_specific(): void
    {
        self::assertSame(40,SalaryPayScaleArrearEngine::phasedReference(6,'2026-07',52480,90700)['rate']);
        self::assertSame(50,SalaryPayScaleArrearEngine::phasedReference(10,'2026-10',18530,38900)['rate']);
        self::assertSame(70,SalaryPayScaleArrearEngine::phasedReference(6,'2027-01',52480,90700)['rate']);
        self::assertSame(75,SalaryPayScaleArrearEngine::phasedReference(10,'2027-01',18530,38900)['rate']);
        self::assertSame(100,SalaryPayScaleArrearEngine::phasedReference(6,'2027-07',52480,90700)['rate']);
    }

    public function test_three_month_period_is_exact_and_source_verification_explicit(): void
    {
        $months=self::fixture();
        foreach ($months as &$m) {
            $m['paid_basic_verified']=true;
            $m['paid_benefits_verified']=true;
            $m['paid_house_rent_verified']=true;
            $m['paid_gpf_verified']=true;
        }
        unset($m);
        self::assertTrue(SalaryPayScaleArrearEngine::reconcile($months)['payment_reconciliation_complete']);
        unset($months['2026-09']);
        $this->expectException(DomainException::class);
        SalaryPayScaleArrearEngine::reconcile($months);
    }

    public function test_new_published_templates_never_double_months(): void
    {
        $defs=SalaryArrearTemplateCatalog::definitions();
        foreach($defs as $row) {
            self::assertStringNotContainsString('JUNE_INCREMENT',$row['formula']);
            self::assertDoesNotMatchRegularExpression('/\)\s*\*\s*3\s*$/', $row['formula']);
        }
        self::assertSame('SUM_MONTHS(REVISED_BASIC - JUNE_BASIC)', $defs[0]['formula']);
    }
}
