<?php

namespace Tests\Unit;

use App\Services\SalaryStructuredArrearEvidenceOverlay;
use App\Services\SalaryStructuredArrearService;
use PHPUnit\Framework\TestCase;

final class SalaryStructuredArrearEvidenceOverlayStep12Test extends TestCase
{
    private function base(): array
    {
        return [
            'june_basic' => 18530, 'old_increment' => 930,
            'revised_basics' => array_fill_keys(['2026-07', '2026-08', '2026-09'], 28715),
            'paid_months' => [
                '2026-07' => ['paid_special_benefit' => 500, 'paid_house_rent' => 8500, 'paid_gpf' => 1853],
                '2026-08' => ['paid_special_benefit' => 500, 'paid_house_rent' => 8500, 'paid_gpf' => 1853],
            ],
            'financial_months' => [],
        ];
    }

    private function row(int $month, string $field, mixed $amount, int $certified = 1): array
    {
        return ['id' => $month * 10 + strlen($field), 'year' => 2026, 'month' => $month,
            'field' => $field, 'amount' => $amount, 'certified' => $certified,
            'evidence_reference' => 'HR-verified-document-2026'];
    }

    public function test_certified_monthly_entitlement_and_payment_sources_complete_amounts(): void
    {
        $rows = [];
        foreach ([7,8,9] as $month) {
            $rows[] = $this->row($month, 'revised_house_rent', 8500);
            $rows[] = $this->row($month, 'revised_gpf', 2872);
        }
        foreach (['paid_special_benefit'=>500,'paid_house_rent'=>8500,'paid_gpf'=>1853] as $field=>$amount) {
            $rows[] = $this->row(9, $field, $amount);
        }
        $evidence = SalaryStructuredArrearEvidenceOverlay::apply($this->base(), $rows);
        $r = SalaryStructuredArrearService::compute($evidence);
        self::assertSame(1500.0, $r['SPECIAL_BENEFIT_RECOVERY']['amount']);
        self::assertSame(0.0, $r['HOUSE_RENT_RECOVERY']['amount']);
        self::assertSame(3057.0, $r['ADDITIONAL_GPF']['amount']);
        self::assertCount(3, $evidence['certified_source_references']);
    }

    public function test_uncertified_september_does_not_become_a_zero_payment(): void
    {
        $evidence = SalaryStructuredArrearEvidenceOverlay::apply($this->base(), [
            $this->row(9, 'paid_special_benefit', 0, 0),
        ]);
        self::assertNull(SalaryStructuredArrearService::compute($evidence)['SPECIAL_BENEFIT_RECOVERY']['amount']);
        self::assertArrayNotHasKey('2026-09', $evidence['paid_months']);
    }

    public function test_conflicting_approved_payment_fails_closed(): void
    {
        $evidence = SalaryStructuredArrearEvidenceOverlay::apply($this->base(), [
            $this->row(7, 'paid_house_rent', 9999),
        ]);
        self::assertContains('2026-07:paid_house_rent:APPROVED_HISTORY_MISMATCH', $evidence['source_conflicts']);
        self::assertArrayNotHasKey('paid_house_rent', $evidence['paid_months']['2026-07']);
    }

    public function test_duplicate_cross_period_certifications_fail_closed(): void
    {
        $row = $this->row(7, 'revised_gpf', 2800);
        $evidence = SalaryStructuredArrearEvidenceOverlay::apply($this->base(), [$row, $row]);
        self::assertNotEmpty($evidence['source_conflicts']);
        self::assertArrayNotHasKey('required_gpf', $evidence['financial_months']['2026-07']);
    }
}
