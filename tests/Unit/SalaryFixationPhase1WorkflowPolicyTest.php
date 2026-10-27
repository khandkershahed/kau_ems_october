<?php
namespace Tests\Unit;
use App\Services\SalaryFixationApprovalPolicy;
use PHPUnit\Framework\TestCase;

final class SalaryFixationPhase1WorkflowPolicyTest extends TestCase
{
    public function test_calculable_fixation_does_not_require_employee_confirmation_or_pdf_match(): void
    {
        self::assertTrue(SalaryFixationApprovalPolicy::isCalculable([
            'grade' => 10, 'ready' => false, 'blockers' => ['Original PDF not supplied'],
            'calculation' => ['phase_2026_07' => 28715, 'phase_2027_01' => 30200, 'phase_2027_07' => 35500],
        ]));
    }

    public function test_three_effective_dates_are_preserved_for_snapshot(): void
    {
        self::assertSame(['2026-07-01','2027-01-01','2027-07-01'],
            array_keys(SalaryFixationApprovalPolicy::EFFECTIVE_PHASES));
    }
}
