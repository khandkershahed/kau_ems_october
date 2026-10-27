<?php

namespace Tests\Unit;

use App\Services\SalaryEffectiveComponentResolver;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SalaryEffectiveComponentResolverStep02Test extends TestCase
{
    private function houseRule(string $basis = 'payable_basic'): array
    {
        return ['id' => 73, 'payroll_component_type_id' => 5,
            'component_name' => 'House Rent Allowance', 'direction' => 'addition',
            'calc_type' => 'percentage', 'value' => '40.00', 'basis' => $basis,
            'frequency' => 'monthly', 'evidence_reference' => 'HR-verified-order'];
    }

    public function test_published_rule_replaces_existing_fixed_legacy_component_without_mutation(): void
    {
        $old = [['name'=>'Basic Pay','calcType'=>'fixed','amount'=>28715],
            ['name'=>'House Rent Allowance','calcType'=>'fixed','amount'=>8757]];
        [$a,$d] = SalaryEffectiveComponentResolver::apply($old, [], [$this->houseRule()], 18530);
        $this->assertSame('fixed',$old[1]['calcType']);
        $this->assertSame(8757,$old[1]['amount']);
        $this->assertSame('percentage',$a[1]['calcType']);
        $this->assertSame(40.0,$a[1]['amount']);
        $this->assertSame('published_effective_rule',$a[1]['component_source']);
        $this->assertSame(73,$a[1]['effective_rule_id']);
        $this->assertSame([], $d);
    }

    public function test_published_legacy_basis_is_kept_separate_from_revised_basic(): void
    {
        [$a] = SalaryEffectiveComponentResolver::apply(
            [['name'=>'Basic Pay','calcType'=>'fixed','amount'=>18530]], [],
            [$this->houseRule('legacy_basic')], 18530);
        $this->assertSame(18530.0,$a[1]['percentage_base_amount']);
        $this->assertSame(40.0,$a[1]['amount']);
    }

    public function test_missing_legacy_base_for_percent_rule_rejects_estimate(): void
    {
        $this->expectException(DomainException::class);
        SalaryEffectiveComponentResolver::apply([], [], [$this->houseRule('legacy_basic')], null);
    }

    public function test_manual_additions_are_not_overwritten_by_catalog_rules(): void
    {
        $manual = ['name'=>'House Rent Allowance','calcType'=>'fixed','amount'=>999,
            'manual_adjustment_id'=>12];
        [$a] = SalaryEffectiveComponentResolver::apply([$manual], [], [$this->houseRule()], 18530);
        $this->assertSame($manual, $a[0]);
        $this->assertCount(2,$a);
    }

    public function test_unpublished_zero_is_never_inferred_in_pure_applier(): void
    {
        $original = [['name'=>'House Rent Allowance','calcType'=>'fixed','amount'=>8757]];
        [$a] = SalaryEffectiveComponentResolver::apply($original, [], [], 18530);
        $this->assertSame($original,$a);
    }

    public function test_duplicate_legacy_rows_are_not_silently_resolved(): void
    {
        $this->expectException(DomainException::class);
        SalaryEffectiveComponentResolver::apply([
            ['name'=>'House Rent Allowance','calcType'=>'fixed','amount'=>1],
            ['name'=>'House Rent Allowance','calcType'=>'fixed','amount'=>2],
        ], [], [$this->houseRule()], 18530);
    }

    public function test_invalid_rule_cannot_turn_into_payable_component(): void
    {
        $rule = $this->houseRule();
        $rule['value'] = 150;
        $this->expectException(DomainException::class);
        SalaryEffectiveComponentResolver::apply([], [], [$rule], 18530);
    }
}
