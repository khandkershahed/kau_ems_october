<?php

namespace Tests\Unit;

use App\Services\SalaryFixationApprovalPolicy;
use PHPUnit\Framework\TestCase;

class SalaryFixationApprovalPolicyTest extends TestCase
{
    private function prepared(): array
    {
        return ['grade' => 10, 'calculation' => [
            'phase_2026_07' => 25600, 'phase_2027_01' => 29600, 'phase_2027_07' => 35300,
        ], 'ready' => false, 'blockers' => ['Original PDF not supplied']];
    }

    public function test_a_valid_calculation_can_be_approved_despite_nonfinancial_review_warnings(): void
    {
        self::assertTrue(SalaryFixationApprovalPolicy::isCalculable($this->prepared()));
    }

    public function test_missing_basic_or_grade_is_never_fabricated(): void
    {
        $data = $this->prepared();
        unset($data['calculation']['phase_2027_07']);
        self::assertFalse(SalaryFixationApprovalPolicy::isCalculable($data));
        $data = $this->prepared();
        $data['grade'] = null;
        self::assertFalse(SalaryFixationApprovalPolicy::isCalculable($data));
        $data = $this->prepared();
        $data['calculation']['phase_2026_07'] = 0;
        self::assertFalse(SalaryFixationApprovalPolicy::isCalculable($data));
    }

    public function test_hr_can_approve_before_employee_review_or_after_dispute(): void
    {
        foreach (['pending_review', 'sent', 'employee_confirmed', 'employee_disputed', 'approved'] as $status) {
            self::assertTrue(SalaryFixationApprovalPolicy::mayApproveStatus($status), $status);
        }
        self::assertFalse(SalaryFixationApprovalPolicy::mayApproveStatus('superseded'));
    }

    public function test_stale_snapshot_is_rejected_but_same_amount_is_accepted(): void
    {
        $saved = [
            'fitment_basic' => 32000, 'increment_2026' => 1600, 'increment_2027' => 1700,
            'phase_2026_07' => 25600, 'phase_2027_01' => 29600, 'phase_2027_07' => 35300,
        ];
        self::assertTrue(SalaryFixationApprovalPolicy::sameCalculation($saved, $saved));
        $fresh = $saved;
        $fresh['phase_2027_07'] += 1;
        self::assertFalse(SalaryFixationApprovalPolicy::sameCalculation($saved, $fresh));
    }

    public function test_regular_activation_is_not_redefined_by_hr_approval(): void
    {
        self::assertSame(['2026-07-01','2027-01-01','2027-07-01'],
            array_keys(SalaryFixationApprovalPolicy::EFFECTIVE_PHASES));
    }
}
