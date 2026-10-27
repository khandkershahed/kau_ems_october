<?php

namespace App\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Approved calculation versions are read-only; drafts never alter estimation. */
final class ApprovedSalaryCalculationVersionResolver
{
    public function forUser(int $userId, int $year, int $month, ?array $fixation): ?array
    {
        if (!Schema::hasTable('salary_calculation_versions')) return null;
        $row = DB::table('salary_calculation_versions')->where('user_id', $userId)
            ->where('salary_year', $year)->where('salary_month', $month)
            ->where('status', 'approved')->orderByDesc('version')->first();
        if (!$row) return null;
        $overrides = app(SalaryEmployeeComponentOverrideService::class);
        if (($overrides->rows($userId,$year,$month) || ($row->component_override_hash ?? null) !== null)
            && !hash_equals($overrides->signature($userId,$year,$month), (string)($row->component_override_hash ?? ''))) {
            return null; // The approved calculation no longer represents current employee overrides.
        }
        if ((int) ($row->fixation_id ?? 0) !== (int) ($fixation['salary_fixation_id'] ?? 0)) {
            // A new fixation supersedes a previously approved workspace.
            return null;
        }
        $adds = json_decode($row->additions, true);
        $ded = json_decode($row->deductions, true);
        $stored = json_decode($row->calculated_result, true);
        $gpfOverride = $row->gpf_override ? json_decode($row->gpf_override, true) : null;
        if (!is_array($adds) || !is_array($ded) || !is_array($stored)) {
            throw new DomainException('Saved approved salary calculation snapshot is malformed.');
        }
        $calc = app(SalaryCalculationService::class)->calculate($adds, $ded, null, $gpfOverride);
        foreach (['basic', 'total_allowance', 'total_deduction', 'gpf_deduction', 'net_salary'] as $key) {
            if (abs((float) ($stored[$key] ?? -1) - (float) $calc[$key]) > 0.009) {
                throw new DomainException('Approved salary calculation snapshot amount mismatch: '.$key);
            }
        }
        return ['financial' => $calc, 'version' => (int) $row->version,
            'snapshot_id' => (int) $row->id, 'snapshot_hash' => $row->snapshot_hash];
    }
}
