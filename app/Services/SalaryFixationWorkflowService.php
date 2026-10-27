<?php

namespace App\Services;

use App\Models\SalaryFixation;
use App\Models\SalaryFixationPaySchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single fixation workflow for individual and bulk actions.
 * Approval updates only the current effective-dated schedule, not paid bills.
 * Every change is logged with before/after snapshots in a separate event table.
 */
final class SalaryFixationWorkflowService
{
    public function __construct(private readonly SalaryFixationService $calculator) {}

    public function approveNow(User $employee, int $actorId): SalaryFixation
    {
        return DB::transaction(function () use ($employee, $actorId) {
            // Same employee lock serializes all schedule changes, including bulk actions.
            User::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $data = $this->calculator->prepare($employee);
            $this->assertCalculable($data);
            $active = SalaryFixation::query()->where('user_id', $employee->id)
                ->where('status', 'approved')->orderByDesc('id')->first();
            if ($active && $active->source_sha256 === ($data['source_sha256'] ?? null)
                && SalaryFixationApprovalPolicy::sameCalculation($active->calculation ?? [], $data['calculation'])
                && SalaryFixationPaySchedule::where('user_id', $employee->id)
                    ->where('salary_fixation_id', $active->id)->count() === count(SalaryFixationApprovalPolicy::EFFECTIVE_PHASES)) {
                return $active; // Identical repeated approval is idempotent.
            }
            $record = SalaryFixation::create($this->attributesFor($employee, $actorId, $data));
            return $this->approveLocked($record, $actorId);
        });
    }

    public function approveSaved(SalaryFixation $fixation, int $actorId): SalaryFixation
    {
        return DB::transaction(function () use ($fixation, $actorId) {
            User::query()->whereKey($fixation->user_id)->lockForUpdate()->firstOrFail();
            return $this->approveLocked(SalaryFixation::query()->whereKey($fixation->id)->lockForUpdate()->firstOrFail(), $actorId);
        });
    }

    public function rejectLatest(User $employee, int $actorId, ?string $reason = null): SalaryFixation
    {
        return DB::transaction(function () use ($employee, $actorId, $reason) {
            User::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $record = SalaryFixation::query()->where('user_id', $employee->id)
                ->orderByDesc('id')->lockForUpdate()->first();
            if (!$record) {
                // A valid calculated fixation can be rejected directly, without first saving.
                $data = $this->calculator->prepare($employee);
                $this->assertCalculable($data);
                $record = SalaryFixation::create($this->attributesFor($employee, $actorId, $data));
            }
            $before = $this->scheduleSnapshot($employee->id);
            if ($record->status !== 'rejected' || $before !== []) {
                // Rejection is an explicit employee-level decision: stop the active
                // revised schedule, even when the most recent snapshot was a draft.
                $oldStatus = (string) $record->status;
                SalaryFixationPaySchedule::query()->where('user_id', $employee->id)->delete();
                SalaryFixation::query()->where('user_id', $employee->id)
                    ->where('status', 'approved')->whereKeyNot($record->id)
                    ->update(['status' => 'superseded']);
                $record->update(['status' => 'rejected']);
                $this->logEvent($record, $actorId, 'rejected', $oldStatus,
                    ['reason' => $reason, 'current_schedule_before' => $before,
                     'current_schedule_after' => $this->scheduleSnapshot($employee->id)]);
            }
            return $record->refresh();
        });
    }

    public function createDraft(User $employee, int $actorId): SalaryFixation
    {
        return DB::transaction(function () use ($employee, $actorId) {
            User::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $data = $this->calculator->prepare($employee);
            $this->assertCalculable($data);
            SalaryFixation::query()->where('user_id', $employee->id)
                ->whereIn('status', ['pending_review', 'sent', 'employee_confirmed', 'employee_disputed'])
                ->update(['status' => 'superseded']);
            $record = SalaryFixation::create($this->attributesFor($employee, $actorId, $data));
            $this->logEvent($record, $actorId, 'draft_saved', null, ['source_sha256' => $record->source_sha256]);
            return $record;
        });
    }

