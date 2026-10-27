<?php

namespace Tests\Unit;

use App\Services\ApprovedFixationResolver;
use App\Services\SalaryBillCompositionService;
use PHPUnit\Framework\TestCase;

/** October 2026 regular-only preview: pure logic tests (no database). */
final class SalaryRegularOnlyPreviewTest extends TestCase
{
    private function fixation(string $effective): array
    {
        return ['source' => 'approved_fixation', 'pay_scale' => 'Govt. Pay Scale 2026',
            'grade' => 'Grade 9', 'grade_number' => 9, 'basic' => 28715.0,
            'effective_date' => $effective, 'salary_fixation_id' => 1, 'pay_schedule_id' => 1];
    }

    /** An arrear-phase fixation never activates regular 2026-scale pay. */
    public function test_arrear_phase_fixation_does_not_authorize_regular_pay(): void
    {
        $this->assertNull(ApprovedFixationResolver::authorizesRegularPhase(null));
        $this->assertNull(ApprovedFixationResolver::authorizesRegularPhase($this->fixation('2026-07-01')));
        $this->assertNull(ApprovedFixationResolver::authorizesRegularPhase($this->fixation('2027-01-01')));
    }

    public function test_regular_activation_phase_authorizes_regular_pay(): void
    {
        $fix = $this->fixation('2027-07-01');
        $this->assertSame($fix, ApprovedFixationResolver::authorizesRegularPhase($fix));
    }

    public function test_missing_evidence_keeps_arrear_not_ready_without_zero(): void
    {
        [$ready, $reasons] = SalaryBillCompositionService::evaluateArrearReadiness([
            ['title' => 'Basic Arrear', 'calculated_amount' => 30555.0],
            ['title' => 'House Rent Difference', 'calculated_amount' => null],
        ]);
        $this->assertFalse($ready);
        $this->assertSame(['Arrear source unverified: House Rent Difference'], $reasons);
    }

    public function test_verified_arrear_rows_are_ready(): void
    {
        [$ready, $reasons] = SalaryBillCompositionService::evaluateArrearReadiness([
            ['title' => 'A', 'calculated_amount' => 1.0], ['title' => 'B', 'calculated_amount' => 2.0],
        ]);
        $this->assertTrue($ready);
        $this->assertSame([], $reasons);
    }

    public function test_invalid_period_returns_blocked_row_with_null_amounts(): void
    {
        $out = app(SalaryBillCompositionService::class)->composeRegularOnly(999999, 2026, 13);
        $this->assertSame('BLOCKED', $out['status']);
        $this->assertNull($out['regular_gross']);
        $this->assertNull($out['regular_net']);
        $this->assertNull($out['pay_scale_source']);
        $this->assertNull($out['calculation_source_hash']);
        $this->assertNull($out['arrear_readiness']);
        $this->assertNotSame([], $out['blocking_reasons']);
    }
}
