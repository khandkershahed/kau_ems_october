<?php

namespace App\Services;

use App\Models\EmployeeInfo;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 02: read-only, effective-dated component rule resolver.
 * Does not infer rates from unpublished/zero assignment templates.
 * Does not write payroll, employee JSON, approval, or ledger rows.
 */
final class SalaryEffectiveComponentResolver
{
    /**
     * Fetch only published monthly rules for the employee's designation/grade.
     * Old-scale rules may remain effective while revised non-basic components
     * are deferred (e.g. revised House Rent). The revised group wins only
     * when it has its own date-valid published rule for that component.
     *
     * @return array{0: array, 1: array}
     */
    public function resolve(EmployeeInfo $employee, array $additions, array $deductions,
        int $year, int $month, ?array $fixation = null): array
    {
        $cutoff = ApprovedFixationResolver::cutoff($year, $month);
        if (!Schema::hasTable('salary_component_effective_rules')) {
            return [$additions, $deductions];
        }
        $oldGroup = $employee->payrollGroup;
        if (!$oldGroup || !$oldGroup->grade || !$oldGroup->payroll_group_type_id) {
            return [$additions, $deductions];
        }
        $groupIds = [(int) $oldGroup->id];
        if ($fixation !== null) {
            $grade = trim((string) ($fixation['grade'] ?? ''));
            if ($grade === '' || !is_numeric($fixation['basic'] ?? null)) {
                throw new DomainException('Effective fixation grade/basic is missing.');
            }
            $revisedGroups = DB::table('payroll_groups as pg')
                ->join('grades as g', 'g.id', '=', 'pg.grade_id')
                ->join('pay_scales as ps', 'ps.id', '=', 'g.pay_scale_id')
                ->where('ps.scale_name', 'Govt. Pay Scale 2026')
                ->where('g.grade_name', $grade)
                ->where('pg.payroll_group_type_id', (int) $oldGroup->payroll_group_type_id)
                ->pluck('pg.id')->all();
            if (count($revisedGroups) > 1) {
                throw new DomainException('Ambiguous revised grade/designation payroll group.');
            }
            if ($revisedGroups) {
                array_unshift($groupIds, (int) $revisedGroups[0]);
                $groupIds = array_values(array_unique($groupIds));
            }
        }
        $rules = DB::table('salary_component_effective_rules as er')
            ->join('payroll_component_types as pct', 'pct.id', '=', 'er.payroll_component_type_id')
            ->whereIn('er.payroll_group_id', $groupIds)
            ->where('er.published', true)
            ->where('er.frequency', 'monthly')
            ->whereDate('er.effective_from', '<=', $cutoff)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('er.effective_until')->orWhereDate('er.effective_until', '>=', $cutoff);
            })
            ->select('er.*', 'pct.name as component_name')->get()->all();
        if (!$rules) {
            return [$additions, $deductions];
        }
        $priority = array_flip($groupIds);
        usort($rules, static fn ($a, $b) =>
            ($priority[(int) $a->payroll_group_id] ?? 999) <=> ($priority[(int) $b->payroll_group_id] ?? 999));
        $chosen = [];
        $seen = [];
        foreach ($rules as $rule) {
            $key = (int) $rule->payroll_component_type_id;
            $groupKey = (int) $rule->payroll_group_id.':'.$key;
            if (isset($seen[$groupKey])) {
                throw new DomainException('Overlapping published component rules for '.$rule->component_name);
            }
            $seen[$groupKey] = true;
            if (!isset($chosen[$key])) {
                $chosen[$key] = (array) $rule;
            }
        }
        // The original employee Basic is the only safe legacy-basic reference.
        // It is not evidence of payment; it is used solely as an approved rule base.
        $legacyBasic = null;
        foreach ($additions as $item) {
            if (self::basic((string) ($item['name'] ?? '')) && is_numeric($item['amount'] ?? null)) {
                $legacyBasic = (float) $item['amount'];
                break;
            }
        }
        return self::apply($additions, $deductions, array_values($chosen), $legacyBasic);
    }

    /**
     * Pure transformation for deterministic tests; no database connection.
     * Manual addition/deduction rows with manual_adjustment_id are preserved.
     * Basic remains sourced from approved fixation/employee setup, not a fixed seed.
     *
     * @return array{0: array, 1: array}
     */
    public static function apply(array $additions, array $deductions, array $rules, ?float $legacyBasic): array
    {
        foreach ($rules as $rule) {
            $id = (int) ($rule['payroll_component_type_id'] ?? 0);
            $name = trim((string) ($rule['component_name'] ?? ''));
            $direction = strtolower((string) ($rule['direction'] ?? ''));
            $type = strtolower((string) ($rule['calc_type'] ?? ''));
            $basis = strtolower((string) ($rule['basis'] ?? ''));
            $frequency = strtolower((string) ($rule['frequency'] ?? ''));
            $value = $rule['value'] ?? null;
            if ($id < 1 || $name === '' || !in_array($direction, ['addition', 'deduction'], true)
                || !in_array($type, ['fixed', 'percentage', 'calculated'], true)
                || $frequency !== 'monthly') {
                throw new DomainException('Invalid published monthly component rule.');
            }
            if (self::basic($name)) {
                if ($type !== 'calculated' || $basis !== 'payable_basic') {
                    throw new DomainException('Basic Pay rule must derive from the authoritative fixation.');
                }
                continue;
            }
            if ($type === 'calculated' || !is_numeric($value)
                || !is_finite((float) $value) || (float) $value < 0
                || ($type === 'percentage' && (float) $value > 100)) {
                throw new DomainException('Invalid published rate for '.$name);
            }
            if ($type === 'percentage' && !in_array($basis, ['payable_basic', 'legacy_basic'], true)) {
                throw new DomainException('Unknown percentage calculation basis for '.$name);
            }
            if ($type === 'fixed' && !in_array($basis, ['', 'none'], true)) {
                throw new DomainException('Fixed rule must not define a Basic percentage basis.');
            }
            if ($type === 'percentage' && $basis === 'legacy_basic'
                && ($legacyBasic === null || $legacyBasic <= 0 || !is_finite($legacyBasic))) {
                throw new DomainException('Verified legacy Basic reference missing for '.$name);
            }
            // Do not allow publication to disguise a historical/manual extra.
            if ($direction === 'addition') { $target =& $additions; } else { $target =& $deductions; }
            $matches = [];
            foreach ($target as $index => $item) {
                if (isset($item['manual_adjustment_id'])) continue;
                $rowId = (int) ($item['payroll_component_type_id'] ?? $item['payroll_component_type']['id'] ?? 0);
                if (($rowId > 0 && $rowId === $id)
                    || ($rowId === 0 && strcasecmp(trim((string) ($item['name'] ?? '')), $name) === 0)) {
                    $matches[] = $index;
                }
            }
            if (count($matches) > 1) {
                throw new DomainException('Duplicate employee component requires review: '.$name);
            }
            $new = count($matches) ? $target[$matches[0]] : [];
            $new = array_merge($new, [
                'name' => $name, 'calcType' => $type, 'amount' => (float) $value,
                'component_type' => $direction === 'addition' ? 'allowance' : 'deduction',
                'payroll_component_type_id' => $id,
                'component_source' => 'published_effective_rule',
                'effective_rule_id' => (int) ($rule['id'] ?? 0),
                'effective_rule_basis' => $basis === 'legacy_basic' ? 'legacy_basic' : 'payable_basic',
                'evidence_reference' => (string) ($rule['evidence_reference'] ?? ''),
            ]);
            unset($new['percentage_base_amount']);
            if ($type === 'percentage' && $basis === 'legacy_basic') {
                $new['percentage_base_amount'] = $legacyBasic;
            }
            if ($matches) {
                $target[$matches[0]] = $new;
            } else {
                $target[] = $new;
            }
            unset($target);
        }
        return [array_values($additions), array_values($deductions)];
    }

    private static function basic(string $name): bool
    {
        return preg_match('/\bbasic(?:\s+pay|\s+salary)?\b/i', $name) === 1;
    }
}
