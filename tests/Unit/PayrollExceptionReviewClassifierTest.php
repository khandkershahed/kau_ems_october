<?php

namespace Tests\Unit;

use App\Services\PayrollExceptionReviewClassifier as Classifier;
use PHPUnit\Framework\TestCase;

final class PayrollExceptionReviewClassifierTest extends TestCase
{
    public function test_grade_scale_and_bank_review_are_separate_actions(): void
    {
        $actions = Classifier::actions('GRADE_SCALE_RELATION_MISMATCH | BANK: MULTIPLE_SALARY_BANK_FLAGS_CHECK_LINKED_ACCOUNT');
        $this->assertContains('HR_VERIFY_PAY_SCALE_AND_GRADE_LINK', $actions);
        $this->assertContains('HR_VERIFY_EXPLICIT_LINKED_BANK_ACCOUNT_AND_FLAGS', $actions);
    }

    public function test_inactive_employee_and_missing_basic_need_independent_hr_decisions(): void
    {
        $actions = Classifier::actions('USER_INACTIVE | SALARY_SETUP: Basic Pay is missing, zero or not a fixed value.');
        $this->assertContains('HR_VERIFY_OCTOBER_EMPLOYMENT_ELIGIBILITY', $actions);
        $this->assertContains('HR_RECONCILE_FULL_SALARY_COMPONENT_SETUP', $actions);
    }

    public function test_duplicate_profile_needs_hr_review_not_automatic_merge(): void
    {
        $this->assertContains('HR_RECONCILE_DUPLICATE_EMPLOYEE_PROFILES',
            Classifier::actions('DUPLICATE_USER_PROFILES'));
    }

    public function test_double_encoded_historical_basic_is_recognized(): void
    {
        $item = json_encode([['name'=>'Basic Pay','amount'=>'37280','calculated_amount'=>37280,'calcType'=>'fixed']]);
        $this->assertSame(37280.0, Classifier::historicalBasic(json_encode($item)));
    }

    public function test_multiple_basic_rows_or_missing_data_are_not_a_valid_reference(): void
    {
        $items = json_encode([['name'=>'Basic Pay','amount'=>9300],['name'=>'Basic Pay','amount'=>9300]]);
        $this->assertNull(Classifier::historicalBasic($items));
        $this->assertNull(Classifier::historicalBasic('not json'));
    }
}
