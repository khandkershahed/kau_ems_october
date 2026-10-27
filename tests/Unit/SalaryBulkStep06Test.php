<?php
namespace Tests\Unit;

use App\Services\SalaryBulkPreviewPresenter;
use App\Services\SalaryBulkSourceGuard;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SalaryBulkStep06Test extends TestCase
{
    private function sample(): array
    {
        return [
            'user_id'=>42,'employee_code'=>'E42','name'=>'Sample','grade'=>'Grade 10',
            'designation'=>'Sample Designation','source_hash'=>str_repeat('a',64),
            'regular'=>['basic'=>28715],
            'regular_additions'=>[['component_source'=>'employee_component']],
            'regular_deductions'=>[],
            'net_payable'=>36315,
            'statement'=>[
                'provisional'=>false,'unknown_arrears'=>[],
                'summary'=>[
                    'current'=>['gross'=>38972,'deductions'=>2882,'net'=>36090],
                    'arrear'=>['gross'=>300,'deductions'=>75,'net'=>225],
                    'total'=>['gross'=>39272,'deductions'=>2957,'net'=>36315],
                ]
            ],
        ];
    }

    public function test_preview_uses_existing_single_salary_totals(): void
    {
        $row=SalaryBulkPreviewPresenter::present(42,$this->sample());
        $this->assertSame('ready',$row['status']);
        $this->assertSame(36090.0,$row['regular_net']);
        $this->assertSame(300.0,$row['arrear_payable']);
        $this->assertSame(75.0,$row['arrear_deductions']);
        $this->assertSame(36315.0,$row['net_payable']);
        $this->assertSame(str_repeat('a',64),$row['source_hash']);
    }
    public function test_manual_override_is_labelled_not_another_formula(): void
    {
        $data=$this->sample();
        $data['regular_additions'][0]['component_source']='employee_month_override';
        $this->assertSame('manual_override',SalaryBulkPreviewPresenter::present(42,$data)['calculation_status']);
    }
    public function test_mismatched_employee_or_total_is_not_ready(): void
    {
        $this->expectException(DomainException::class);
        SalaryBulkPreviewPresenter::present(41,$this->sample());
    }
    public function test_inconsistent_net_is_not_ready(): void
    {
        $data=$this->sample();$data['statement']['summary']['total']['net']=36316;
        $this->expectException(DomainException::class);
        SalaryBulkPreviewPresenter::present(42,$data);
    }
    public function test_unknown_is_never_a_qualified_zero(): void
    {
        $data=$this->sample();$data['statement']['provisional']=true;
        $this->expectException(DomainException::class);
        SalaryBulkPreviewPresenter::present(42,$data);
    }
    public function test_missing_evidence_distinct_from_normal_block(): void
    {
        $row=SalaryBulkPreviewPresenter::failure(42,'Arrear source UNKNOWN: Special Benefit.');
        $this->assertSame('missing_evidence',$row['status']);
        $this->assertNull($row['net_payable']);
        $this->assertSame('blocked',SalaryBulkPreviewPresenter::failure(42,'Duplicate GPF ledger')['status']);
    }
    public function test_source_hash_change_rejected(): void
    {
        $this->expectException(DomainException::class);
        SalaryBulkSourceGuard::assertMatches(str_repeat('a',64),str_repeat('b',64));
    }
    public function test_identical_source_hash_accepted(): void
    {
        SalaryBulkSourceGuard::assertMatches(str_repeat('a',64),str_repeat('a',64));
        $this->assertTrue(true);
    }
}