    public static function incrementDisplay(array $prepared): array
    {
        $calculation = $prepared['calculation'] ?? [];
        $value = $calculation['increment_2026'] ?? null;
        $category = $prepared['special_eligibility_category'] ?? 'ordinary';
        $manualHold = (bool) ($prepared['hold_increment'] ?? false);
        if (is_numeric($value) && (float) $value > 0) {
            return ['amount' => (float) $value, 'label' => $category === 'study_leave_exception'
                ? 'Study Leave — increment allowed' : 'Increment applicable'];
        }
        $labels = [
            'adhoc' => 'AD HOC — no increment',
            'increment_hold' => 'Increment Hold',
            'study_leave_restricted' => 'Study Leave — no increment',
        ];
        if (isset($labels[$category])) {
            return ['amount' => null, 'label' => $labels[$category]];
        }
        if ($manualHold) {
            return ['amount' => null, 'label' => 'Increment Hold / HR override'];
        }
        if ((int) ($prepared['grade'] ?? 0) === 1) {
            return ['amount' => null, 'label' => 'Grade 1 — no increment'];
        }
        if (($calculation['at_last_stage'] ?? false) === true) {
            return ['amount' => null, 'label' => 'Last pay stage'];
        }
        return ['amount' => null, 'label' => is_numeric($value) ? 'No increment' : 'Not calculated'];
    }

    public function recordAdjustment(User $employee, int $actorId, array $before, array $after): void
    {
        DB::table('salary_fixation_workflow_events')->insert([
            'user_id' => $employee->id,
            'salary_fixation_id' => null,
            'actor_id' => $actorId,
            'action' => 'manual_adjustment_saved',
            'previous_status' => null,
            'details' => json_encode(['old' => $before, 'new' => $after], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => now(),
        ]);
    }

    private function approveLocked(SalaryFixation $record, int $actorId): SalaryFixation
    {
        $this->assertCalculable([
            'grade' => $record->grade_number,
            'calculation' => $record->calculation,
        ]);
        if ($record->status === 'rejected' || $record->status === 'superseded') {
            throw ValidationException::withMessages(['fixation' => 'Select or calculate a new fixation version before approving.']);
        }
        $before = $this->scheduleSnapshot($record->user_id);
        // Historical fixation snapshots remain unchanged. Only current active schedules
        // are repointed; old approvals retain their prepared_data and audit metadata.
        SalaryFixation::query()->where('user_id', $record->user_id)
            ->where('status', 'approved')->whereKeyNot($record->id)->update(['status' => 'superseded']);
        foreach (SalaryFixationApprovalPolicy::EFFECTIVE_PHASES as $date => $key) {
            SalaryFixationPaySchedule::query()->updateOrCreate(
                ['user_id' => $record->user_id, 'effective_date' => $date],
                ['salary_fixation_id' => $record->id,
                 'payable_basic' => $record->calculation[$key],
                 'grade_number' => $record->grade_number]
            );
        }
        $priorStatus = (string) $record->status;
        if ($priorStatus !== 'approved') {
            $record->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now()]);
        }
        $this->logEvent($record, $actorId, 'approved', $priorStatus,
            ['current_schedule_before' => $before,
             'current_schedule_after' => $this->scheduleSnapshot($record->user_id)]);
        return $record->refresh();
    }

    private function assertCalculable(array $data): void
    {
        if (!SalaryFixationApprovalPolicy::isCalculable($data)) {
            throw ValidationException::withMessages(['fixation' => 'A Grade (1–20) and three positive calculated Basic values are required. Enter actual employee values first.']);
        }
    }

    private function attributesFor(User $employee, int $actorId, array $data): array
    {
        return [
            'user_id' => $employee->id,
            'prepared_by' => $actorId,
            'pay_scale_reference' => '2026-SRO-347',
            'effective_date' => '2026-07-01',
            'grade_number' => $data['grade'],
            'source_type' => $data['source_type'] ?? 'manual_hr_fixation',
            'source_record_id' => $data['source_record_id'] ?? null,
            'source_sha256' => $data['source_sha256'] ?? hash('sha256', json_encode($data)),
            'old_basic' => $data['basic'] ?? 0,
            'personal_pay' => $data['personal_pay'] ?? 0,
            'calculation' => $data['calculation'],
            'warnings' => $data['warnings'] ?? [],
            'prepared_data' => $data,
            'status' => 'pending_review',
        ];
    }

    private function scheduleSnapshot(int $userId): array
    {
        return SalaryFixationPaySchedule::query()->where('user_id', $userId)
            ->orderBy('effective_date')->get(['salary_fixation_id', 'effective_date', 'payable_basic', 'grade_number'])
            ->map(fn ($s) => [
                'salary_fixation_id' => (int) $s->salary_fixation_id,
                'effective_date' => $s->effective_date?->format('Y-m-d'),
                'payable_basic' => (string) $s->payable_basic,
                'grade_number' => (int) $s->grade_number,
            ])->all();
    }

    private function logEvent(SalaryFixation $record, int $actorId, string $action, ?string $previousStatus, array $details): void
    {
        DB::table('salary_fixation_workflow_events')->insert([
            'user_id' => $record->user_id,
            'salary_fixation_id' => $record->id,
            'actor_id' => $actorId,
            'action' => $action,
            'previous_status' => $previousStatus,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => now(),
        ]);
    }
}
