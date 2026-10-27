<?php

namespace App\Services;

use App\Models\EmployeeInfo;
use App\Models\PayrollComponent;
use DomainException;

/** Side-effect-free view of payroll-group defaults prior to explicit overwrite. */
final class SalaryDefaultPreviewService
{
    public static function preview(EmployeeInfo $employee): array
    {
        if (!$employee->payroll_group_id) {
            throw new DomainException('Employee payroll group is missing.');
        }
        $components = PayrollComponent::with('payrollComponentType')
            ->where('payroll_group_id', $employee->payroll_group_id)
            ->orderBy('id')->get();
        $adds = [];
        $ded = [];
        $existing = array_merge(is_array($employee->additionObjects) ? $employee->additionObjects : [],
            is_array($employee->substractionObjects) ? $employee->substractionObjects : []);
        $warnings = [];
        foreach ($components as $component) {
            $item = $component->toArray();
            $item['name'] = $component->payrollComponentType?->name ?? $item['name'] ?? '';
            $item['calcType'] = $component->calcType ?: 'fixed';
            $item['amount'] = (float) $component->amount;
            if ($item['amount'] == 0.0) {
                $existingMatch = null;
                foreach ($existing as $previous) {
                    $previousName = trim((string) ($previous['name'] ?? $previous['payroll_component_type']['name'] ?? ''));
                    if (strcasecmp($previousName, (string) $item['name']) === 0
                        && is_numeric($previous['amount'] ?? null) && (float) $previous['amount'] > 0) {
                        $existingMatch = $previous;
                        break;
                    }
                }
                if ($existingMatch !== null) {
                    // A zero draft/group template cannot silently remove a
                    // previously configured employee salary component.
                    $item['amount'] = (float) $existingMatch['amount'];
                    $item['calcType'] = strtolower((string) ($existingMatch['calcType'] ?? 'fixed'));
                    $item['reference_source'] = 'employee_existing_nonzero_config';
                    $warnings[] = $item['name'].' uses the currently saved employee rate because its group default is zero.';
                }
            }
            if ($component->component_type === 'allowance') $adds[] = $item;
            elseif ($component->component_type === 'deduction') $ded[] = $item;
        }
        if (!$adds) throw new DomainException('Payroll group has no configured allowance components.');
        $financial = PayrollPreviewCalculator::calculate($adds, $ded);
        return ['additions' => $adds, 'deductions' => $ded,
            'financial' => $financial, 'warnings' => $warnings];
    }
}
