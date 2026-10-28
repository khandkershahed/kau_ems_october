<?php

namespace App\Services;

use App\Support\SalaryWorkflowTransitions as State;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Initializes canonical workflow rows without mutating payroll's financial source tables. */
final class SalaryWorkflowRosterService
{
    public function reconcile(int $year, int $month, ?array $userIds = null, int $limit = 500): array
    {
        if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12 || $limit < 1 || $limit > 1000) {
            throw new DomainException('Invalid salary period or batch limit.');
        }
        if ($userIds !== null && (count($userIds) > 1000 || count($userIds) !== count(array_unique($userIds)))) {
            throw new DomainException('Select at most 1000 distinct employees.');
        }
        $users = DB::table('employee_infos')->select('user_id')->distinct()->orderBy('user_id');
        if ($userIds !== null) {
            if (!$userIds) return ['examined' => 0, 'initialized' => 0, 'existing' => 0, 'ambiguous' => 0, 'posted' => 0, 'blocked' => 0];
            foreach ($userIds as $id) if (!is_int($id) || $id < 1) throw new DomainException('Invalid employee identifier.');
            $users->whereIn('user_id', $userIds);
        }
        $ids = $users->limit($limit)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $stats = ['examined' => 0, 'initialized' => 0, 'existing' => 0, 'ambiguous' => 0, 'posted' => 0, 'blocked' => 0];
        foreach ($ids as $userId) {
            DB::transaction(function () use ($userId, $year, $month, &$stats): void {
                $stats['examined']++;
                // Insert without replacing anything; unique user+period index handles concurrent roster builders.
                $profileCount = DB::table('employee_infos')->where('user_id', $userId)->count();
                $billIds = DB::table('salary_generates')->where('user_id', $userId)
                    ->where('salaryGenerationYear', $year)->where('monthId', $month)
                    ->orderBy('id')->limit(2)->pluck('id');
                $existing = DB::table('salary_monthly_workflows')->where('user_id', $userId)
                    ->where('salary_year', $year)->where('salary_month', $month)->lockForUpdate()->first();
                if ($existing) {
                    $stats['existing']++;
                    // No status inferred or financial data overwritten on repeated runs.
                    if ($profileCount !== 1 || $billIds->count() > 1 ||
                        ($billIds->count() === 1 && ($existing->workflow_status !== State::GENERATED ||
                          (int) $existing->posted_salary_generate_id !== (int) $billIds->first()))) {
                        $stats['ambiguous']++;
                    }
                    return;
                }
                $ambiguous = $profileCount !== 1 || $billIds->count() > 1;
                $posted = !$ambiguous && $billIds->count() === 1;
                $reasons = $ambiguous ? ['Ambiguous employee profile or duplicate existing monthly bills: manual reconciliation required.']
                    : ($posted ? null : ['Awaiting verified calculation and HR approval.']);
                $inserted = DB::table('salary_monthly_workflows')->insertOrIgnore([
                    'user_id' => $userId, 'salary_year' => $year, 'salary_month' => $month,
                    'workflow_status' => $posted ? State::GENERATED : State::BLOCKED,
                    'posted_salary_generate_id' => $posted ? (int) $billIds->first() : null,
                    'blocking_reasons' => $reasons ? json_encode($reasons, JSON_THROW_ON_ERROR) : null,
                    'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                if (!$inserted) { $stats['existing']++; return; }
                $stats['initialized']++;
                if ($ambiguous) $stats['ambiguous']++;
                elseif ($posted) $stats['posted']++;
                else $stats['blocked']++;
                $workflowId = DB::table('salary_monthly_workflows')->where('user_id', $userId)
                    ->where('salary_year', $year)->where('salary_month', $month)->value('id');
                DB::table('salary_workflow_events')->insert([
                    'salary_monthly_workflow_id' => $workflowId, 'user_id' => $userId,
                    'salary_year' => $year, 'salary_month' => $month,
                    'action' => $posted ? 'reconcile_existing_bill' : ($ambiguous ? 'reconcile_ambiguous' : 'initialize_blocked'),
                    'from_status' => 'UNINITIALIZED', 'to_status' => $posted ? State::GENERATED : State::BLOCKED,
                    'actor_id' => null, 'reason' => $posted ? 'Existing posted bill imported as immutable evidence.' :
                        ($ambiguous ? 'Ambiguous evidence; manual review required.' : 'Canonical roster initialized.'),
                    'before_hash' => null, 'after_hash' => null, 'revision' => 1, 'created_at' => now(),
                ]);
            }, 3);
        }
        return $stats;
    }
}
