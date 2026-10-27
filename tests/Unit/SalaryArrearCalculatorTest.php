<?php

namespace Tests\Unit;

use App\Services\SalaryArrearCalculator;
use App\Services\SalaryStructuredArrearService;
use DomainException;
use PHPUnit\Framework\TestCase;

class SalaryArrearCalculatorTest extends TestCase
{
    private function gradeTen(): array
    {
        return [
            'june_basic' => 18530, 'entitled_basic' => 28715,
            'paid_basic' => 19460, 'paid_special_benefit' => 2919,
            'paid_house_rent' => 8000, 'paid_gpf' => 1946,
            'special_policy' => 'recover_paid', 'house_policy' => 'reconcile',
            'gpf_policy' => 'reconcile', 'gpf_rounding' => 'truncate_taka',
            'revised_house_rent' => 8000, 'gpf_rate_percent' => 10,
            'manual_items' => [],
        ];
    }

    public function test_meeting_example_reconciles_without_double_deduction(): void
    {
        $r = SalaryArrearCalculator::calculateMonth($this->gradeTen());
        $this->assertSame(10185.0, $r['gross_basic_arrear']);
        $this->assertSame(930.0, $r['old_increment_adjustment']);
        $this->assertSame(9255.0, $r['adjusted_basic_arrear']);
        $this->assertSame(2919.0, $r['special_benefit_recovery']);
        $this->assertSame(925.0, $r['additional_gpf']);
        $this->assertSame(5411.0, $r['net_arrear']);
        $this->assertSame(16233.0, $r['net_arrear'] * 3);
    }

    public function test_increment_hold_does_not_double_subtract(): void
    {
        $input = $this->gradeTen();
        $input['paid_basic'] = 18530;
        $input['entitled_basic'] = 27715; // HR-provided no-increment entitlement
        $r = SalaryArrearCalculator::calculateMonth($input);
        $this->assertSame(0.0, $r['old_increment_adjustment']);
        $this->assertSame(9185.0, $r['adjusted_basic_arrear']);
    }

    public function test_signed_house_rent_differences_and_manual_components(): void
    {
        $input = $this->gradeTen();
        $input['revised_house_rent'] = 7500;
        $input['manual_items'] = [
            ['direction' => 'addition', 'amount' => 1000],
            ['direction' => 'deduction', 'amount' => 250],
        ];
        $r = SalaryArrearCalculator::calculateMonth($input);
        $this->assertSame(-500.0, $r['house_rent_adjustment']);
        $this->assertSame(750.0, $r['manual_net']);
        $this->assertSame(5661.0, $r['net_arrear']);
    }

    public function test_missing_house_rent_entitlement_fails_closed(): void
    {
        $input = $this->gradeTen();
        unset($input['revised_house_rent']);
        $this->expectException(DomainException::class);
        SalaryArrearCalculator::calculateMonth($input);
    }

    public function test_unconfirmed_recovery_policy_fails_closed(): void
    {
        $input = $this->gradeTen();
        $input['special_policy'] = 'unconfirmed';
        $this->expectException(DomainException::class);
        SalaryArrearCalculator::calculateMonth($input);
    }

    public function test_truncation_and_rounding_are_explicit(): void
    {
        $input = $this->gradeTen();
        $input['paid_gpf'] = 0;
        $truncated = SalaryArrearCalculator::calculateMonth($input);
        $this->assertSame(2871.0, $truncated['additional_gpf']);
        $input['gpf_rounding'] = 'round_taka';
        $rounded = SalaryArrearCalculator::calculateMonth($input);
        $this->assertSame(2872.0, $rounded['additional_gpf']);
        $input['gpf_rounding'] = 'paisa';
        $precise = SalaryArrearCalculator::calculateMonth($input);
        $this->assertSame(2871.50, $precise['additional_gpf']);
    }

    public function test_missing_actual_paid_basic_is_not_zero(): void
    {
        $input = $this->gradeTen();
        unset($input['paid_basic']);
        $this->expectException(DomainException::class);
        SalaryArrearCalculator::calculateMonth($input);
    }

