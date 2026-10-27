<?php

namespace App\Services;

use DomainException;

/**
 * Phase 3 side-effect-free calculator. Never writes salary bills or GPF ledger.
 * All monetary figures are rounded at the component boundary to two decimals;
 * default GPF uses HALF UP to the nearest whole taka.
 */
final class SalaryCalculationService
{
    public static function halfUpTaka(float $amount): float
    {
        if (!is_finite($amount) || $amount < 0) throw new DomainException('GPF cannot be negative.');
        return (float) floor($amount + 0.5 + 1e-9);
    }

    public static function isGpf(array $component): bool
    {
        $name = strtolower((string) ($component['name'] ?? $component['payroll_component_type']['name'] ?? ''));
        $type = strtolower((string) ($component['component_type'] ?? ''));
        return str_contains($name, 'gpf') || str_contains($name, 'provident') || str_contains($type, 'provident');
    }

    /**
     * @param array<int,array<string,mixed>> $additions
     * @param array<int,array<string,mixed>> $deductions
     * @param array<string,mixed>|null $fixation ApprovedFixationResolver output
     * @param array<string,mixed>|null $gpfOverride {type: fixed|percentage, amount: number}
     */
    public function calculate(array $additions, array $deductions, ?array $fixation = null, ?array $gpfOverride = null): array
    {
        if ($fixation !== null) {
            $projected = ApprovedFixationComponentProjector::project($additions, $deductions, $fixation);
            $additions = $projected['additions'];
            $deductions = $projected['deductions'];
        }
        $basicRows = array_values(array_filter($additions, static function ($item) {
            if (!is_array($item) || isset($item['manual_adjustment_id'])) return false;
            return preg_match('/\bbasic(?:\s+pay|\s+salary)?\b/i', (string) ($item['name'] ?? $item['payroll_component_type']['name'] ?? '')) === 1;
        }));
        if (count($basicRows) !== 1) throw new DomainException('Exactly one Basic Pay component is required.');
        if (!is_numeric($basicRows[0]['amount'] ?? null)
            || !is_finite((float) $basicRows[0]['amount'])
            || (float) $basicRows[0]['amount'] <= 0
            || strtolower((string) ($basicRows[0]['calcType'] ?? 'fixed')) !== 'fixed') {
            throw new DomainException('Basic Pay must be a positive fixed amount.');
        }
        $basic = (float) $basicRows[0]['amount'];
        $normal = static function (array $item, float $basic, string $direction): array {
            $name = trim((string) ($item['name'] ?? $item['payroll_component_type']['name'] ?? ''));
            $type = strtolower((string) ($item['calcType'] ?? 'fixed'));
            if ($name === '' || !in_array($type, ['fixed', 'percentage'], true)
                || !is_numeric($item['amount'] ?? null)) {
                throw new DomainException('Invalid '.$direction.' component name, type or amount.');
            }
            $amount = (float) $item['amount'];
            if (!is_finite($amount) || $amount < 0 || $amount > 1000000000) {
                throw new DomainException('Invalid '.$direction.' component value for '.$name);
            }
            if ($type === 'percentage' && $amount > 1000) throw new DomainException('Percentage rate is out of range.');
            return array_merge($item, ['name' => $name, 'calcType' => $type,
                'amount' => $amount, 'component_type_original' => $item['component_type'] ?? null, 'component_type' => $direction,
                'calculated_amount' => round($type === 'percentage' ? (isset($item['percentage_base_amount']) ? (float) $item['percentage_base_amount'] : $basic) * $amount / 100 : $amount, 2)]);
        };
        $allowances = [];
        foreach ($additions as $item) {
            if (!is_array($item)) throw new DomainException('Invalid allowance row.');
            $allowances[] = $normal($item, $basic, 'allowance');
        }
        $otherDeductions = [];
        $gpfRows = [];
        foreach ($deductions as $item) {
            if (!is_array($item)) throw new DomainException('Invalid deduction row.');
            if (self::isGpf($item)) $gpfRows[] = $item;
            else $otherDeductions[] = $normal($item, $basic, 'deduction');
        }
        if (count($gpfRows) > 1) throw new DomainException('Duplicate GPF deduction components must be reconciled.');
        $gpfType = 'percentage';
        $gpfAmount = 10.0;
        $gpfSource = 'default_10_percent_half_up';
        // An existing 2015 employee-specific subscription is already a saved
        // override. Do not silently replace it when opening a legacy period.
        // Approved 2026 fixation uses the new 10% default unless HR overrides.
        $hasRevisedBasic = $fixation !== null || !empty($basicRows[0]['approved_fixation_id']);
        if (!$hasRevisedBasic && count($gpfRows) === 1 && $gpfOverride === null
            && ($gpfRows[0]['gpf_calculation_source'] ?? null) !== 'default_10_percent_half_up') {
            $existingType = strtolower((string) ($gpfRows[0]['calcType'] ?? 'fixed'));
            $existingAmount = $gpfRows[0]['amount'] ?? null;
            if (!in_array($existingType, ['fixed', 'percentage'], true) || !is_numeric($existingAmount)) {
                throw new DomainException('Existing GPF subscription is invalid.');
            }
            $gpfType = $existingType;
            $gpfAmount = (float) $existingAmount;
            $gpfSource = 'existing_employee_subscription';
        }
        // An explicitly published, effective-dated GPF rule takes precedence
        // over the old generic 10% display default. Manual override still wins.
        if (count($gpfRows) === 1 && $gpfOverride === null
            && in_array(($gpfRows[0]['component_source'] ?? ''), ['published_effective_rule','employee_month_override'], true)) {
            $gpfType = strtolower((string) ($gpfRows[0]['calcType'] ?? ''));
            $gpfAmount = (float) ($gpfRows[0]['amount'] ?? -1);
            $gpfSource = (string) $gpfRows[0]['component_source'];
            if (!in_array($gpfType, ['fixed', 'percentage'], true)
                || !is_finite($gpfAmount) || $gpfAmount < 0 || ($gpfType === 'percentage' && $gpfAmount > 100)) {
                throw new DomainException('Invalid published GPF rule.');
            }
        }
        if ($gpfOverride !== null && ($gpfOverride['type'] ?? '') === 'disabled') {
            if (count($gpfRows) !== 0) throw new DomainException('Remove configured GPF from component list before disabling it.');
            $gpfType = 'disabled'; $gpfAmount = 0.0; $gpfSource = 'not_configured';
            $gpfOverride = null;
        }
        if ($gpfOverride !== null) {
            $gpfType = strtolower((string) ($gpfOverride['type'] ?? ''));
            $gpfAmount = $gpfOverride['amount'] ?? null;
            if (!in_array($gpfType, ['fixed', 'percentage'], true) || !is_numeric($gpfAmount)
                || !is_finite((float) $gpfAmount) || (float) $gpfAmount < 0
                || (float) $gpfAmount > 1000000000 || ($gpfType === 'percentage' && (float) $gpfAmount > 1000)) {
                throw new DomainException('Invalid employee GPF override.');
            }
            $gpfSource = 'employee_override';
            $gpfAmount = (float) $gpfAmount;
        }
        $gpfBase = in_array($gpfSource, ['published_effective_rule','employee_month_override'], true) && isset($gpfRows[0]['percentage_base_amount'])
            ? (float) $gpfRows[0]['percentage_base_amount'] : $basic;
        $gpfCalculated = self::halfUpTaka($gpfType === 'percentage' ? $gpfBase * $gpfAmount / 100 : $gpfAmount);
        $gpf = array_merge($gpfRows[0] ?? [], ['name' => $gpfRows[0]['name'] ?? 'General Provident Fund (GPF)',
            'calcType' => $gpfType, 'amount' => $gpfAmount, 'component_type' => 'deduction',
            'calculated_amount' => $gpfCalculated, 'gpf_calculation_source' => $gpfSource]);
        $deductionItems = $gpfType === 'disabled' ? $otherDeductions : array_merge($otherDeductions, [$gpf]);
        $gross = round(array_sum(array_column($allowances, 'calculated_amount')), 2);
        $totalDeduction = round(array_sum(array_column($deductionItems, 'calculated_amount')), 2);
        $tax = 0.0;
        foreach ($otherDeductions as $item) {
            if (str_contains(strtolower($item['name']), 'tax') || strtolower((string) ($item['component_type_original'] ?? '')) === 'tax') {
                $tax += (float) $item['calculated_amount'];
            }
        }
        if ($gross < $totalDeduction) throw new DomainException('Salary deductions exceed gross salary.');
        return [
            'basic' => round($basic, 2), 'allowances' => $allowances,
            'deductions' => $deductionItems, 'total_allowance' => $gross,
            'total_deduction' => $totalDeduction, 'gpf_deduction' => $gpfCalculated,
            'tax_deduction' => round($tax, 2), 'net_salary' => round($gross - $totalDeduction, 2),
            'gpf_rule' => ['type' => $gpfType, 'amount' => (float) $gpfAmount, 'source' => $gpfSource],
            'calculation_source' => $fixation ? 'approved_fixation' : 'legacy_employee_setup',
            'fixation_id' => $fixation['salary_fixation_id'] ?? null,
        ];
    }
}
