<?php

namespace App\Services;

use DomainException;

/**
 * Non-mutating bridge for Phase 2. Keeps an employee's configured fixed amounts
 * and percentage rates, replaces ONLY approved effective Basic, and uses the
 * new Basic for existing percentage calculations. Never applies unconfigured
 * inactive/zero 2026 templates or writes to employee_infos.
 */
final class ApprovedFixationComponentProjector
{
    public static function project(array $additions, array $deductions, array $fixation): array
    {
        if (!is_numeric($fixation['basic'] ?? null) || (float) $fixation['basic'] <= 0) {
            throw new DomainException('Approved fixation Basic must be positive.');
        }
        // Approved fixation can supply the authoritative Basic even when a legacy
        // component blob is absent. Other allowances must not be invented.
        $basicCount = 0;
        $revised = [];
        foreach ($additions as $item) {
            if (!is_array($item)) throw new DomainException('Invalid configured salary allowance.');
            $name = trim((string) ($item['name'] ?? $item['payroll_component_type']['name'] ?? ''));
            if (self::isBasic($name)) {
                $basicCount++;
                $item['amount'] = (float) $fixation['basic'];
                $item['calcType'] = 'fixed';
                $item['name'] = $name ?: 'Basic Pay';
                $item['approved_fixation_id'] = $fixation['salary_fixation_id'] ?? null;
            }
            // Revised 2026 Basic replaces 2015 Special Benefit: historical
            // Benefit is a separate arrear-recovery component, not revised pay.
            if (!PayrollEffectivePolicy::isSpecialBenefit($item)) $revised[] = $item;
        }
        if ($basicCount > 1) throw new DomainException('Multiple Basic Pay components need HR reconciliation.');
        if ($basicCount === 0) {
            $revised[] = ['name' => 'Basic Pay', 'amount' => (float) $fixation['basic'],
                'calcType' => 'fixed', 'component_type' => 'allowance',
                'approved_fixation_id' => $fixation['salary_fixation_id'] ?? null];
        }
        // All legacy deductions, including employee-specific GPF subscriptions,
        // are preserved in Phase 2. New default GPF rounding is Phase 3.
        $deductions = array_values($deductions);
        PayrollPreviewCalculator::calculate($revised, $deductions);
        return [
            'additions' => array_values($revised),
            'deductions' => $deductions,
            'pay_scale' => $fixation['pay_scale'],
            'grade' => $fixation['grade'],
            'basic' => (float) $fixation['basic'],
            'source' => 'approved_fixation',
            'fixation' => $fixation,
        ];
    }

    private static function isBasic(string $name): bool
    {
        return preg_match('/\bbasic(?:\s+pay|\s+salary)?\b/i', $name) === 1;
    }
}
