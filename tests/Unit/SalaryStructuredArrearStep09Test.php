<?php

namespace Tests\Unit;

use App\Services\SalaryStructuredArrearService;
use PHPUnit\Framework\TestCase;

final class SalaryStructuredArrearStep09Test extends TestCase
{
    private function source(): array
    {
        return [
            'june_basic' => 52480, 'old_increment' => 2630,
            'revised_basics' => ['2026-07'=>72268,'2026-08'=>72268,'2026-09'=>72268],
            'increment_status'=>'RECONSTRUCTED_2015_STAGE_NOT_PAYMENT',
            'paid_months' => [],
        ];
    }

    public function test_basic_entitlement_does_not_require_september_salary_bill(): void
    {
        $rows = SalaryStructuredArrearService::compute($this->source());
        self::assertSame(59364.0, $rows['BASIC_ARREAR_GROSS']['amount']);
        self::assertSame(7890.0, $rows['BASIC_INCREMENT_ADJUSTMENT']['amount']);
        self::assertNull($rows['SPECIAL_BENEFIT_RECOVERY']['amount']);
        self::assertNull($rows['HOUSE_RENT_RECOVERY']['amount']);
        self::assertNull($rows['ADDITIONAL_GPF']['amount']);
    }

    public function test_month_specific_entitlement_not_flat_three_times(): void
    {
        $source = $this->source();
        $source['revised_basics']['2026-08'] = 73000;
        self::assertSame(60096.0, SalaryStructuredArrearService::compute($source)['BASIC_ARREAR_GROSS']['amount']);
    }

    public function test_negative_house_recovery_becomes_payable(): void
    {
        $source = $this->source();
        foreach (['2026-07','2026-08','2026-09'] as $month) {
            $source['paid_months'][$month] = ['paid_house_rent'=>1000,'paid_special_benefit'=>0,'paid_gpf'=>100];
            $source['financial_months'][$month] = ['entitled_house_rent'=>1100,'required_gpf'=>150];
        }
        $rows = SalaryStructuredArrearService::compute($source);
        self::assertSame('payable', $rows['HOUSE_RENT_RECOVERY']['direction']);
        self::assertSame(300.0, $rows['HOUSE_RENT_RECOVERY']['amount']);
        self::assertSame(150.0, $rows['ADDITIONAL_GPF']['amount']);
        self::assertSame(0.0, $rows['SPECIAL_BENEFIT_RECOVERY']['amount']);
    }

    public function test_unverified_zero_is_not_invented_and_negative_gpf_not_refunded(): void
    {
        $source = $this->source();
        $source['paid_months'] = ['2026-07' => ['paid_gpf'=>200]];
        $source['financial_months'] = ['2026-07'=>['required_gpf'=>100]];
        $rows = SalaryStructuredArrearService::compute($source);
        self::assertNull($rows['ADDITIONAL_GPF']['amount']);
        self::assertNull($rows['SPECIAL_BENEFIT_RECOVERY']['amount']);
    }

    public function test_only_october_2026_uses_special_structured_period(): void
    {
        self::assertTrue(SalaryStructuredArrearService::applicable(2026,10));
        self::assertFalse(SalaryStructuredArrearService::applicable(2026,9));
        self::assertFalse(SalaryStructuredArrearService::applicable(2027,10));
    }
}