    public function test_retain_special_benefit_is_separate_rule(): void
    {
        $input = $this->gradeTen();
        $input['special_policy'] = 'retain';
        $r = SalaryArrearCalculator::calculateMonth($input);
        $this->assertSame(0.0, $r['special_benefit_recovery']);
    }

    public function test_approved_history_requires_unique_basic(): void
    {
        $bill = (object)[
            'reviewStatus' => 'approved',
            'salaryAllowanceCalcObject' => json_encode([
                ['name'=>'Basic Pay', 'calculated_amount'=>18530],
                ['name'=>'Basic Pay', 'calculated_amount'=>18530],
            ]),
            'salaryDeductionCalcObject' => '[]', 'gpfDeduction' => 1853, 'id'=>33,
        ];
        $this->expectException(DomainException::class);
        SalaryArrearCalculator::paidFromPayroll($bill);
    }
    public function test_historical_approved_bill_extracts_basic_special_rent_and_gpf(): void
    {
        $bill = (object)[
            'id' => 51,
            'reviewStatus' => 'approved',
            'salaryAllowanceCalcObject' => json_encode([
                ['name' => 'Basic Pay', 'calculated_amount' => 19460],
                ['name' => 'Special Benefit', 'calculated_amount' => 2919],
                ['name' => 'House Rent', 'calculated_amount' => 7800],
            ]),
            'salaryDeductionCalcObject' => '[]', 'gpfDeduction' => 1946,
        ];
        $paid = SalaryArrearCalculator::paidFromPayroll($bill);
        $this->assertSame(19460.0, $paid['paid_basic']);
        $this->assertSame(2919.0, $paid['paid_special_benefit']);
        $this->assertSame(7800.0, $paid['paid_house_rent']);
        $this->assertSame(1946.0, $paid['paid_gpf']);
        $this->assertSame(51, $paid['source_record_id']);
    }

    public function test_historical_bill_without_approval_is_not_accepted(): void
    {
        $bill = (object)['reviewStatus' => 'pending', 'salaryAllowanceCalcObject' => '[]',
            'salaryDeductionCalcObject' => '[]', 'gpfDeduction' => 0];
        $this->expectException(DomainException::class);
        SalaryArrearCalculator::paidFromPayroll($bill);
    }

    /** Phase 3A: a revised bill containing arrear components is never paid evidence. */
    public function test_revised_arrear_bill_is_rejected_as_paid_evidence(): void
    {
        $bill = (object)[
            'id' => 5850, 'reviewStatus' => 'pending',
            'salaryAllowanceCalcObject' => json_encode([
                ['name' => 'Basic Pay', 'calculated_amount' => 28715],
                ['name' => 'Bokeya Prapyo / Basic Arrear', 'calculated_amount' => 30555],
            ]),
            'salaryDeductionCalcObject' => json_encode([
                ['name' => '[-]Basic Difference / Old Increment', 'calculated_amount' => 2790],
                ['name' => '[-]Additional GPF', 'calculated_amount' => 2778],
            ]),
            'gpfDeduction' => 2872,
        ];
        $this->expectException(DomainException::class);
        SalaryArrearCalculator::paidFromPayroll($bill);
    }

    /** Phase 3A: even an approved revised bill with arrear components is rejected. */
    public function test_approved_arrear_bill_is_also_rejected(): void
    {
        $bill = (object)[
            'id' => 99, 'reviewStatus' => 'approved',
            'salaryAllowanceCalcObject' => json_encode([
                ['name' => 'Basic Pay', 'calculated_amount' => 28715],
                ['name' => 'Basic Arrear', 'calculated_amount' => 1000],
            ]),
            'salaryDeductionCalcObject' => '[]', 'gpfDeduction' => 0,
        ];
        $this->expectException(DomainException::class);
        SalaryArrearCalculator::paidFromPayroll($bill);
    }

