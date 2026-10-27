<?php

namespace Tests\Unit;

use App\Services\SalaryArrearPostingService;
use PHPUnit\Framework\TestCase;
use DomainException;

final class SalaryArrearPostingMathTest extends TestCase
{
    public function test_grade_ten_original_example_reconciles(): void
    {
        $lines = [
            (object) ['direction'=>'addition','amount'=>30555,'code'=>'gross_basic_arrear'],
            (object) ['direction'=>'deduction','amount'=>2790,'code'=>'old_increment_adjustment'],
            (object) ['direction'=>'deduction','amount'=>8757,'code'=>'special_benefit_recovery'],
            (object) ['direction'=>'deduction','amount'=>2775,'code'=>'additional_gpf'],
        ];
        $sum = SalaryArrearPostingService::summarizeLines($lines);
        $this->assertSame(3055500, $sum['additions']);
        $this->assertSame(1432200, $sum['deductions']);
        $this->assertSame(1623300, $sum['net']);
        $this->assertSame(277500, $sum['gpf']);
    }

    public function test_signed_house_rent_and_refunded_gpf(): void
    {
        $lines = [
            (object) ['direction'=>'deduction','amount'=>500,'code'=>'house_rent_adjustment'],
            (object) ['direction'=>'addition','amount'=>50,'code'=>'additional_gpf'],
        ];
        $result = SalaryArrearPostingService::summarizeLines($lines);
        $this->assertSame(-45000, $result['net']);
        $this->assertSame(-5000, $result['gpf']);
    }

    public function test_bad_direction_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        SalaryArrearPostingService::summarizeLines([(object) ['direction'=>'invalid','amount'=>100,'code'=>'other']]);
    }
}
