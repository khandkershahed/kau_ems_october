<?php

namespace Tests\Unit;

use App\Services\PayrollPreviewCalculator;
use DomainException;
use PHPUnit\Framework\TestCase;

class PayrollPreviewCalculatorTest extends TestCase
{
    public function test_2015_fixed_percentage_gpf_tax_and_manual_adjustments(): void
    {
        $adds = [
            ['name' => 'Basic Pay', 'amount' => 18530, 'calcType' => 'fixed'],
            ['name' => 'House Rent', 'amount' => 40, 'calcType' => 'percentage'],
            ['name' => 'Medical Allowance', 'amount' => 1500, 'calcType' => 'fixed'],
            ['name' => 'Manual Reward', 'amount' => 500, 'calcType' => 'fixed', 'manual_adjustment_id' => 2],
        ];
        $ded = [
            ['name' => 'GPF', 'amount' => 10, 'calcType' => 'percentage'],
            ['name' => 'Income Tax', 'amount' => 200, 'calcType' => 'fixed'],
            ['name' => 'Manual Recovery', 'amount' => 300, 'calcType' => 'fixed', 'manual_adjustment_id' => 3],
        ];
        $result = PayrollPreviewCalculator::calculate($adds, $ded);
        $this->assertSame(18530.0, $result['basic']);
        $this->assertSame(27942.0, $result['total_allowance']);
        $this->assertSame(2353.0, $result['total_deduction']);
        $this->assertSame(1853.0, $result['gpf_deduction']);
        $this->assertSame(200.0, $result['tax_deduction']);
        $this->assertSame(25589.0, $result['net_salary']);
    }

    public function test_missing_basic_is_blocked(): void
    {
        $this->expectException(DomainException::class);
        PayrollPreviewCalculator::calculate([['name' => 'House Rent', 'amount' => 1000]], []);
    }

    public function test_duplicate_basic_is_blocked(): void
    {
        $this->expectException(DomainException::class);
        PayrollPreviewCalculator::calculate([['name' => 'Basic Pay', 'amount' => 15000], ['name' => 'Basic Pay', 'amount' => 15000]], []);
    }

    public function test_manual_basic_adjustment_does_not_change_calculation_base(): void
    {
        $r = PayrollPreviewCalculator::calculate([
            ['name' => 'Basic Pay', 'amount' => 10000],
            ['name' => 'Manual Basic Correction', 'amount' => 100, 'manual_adjustment_id' => 1],
            ['name' => 'House Rent', 'amount' => 20, 'calcType' => 'percentage'],
        ], []);
        $this->assertSame(10000.0, $r['basic']);
        $this->assertSame(12100.0, $r['net_salary']);
    }
}
