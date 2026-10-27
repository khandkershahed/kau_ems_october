<?php
namespace Tests\Unit;

use App\Services\SalaryBillReconciliation;
use PHPUnit\Framework\TestCase;

final class SalaryBillPhase5ReconciliationTest extends TestCase
{
    public function test_historical_salary_bill_matches_source_pdf(): void
    {
        $allowances=[
            ['name'=>'Basic Pay','calculated_amount'=>19460],
            ['name'=>'House Rent Allowance','calculated_amount'=>8757],
            ['name'=>'Medical Allowance','calculated_amount'=>1500],
            ['name'=>'Special benefit','calculated_amount'=>2919],
            ['name'=>'Education Allowance','calculated_amount'=>500],
        ];
        $deductions=[['name'=>'General Provident Fund','calculated_amount'=>1946],
            ['name'=>'Revenue Stamp','calculated_amount'=>10]];
        $bill=(object)['totalAllowance'=>33136,'totalDeduction'=>1956,
            'gpfDeduction'=>1946,'taxDeduction'=>0,'salaryTotal'=>31180];
        $result=SalaryBillReconciliation::verify($bill,$allowances,$deductions);
        $this->assertSame([],$result['issues']);
        $this->assertSame(31180.0,$result['combined_net']);
    }

    public function test_phase5_gpf_regular_and_supplementary_are_separate(): void
    {
        $add=[['name'=>'Basic Pay','calculated_amount'=>28715],
            ['name'=>'House Rent','calculated_amount'=>8757],
            ['name'=>'Medical Allowance','calculated_amount'=>1500],
            ['name'=>'Education Allowance','calculated_amount'=>500],
            ['name'=>'Bokeya Prapyo','calculated_amount'=>30555,'arrear_phase5'=>true,
                'arrear_code'=>'BASIC_ARREAR_GROSS']];
        $ded=[['name'=>'GPF','calculated_amount'=>2872],
            ['name'=>'Revenue Stamp','calculated_amount'=>10],
            ['name'=>'Basic Difference','calculated_amount'=>2790,'arrear_phase5'=>true,'arrear_code'=>'BASIC_INCREMENT_ADJUSTMENT'],
            ['name'=>'Special Benefit Recovery','calculated_amount'=>8757,'arrear_phase5'=>true,'arrear_code'=>'SPECIAL_BENEFIT_RECOVERY'],
            ['name'=>'Additional GPF','calculated_amount'=>2775,'arrear_phase5'=>true,'arrear_code'=>'ADDITIONAL_GPF']];
        $gross=70027;$totalDed=17204;
        $bill=(object)['totalAllowance'=>$gross,'totalDeduction'=>$totalDed,
            'gpfDeduction'=>2872,'taxDeduction'=>0,'salaryTotal'=>$gross-$totalDed];
        $result=SalaryBillReconciliation::verify($bill,$add,$ded);
        $this->assertSame([],$result['issues']);
        $this->assertSame(2775.0,$result['supplementary_gpf']);
        $this->assertSame(2872.0,$result['regular_gpf']);
        $this->assertSame(16233.0,$result['arrear_net']);
    }

    public function test_modified_bank_or_total_is_not_hidden_by_matching_net(): void
    {
        $bill=(object)['totalAllowance'=>1000,'totalDeduction'=>100,
            'gpfDeduction'=>0,'taxDeduction'=>0,'salaryTotal'=>900];
        $result=SalaryBillReconciliation::verify($bill,
            [['name'=>'Basic Pay','calculated_amount'=>995]],
            [['name'=>'Revenue Stamp','calculated_amount'=>95]]);
        $this->assertContains('ALLOWANCES_MISMATCH',$result['issues']);
        $this->assertContains('DEDUCTIONS_MISMATCH',$result['issues']);
    }
}
