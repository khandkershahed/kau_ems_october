<?php

namespace Tests\Unit;

use App\Services\SalaryFixationService;
use Tests\TestCase;

class SalaryFixationCalculationTest extends TestCase
{
    public function test_grade_nine_matches_the_supplied_html_example(): void
    {
        $result = (new SalaryFixationService())->calculate(9, 26760);
        $this->assertSame(51000, $result['fitment_basic']);
        $this->assertSame(2500, $result['increment_2026']);
        $this->assertSame(2700, $result['increment_2027']);
        $this->assertEquals(38956, $result['phase_2026_07']);
        $this->assertEquals(46228, $result['phase_2027_01']);
        $this->assertSame(56200, $result['phase_2027_07']);
    }

    public function test_grade_ten_uses_fifty_and_seventy_five_percent(): void
    {
        $result = (new SalaryFixationService())->calculate(10, 16000);
        $this->assertSame(32000, $result['fitment_basic']);
        $this->assertEquals(.50, $result['rate_2026']);
        $this->assertEquals(.75, $result['rate_2027']);
        $this->assertEquals(25600, $result['phase_2026_07']);
        $this->assertEquals(29600, $result['phase_2027_01']);
        $this->assertSame(35300, $result['phase_2027_07']);
    }

    public function test_increment_can_be_held(): void
    {
        $result = (new SalaryFixationService())->calculate(9, 26760, 0, true);
        $this->assertSame(0, $result['increment_2026']);
        $this->assertSame(0, $result['increment_2027']);
        $this->assertEquals(36456, $result['phase_2026_07']);
        $this->assertSame(51000, $result['phase_2027_07']);
    }

    public function test_fixed_grade_one_has_no_increment(): void
    {
        $result = (new SalaryFixationService())->calculate(1, 78000);
        $this->assertSame(156000, $result['fitment_basic']);
        $this->assertSame(0, $result['increment_2026']);
        $this->assertSame(0, $result['increment_2027']);
    }
    public function test_grade_six_matches_the_supplied_employee_10026_original_report(): void
    {
        $r = (new SalaryFixationService())->calculate(6, 52480);
        $this->assertEquals(90700, $r['fitment_basic']);
        $this->assertEquals(4500, $r['increment_2026']);
        $this->assertEquals(72268, $r['phase_2026_07']);
        $this->assertEquals(83734, $r['phase_2027_01']);
        $this->assertEquals(100000, $r['phase_2027_07']);
    }

    public function test_education_leave_can_hold_2026_and_allow_2027(): void
    {
        $r = (new SalaryFixationService())->calculate(6, 52480, 0, true, false);
        $this->assertEquals(0, $r['increment_2026']);
        $this->assertEquals(4500, $r['increment_2027']);
        $this->assertEquals(67768, $r['phase_2026_07']);
        $this->assertEquals(79234, $r['phase_2027_01']);
        $this->assertEquals(95200, $r['phase_2027_07']);
    }

    public function test_first_increment_hold_and_second_increment_allow_are_independent(): void
    {
        $heldBoth = (new SalaryFixationService())->calculate(6, 52480, 0, true, true);
        $heldFirst = (new SalaryFixationService())->calculate(6, 52480, 0, true, false);
        $this->assertSame(0, $heldFirst['increment_2026']);
        $this->assertSame(4500, $heldFirst['increment_2027']);
        $this->assertSame(90700, $heldBoth['phase_2027_07']);
        $this->assertSame(95200, $heldFirst['phase_2027_07']);
    }

    public function test_holding_an_increment_does_not_change_the_fitment_stage(): void
    {
        $allowed = (new SalaryFixationService())->calculate(6, 52480, 0, false, false);
        $held = (new SalaryFixationService())->calculate(6, 52480, 0, true, true);
        $this->assertSame($allowed['fitment_basic'], $held['fitment_basic']);
        $this->assertSame($allowed['increase_difference'], $held['increase_difference']);
        $this->assertSame(0, $held['increment_2026']);
        $this->assertSame(0, $held['increment_2027']);
    }
}
