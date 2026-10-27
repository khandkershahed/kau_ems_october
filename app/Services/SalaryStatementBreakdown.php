<?php

namespace App\Services;

use DomainException;

/**
 * Read-only statement projection. Does not derive entitlements, post bills,
 * record GPF, or replace the live authoritative SalaryCalculationService.
 * NULL arrear values remain UNKNOWN, never a verified zero.
 */
final class SalaryStatementBreakdown
{
    private static function cents(mixed $number): int
    {
        if (!is_numeric($number) || !is_finite((float) $number) || (float) $number < 0) {
            throw new DomainException('Statement contains invalid monetary amount.');
        }
        return (int) round((float) $number * 100, 0, PHP_ROUND_HALF_UP);
    }

    /** @param array<string,mixed> $financial @param array<int,array<string,mixed>> $arrear */
    public static function build(array $financial, array $arrear): array
    {
        $allowances = [];
        $deductions = [];
        $regularGross = 0;
        $regularDeductions = 0;
        foreach (['allowances' => 'payable', 'deductions' => 'deduction'] as $key => $direction) {
            foreach ($financial[$key] ?? [] as $component) {
                $value = self::cents($component['calculated_amount'] ?? null);
                $row = [
                    'title' => (string) ($component['name'] ?? ''),
                    'direction' => $direction,
                    'current' => $value / 100,
                    'arrear' => 0.0,
                    'total' => $value / 100,
                    'source' => $component['component_source'] ?? $component['gpf_calculation_source'] ?? 'employee_component',
                    'unknown' => false,
                ];
                if (trim($row['title']) === '') throw new DomainException('Salary component title missing.');
                if ($direction === 'payable') { $allowances[] = $row; $regularGross += $value; }
                else { $deductions[] = $row; $regularDeductions += $value; }
            }
        }
        if ($regularGross !== self::cents($financial['total_allowance'] ?? null)
            || $regularDeductions !== self::cents($financial['total_deduction'] ?? null)
            || ($regularGross - $regularDeductions) !== self::cents($financial['net_salary'] ?? null)) {
            throw new DomainException('Statement does not reconcile with authoritative regular calculation.');
        }
        $payableArrear = 0;
        $deductionArrear = 0;
        $unknown = [];
        foreach ($arrear as $row) {
            $direction = $row['direction'] ?? null;
            if (!in_array($direction, ['payable', 'deduction'], true)) throw new DomainException('Invalid arrear direction.');
            $title = trim((string)($row['title'] ?? $row['name'] ?? ''));
            if ($title === '') throw new DomainException('Arrear title is missing.');
            $raw = array_key_exists('final_amount', $row) ? $row['final_amount'] : ($row['calculated_amount'] ?? null);
            $missing = $raw === null || $raw === '';
            $amount = $missing ? null : self::cents($raw);
            $item = [
                'title' => $title,
                'direction' => $direction,
                'current' => 0.0,
                'arrear' => $amount === null ? null : $amount / 100,
                'total' => $amount === null ? null : $amount / 100,
                'source' => $row['code'] ?? $row['rule_id'] ?? 'manual_arrear',
                'unknown' => $missing,
            ];
            if ($missing) $unknown[] = $title;
            if ($direction === 'payable') { $allowances[] = $item; $payableArrear += $amount ?? 0; }
            else { $deductions[] = $item; $deductionArrear += $amount ?? 0; }
        }
        $provisional = count($unknown) > 0;
        $summary = [
            'current' => ['gross' => $regularGross / 100, 'deductions' => $regularDeductions / 100,
                'net' => ($regularGross - $regularDeductions) / 100],
            'arrear' => ['gross' => $provisional ? null : $payableArrear / 100,
                'deductions' => $provisional ? null : $deductionArrear / 100,
                'net' => $provisional ? null : ($payableArrear - $deductionArrear) / 100],
            'total' => ['gross' => $provisional ? null : ($regularGross + $payableArrear) / 100,
                'deductions' => $provisional ? null : ($regularDeductions + $deductionArrear) / 100,
                'net' => $provisional ? null : ($regularGross - $regularDeductions + $payableArrear - $deductionArrear) / 100],
            'provisional_display' => [
                'gross' => ($regularGross + $payableArrear) / 100,
                'deductions' => ($regularDeductions + $deductionArrear) / 100,
                'net' => ($regularGross - $regularDeductions + $payableArrear - $deductionArrear) / 100,
            ],
        ];
        return ['allowances' => $allowances, 'deductions' => $deductions,
            'summary' => $summary, 'provisional' => $provisional, 'unknown_arrears' => $unknown];
    }
}
