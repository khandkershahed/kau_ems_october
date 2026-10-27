<?php

namespace Tests\Unit;

use App\Services\SalaryOfficialReportSource;
use DomainException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SalaryOfficialReportSourceStep07Test extends TestCase
{
    private function entries(array $items, string $direction = 'allowance'): array
    {
        return (new ReflectionMethod(SalaryOfficialReportSource::class, 'entries'))
            ->invoke(null, $items, $direction);
    }

    public function test_fixed_posted_allowance_preserves_exact_saved_amount(): void
    {
        $lines = $this->entries([['name'=>'Basic Pay','calcType'=>'fixed','amount'=>43170]]);
        $this->assertSame(43170.0, $lines[0]['current']);
        $this->assertSame(0.0, $lines[0]['arrear']);
    }

    public function test_percentage_rate_is_not_fabricated_as_a_saved_payment(): void
    {
        $lines = $this->entries([['name'=>'House Rent','calcType'=>'percentage','amount'=>40]]);
        $this->assertNull($lines[0]['total']);
        $this->assertTrue($lines[0]['unverified']);
    }

    public function test_frozen_calculated_percentage_is_accepted(): void
    {
        $lines = $this->entries([['name'=>'House Rent','calcType'=>'percentage','amount'=>40,'calculated_amount'=>17268]]);
        $this->assertSame(17268.0, $lines[0]['total']);
        $this->assertFalse($lines[0]['unverified']);
    }

    public function test_arrear_is_only_counted_in_arrear_column(): void
    {
        $lines = $this->entries([['name'=>'Basic Arrear','amount'=>1200,'arrear_phase5'=>true,'arrear_entitlement_months'=>['2026-07','2026-08','2026-09']]]);
        $this->assertSame(0.0, $lines[0]['current']);
        $this->assertSame(1200.0, $lines[0]['arrear']);
        $this->assertCount(3, $lines[0]['months']);
    }

    public function test_negative_posted_component_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->entries([['name'=>'Unknown','amount'=>-20]]);
    }

    public function test_unknown_component_prevents_falsely_verified_sum(): void
    {
        $lines = $this->entries([['name'=>'House Rent','calcType'=>'percentage','amount'=>40]]);
        $result = (new ReflectionMethod(SalaryOfficialReportSource::class, 'sumRows'))->invoke(null, $lines);
        $this->assertNull($result);
    }

    public function test_gpf_rounding_does_not_infer_new_subscription(): void
    {
        $lines = $this->entries([['name'=>'GPF','calcType'=>'fixed','amount'=>'2872.00']], 'deduction');
        $this->assertSame(2872.0, $lines[0]['current']);
    }
}
