<?php

namespace App\Services;

use App\Support\SalaryWorkflowTransitions as State;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Foundation service: does not calculate, create, update or delete a salary bill. */
final class SalaryWorkflowService
{
    public function transition(
        int $userId,
        int $year,
        int $month,
        string $action,
        int $actorId,
        string $reason,
        ?int $calculationVersionId = null,
        ?int $postedBillId = null,
        ?string $postingError = null,
        ?int $expectedRevision = null,
    ): object {
        if ($userId < 1 || $actorId < 1 || $year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
            throw new DomainException('Invalid employee, actor or salary period.');
        }
        if (trim($reason) === '') {
            throw new DomainException('Audit reason is required.');
        }
        return DB::transaction(function () use ($userId, $year, $month, $action, $actorId, $reason, $calculationVersionId, $postedBillId, $postingError, $expectedRevision) {
            $row = DB::table('salary_monthly_workflows')->where('user_id', $userId)
                ->where('salary_year', $year)->where('salary_month', $month)
                ->lockForUpdate()->first();
            if (!$row) {
                throw new DomainException('Payroll period workflow must be initialized and reconciled before transition.');
            }
            if ($expectedRevision !== null && (int)$row->revision !== $expectedRevision) {
                throw new DomainException('Workflow revision conflict. Refresh before taking action.');
            }
            if (DB::table('employee_infos')->where('user_id', $userId)->count() !== 1) {
                throw new DomainException('Ambiguous employee profile; resolve duplicate profiles before approval.');
            }
            $actualBill = DB::table('salary_generates')->where('user_id', $userId)
                ->where('salaryGenerationYear', $year)->where('monthId', $month)->limit(2)->get(['id']);
            if ($actualBill->count() > 1) {
                throw new DomainException('Multiple existing salary bills require financial reconciliation.');
            }
            if ($actualBill->isNotEmpty() && $row->workflow_status !== State::GENERATED) {
                throw new DomainException('Posted salary bill exists; reconcile workflow without overwriting the bill.');
            }
            $target = State::target($row->workflow_status, $action);
            if ($action === 'approve_calculation' && !$calculationVersionId) {
                throw new DomainException('Saved approved calculation is required.');
            }
            $values = ['workflow_status' => $target, 'revision' => (int) $row->revision + 1, 'updated_at' => now()];
            $beforeHash = $row->ready_snapshot_hash;
            $afterHash = $beforeHash;
            if ($action === 'approve_calculation' || $action === 'approve_for_generation') {
                if (!$calculationVersionId) throw new DomainException('Saved calculation version required for approval.');
                $version = DB::table('salary_calculation_versions')->where('id', $calculationVersionId)
                    ->where('user_id', $userId)->where('salary_year', $year)
                    ->where('salary_month', $month)->first();
                $latestVersion = DB::table('salary_calculation_versions')->where('user_id', $userId)
                    ->where('salary_year', $year)->where('salary_month', $month)->max('version');
                if (!$version || $version->status !== 'approved' || (int)$version->version !== (int)$latestVersion
                    || !preg_match('/^[a-fA-F0-9]{64}$/', (string) $version->snapshot_hash)) {
                    throw new DomainException('Approved calculation snapshot is missing or invalid.');
                }
                if ($action === 'approve_calculation') {
                    $financialSourceHash = app(SalaryFinancialSourceFingerprint::class)->forEmployeePeriod($userId, $year, $month);
                    DB::table('salary_workflow_source_signatures')->updateOrInsert(
                        ['salary_monthly_workflow_id'=>$row->id],
                        ['calculation_source_hash'=>$financialSourceHash, 'ready_source_hash'=>null, 'updated_at'=>now(), 'created_at'=>now()]
                    );
                    $values['active_calculation_version_id'] = $calculationVersionId;
                    $values['calculation_approved_by'] = $actorId;
                    $values['calculation_approved_at'] = now();
                    $values['blocking_reasons'] = null;
                } else {
                    app(SalaryFinancialSourceFingerprint::class)->assertNoApprovedDrift($row, $userId, $year, $month);
                    if ((int) $row->active_calculation_version_id !== $calculationVersionId) {
                        throw new DomainException('The calculation version has changed; review is required.');
                    }
                    if (!$row->calculation_approved_by || !$row->calculation_approved_at) {
                        throw new DomainException('First-stage calculation approval is missing.');
                    }
                    DB::table('salary_workflow_source_signatures')->where('salary_monthly_workflow_id',$row->id)->update(['ready_source_hash'=>app(SalaryFinancialSourceFingerprint::class)->forEmployeePeriod($userId,$year,$month),'updated_at'=>now()]);
                    $values['ready_calculation_version_id'] = $calculationVersionId;
                    $values['ready_snapshot_hash'] = $version->snapshot_hash;
                    $values['ready_approved_by'] = $actorId;
                    $values['ready_approved_at'] = now();
                    $afterHash = $version->snapshot_hash;
                }
            }
            if ($action === 'return_for_revision') {
                DB::table('salary_workflow_source_signatures')->where('salary_monthly_workflow_id',$row->id)->update(['ready_source_hash'=>null,'updated_at'=>now()]);
                $values['ready_calculation_version_id'] = null;
                $values['ready_snapshot_hash'] = null;
                $values['ready_approved_by'] = null;
                $values['ready_approved_at'] = null;
                $afterHash = null;
            }
            if ($action === 'posting_failed') {
                if (!is_string($postingError) || trim($postingError) === '') {
                    throw new DomainException('Exact posting failure reason is required.');
                }
                $values['posting_error'] = $postingError;
            }
            if ($action === 'posting_succeeded') {
                if (!$postedBillId || $actualBill->count() !== 1 || (int) $actualBill->first()->id !== $postedBillId) {
                    throw new DomainException('Generated status requires the actual persisted salary bill.');
                }
                if (!$row->ready_calculation_version_id || !$row->ready_snapshot_hash) {
                    throw new DomainException('Ready approval snapshot is missing.');
                }
                $values['posted_salary_generate_id'] = $postedBillId;
                $values['posting_error'] = null;
            }
            DB::table('salary_monthly_workflows')->where('id', $row->id)->update($values);
            DB::table('salary_workflow_events')->insert([
                'salary_monthly_workflow_id' => $row->id, 'user_id' => $userId,
                'salary_year' => $year, 'salary_month' => $month,
                'action' => $action, 'from_status' => $row->workflow_status,
                'to_status' => $target, 'actor_id' => $actorId, 'reason' => $reason,
                'before_hash' => $beforeHash, 'after_hash' => $afterHash,
                'revision' => $values['revision'], 'created_at' => now(),
            ]);
            return DB::table('salary_monthly_workflows')->where('id', $row->id)->first();
        }, 3);
    }
}
