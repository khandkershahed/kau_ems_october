<?php

namespace Tests\Unit;

use App\Services\SalaryBillReconciliation;
use PHPUnit\Framework\TestCase;

final class SalaryBillReconciliationTest extends TestCase
{
    private function bill(float $gross, float $ded, float $gpf, float $tax = 0): object
    {
        return (object) [
            'totalAllowance' => $gross, 'totalDeduction' => $ded,
            'salaryTotal' => $gross - $ded,
            'gpfDeduction' => $gpf, 'taxDeduction' => $tax,
        ];
    }

    private function regularAdditions(): array
    {
        return [
            ['name' => 'Basic Pay', 'calculated_amount' => 19460],
            ['name' => 'House Rent Allowance', 'calculated_amount' => 8757],
            ['name' => 'Medical Allowance', 'calculated_amount' => 1500],
            ['name' => 'Special benefit', 'calculated_amount' => 2919],
            ['name' => 'Education Allowance', 'calculated_amount' => 500],
        ];
    }

    private function regularDeductions(): array
    {
        return [
            ['name' => 'General Provident Fund', 'calculated_amount' => 1946],
            ['name' => 'Revenue Stamp', 'calculated_amount' => 10],
        ];
    }

    public function test_original_sample_salary_bill_balances(): void
    {
        $r = SalaryBillReconciliation::verify(
            $this->bill(33136, 1956, 1946),
            $this->regularAdditions(), $this->regularDeductions());
        $this->assertSame([], $r['issues']);
        $this->assertSame(31180.0, $r['regular_net']);
        $this->assertSame(31180.0, $r['combined_net']);
    }

    public function test_changed_allowance_is_detected_even_when_stored_net_is_unchanged(): void
    {
        $adds = $this->regularAdditions();
        $adds[1]['calculated_amount'] = 8000;
        $r = SalaryBillReconciliation::verify($this->bill(33136, 1956, 1946), $adds, $this->regularDeductions());
        $this->assertContains('ALLOWANCES_MISMATCH', $r['issues']);
        $this->assertContains('NET_SALARY_MISMATCH', $r['issues']);
    }

    public function test_arrear_and_additional_gpf_are_separate_from_regular_pay(): void
    {
        $adds = $this->regularAdditions();
        $adds[] = ['name' => 'Basic arrear', 'calculated_amount' => 30555,
            'arrear_snapshot_id' => 9, 'arrear_line_id' => 1];
        $ded = $this->regularDeductions();
        $ded[] = ['name' => 'Old-scale Increment Adjustment', 'calculated_amount' => 2790,
            'arrear_snapshot_id' => 9, 'arrear_line_id' => 2];
        $ded[] = ['name' => 'Additional GPF', 'calculated_amount' => 2775,
            'arrear_snapshot_id' => 9, 'arrear_line_id' => 3, 'arrear_code' => 'additional_gpf'];
        $r = SalaryBillReconciliation::verify($this->bill(63691, 7521, 4721), $adds, $ded);
        $this->assertSame([], $r['issues']);
        $this->assertSame(31180.0, $r['regular_net']);
        $this->assertSame(24990.0, $r['arrear_net']);
        $this->assertSame(2775.0, $r['supplementary_gpf']);
    }

    public function test_gpf_refund_reduces_recorded_gpf_deduction(): void
    {
        $adds = $this->regularAdditions();
        $adds[] = ['name' => 'GPF refund', 'calculated_amount' => 100,
            'arrear_snapshot_id' => 3, 'arrear_line_id' => 7, 'arrear_code' => 'additional_gpf'];
        $r = SalaryBillReconciliation::verify($this->bill(33236, 1956, 1846), $adds, $this->regularDeductions());
        $this->assertSame([], $r['issues']);
        $this->assertSame(-100.0, $r['supplementary_gpf']);
    }

    public function test_malformed_or_duplicate_basic_is_rejected(): void
    {
        $adds = $this->regularAdditions();
        $adds[] = ['name' => 'Basic Pay', 'calculated_amount' => 10];
        $r = SalaryBillReconciliation::verify($this->bill(33146, 1956, 1946), $adds, $this->regularDeductions());
        $this->assertContains('BASIC_COMPONENT_NOT_UNIQUE', $r['issues']);
    }
}