    /** Phase 3A: Employee 20057-style evidence yields the expected components. */
    public function test_employee_20057_evidence_yields_expected_components(): void
    {
        $evidence = [
            'june_basic' => 18530,
            'old_increment' => 930,
            'increment_status' => 'RECONSTRUCTED_2015_STAGE_NOT_PAYMENT',
            'revised_basics' => ['2026-07' => 28715, '2026-08' => 28715, '2026-09' => 28715],
            'revised_sources' => ['2026-07' => 1, '2026-08' => 1, '2026-09' => 1],
            'paid_months' => [
                '2026-07' => ['paid_basic' => 19460, 'paid_special_benefit' => 2919,
                    'paid_house_rent' => 8757, 'paid_gpf' => 1946],
                '2026-08' => ['paid_basic' => 19460, 'paid_special_benefit' => 2919,
                    'paid_house_rent' => 8757, 'paid_gpf' => 1946],
                '2026-09' => ['paid_basic' => 19460, 'paid_special_benefit' => 2919,
                    'paid_house_rent' => 8757, 'paid_gpf' => 1946],
            ],
            'financial_months' => [],
        ];
        $r = SalaryStructuredArrearService::compute($evidence);
        $this->assertSame(30555.0, $r['BASIC_ARREAR_GROSS']['amount']);
        $this->assertSame(2790.0, $r['BASIC_INCREMENT_ADJUSTMENT']['amount']);
        $this->assertSame(27765.0, $r['BASIC_ARREAR_GROSS']['amount'] - $r['BASIC_INCREMENT_ADJUSTMENT']['amount']);
    }

    /** Phase 3A: missing paid source leaves the component UNKNOWN, never zero. */
    public function test_missing_paid_source_leaves_component_unknown(): void
    {
        $evidence = $this->evidence20057();
        $evidence['paid_months'] = [];
        $evidence['financial_months'] = [];
        $r = SalaryStructuredArrearService::compute($evidence);
        $this->assertSame(30555.0, $r['BASIC_ARREAR_GROSS']['amount']);
        $this->assertSame(2790.0, $r['BASIC_INCREMENT_ADJUSTMENT']['amount']);
        $this->assertSame(null, $r['SPECIAL_BENEFIT_RECOVERY']['amount']);
        $this->assertSame('UNKNOWN', $r['SPECIAL_BENEFIT_RECOVERY']['status']);
    }

    /** Phase 3B (D,E): pending September source keeps 8757 reported but unverified. */
    public function test_employee_20057_special_benefit_reported_but_unverified(): void
    {
        $evidence = $this->evidence20057();
        $evidence['paid_months']['2026-09']['payment_verified'] = false;
        $evidence['paid_months']['2026-09']['payment_status'] = 'pending';
        $r = SalaryStructuredArrearService::compute($evidence);
        $this->assertSame(8757.0, $r['SPECIAL_BENEFIT_RECOVERY']['amount']); // 2919*3
        $this->assertSame('PAYMENT_UNVERIFIED', $r['SPECIAL_BENEFIT_RECOVERY']['status']);
        $this->assertSame(2271.0, $r['HOUSE_RENT_RECOVERY']['amount']); // (8757-8000)*3
        $this->assertSame('PAYMENT_UNVERIFIED', $r['HOUSE_RENT_RECOVERY']['status']);
    }

    /** Phase 3B (A-C): 20057 basic arrear amounts and directions. */
    public function test_employee_20057_basic_arrear_components(): void
    {
        $r = SalaryStructuredArrearService::compute($this->evidence20057());
        $this->assertSame(30555.0, $r['BASIC_ARREAR_GROSS']['amount']);
        $this->assertSame('payable', $r['BASIC_ARREAR_GROSS']['direction']);
        $this->assertSame(2790.0, $r['BASIC_INCREMENT_ADJUSTMENT']['amount']);
        $this->assertSame('deduction', $r['BASIC_INCREMENT_ADJUSTMENT']['direction']);
        $this->assertSame(27765.0, $r['BASIC_ARREAR_GROSS']['amount'] - $r['BASIC_INCREMENT_ADJUSTMENT']['amount']);
        $this->assertSame('SOURCE_RECONCILED', $r['SPECIAL_BENEFIT_RECOVERY']['status']);
    }

