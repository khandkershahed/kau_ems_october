<?php

namespace Tests\Unit;

use App\Services\SalaryCalculationService;
use App\Services\SalaryEmployeeComponentOverrideService as OverrideService;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SalaryEmployeeComponentOverrideStep03Test extends TestCase
{
    private function basic(): array { return ['name'=>'Basic Pay','calcType'=>'fixed','amount'=>28715]; }
    private function rent(): array { return ['name'=>'House Rent Allowance','payroll_component_type_id'=>8,'calcType'=>'fixed','amount'=>8757]; }
    private function rule(array $changes=[]): array {
        return array_merge(['id'=>4,'component_key'=>'type:8','payroll_component_type_id'=>8,
            'component_title'=>'House Rent Allowance','direction'=>'addition','mode'=>'manual',
            'calc_type'=>'percentage','value'=>40,'basis'=>'payable_basic','legacy_basic_reference'=>null],$changes);
    }

    public function test_published_fixed_component_is_replaced_by_monthly_percentage_without_mutating_source(): void
    {
        $old=[$this->basic(),$this->rent()];
        [$adds,$ded]=OverrideService::apply($old,[],[$this->rule()]);
        self::assertSame('fixed',$old[1]['calcType']);
        self::assertSame('percentage',$adds[1]['calcType']);
        self::assertSame('employee_month_override',$adds[1]['component_source']);
        self::assertSame(11486.0, (new SalaryCalculationService)->calculate($adds,$ded,null,['type'=>'disabled','amount'=>0])['allowances'][1]['calculated_amount']);
    }

    public function test_legacy_basic_percentage_reference_is_applied(): void
    {
        [$add,$ded]=OverrideService::apply([$this->basic(),$this->rent()],[],[
            $this->rule(['basis'=>'legacy_basic','legacy_basic_reference'=>20000])]);
        self::assertSame(20000.0,$add[1]['percentage_base_amount']);
        self::assertSame(8000.0,(new SalaryCalculationService)->calculate($add,$ded,null,['type'=>'disabled','amount'=>0])['allowances'][1]['calculated_amount']);
    }

    public function test_monthly_disable_removes_only_the_target_component(): void
    {
        [$add,$ded]=OverrideService::apply([$this->basic(),$this->rent()],[],[$this->rule(['mode'=>'disabled'])]);
        self::assertCount(1,$add); self::assertSame('Basic Pay',$add[0]['name']); self::assertSame([],$ded);
    }

    public function test_basic_cannot_be_overridden(): void
    {
        $this->expectException(DomainException::class);
        OverrideService::apply([$this->basic()],[],[$this->rule([
            'component_key'=>OverrideService::key(null,'Basic Pay'),
            'payroll_component_type_id'=>null,'component_title'=>'Basic Pay'])]);
    }

    public function test_gpf_override_uses_half_up_rounding(): void
    {
        $gpf=['name'=>'General Provident Fund (GPF)','payroll_component_type_id'=>12,'calcType'=>'fixed','amount'=>2500];
        $rule=$this->rule(['direction'=>'deduction','component_key'=>'type:12',
            'component_title'=>'General Provident Fund (GPF)','payroll_component_type_id'=>12,'value'=>10]);
        [$adds,$ded]=OverrideService::apply([$this->basic()],[$gpf],[$rule]);
        $result=(new SalaryCalculationService)->calculate($adds,$ded);
        self::assertSame(2872.0,$result['gpf_deduction']);
        self::assertSame('employee_month_override',$result['gpf_rule']['source']);
    }

    public function test_custom_addition_does_not_replace_recorded_component(): void
    {
        $rule=$this->rule(['component_key'=>OverrideService::key(null,'Temporary Allowance',true),
            'component_title'=>'Temporary Allowance','payroll_component_type_id'=>null,'calc_type'=>'fixed','value'=>500,'basis'=>'none']);
        [$adds, $ded]=OverrideService::apply([$this->basic(), $this->rent()], [], [$rule]);
        self::assertCount(3,$adds);
        self::assertSame('Temporary Allowance',$adds[2]['name']);
        self::assertSame(500.0,$adds[2]['amount']);
    }

    public function test_duplicate_type_components_are_rejected(): void
    {
        $this->expectException(DomainException::class);
        OverrideService::apply([$this->basic(),$this->rent(),$this->rent()],[],[$this->rule()]);
    }

    public function test_snapshot_cannot_bypass_saved_employee_override(): void
    {
        $this->expectException(DomainException::class);
        OverrideService::assertSnapshotMatches([$this->basic(),$this->rent()],[],[$this->rule()]);
    }

    public function test_snapshot_matches_applied_employee_override(): void
    {
        [$adds,$ded]=OverrideService::apply([$this->basic(),$this->rent()],[],[$this->rule()]);
        OverrideService::assertSnapshotMatches($adds,$ded,[$this->rule()]);
        self::assertCount(2,$adds);
    }

    public function test_period_identity_not_encoded_into_component_key(): void
    {
        self::assertSame('type:8', OverrideService::key(8,'House Rent Allowance'));
        self::assertSame(OverrideService::key(null,'House Rent Allowance'),OverrideService::key(null,' house   RENT allowance '));
    }
}
