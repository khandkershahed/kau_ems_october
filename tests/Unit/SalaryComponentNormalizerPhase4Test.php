<?php

namespace Tests\Unit;

use App\Services\SalaryComponentNormalizer;
use App\Services\SalaryCalculationService;
use App\Services\ApprovedFixationResolver;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SalaryComponentNormalizerPhase4Test extends TestCase
{
    public function test_double_encoded_employee_components_are_readable(): void
    {
        $rows = [['name'=>'Basic Pay','amount'=>19460,'calcType'=>'fixed'],
            ['name'=>'House Rent Allowance','amount'=>8757,'calcType'=>'fixed']];
        self::assertSame(19460.0,SalaryComponentNormalizer::rows(json_encode(json_encode($rows)))[0]['amount']);
    }
    public function test_approved_fixation_replaces_basic_without_changing_legacy_input(): void
    {
        $legacy=[['name'=>'Basic Pay','calcType'=>'fixed','amount'=>19460],
            ['name'=>'Medical Allowance','calcType'=>'fixed','amount'=>1500]];
        $fixed=ApprovedFixationResolver::interpret(['effective_date'=>'2026-07-01','grade_number'=>10,
            'payable_basic'=>28715,'salary_fixation_id'=>1]);
        $output=(new SalaryCalculationService())->calculate($legacy, [], $fixed, ['type'=>'disabled','amount'=>0]);
        self::assertSame(28715.0,$output['basic']);
        self::assertSame(30215.0,$output['total_allowance']);
        self::assertSame(0.0,$output['gpf_deduction']);
        self::assertSame(19460,$legacy[0]['amount']);
    }
    public function test_component_malformed_json_cannot_silently_become_zero(): void
    {
        $this->expectException(DomainException::class);
        SalaryComponentNormalizer::rows('[broken');
    }
    public function test_duplicate_basics_still_rejected(): void
    {
        $this->expectException(DomainException::class);
        (new SalaryCalculationService())->calculate([
            ['name'=>'Basic Pay','amount'=>19460,'calcType'=>'fixed'],
            ['name'=>'Basic Salary','amount'=>20000,'calcType'=>'fixed']],[]);
    }
}
