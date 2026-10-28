<?php

namespace App\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Financial input signature only. Never calculates monetary values or updates a bill. */
final class SalaryFinancialSourceFingerprint
{
    public function forEmployeePeriod(int $userId, int $year, int $month): string
    {
        if (!Schema::hasTable('salary_workflow_source_signatures') || !Schema::hasColumn('salary_arrear_rules', 'payroll_group_id')) {
            throw new DomainException('PATCH 04 financial source migration must be applied before HR approval.');
        }
        $profiles = DB::table('employee_infos')->where('user_id', $userId)->limit(2)->get();
        if ($profiles->count() !== 1) throw new DomainException('Employee profile must be unique to fingerprint financial inputs.');
        $groupId = (int) $profiles->first()->payroll_group_id;
        $period = sprintf('%04d-%02d-01', $year, $month);
        $rules = DB::table('salary_arrear_rules')
            ->where('published', 1)->where('archived', 0)->orderBy('id')->get()
            ->filter(static function ($r) use ($userId, $groupId, $period) {
                if ($r->scope === 'specific' && !in_array($userId, array_map('intval', json_decode((string)$r->employee_ids, true) ?: []), true)) return false;
                if ($r->scope === 'group' && (int)$r->payroll_group_id !== $groupId) return false;
                if ($r->scope === 'period') {
                    $payPeriod = substr($period, 0, 7);
                    $selected = json_decode((string)$r->selected_months, true) ?: [];
                    if ($selected && !in_array($payPeriod, $selected, true)) return false;
                    if (!$selected && $r->start_month && $period < substr((string)$r->start_month, 0, 10)) return false;
                    if (!$selected && $r->end_month && $period > substr((string)$r->end_month, 0, 10)) return false;
                }
                return true;
            })->map(static fn ($r) => (array)$r)->values()->all();
        $fixations = Schema::hasTable('salary_fixations') ? DB::table('salary_fixations')->where('user_id', $userId)->orderBy('id')->get()->toArray() : [];
        $overrides = Schema::hasTable('salary_component_employee_overrides') ? DB::table('salary_component_employee_overrides')->where('user_id', $userId)->where('salary_year', $year)->where('salary_month', $month)->orderBy('id')->get()->toArray() : [];
        $publications = DB::table('salary_component_publications')->where('published', 1)->orderBy('id')->get()->toArray();
        $effective = Schema::hasTable('salary_component_effective_rules') ? DB::table('salary_component_effective_rules')->orderBy('id')->get()->toArray() : [];
        return hash('sha256', json_encode([
            'employee_profile' => (array)$profiles->first(), 'fixation' => $fixations,
            'overrides' => $overrides, 'arrear_rules' => $rules,
            'component_publications' => $publications, 'effective_components' => $effective,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function assertNoApprovedDrift(object $workflow, int $userId, int $year, int $month): void
    {
        $signature = DB::table('salary_workflow_source_signatures')->where('salary_monthly_workflow_id', $workflow->id)->first();
        if (!$signature || !hash_equals((string)$signature->calculation_source_hash, $this->forEmployeePeriod($userId, $year, $month))) {
            throw new DomainException('Financial inputs changed since calculation approval. Return to BLOCKED and recalculate before generation approval.');
        }
    }
}
