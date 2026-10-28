<?php
namespace App\Services;

use App\Support\SalaryWorkflowTransitions as State;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Must run inside SalaryBillPostingService's employee-locked transaction. */
final class SalaryPostingApprovalGuard
{
    public function assertReady(int $userId, int $year, int $month): object
    {
        foreach (['salary_monthly_workflows', 'salary_calculation_versions', 'salary_workflow_source_signatures'] as $table) {
            if (!Schema::hasTable($table)) throw new DomainException('Payroll approval schema is missing: '.$table);
        }
        $row = DB::table('salary_monthly_workflows')->where('user_id', $userId)
            ->where('salary_year', $year)->where('salary_month', $month)
            ->lockForUpdate()->first();
        if (!$row || $row->workflow_status !== State::READY) {
            throw new DomainException('Salary posting requires READY_FOR_GENERATION approval for this employee and month.');
        }
        if (DB::table('employee_infos')->where('user_id', $userId)->count() !== 1) {
            throw new DomainException('Ambiguous employee profile; reconcile before posting.');
        }
        if (!$row->calculation_approved_by || !$row->calculation_approved_at
            || !$row->ready_approved_by || !$row->ready_approved_at
            || !$row->active_calculation_version_id || !$row->ready_calculation_version_id
            || (int)$row->active_calculation_version_id !== (int)$row->ready_calculation_version_id
            || !preg_match('/^[a-f0-9]{64}$/i', (string)$row->ready_snapshot_hash)) {
            throw new DomainException('Complete two-stage approval and immutable snapshot are required.');
        }
        $version = DB::table('salary_calculation_versions')->where('id', $row->ready_calculation_version_id)
            ->where('user_id', $userId)->where('salary_year', $year)
            ->where('salary_month', $month)->first();
        $latest = DB::table('salary_calculation_versions')->where('user_id', $userId)
            ->where('salary_year', $year)->where('salary_month', $month)->max('version');
        if (!$version || $version->status !== 'approved' || (int)$version->version !== (int)$latest
            || !hash_equals(strtolower((string)$row->ready_snapshot_hash), strtolower((string)$version->snapshot_hash))) {
            throw new DomainException('Salary calculation approval is stale or has been replaced.');
        }
        $signature = DB::table('salary_workflow_source_signatures')
            ->where('salary_monthly_workflow_id', $row->id)->first();
        $live = app(SalaryFinancialSourceFingerprint::class)->forEmployeePeriod($userId, $year, $month);
        if (!$signature || !$signature->calculation_source_hash || !$signature->ready_source_hash
            || !hash_equals((string)$signature->calculation_source_hash, $live)
            || !hash_equals((string)$signature->ready_source_hash, $live)) {
            throw new DomainException('Financial sources changed since approval; return for revision and recalculate.');
        }
        return $row;
    }

    public function recordGenerated(object $row, int $billId, ?int $actorId): void
    {
        // Called before outer posting transaction commits; a failure rolls back bill and GPF.
        $revision = (int)$row->revision + 1;
        $changed = DB::table('salary_monthly_workflows')->where('id', $row->id)
            ->where('workflow_status', State::READY)->where('revision', $row->revision)
            ->update([
                'workflow_status'=>State::GENERATED, 'posted_salary_generate_id'=>$billId,
                'posting_error'=>null, 'revision'=>$revision, 'updated_at'=>now(),
            ]);
        if ($changed !== 1) throw new DomainException('Concurrent approval change detected; payroll posting rolled back.');
        DB::table('salary_workflow_events')->insert([
            'salary_monthly_workflow_id'=>$row->id, 'user_id'=>$row->user_id,
            'salary_year'=>$row->salary_year, 'salary_month'=>$row->salary_month,
            'action'=>'posting_succeeded', 'from_status'=>State::READY, 'to_status'=>State::GENERATED,
            'actor_id'=>$actorId, 'reason'=>'Approved salary bill posted through SalaryBillPostingService',
            'before_hash'=>$row->ready_snapshot_hash, 'after_hash'=>$row->ready_snapshot_hash,
            'revision'=>$revision, 'created_at'=>now(),
        ]);
    }
}