    /** Phase 3B (G/H): verified house rent difference — positive payable, over-deduction payable. */
    public function test_house_rent_difference_directions(): void
    {
        $r = SalaryStructuredArrearService::compute($this->evidence20057());
        $this->assertSame(2271.0, $r['HOUSE_RENT_RECOVERY']['amount']); // (8757-8000)*3
        $this->assertSame('deduction', $r['HOUSE_RENT_RECOVERY']['direction']);
        $e = $this->evidence20057();
        $e['financial_months'] = ['2026-07' => ['entitled_house_rent' => 9000],
            '2026-08' => ['entitled_house_rent' => 9000], '2026-09' => ['entitled_house_rent' => 9000]];
        $r = SalaryStructuredArrearService::compute($e);
        $this->assertSame(729.0, $r['HOUSE_RENT_RECOVERY']['amount']); // (9000-8757)*3
        $this->assertSame('payable', $r['HOUSE_RENT_RECOVERY']['direction']);
    }

    /** Phase 3B (I/J): missing house-rent entitlement or GPF rule stays UNKNOWN. */
    public function test_missing_house_rent_and_gpf_evidence_stays_unknown(): void
    {
        $e = $this->evidence20057();
        $e['financial_months'] = [];
        $r = SalaryStructuredArrearService::compute($e);
        $this->assertSame(null, $r['HOUSE_RENT_RECOVERY']['amount']);
        $this->assertSame('UNKNOWN', $r['HOUSE_RENT_RECOVERY']['status']);
        $this->assertSame(null, $r['ADDITIONAL_GPF']['amount']);
        $this->assertSame('UNKNOWN', $r['ADDITIONAL_GPF']['status']);
    }

    /** Phase 3B (K): positive verified GPF difference is a deduction. */
    public function test_positive_verified_gpf_difference_is_deduction(): void
    {
        $e = $this->evidence20057();
        foreach (['2026-07', '2026-08', '2026-09'] as $p) {
            $e['financial_months'][$p]['required_gpf'] = 2146;
        }
        $r = SalaryStructuredArrearService::compute($e);
        $this->assertSame(600.0, $r['ADDITIONAL_GPF']['amount']); // (2146-1946)*3
        $this->assertSame('deduction', $r['ADDITIONAL_GPF']['direction']);
        $this->assertSame('SOURCE_RECONCILED', $r['ADDITIONAL_GPF']['status']);
    }

    /** Phase 3B (F): unverified payment status is excluded from approval eligibility. */
    public function test_unverified_payment_status_is_excluded_from_approval(): void
    {
        $evidence = $this->evidence20057();
        $evidence['paid_months']['2026-09']['payment_verified'] = false;
        $calculated = SalaryStructuredArrearService::compute($evidence);
        $this->assertSame('PAYMENT_UNVERIFIED', $calculated['SPECIAL_BENEFIT_RECOVERY']['status']);
        $src = file_get_contents((new \ReflectionClass(SalaryStructuredArrearService::class))->getFileName());
        $this->assertStringContainsString("'PAYMENT_UNVERIFIED', 'UNKNOWN', 'SOURCE_CONFLICT_REVIEW_REQUIRED'", $src);
    }


/** Phase 3B cleanup marker */


    /** Phase 3B: 20057 evidence factory — verified monthly payment + house entitlement. */
    private function evidence20057(): array
    {
        return [
            'june_basic' => 18530, 'old_increment' => 930,
            'increment_status' => 'RECONSTRUCTED_2015_STAGE_NOT_PAYMENT',
            'revised_basics' => ['2026-07' => 28715, '2026-08' => 28715, '2026-09' => 28715],
            'revised_sources' => ['2026-07' => 1, '2026-08' => 1, '2026-09' => 1],
            'paid_months' => [
                '2026-07' => ['paid_basic' => 19460, 'paid_special_benefit' => 2919, 'paid_house_rent' => 8757, 'paid_gpf' => 1946, 'payment_verified' => true],
                '2026-08' => ['paid_basic' => 19460, 'paid_special_benefit' => 2919, 'paid_house_rent' => 8757, 'paid_gpf' => 1946, 'payment_verified' => true],
                '2026-09' => ['paid_basic' => 19460, 'paid_special_benefit' => 2919, 'paid_house_rent' => 8757, 'paid_gpf' => 1946, 'payment_verified' => true],
            ],
            'financial_months' => ['2026-07' => ['entitled_house_rent' => 8000],
                '2026-08' => ['entitled_house_rent' => 8000], '2026-09' => ['entitled_house_rent' => 8000]],
        ];
    }
}
