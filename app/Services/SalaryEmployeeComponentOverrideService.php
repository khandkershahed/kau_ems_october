<?php

namespace App\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Monthly employee component replacement rules; never changes employee setup JSON or ledgers. */
final class SalaryEmployeeComponentOverrideService
{
    public static function key(?int $typeId, string $name, bool $custom = false): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $name));
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
        if ($normalized === '') throw new DomainException('Component name is required.');
        if ($custom) return 'custom:'.hash('sha256', $normalized);
        return $typeId && $typeId > 0 ? 'type:'.$typeId : 'legacy:'.hash('sha256', $normalized);
    }

    public function rows(int $userId, int $year, int $month): array
    {
        if (!Schema::hasTable('salary_component_employee_overrides')) return [];
        return DB::table('salary_component_employee_overrides')
            ->where('user_id', $userId)->where('salary_year', $year)->where('salary_month', $month)
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function signature(int $userId, int $year, int $month): string
    {
        $states = array_map(static fn (array $r) => [
            $r['component_key'], $r['direction'], $r['mode'], $r['calc_type'],
            (string) $r['value'], $r['basis'], (string) $r['legacy_basic_reference'],
            $r['component_title'], (int) $r['revision'],
        ], $this->rows($userId, $year, $month));
        return hash('sha256', json_encode($states, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Guard the posted calculation snapshot from bypassing monthly overrides. */
    public static function assertSnapshotMatches(array $add, array $ded, array $overrides): void
    {
        foreach ($overrides as $o) {
            $o=(array)$o;
            $direction=(string) $o['direction'];
            $rows=$direction==='addition'?$add:$ded;
            $key=(string)$o['component_key'];
            $found=[];
            foreach ($rows as $r) {
                if (!is_array($r) || isset($r['manual_adjustment_id'])) continue;
                $id=(int)($r['payroll_component_type_id']??$r['payroll_component_type']['id']??0);
                $rName=(string)($r['name']??'');
                if ($key===self::key($id?:null,$rName) ||
                    ($key===($r['override_component_key']??null))) $found[]=$r;
            }
            if ($o['mode']==='disabled') {
                if ($found) throw new DomainException('Disabled override component was re-added to the salary snapshot: '.$o['component_title']);
                continue;
            }
            if (count($found)!==1) throw new DomainException('Monthly component override was omitted or duplicated: '.$o['component_title']);
            $r=$found[0];
            if (strtolower((string)($r['calcType']??'')) !== $o['calc_type']
                || abs((float)($r['amount']??-1)-(float)$o['value'])>.009
                || (string)($r['component_source']??'')!=='employee_month_override') {
                throw new DomainException('Salary snapshot does not match employee component override: '.$o['component_title']);
            }
            if ($o['calc_type']==='percentage' && $o['basis']==='legacy_basic'
                && abs((float)($r['percentage_base_amount']??-1)-(float)$o['legacy_basic_reference'])>.009) {
                throw new DomainException('Legacy Basic reference changed for '.$o['component_title']);
            }
        }
    }

    public static function basic(string $name): bool
    {
        return preg_match('/\bbasic(?:\s+pay|\s+salary)?\b/i', $name) === 1;
    }

    /** Pure resolver, called after the Step 02 published-rule resolver. */
    public static function apply(array $additions, array $deductions, array $overrides): array
    {
        foreach ($overrides as $override) {
            $o = (array) $override;
            $direction = (string) ($o['direction'] ?? '');
            if (!in_array($direction, ['addition', 'deduction'], true)) throw new DomainException('Invalid override direction.');
            if ($direction === 'addition') $target =& $additions;
            else $target =& $deductions;
            $key = (string) ($o['component_key'] ?? '');
            $matches = [];
            foreach ($target as $i => $row) {
                if (isset($row['manual_adjustment_id'])) continue;
                $typeId = (int) ($row['payroll_component_type_id'] ?? $row['payroll_component_type']['id'] ?? 0);
                $rowName = (string) ($row['name'] ?? '');
                if ($key === self::key($typeId ?: null, $rowName)) $matches[] = $i;
            }
            if (count($matches) > 1) throw new DomainException('Duplicate component rows for employee override: '.$o['component_title']);
            $isCustom = str_starts_with($key, 'custom:');
            if (!$matches && !$isCustom) throw new DomainException('Override component is no longer configured: '.$o['component_title']);
            if ($isCustom && $matches) throw new DomainException('Custom component collides with a standard component.');
            $row = $matches ? $target[$matches[0]] : [];
            $name = trim((string) ($o['component_title'] ?? ''));
            if (self::basic($name) || ($matches && self::basic((string) ($row['name'] ?? '')))) {
                throw new DomainException('Basic Pay is authoritative and cannot be overridden here.');
            }
            if (($o['mode'] ?? null) === 'disabled') {
                if ($matches) array_splice($target, $matches[0], 1);
                unset($target);
                continue;
            }
            if (($o['mode'] ?? null) !== 'manual') throw new DomainException('Invalid override mode.');
            $type = (string) ($o['calc_type'] ?? '');
            $value = $o['value'] ?? null;
            $basis = (string) ($o['basis'] ?? '');
            if (!in_array($type, ['fixed', 'percentage'], true) || !is_numeric($value)
                || !is_finite((float) $value) || (float) $value < 0
                || ($type === 'percentage' && ((float) $value > 100 || !in_array($basis, ['payable_basic','legacy_basic'], true)))
                || ($type === 'fixed' && $basis !== 'none')) {
                throw new DomainException('Invalid component override: '.$name);
            }
            if ($type === 'percentage' && $basis === 'legacy_basic'
                && (!is_numeric($o['legacy_basic_reference'] ?? null) || (float) $o['legacy_basic_reference'] <= 0)) {
                throw new DomainException('Legacy Basic requires an explicit verified reference.');
            }
            $new = array_merge($row, [
                'name'=>$name, 'payroll_component_type_id'=>$o['payroll_component_type_id'] ?? null,
                'calcType'=>$type, 'amount'=>(float) $value,
                'component_type'=>$direction === 'addition' ? 'allowance' : 'deduction',
                'component_source'=>'employee_month_override', 'employee_override_id'=>(int) ($o['id'] ?? 0),
                'override_component_key'=>$key, 'effective_rule_basis'=>$basis,
                'override_reason'=>$o['reason'] ?? null, 'override_evidence_reference'=>$o['evidence_reference'] ?? null,
            ]);
            unset($new['percentage_base_amount']);
            if ($type === 'percentage' && $basis === 'legacy_basic') {
                $new['percentage_base_amount'] = (float) $o['legacy_basic_reference'];
            }
            if ($matches) $target[$matches[0]] = $new;
            else $target[] = $new;
            unset($target);
        }
        return [array_values($additions), array_values($deductions)];
    }
}
