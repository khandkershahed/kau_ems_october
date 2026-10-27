<?php

namespace Tests\Unit;

use App\Services\ApprovedFixationComponentProjector;
use App\Services\ApprovedFixationResolver;
use App\Services\PayrollPreviewCalculator;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ApprovedFixationPhase2Test extends TestCase
{
    private function fixation(float $basic, int $grade = 10): array
    {
        return ApprovedFixationResolver::interpret([
            'id' => 7, 'salary_fixation_id' => 41, 'effective_date' => '2026-07-01',
            'grade_number' => $grade, 'payable_basic' => $basic,
        ]);
    }

    private function allowances(): array
    {
        return [
            ['name' => 'Basic Pay', 'calcType' => 'fixed', 'amount' => 18530],
            ['name' => 'House Rent', 'calcType' => 'percentage', 'amount' => 40],
            ['name' => 'Medical Allowance', 'calcType' => 'fixed', 'amount' => 1500],
            ['name' => 'Special Benefit', 'calcType' => 'fixed', 'amount' => 2919],
        ];
    }

    public function test_phase_specific_approved_basic_is_validated(): void
    {
        $this->assertSame('2026-10-01', ApprovedFixationResolver::cutoff(2026, 10));
        $this->assertSame('2027-01-01', ApprovedFixationResolver::cutoff(2027, 1));
        $this->assertSame(28715.0, $this->fixation(28715)['basic']);
        $this->assertSame('Grade 10', $this->fixation(28715)['grade']);
    }

    public function test_percentage_uses_revised_basic_but_fixed_rates_remain(): void
    {
        $projected = ApprovedFixationComponentProjector::project(
            $this->allowances(), [['name' => 'GPF', 'calcType' => 'percentage', 'amount' => 10]], $this->fixation(28715));
        $result = PayrollPreviewCalculator::calculate($projected['additions'], $projected['deductions']);
        $this->assertEqualsWithDelta(28715, $result['basic'], .001);
        $this->assertEqualsWithDelta(11486, $result['allowances'][1]['calculated_amount'], .001);
        $this->assertEqualsWithDelta(1500, $result['allowances'][2]['calculated_amount'], .001);
        $this->assertCount(3, $result['allowances']); // No revised-scale Special Benefit.
        $this->assertEqualsWithDelta(2871.50, $result['gpf_deduction'], .001); // Phase 3 rounds HALF UP to 2872.
    }

    public function test_projection_does_not_mutate_legacy_component_values(): void
    {
        $source = $this->allowances();
        ApprovedFixationComponentProjector::project($source, [], $this->fixation(28715));
        $this->assertSame(18530, $source[0]['amount']);
        $this->assertSame(2919, $source[3]['amount']);
    }

    public function test_grade_override_is_independent_of_old_profile_grade(): void
    {
        $fixation = $this->fixation(24400, 20);
        $this->assertSame(20, $fixation['grade_number']);
        $this->assertSame(24400.0, $fixation['basic']);
    }

    public function test_missing_legacy_components_use_only_verified_approved_basic(): void
    {
        // Phase 4 accepts a valid approved fixation as the authority for Basic.
        // It MUST NOT invent missing House Rent, Medical, Special Benefit or GPF.
        $projected = ApprovedFixationComponentProjector::project([], [], $this->fixation(28715));
        $this->assertSame('approved_fixation', $projected['source']);
        $this->assertCount(1, $projected['additions']);
        $this->assertSame('Basic Pay', $projected['additions'][0]['name']);
        $this->assertEqualsWithDelta(28715.0, $projected['additions'][0]['amount'], .001);
        $this->assertSame([], $projected['deductions']);
        $this->assertSame(41, $projected['additions'][0]['approved_fixation_id']);
        $result = PayrollPreviewCalculator::calculate($projected['additions'], $projected['deductions']);
        $this->assertEqualsWithDelta(28715.0, $result['basic'], .001);
        $this->assertCount(1, $result['allowances']);
    }

    public function test_two_basics_are_rejected(): void
    {
        $this->expectException(DomainException::class);
        ApprovedFixationComponentProjector::project([
            ['name' => 'Basic Pay', 'amount' => 100, 'calcType' => 'fixed'],
            ['name' => 'Basic Salary', 'amount' => 200, 'calcType' => 'fixed'],
        ], [], $this->fixation(28715));
    }

    public function test_invalid_or_unapproved_stage_cannot_be_interpreted(): void
    {
        $this->expectException(DomainException::class);
        ApprovedFixationResolver::interpret([
            'id' => 12, 'salary_fixation_id' => 45,
            'effective_date' => '2026-05-01', 'payable_basic' => 30000, 'grade_number' => 10,
        ]);
    }
}
