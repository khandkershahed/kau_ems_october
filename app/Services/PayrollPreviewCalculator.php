<?php

namespace App\Services;

use DomainException;

/** Read-only implementation of the current salary bill's fixed/percentage arithmetic. */
final class PayrollPreviewCalculator
{
    public static function calculate(array $additions, array $deductions): array
    {
        $basicItems = array_values(array_filter($additions, static function ($item): bool {
            if (!is_array($item) || !empty($item['manual_adjustment_id'])) return false;
            $name = strtolower((string) ($item['name'] ?? $item['payroll_component_type']['name'] ?? ''));
            return str_contains($name, 'basic');
        }));
        if (count($basicItems) !== 1) {
            throw new DomainException('Salary setup must contain exactly one non-manual Basic Pay component.');
        }
        $basic = (float) ($basicItems[0]['amount'] ?? 0);
        if ($basic <= 0 || strtolower((string) ($basicItems[0]['calcType'] ?? 'fixed')) !== 'fixed') {
            throw new DomainException('Basic Pay is missing, zero or not a fixed value.');
        }
        $add = self::items($additions, $basic);
        $ded = self::items($deductions, $basic);
        $totalAdd = array_sum(array_column($add, 'calculated_amount'));
        $totalDed = array_sum(array_column($ded, 'calculated_amount'));
        $tax = 0.0;
        $gpf = 0.0;
        foreach ($ded as $item) {
            $name = strtolower((string) ($item['name'] ?? $item['payroll_component_type']['name'] ?? ''));
            $type = strtolower((string) ($item['component_type'] ?? ''));
            if (str_contains($name, 'tax') || $type === 'tax') {
                $tax += $item['calculated_amount'];
            } elseif (str_contains($name, 'gpf') || str_contains($name, 'provident') || str_contains($name, 'pf')
                || str_contains($type, 'provident') || str_contains($name, 'pension')) {
                $gpf += $item['calculated_amount'];
            }
        }
        return [
            'basic' => round($basic, 2),
            'allowances' => $add,
            'deductions' => $ded,
            'total_allowance' => round($totalAdd, 2),
            'total_deduction' => round($totalDed, 2),
            'tax_deduction' => round($tax, 2),
            'gpf_deduction' => round($gpf, 2),
            'net_salary' => round($totalAdd - $totalDed, 2),
        ];
    }

    private static function items(array $source, float $basic): array
    {
        $results = [];
        foreach ($source as $item) {
            if (!is_array($item)) throw new DomainException('Invalid salary component JSON item.');
            $name = trim((string) ($item['name'] ?? $item['payroll_component_type']['name'] ?? ''));
            if ($name === '') throw new DomainException('Salary component has no title.');
            $type = strtolower((string) ($item['calcType'] ?? 'fixed'));
            if (!in_array($type, ['percentage', 'fixed'], true)) throw new DomainException('Invalid calculation type for '.$name);
            if (!is_numeric($item['amount'] ?? null)) throw new DomainException('Missing component amount for '.$name);
            $amount = (float) $item['amount'];
            if (!is_finite($amount) || $amount < 0) throw new DomainException('Invalid component amount for '.$name);
            $item['calculated_amount'] = $type === 'percentage' ? $basic * $amount / 100.0 : $amount;
            $results[] = $item;
        }
        return $results;
    }
}
