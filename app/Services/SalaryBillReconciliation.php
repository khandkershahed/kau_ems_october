<?php

namespace App\Services;

use DomainException;

/**
 * Read-only reconciliation of a saved monthly salary bill.
 * All comparisons use integer paisa; no payroll record is changed.
 */
final class SalaryBillReconciliation
{
    public static function cents(mixed $value): int
    {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new DomainException('Missing or invalid monetary value in salary bill.');
        }
        return (int) round(((float) $value) * 100, 0, PHP_ROUND_HALF_UP);
    }

    /** @return array<string, mixed> */
    public static function verify(object $bill, array $allowances, array $deductions): array
    {
        $issues = [];
        $regularAdd = $arrearAdd = $regularDed = $arrearDed = $regularGpf = $arrearGpf = $tax = 0;
        $arrearSnapshots = [];
        $basicCount = 0;
        $phase5Seen = false;

        foreach (['addition' => $allowances, 'deduction' => $deductions] as $direction => $items) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    $issues[] = 'INVALID_COMPONENT_OBJECT';
                    continue;
                }
                if (!array_key_exists('calculated_amount', $item)) {
                    $issues[] = 'COMPONENT_MISSING_CALCULATED_AMOUNT';
                    continue;
                }
                try {
                    $cents = self::cents($item['calculated_amount']);
                } catch (DomainException) {
                    $issues[] = 'COMPONENT_AMOUNT_INVALID';
                    continue;
                }
                if ($cents < 0) $issues[] = 'NEGATIVE_COMPONENT_AMOUNT';
                $name = strtolower((string) ($item['name'] ?? $item['payroll_component_type']['name'] ?? ''));
                $type = strtolower((string) ($item['component_type'] ?? ''));
                $isPhase5 = !empty($item['arrear_phase5']);
                if ($isPhase5) $phase5Seen = true;
                $arrear = isset($item['arrear_snapshot_id']) || $isPhase5;
                if ($arrear && !$isPhase5) {
                    $snapshotId = (int) $item['arrear_snapshot_id'];
                    if ($snapshotId <= 0 || empty($item['arrear_line_id'])) $issues[] = 'ARREAR_COMPONENT_REFERENCE_MISSING';
                    $arrearSnapshots[$snapshotId] = true;
                }
                if ($direction === 'addition') {
                    if ($arrear) {
                        $arrearAdd += $cents;
                        if (strtolower((string)($item['arrear_code'] ?? '')) === 'additional_gpf') {
                            $arrearGpf -= $cents; // Refund / reversal decreases GPF deduction.
                        }
                    } else $regularAdd += $cents;
                    if (!$arrear && str_contains($name, 'basic')) ++$basicCount;
                } else {
                    if ($arrear) $arrearDed += $cents;
                    else $regularDed += $cents;
                    $gpfComponent = ($arrear && strtolower((string)($item['arrear_code'] ?? '')) === 'additional_gpf')
                        || (!$arrear && (str_contains($name, 'gpf') || str_contains($name, 'provident')
                            || str_contains($name, 'pf') || str_contains($type, 'provident') || str_contains($name, 'pension')));
                    if ($gpfComponent) {
                        if ($arrear) $arrearGpf += $cents;
                        else $regularGpf += $cents;
                    }
                    if (!$arrear && (str_contains($name, 'tax') || $type === 'tax')) $tax += $cents;
                }
            }
        }
        if ($basicCount !== 1) $issues[] = 'BASIC_COMPONENT_NOT_UNIQUE';
        $expectedAllowance = $regularAdd + $arrearAdd;
        $expectedDeduction = $regularDed + $arrearDed;
        $expectedNet = $expectedAllowance - $expectedDeduction;
        foreach (['totalAllowance' => [$expectedAllowance, 'ALLOWANCES_MISMATCH'],
            'totalDeduction' => [$expectedDeduction, 'DEDUCTIONS_MISMATCH'],
            'salaryTotal' => [$expectedNet, 'NET_SALARY_MISMATCH'],
            'gpfDeduction' => [$phase5Seen ? $regularGpf : $regularGpf + $arrearGpf, 'GPF_DEDUCTION_MISMATCH'],
            'taxDeduction' => [$tax, 'TAX_DEDUCTION_MISMATCH']] as $field => [$expected, $issue]) {
            try {
                if (self::cents($bill->{$field} ?? null) !== $expected) $issues[] = $issue;
            } catch (DomainException) {
                $issues[] = $issue;
            }
        }
        if ($expectedNet < 0) $issues[] = 'NEGATIVE_NET_SALARY';

        return [
            'issues' => array_values(array_unique($issues)),
            'regular_gross' => (float) ($regularAdd / 100),
            'regular_deductions' => (float) ($regularDed / 100),
            'regular_net' => (float) (($regularAdd - $regularDed) / 100),
            'arrear_additions' => (float) ($arrearAdd / 100),
            'arrear_deductions' => (float) ($arrearDed / 100),
            'arrear_net' => (float) (($arrearAdd - $arrearDed) / 100),
            'regular_gpf' => (float) ($regularGpf / 100),
            'supplementary_gpf' => (float) ($arrearGpf / 100),
            'combined_net' => (float) ($expectedNet / 100),
            'arrear_snapshot_ids' => array_keys($arrearSnapshots),
        ];
    }
}
