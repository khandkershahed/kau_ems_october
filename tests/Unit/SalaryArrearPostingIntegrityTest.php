<?php

namespace Tests\Unit;

use App\Services\SalaryArrearPostingService;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SalaryArrearPostingIntegrityTest extends TestCase
{
    private static function sourceLine(int $id = 5, float $amount = 925.00): object
    {
        return (object) [
            'id' => $id,
            'year' => 2026,
            'month' => 7,
            'code' => 'additional_gpf',
            'title' => 'Additional GPF Contribution',
            'direction' => 'deduction',
            'amount' => $amount,
            'source_reference' => 'approved_salary_generates#50',
        ];
    }

    private static function calculatedLine(): array
    {
        return [
            'year' => 2026,
            'month' => 7,
            'code' => 'additional_gpf',
            'title' => 'Additional GPF Contribution',
            'direction' => 'deduction',
            'amount' => 925.00,
            'source_reference' => 'approved_salary_generates#50',
        ];
    }

    private static function billItem(int $id = 5): array
    {
        return [
            'arrear_snapshot_id' => 7,
            'arrear_line_id' => $id,
            'arrear_code' => 'additional_gpf',
            'name' => 'Additional GPF Contribution',
            'arrear_entitlement_month' => '2026-07',
            'calculated_amount' => 925.00,
        ];
    }

    public function test_exact_approved_arrear_lines_are_accepted(): void
    {
        SalaryArrearPostingService::assertSnapshotLinesMatch(
            [self::sourceLine()], [self::calculatedLine()]
        );
        $this->assertTrue(true);
    }

    public function test_changed_gpf_line_with_same_total_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        SalaryArrearPostingService::assertSnapshotLinesMatch(
            [self::sourceLine(5, 900.00)], [self::calculatedLine()]
        );
    }

    public function test_changed_arrear_line_period_is_rejected(): void
    {
        $line = self::sourceLine();
        $line->month = 8;
        $this->expectException(DomainException::class);
        SalaryArrearPostingService::assertSnapshotLinesMatch([$line], [self::calculatedLine()]);
    }

    public function test_missing_approved_line_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        SalaryArrearPostingService::assertSnapshotLinesMatch([], [self::calculatedLine()]);
    }

    public function test_intact_posted_bill_components_are_accepted(): void
    {
        SalaryArrearPostingService::assertPostedBillItems(
            [self::sourceLine()], [], [self::billItem()], 7
        );
        $this->assertTrue(true);
    }

    public function test_posted_bill_missing_a_line_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        SalaryArrearPostingService::assertPostedBillItems([self::sourceLine()], [], [], 7);
    }

    public function test_posted_bill_duplicate_line_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        SalaryArrearPostingService::assertPostedBillItems(
            [self::sourceLine()], [], [self::billItem(), self::billItem()], 7
        );
    }

    public function test_posted_bill_modified_amount_is_rejected(): void
    {
        $item = self::billItem();
        $item['calculated_amount'] = 926;
        $this->expectException(DomainException::class);
        SalaryArrearPostingService::assertPostedBillItems([self::sourceLine()], [], [$item], 7);
    }

    public function test_zero_amount_approved_lines_do_not_require_salary_items(): void
    {
        SalaryArrearPostingService::assertPostedBillItems([self::sourceLine(5, 0)], [], [], 7);
        $this->assertTrue(true);
    }
}
