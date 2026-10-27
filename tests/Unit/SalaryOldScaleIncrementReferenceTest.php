<?php

namespace Tests\Unit;

use App\Support\SalaryOldScaleIncrementReference;
use PHPUnit\Framework\TestCase;

final class SalaryOldScaleIncrementReferenceTest extends TestCase
{
    public function test_2015_grade_ten_stage_difference_is_a_reference_not_payment_proof(): void
    {
        $candidate = SalaryOldScaleIncrementReference::nextStageDifference(
            [16000, 16800, 17640, 18530, 19460, 20440], 18530
        );
        self::assertSame(19460.0, $candidate['next_2015_stage']);
        self::assertSame(930.0, $candidate['candidate_increment']);
        self::assertSame('REFERENCE_ONLY_NOT_PROOF_OF_JULY_PAYMENT', $candidate['status']);
        self::assertEquals(2790, $candidate['candidate_increment'] * 3);
    }

    public function test_unknown_june_stage_is_not_inferred(): void
    {
        self::assertNull(SalaryOldScaleIncrementReference::nextStageDifference([18530,19460], 18888));
    }

    public function test_last_stage_has_no_next_stage(): void
    {
        self::assertNull(SalaryOldScaleIncrementReference::nextStageDifference([18530,19460], 19460));
    }
}
