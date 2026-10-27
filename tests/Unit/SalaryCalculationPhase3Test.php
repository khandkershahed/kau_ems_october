<?php

namespace Tests\Unit;

use App\Services\SalaryCalculationService;
use App\Services\ApprovedFixationResolver;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SalaryCalculationPhase3Test extends TestCase
{
    private function engine(): SalaryCalculationService { return new SalaryCalculationService(); }
    private function additions(): array { return [
        ['name'=>'Basic Pay','amount'=>19460,'calcType'=>'fixed'],
        ['name'=>'House Rent','amount'=>8757,'calcType'=>'fixed'],
        ['name'=>'Medical Allowance','amount'=>1500,'calcType'=>'fixed'],
        ['name'=>'Special Benefit','amount'=>2919,'calcType'=>'fixed'],
        ['name'=>'Education Allowance','amount'=>500,'calcType'=>'fixed'],
    ]; }
    private function fixation(): array { return ApprovedFixationResolver::interpret([
        'id'=>4,'salary_fixation_id'=>21,'effective_date'=>'2026-07-01',
        'grade_number'=>10,'payable_basic'=>28715,
    ]); }
    public function test_default_gpf_half_up_from_28715(): void
    {
        $a=$this->engine()->calculate($this->additions(), [], $this->fixation());
        $this->assertSame(28715.0, $a['basic']);
        $this->assertSame(2872.0, $a['gpf_deduction']);
        $this->assertSame('default_10_percent_half_up',$a['gpf_rule']['source']);
        $this->assertSame('approved_fixation',$a['calculation_source']);
    }
    public function test_24400_basic_gpf_2440(): void
    {
        $a=$this->engine()->calculate([['name'=>'Basic Pay','calcType'=>'fixed','amount'=>24400]],[]);
        $this->assertSame(2440.0,$a['gpf_deduction']);
    }
    public function test_existing_fixed_gpf_replaced_by_single_default_row(): void
    {
        $a=$this->engine()->calculate($this->additions(), [['name'=>'General Provident Fund','calcType'=>'fixed','amount'=>1946]]);
        $this->assertSame(1946.0,$a['gpf_deduction']);
        $this->assertCount(1,$a['deductions']);
    }
    public function test_legacy_employee_specific_gpf_subscription_is_preserved(): void
    {
        $a=$this->engine()->calculate($this->additions(), [['name'=>'GPF','calcType'=>'fixed','amount'=>3200]]);
        $this->assertSame(3200.0,$a['gpf_deduction']);
        $this->assertSame('existing_employee_subscription',$a['gpf_rule']['source']);
    }
    public function test_fixed_override_changes_gpf_without_ledger_posting(): void
    {
        $a=$this->engine()->calculate($this->additions(),[],null,['type'=>'fixed','amount'=>2200]);
        $this->assertSame(2200.0,$a['gpf_deduction']);
    }
    public function test_percentage_override_half_up(): void
    {
        $a=$this->engine()->calculate($this->additions(),[],null,['type'=>'percentage','amount'=>7.5]);
        $this->assertSame(1460.0,$a['gpf_deduction']);
    }
    public function test_revised_basic_recalculates_percentage_allowances(): void
    {
        $a=$this->engine()->calculate([
            ['name'=>'Basic Pay','calcType'=>'fixed','amount'=>19460],
            ['name'=>'House Rent','calcType'=>'percentage','amount'=>40],
            ['name'=>'Special Benefit','calcType'=>'fixed','amount'=>2919],
        ], [], $this->fixation());
        $this->assertEqualsWithDelta(11486,$a['allowances'][1]['calculated_amount'],0.001);
        $this->assertCount(2,$a['allowances']);
    }
    public function test_missing_basic_is_not_payable(): void
    {
        $this->expectException(DomainException::class);
        $this->engine()->calculate([['name'=>'House Rent','amount'=>1000,'calcType'=>'fixed']], []);
    }
    public function test_duplicate_basics_are_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->engine()->calculate([
            ['name'=>'Basic Pay','amount'=>19000,'calcType'=>'fixed'],
            ['name'=>'Basic Salary','amount'=>20000,'calcType'=>'fixed'],
        ], []);
    }
    public function test_duplicate_gpf_are_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->engine()->calculate($this->additions(), [
            ['name'=>'GPF','amount'=>100,'calcType'=>'fixed'],
            ['name'=>'General Provident Fund','amount'=>100,'calcType'=>'fixed'],
        ]);
    }
    public function test_existing_legacy_configuration_unchanged_after_calculation(): void
    {
        $source=$this->additions();
        $this->engine()->calculate($source, [], $this->fixation());
        $this->assertSame(19460,$source[0]['amount']);
        $this->assertSame(2919,$source[3]['amount']);
    }
}
