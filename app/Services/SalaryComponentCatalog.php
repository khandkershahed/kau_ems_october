<?php

namespace App\Services;

use App\Models\EmployeeInfo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Merges the employee's recorded components with explicitly published catalog defaults. */
final class SalaryComponentCatalog
{
    public function forEmployee(EmployeeInfo $employee, array $additions, array $deductions, ?int $year = null, ?int $month = null, ?array $fixation = null): array
    {
        if (!Schema::hasTable('salary_component_publications')) return $this->applyEffective($employee, $additions, $deductions, $year, $month, $fixation);
        $pubs = DB::table('salary_component_publications')->get();
        if (!$pubs->count()) return $this->applyEffective($employee, $additions, $deductions, $year, $month, $fixation);
        $types = DB::table('payroll_component_types')->whereIn('id', $pubs->pluck('payroll_component_type_id'))
            ->pluck('name', 'id');
        $groupDefaults = DB::table('payroll_components')->where('payroll_group_id', $employee->payroll_group_id)
            ->whereIn('payroll_component_type_id', $pubs->pluck('payroll_component_type_id'))->where('isActive', true)->get()
            ->keyBy('payroll_component_type_id');
        foreach ($pubs as $pub) {
            $name = (string)($types[$pub->payroll_component_type_id] ?? '');
            if ($name === '') continue;
            $basic = preg_match('/\bbasic(?:\s+pay)?\b/i', $name);
            if ($pub->direction === 'deduction') $target =& $deductions;
            else $target =& $additions;
            // Existing saved component rows can be suppressed in NEW projections,
            // but no legacy employee setup JSON or historical bill is edited.
            if (!$pub->published && !$basic) {
                $target = array_values(array_filter($target, static fn ($row) =>
                    (int)($row['payroll_component_type_id'] ?? $row['payroll_component_type']['id'] ?? -1)
                        !== (int)$pub->payroll_component_type_id &&
                    strcasecmp((string)($row['name'] ?? ''), $name) !== 0));
                unset($target);
                continue;
            }
            $found = false;
            foreach ($target as $row) {
                if ((int)($row['payroll_component_type_id'] ?? $row['payroll_component_type']['id'] ?? -1) === (int)$pub->payroll_component_type_id
                    || strcasecmp((string)($row['name'] ?? ''), $name) === 0) { $found = true; break; }
            }
            if (!$found && $pub->published) {
                $original = $groupDefaults[$pub->payroll_component_type_id] ?? null;
                $rate = $pub->default_amount ?? $original?->amount;
                $type = $pub->default_amount !== null ? $pub->default_calc_type : ($original?->calcType ?? 'fixed');
                if ($rate === null || !is_numeric($rate)) { unset($target); continue; }
                $target[] = ['name'=>$name, 'calcType'=>strtolower((string)$type),
                    'amount'=>(float)$rate, 'payroll_component_type_id'=>(int)$pub->payroll_component_type_id,
                    'catalog_published'=>true, 'component_type'=>$pub->direction === 'deduction' ? 'deduction' : 'allowance'];
            }
            unset($target);
        }
        return $this->applyEffective($employee, array_values($additions), array_values($deductions), $year, $month, $fixation);
    }

    private function applyEffective(EmployeeInfo $employee, array $adds, array $deds,
        ?int $year, ?int $month, ?array $fixation): array
    {
        // Old callers retain byte-for-byte equivalent behavior without a period.
        if ($year === null || $month === null) return [$adds, $deds];
        [$adds, $deds] = app(SalaryEffectiveComponentResolver::class)
            ->resolve($employee, $adds, $deds, $year, $month, $fixation);
        return SalaryEmployeeComponentOverrideService::apply(
            $adds, $deds,
            app(SalaryEmployeeComponentOverrideService::class)->rows((int)$employee->user_id, $year, $month)
        );
    }

}
