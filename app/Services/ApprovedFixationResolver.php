<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\PayScale;
use App\Models\SalaryFixationPaySchedule;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Read-only employee/month resolver. Approved revised pay is effective from its
 * signed schedule, never from the globally active pay-scale flag or a fixed 2027 gate.
 * Does not change employee_infos, pay scales, fixation snapshots or old bills.
 */
final class ApprovedFixationResolver
{
    public const FIRST_REVISED_PHASE = '2026-07-01';

    /** Regular pay-scale activation phase; earlier phases are arrear-only. */
    public const REGULAR_ACTIVATION_PHASE = '2027-07-01';

    /**
     * Pure gate: an approved fixation authorizes regular 2026-scale pay ONLY
     * from the regular activation phase. A July 2026 arrear-phase fixation
     * (effective 2026-07-01) never activates the 2026 scale for regular pay.
     */
    public static function authorizesRegularPhase(?array $fixation): ?array
    {
        if ($fixation === null) return null;
        return (($fixation['effective_date'] ?? '') === self::REGULAR_ACTIVATION_PHASE) ? $fixation : null;
    }

    /** Regular-only resolver: ignores arrear-phase fixations for regular pay. */
    public function forRegular(int $userId, int $year, int $month): ?array
    {
        return self::authorizesRegularPhase($this->forUser($userId, $year, $month));
    }
    public static function cutoff(int $year, int $month): string
    {
        if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
            throw new DomainException('A valid payroll year and month are required.');
        }
        return sprintf('%04d-%02d-01', $year, $month);
    }

    /** @return array<string,mixed>|null */
    public function forUser(int $userId, int $year, int $month): ?array
    {
        $cutoff = self::cutoff($year, $month);
        if ($userId <= 0 || $cutoff < self::FIRST_REVISED_PHASE) {
            return null;
        }
        $schedule = SalaryFixationPaySchedule::query()
            ->with('fixation')
            ->where('user_id', $userId)
            ->whereDate('effective_date', '<=', $cutoff)
            ->whereHas('fixation', fn ($q) => $q->where('status', 'approved'))
            ->orderByDesc('effective_date')->orderByDesc('id')->first();

        return $schedule ? $this->fromSchedule($schedule) : null;
    }

    /** @return array<int,array<string,mixed>> keyed by user ID */
    public function forUsers(array $userIds, int $year, int $month): array
    {
        $cutoff = self::cutoff($year, $month);
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn ($v) => $v > 0)));
        if ($cutoff < self::FIRST_REVISED_PHASE || !$userIds) return [];
        $schedules = SalaryFixationPaySchedule::query()
            ->with('fixation')
            ->whereIn('user_id', $userIds)
            ->whereDate('effective_date', '<=', $cutoff)
            ->whereHas('fixation', fn ($q) => $q->where('status', 'approved'))
            ->orderByDesc('effective_date')->orderByDesc('id')->get();
        $out = [];
        foreach ($schedules as $schedule) {
            $id = (int) $schedule->user_id;
            if (!isset($out[$id])) $out[$id] = $this->fromSchedule($schedule);
        }
        return $out;
    }

    /** Pure, DB-free mapping; additionally validates the saved schedule values. */
    public static function interpret(array $row): array
    {
        $date = (string) ($row['effective_date'] ?? '');
        $grade = (int) ($row['grade_number'] ?? 0);
        $basic = $row['payable_basic'] ?? null;
        if (!in_array($date, ['2026-07-01', '2027-01-01', '2027-07-01'], true)
            || $grade < 1 || $grade > 20 || !is_numeric($basic)
            || !is_finite((float) $basic) || (float) $basic <= 0) {
            throw new DomainException('Approved fixation has an invalid phase, grade or Basic amount.');
        }
        return [
            'source' => 'approved_fixation',
            'pay_scale' => 'Govt. Pay Scale 2026',
            'grade' => 'Grade '.$grade,
            'grade_number' => $grade,
            'basic' => round((float) $basic, 2),
            'effective_date' => $date,
            'salary_fixation_id' => (int) ($row['salary_fixation_id'] ?? 0),
            'pay_schedule_id' => (int) ($row['id'] ?? 0),
            'fixation_version' => (int) ($row['salary_fixation_id'] ?? 0),
        ];
    }

    private function fromSchedule(SalaryFixationPaySchedule $schedule): array
    {
        return self::interpret([
            'id' => $schedule->id,
            'salary_fixation_id' => $schedule->salary_fixation_id,
            'effective_date' => $schedule->effective_date?->format('Y-m-d'),
            'grade_number' => $schedule->grade_number,
            'payable_basic' => $schedule->payable_basic,
        ]);
    }
}
