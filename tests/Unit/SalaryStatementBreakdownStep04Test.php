<?php

namespace Tests\Unit;

use App\Services\SalaryCalculationService;
use App\Services\SalaryStatementBreakdown;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SalaryStatementBreakdownStep04Test extends TestCase
{
    private function regular(): array
    {
        return (new SalaryCalculationService())->calculate(
            [
                ['name'=>'Basic Pay','calcType'=>'fixed','amount'=>28715],
                ['name'=>'House Rent','calcType'=>'percentage','amount'=>40,'percentage_base_amount'=>21892.5],
                ['name'=>'Medical Allowance','calcType'=>'fixed','amount'=>1500],
            ],
            [['name'=>'Revenue Stamp','calcType'=>'fixed','amount'=>10],
             ['name'=>'General Provident Fund (GPF)','calcType'=>'fixed','amount'=>2872,'component_source'=>'employee_month_override']],
            null
        );
    }

    public function test_current_arrear_total_reconciles_to_regular_service(): void
    {
        $s = SalaryStatementBreakdown::build($this->regular(), [
            ['title'=>'Basic Arrear','direction'=>'payable','final_amount'=>300],
            ['title'=>'Special Benefit Recovery','direction'=>'deduction','final_amount'=>75],
        ]);
        self::assertEqualsWithDelta(38972.0, $s['summary']['current']['gross'], 0.000001);
        self::assertEqualsWithDelta(2882.0, $s['summary']['current']['deductions'], 0.000001);
        self::assertEqualsWithDelta(300.0, $s['summary']['arrear']['gross'], 0.000001);
        self::assertEqualsWithDelta(75.0, $s['summary']['arrear']['deductions'], 0.000001);
        self::assertEqualsWithDelta(36315.0, $s['summary']['total']['net'], 0.000001);
        self::assertFalse($s['provisional']);
        self::assertCount(3, $s['deductions'] );
    }

    public function test_unknown_arrear_is_not_verified_zero(): void
    {
        $s = SalaryStatementBreakdown::build($this->regular(), [
            ['title'=>'Missing September Payment','direction'=>'deduction','final_amount'=>null],
        ]);
        self::assertTrue($s['provisional']);
        self::assertNull($s['deductions'][2]['arrear']);
        self::assertNull($s['summary']['total']['net']);
        self::assertSame(['Missing September Payment'], $s['unknown_arrears']);
        self::assertEqualsWithDelta(36090.0, $s['summary']['provisional_display']['net'], 0.000001);
    }

    public function test_separate_regular_and_additional_gpf_no_duplicate(): void
    {
        $s = SalaryStatementBreakdown::build($this->regular(), [
            ['title'=>'Additional GPF','direction'=>'deduction','final_amount'=>600],
        ]);
        self::assertCount(3, $s['deductions']);
        self::assertEqualsWithDelta(2872.0, $s['deductions'][1]['current'], 0.000001);
        self::assertEqualsWithDelta(600.0, $s['deductions'][2]['arrear'], 0.000001);
        self::assertEqualsWithDelta(0.0, $s['deductions'][1]['arrear'], 0.000001);
        self::assertEqualsWithDelta(35490.0, $s['summary']['total']['net'], 0.000001);
    }

    public function test_source_mismatch_is_rejected(): void
    {
        $regular = $this->regular();
        $regular['net_salary'] += 10;
        $this->expectException(DomainException::class);
        SalaryStatementBreakdown::build($regular, []);
    }

    public function test_negative_arrear_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        SalaryStatementBreakdown::build($this->regular(), [
            ['title'=>'Invalid','direction'=>'deduction','final_amount'=>-1],
        ]);
    }
}
