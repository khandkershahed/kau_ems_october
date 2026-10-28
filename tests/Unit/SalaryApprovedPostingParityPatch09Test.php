<?php
namespace Tests\Unit;

use App\Services\SalaryApprovedPostingParityGuard as Guard;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SalaryApprovedPostingParityPatch09Test extends TestCase
{
    private function approved(): array
    {
        return ['basic'=>20000,'total_allowance'=>30000,'total_deduction'=>5000,
            'gpf_deduction'=>3000,'net_salary'=>25000];
    }
    private function composed(): array
    {
        return ['regular'=>$this->approved(),'arrear_payable'=>1200.25,
            'arrear_deduction'=>200.25,'net_payable'=>26000];
    }
    public function test_matching_regular_and_arrear_pass(): void
    {
        Guard::assertAmounts($this->approved(),['net_arrear'=>1000],$this->composed());
        $this->assertTrue(true);
    }
    public function test_altered_arrear_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        Guard::assertAmounts($this->approved(),['net_arrear'=>999],$this->composed());
    }
    public function test_changed_net_payable_is_rejected(): void
    {
        $source=$this->composed();$source['net_payable']=27000;
        $this->expectException(DomainException::class);
        Guard::assertAmounts($this->approved(),['net_arrear'=>1000],$source);
    }
    public function test_missing_arrear_cannot_be_treated_as_zero(): void
    {
        $this->expectException(DomainException::class);
        Guard::assertAmounts($this->approved(),['net_arrear'=>null],$this->composed());
    }
}
