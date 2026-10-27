<?php

namespace Tests\Unit;

use App\Services\SalaryArrearFormulaEngine;
use App\Services\SalaryCalculationService;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SalaryArrearFormulaPhase4Test extends TestCase
{
    private function parser(): SalaryArrearFormulaEngine { return new SalaryArrearFormulaEngine(); }

    public function test_grade_ten_monthwise_arrear_reconciles_without_double_increment(): void
    {
        $monthly = array_fill(0,3, ['REVISED_BASIC'=>28715, 'JUNE_BASIC'=>18530,
            'PAID_BASIC'=>19460, 'PAID_SPECIAL_BENEFIT'=>2919,
            'REVISED_GPF'=>2872, 'PAID_GPF'=>1947]);
        $f=$this->parser();
        $gross=$f->evaluate('SUM_MONTHS(REVISED_BASIC - JUNE_BASIC)',[], $monthly);
        $old=$f->evaluate('SUM_MONTHS(PAID_BASIC - JUNE_BASIC)',[], $monthly);
        $netBasic=$f->evaluate('SUM_MONTHS(REVISED_BASIC - PAID_BASIC)',[], $monthly);
        self::assertSame(30555.0,$gross);
        self::assertSame(2790.0,$old);
        self::assertSame(27765.0,$netBasic);
        self::assertSame($gross-$old,$netBasic);
        self::assertSame(8757.0,$f->evaluate('SUM_MONTHS(PAID_SPECIAL_BENEFIT)',[], $monthly));
        self::assertSame(2775.0,$f->evaluate('SUM_MONTHS(REVISED_GPF - PAID_GPF)',[], $monthly));
        self::assertSame(16233.0,$gross-$old-8757-2775);
    }
    public function test_safe_functions_if_min_max_round_and_percentage(): void
    {
        self::assertSame(2872.0,$this->parser()->evaluate('ROUND(28715 * 10 / 100)',[]));
        self::assertSame(12.0,$this->parser()->evaluate('IF(2 > 1, MAX(12,7), 0)',[]));
        self::assertSame(1.0,$this->parser()->evaluate('MIN(1,2)',[]));
        self::assertSame(2872.0,SalaryCalculationService::halfUpTaka(2871.5));
    }
    public function test_missing_paid_basic_does_not_become_zero(): void
    {
        $this->expectException(DomainException::class);
        $this->parser()->evaluate('REVISED_BASIC - PAID_BASIC',['REVISED_BASIC'=>28715]);
    }
    public function test_arbitrary_php_and_sql_are_rejected(): void
    {
        foreach (['SYSTEM("rm -rf /tmp")','1; DROP TABLE salary_generates','EVAL(2+2)'] as $formula) {
            try { $this->parser()->validate($formula); self::fail('Unsafe formula was accepted.'); }
            catch (DomainException $expected) { self::assertNotEmpty($expected->getMessage()); }
        }
    }
    public function test_division_by_zero_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->parser()->evaluate('1 / (3 - 3)',[]);
    }
    public function test_lazy_if_does_not_require_unused_historical_source(): void
    {
        self::assertSame(100.0,$this->parser()->evaluate('IF(1,100,PAID_BASIC)',[]));
    }
}
