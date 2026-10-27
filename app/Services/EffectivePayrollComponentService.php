<?php

namespace App\Services;

use App\Models\EmployeeInfo;
use App\Models\Grade;
use App\Models\PayScale;
use App\Models\PayrollComponent;
use App\Models\PayrollGroup;
use App\Models\SalaryFixationPaySchedule;
use DomainException;

/** Resolve approved new-scale configuration without ever copying historical fixed rates. */
final class EffectivePayrollComponentService
{
    public function forApproved2026Scale(EmployeeInfo $employee, SalaryFixationPaySchedule $schedule): array
    {
        $oldGroup = $employee->payrollGroup;
        $oldGrade = $oldGroup?->grade;
        if (!$oldGroup || !$oldGrade || !$oldGroup->payroll_group_type_id) {
            throw new DomainException('Employee grade/designation is missing.');
        }
        if (!preg_match('/^Grade\s+(\d+)$/i', trim((string) $oldGrade->grade_name), $gradeMatch)
            || (int) $gradeMatch[1] !== (int) $schedule->grade_number) {
            throw new DomainException('Approved fixation grade differs from the employee grade.');
        }
        if ((float) $schedule->payable_basic <= 0) {
            throw new DomainException('Approved fixation payable basic must be positive.');
        }

        $scale = PayScale::where('scale_name', 'Govt. Pay Scale 2026')->first();
        if (!$scale) {
            throw new DomainException('Govt. Pay Scale 2026 is missing.');
        }
        $grade = Grade::where('pay_scale_id', $scale->id)
            ->where('grade_name', $oldGrade->grade_name)->first();
        if (!$grade) {
            throw new DomainException('Matching 2026 grade is missing.');
        }
        $group = PayrollGroup::where('grade_id', $grade->id)
            ->where('payroll_group_type_id', $oldGroup->payroll_group_type_id)->first();
        if (!$group) {
            throw new DomainException('2026 designation-to-grade mapping is missing.');
        }
        if (!$scale->isActive || !$grade->isActive || !$group->isActive) {
            throw new DomainException('The 2026 payroll configuration has not been activated/approved.');
        }

        $components = PayrollComponent::with('payrollComponentType')
            ->where('payroll_group_id', $group->id)->orderBy('id')->get();
        $active = $components->filter(fn ($c) => (bool) $c->isActive);
        if ($active->isEmpty()) {
            throw new DomainException('No approved active 2026 component rules exist; zero-value drafts are not payable rules.');
        }
        $additions = [];
        $deductions = [];
        $basicCount = 0;
        foreach ($active as $component) {
            $name = trim((string) ($component->payrollComponentType?->name ?? ''));
            if ($name === '') {
                throw new DomainException('An active 2026 component has no valid component name.');
            }
            if (PayrollEffectivePolicy::isSpecialBenefit(['name' => $name])) {
                throw new DomainException('Special Benefit/Bonus cannot be active in the 2026 scale.');
            }
            $isBasic = preg_match('/\bbasic(?:\s+pay|\s+salary)?\b/i', $name) === 1;
            if ($isBasic) {
                $basicCount++;
                if ($component->component_type !== 'allowance') {
                    throw new DomainException('2026 Basic Pay must be an allowance.');
                }
            } elseif ((float) $component->amount <= 0) {
                // All Step 1 templates start at zero. Zero is not a certified rate.
                throw new DomainException('Active 2026 component has an unconfigured/zero rate: '.$name);
            }
            $item = [
                'name' => $name,
                'amount' => $isBasic ? (float) $schedule->payable_basic : (float) $component->amount,
                'calcType' => $isBasic ? 'fixed' : $component->calcType,
                'component_type' => $component->component_type,
                'payroll_component_type_id' => $component->payroll_component_type_id,
                'payroll_group_id' => $group->id,
            ];
            if ($component->component_type === 'allowance') {
                $additions[] = $item;
            } else {
                $deductions[] = $item;
            }
        }
        if ($basicCount !== 1) {
            throw new DomainException('2026 configuration requires exactly one active Basic Pay component.');
        }
        return ['additions' => $additions, 'deductions' => $deductions,
            'pay_scale' => $scale->scale_name, 'grade' => $grade->grade_name];
    }
}
