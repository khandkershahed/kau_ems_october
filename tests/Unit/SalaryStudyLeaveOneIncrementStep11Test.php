<?php

namespace Tests\Unit;

use App\Services\SalaryFixationService;
use Tests\TestCase;

final class SalaryStudyLeaveOneIncrementStep11Test extends TestCase
{
    public function test_exactly_five_listed_study_leave_exceptions_receive_one_increment_only(): void
    {
        $eligible = ['10037','10042','10056','10086','10105'];
        $entries = config('kau_fixation_eligibility.study_leave', []);
        $codes = [];
        foreach ($entries as $code => $entry) {
            if (($entry['first_increment_allowed'] ?? false) === true) {
                $codes[] = (string)$code;
                $this->assertSame(['hold_2026'=>false,'hold_2027'=>true],
                    SalaryFixationService::studyLeaveSingleIncrementHolds($entry));
            } else {
                $this->assertNull(SalaryFixationService::studyLeaveSingleIncrementHolds($entry));
            }
        }
        sort($codes); sort($eligible);
        $this->assertSame($eligible,$codes);
    }

    public function test_second_increment_is_not_added_to_grade_ten_pay(): void
    {
        $r=(new SalaryFixationService())->calculate(10,16000,0,false,true);
        $this->assertGreaterThan(0,$r['increment_2026']);
        $this->assertSame(0.0,(float)$r['increment_2027']);
        $this->assertSame((float)$r['phase_2027_07_before_increment'],(float)$r['phase_2027_07']);
    }

    public function test_ordinary_employee_still_receives_two_increments(): void
    {
        $r=(new SalaryFixationService())->calculate(10,16000,0,false,false);
        $this->assertGreaterThan(0,$r['increment_2026']);
        $this->assertGreaterThan(0,$r['increment_2027']);
    }
}
