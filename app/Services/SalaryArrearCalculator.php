<?php

namespace App\Services;

use App\Models\SalaryFixationPaySchedule;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use DomainException;

/** Read-only historical reconciliation. No salary or GPF entries are posted here. */
final class SalaryArrearCalculator
{
    private const SOURCE_FIELDS = ['paid_basic', 'paid_special_benefit', 'paid_house_rent', 'paid_gpf'];

    /** Consistently represent monetary calculations in whole paisa/cents. */
    private static function cents(float|int|string $value): int
    {
        return (int) round((float) $value * 100, 0, PHP_ROUND_HALF_UP);
    }

    private static function money(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private static function name(array $item): string
    {
        return strtolower(trim((string) ($item['name'] ?? $item['payroll_component_type']['name'] ?? '')));
    }

    private static function componentSum(array $items, string $needle, bool $single = false): ?int
    {
        $matched = [];
        foreach ($items as $item) {
            if (!is_array($item)) throw new DomainException('Invalid historical salary item.');
            $name = self::name($item);
            if (str_contains($name, $needle)) {
                $value = $item['calculated_amount'] ?? $item['amount'] ?? null;
                if (!is_numeric($value) || !is_finite((float) $value)) {
                    throw new DomainException('Missing/invalid historical amount for '.$name);
                }
                $matched[] = self::cents($value);
            }
        }
        if ($single && count($matched) !== 1) return null;
        return array_sum($matched);
    }

    /** Determine official reported amounts from a saved, approved monthly salary bill. */
    public static function paidFromPayroll(object $bill): array
    {
        if (($bill->reviewStatus ?? '') !== 'approved') {
            throw new DomainException('Historical salary bill has not been approved.');
        }
        $add = json_decode((string) $bill->salaryAllowanceCalcObject, true);
        $ded = json_decode((string) $bill->salaryDeductionCalcObject, true);
        if (is_string($add)) $add = json_decode($add, true);
        if (is_string($ded)) $ded = json_decode($ded, true);
        if (!is_array($add) || !is_array($ded)) {
            throw new DomainException('Historical payroll component JSON is missing/invalid.');
        }
        // A revised bill that already contains arrear/fixation components is a
        // product of the fixation process, not independent historical payment
        // evidence. It must never be used as a paid-historical source.
        foreach ([$add, $ded] as $items) {
            foreach ($items as $item) {
                $name = strtolower(trim((string) ($item['name'] ?? '')));
                foreach (['arrear', 'basic difference', 'old increment', 'special benefit recovery',
                    'house rent difference', 'additional gpf'] as $needle) {
                    if (str_contains($name, $needle)) {
                        throw new DomainException('Revised bill contains arrear components; not usable as historical payment evidence.');
                    }
                }
            }
        }
        $basic = self::componentSum($add, 'basic', true);
        if ($basic === null || $basic <= 0) {
            throw new DomainException('Historical bill does not contain one valid Basic Pay.');
        }
        $special = 0;
        foreach ($add as $item) {
            if (PayrollEffectivePolicy::isSpecialBenefit($item)) {
                $value = $item['calculated_amount'] ?? $item['amount'] ?? null;
                if (!is_numeric($value)) throw new DomainException('Invalid Special Benefit amount.');
                $special += self::cents($value);
            }
        }
        $house = 0;
        foreach ($add as $item) {
            $name = self::name($item);
            if (str_contains($name, 'house rent') || str_contains($name, 'house allowance')) {
                $value = $item['calculated_amount'] ?? $item['amount'] ?? null;
                if (!is_numeric($value)) throw new DomainException('Invalid House Rent amount.');
                $house += self::cents($value);
            }
        }
        if (!is_numeric($bill->gpfDeduction ?? null)) throw new DomainException('Historical GPF field missing.');
        return [
            'paid_basic' => self::money($basic),
            'paid_special_benefit' => self::money($special),
            'paid_house_rent' => self::money($house),
            'paid_gpf' => self::money(self::cents($bill->gpfDeduction)),
            'source_type' => 'approved_salary_generates',
            'source_record_id' => $bill->id,
        ];
    }

    /** Step 05 shared pay-scale entitlement reconciliation; preview-only. */
    public static function reconcileScaleMonths(array $months): array
    {
        return SalaryPayScaleArrearEngine::reconcile($months);
    }

    /** Pure calculation: never deduct the same old-scale increment twice. */
    public static function calculateMonth(array $inputs): array
    {
        foreach (['june_basic', 'entitled_basic', 'paid_basic', 'paid_special_benefit', 'paid_house_rent', 'paid_gpf'] as $key) {
            if (!isset($inputs[$key]) || !is_numeric($inputs[$key]) || (float) $inputs[$key] < 0) {
                throw new DomainException('Missing or invalid '.$key);
            }
        }
        $june = self::cents($inputs['june_basic']);
        $entitled = self::cents($inputs['entitled_basic']);
        $paid = self::cents($inputs['paid_basic']);
        $grossBasic = $entitled - $june;
        $oldAdjustment = $paid - $june;
        // Signed adjustment: negative means employee actually received less than June baseline.
        $basicDifference = $grossBasic - $oldAdjustment;
        if ($basicDifference !== $entitled - $paid) {
            throw new DomainException('Basic reconciliation did not balance.');
        }
        $special = self::cents($inputs['paid_special_benefit']);
        $housePaid = self::cents($inputs['paid_house_rent']);
        $gpfPaid = self::cents($inputs['paid_gpf']);
        $specialRecovery = match ($inputs['special_policy'] ?? 'unconfirmed') {
            'recover_paid' => $special,
            'retain' => 0,
            default => throw new DomainException('Special Benefit recovery policy not confirmed.'),
        };
        $houseAdjustment = match ($inputs['house_policy'] ?? 'unconfirmed') {
            'reconcile' => isset($inputs['revised_house_rent'])
                ? self::cents($inputs['revised_house_rent']) - $housePaid
                : throw new DomainException('Revised House Rent evidence is missing.'),
            'none' => 0,
            default => throw new DomainException('House Rent policy not confirmed.'),
        };
        $gpfIncrease = match ($inputs['gpf_policy'] ?? 'unconfirmed') {
            'reconcile' => isset($inputs['revised_gpf'])
                ? self::cents($inputs['revised_gpf']) - $gpfPaid
                : self::calculatedGpf($entitled, $inputs) - $gpfPaid,
            'none' => 0,
            default => throw new DomainException('GPF policy not confirmed.'),
        };
        $manual = 0;
        foreach ($inputs['manual_items'] ?? [] as $line) {
            if (!in_array($line['direction'] ?? '', ['addition', 'deduction'], true)
                || !is_numeric($line['amount'] ?? null) || (float) $line['amount'] <= 0) {
                throw new DomainException('Invalid manual addition/deduction.');
            }
            $manual += ($line['direction'] === 'addition' ? 1 : -1) * self::cents($line['amount']);
        }
        $net = $basicDifference - $specialRecovery + $houseAdjustment - $gpfIncrease + $manual;
        return [
            'gross_basic_arrear' => self::money($grossBasic),
            'old_increment_adjustment' => self::money($oldAdjustment),
            'adjusted_basic_arrear' => self::money($basicDifference),
            'special_benefit_recovery' => self::money($specialRecovery),
            'house_rent_adjustment' => self::money($houseAdjustment),
            'additional_gpf' => self::money($gpfIncrease),
            'manual_net' => self::money($manual),
            'net_arrear' => self::money($net),
        ];
    }

    private static function calculatedGpf(int $entitledCents, array $inputs): int
    {
        if (!isset($inputs['gpf_rate_percent']) || !is_numeric($inputs['gpf_rate_percent'])) {
            throw new DomainException('Approved GPF rate or employee-specific amount missing.');
        }
        $rate = (float) $inputs['gpf_rate_percent'];
        if ($rate < 0 || $rate > 100) throw new DomainException('Invalid GPF rate.');
        $raw = $entitledCents * $rate / 100;
        return match ($inputs['gpf_rounding'] ?? 'unconfirmed') {
            'truncate_taka' => (int) floor($raw / 100) * 100,
            'round_taka' => (int) round($raw / 100, 0, PHP_ROUND_HALF_UP) * 100,
            'paisa' => (int) round($raw, 0, PHP_ROUND_HALF_UP),
            default => throw new DomainException('GPF rounding policy not confirmed.'),
        };
    }

    public function preview(object $period, int $userId): array
    {
        $errors = [];
        $warnings = [];
        $months = [];
        $lines = [];
        $periodId = (int) $period->id;
        if ($period->policy_status !== 'confirmed' || !$period->policy_evidence) {
            $errors[] = 'RETROSPECTIVE_ENTITLEMENT_POLICY_NOT_CONFIRMED';
        }
        if (in_array('unconfirmed', [(string) $period->special_benefit_policy, (string) $period->house_rent_policy,
            (string) $period->gpf_policy], true)) $errors[] = 'COMPONENT_POLICY_UNCONFIRMED';
        $profileCount = DB::table('employee_infos')->where('user_id', $userId)->count();
        if ($profileCount !== 1) $errors[] = 'EMPLOYEE_PROFILE_MISSING_OR_DUPLICATED';
        $fixations = DB::table('salary_fixations')->where('user_id', $userId)
            ->where('status', 'approved')->get();
        if ($fixations->count() !== 1) $errors[] = 'APPROVED_FIXATION_MISSING_OR_AMBIGUOUS';
        $fixation = $fixations->first();
        $juneBasic = $fixation ? (float) $fixation->old_basic : null;
        if ($juneBasic === null || $juneBasic <= 0) $errors[] = 'APPROVED_FIXATION_BASELINE_MISSING';
        $start = Carbon::parse($period->entitlement_start)->startOfMonth();
        $end = Carbon::parse($period->entitlement_end)->startOfMonth();
        if ($end->lt($start) || $end->diffInMonths($start) > 24) {
            $errors[] = 'PERIOD_RANGE_INVALID';
            return $this->finish($userId, $periodId, $months, $lines, $errors, $warnings);
        }
        $schedules = SalaryFixationPaySchedule::where('user_id', $userId)
            ->orderBy('effective_date')->get()->filter(fn ($s) => $fixation && (int) $s->salary_fixation_id === (int) $fixation->id);
        $paymentCerts = DB::table('salary_arrear_payment_confirmations')
            ->where('period_id', $periodId)->where('user_id', $userId)
            ->get()->keyBy(fn ($r) => sprintf('%04d-%02d', $r->year, $r->month));
        $manual = DB::table('salary_arrear_manual_items')->where('period_id', $periodId)
            ->where('user_id', $userId)->orderBy('id')->get()->groupBy(fn ($r) => sprintf('%04d-%02d', $r->year, $r->month));
        $overrides = DB::table('salary_arrear_source_overrides')->where('period_id', $periodId)
            ->where('user_id', $userId)->get()->groupBy(fn ($r) => sprintf('%04d-%02d', $r->year, $r->month));
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $year = (int) $cursor->year;
            $month = (int) $cursor->month;
            $key = $cursor->format('Y-m');
            $date = $cursor->toDateString();
            $localErrors = [];
            $schedule = $schedules->filter(fn ($s) => Carbon::parse($s->effective_date)->toDateString() <= $date)->last();
            if (!$schedule) $localErrors[] = 'REVISED_BASIC_SCHEDULE_NOT_APPROVED_FOR_MONTH';
            $bills = DB::table('salary_generates')->where('user_id', $userId)
                ->where('salaryGenerationYear', $year)->where('monthId', $month)->get();
            if ($bills->count() > 1) $localErrors[] = 'DUPLICATE_HISTORICAL_SALARY_BILLS';
            $paid = null;
            if ($bills->count() === 1) {
                $paymentCertification = $paymentCerts->get($key);
                if (!$paymentCertification
                    || (int) $paymentCertification->salary_generate_id !== (int) $bills->first()->id) {
                    $warnings[] = $key.': BANK_PAYMENT_NOT_VERIFIED';
                }
                try { $paid = self::paidFromPayroll($bills->first()); }
                catch (DomainException $e) { $localErrors[] = 'HISTORICAL_PAYROLL: '.$e->getMessage(); }
            } else if ($bills->isEmpty()) {
                $localErrors[] = 'PAID_PAYROLL_RECORD_MISSING';
            }
            $sourceOverrides = $overrides->get($key, collect())->keyBy('field');
            foreach (['revised_house_rent', 'revised_gpf', 'gpf_rate_percent'] as $field) {
                if ($sourceOverrides->has($field) && !(bool) $sourceOverrides[$field]->certified) {
                    $localErrors[] = 'ENTITLEMENT_OVERRIDE_UNCERTIFIED: '.$field;
                }
            }
            if ($paid === null) {
                if (count(array_intersect(self::SOURCE_FIELDS, $sourceOverrides->keys()->all())) === count(self::SOURCE_FIELDS)
                    && $sourceOverrides->every(fn ($r) => (bool) $r->certified && trim($r->evidence_reference) !== '')) {
                    $paid = [];
                    foreach (self::SOURCE_FIELDS as $field) $paid[$field] = (float) $sourceOverrides[$field]->amount;
                    $paid['source_type'] = 'certified_manual_historical_source';
                    $paid['source_record_id'] = null;
                    $localErrors = array_values(array_filter($localErrors, fn ($e) => $e !== 'PAID_PAYROLL_RECORD_MISSING'
                        && !str_starts_with($e, 'HISTORICAL_PAYROLL:')));
                    $warnings[] = $key.': CERTIFIED_MANUAL_PAYMENT_SOURCE';
                }
            } elseif ($sourceOverrides->has('paid_basic') || $sourceOverrides->has('paid_gpf')
                || $sourceOverrides->has('paid_special_benefit') || $sourceOverrides->has('paid_house_rent')) {
                $localErrors[] = 'CONFLICTING_PAID_SOURCE_OVERRIDE';
            }
            $inputs = array_merge($paid ?? [], [
                'june_basic' => $juneBasic,
                'entitled_basic' => $schedule?->payable_basic,
                'special_policy' => $period->special_benefit_policy,
                'house_policy' => $period->house_rent_policy,
                'gpf_policy' => $period->gpf_policy,
                'gpf_rounding' => $period->gpf_rounding,
                'gpf_rate_percent' => $sourceOverrides->has('gpf_rate_percent')
                    ? $sourceOverrides['gpf_rate_percent']->amount : $period->gpf_rate_percent,
                'revised_house_rent' => $sourceOverrides->has('revised_house_rent')
                    ? $sourceOverrides['revised_house_rent']->amount : null,
                'revised_gpf' => $sourceOverrides->has('revised_gpf')
                    ? $sourceOverrides['revised_gpf']->amount : null,
                'manual_items' => $manual->get($key, collect())->map(fn ($x) => (array) $x)->all(),
            ]);
            $calculated = null;
            if (!$localErrors && $period->policy_status === 'confirmed'
                && !in_array('COMPONENT_POLICY_UNCONFIRMED', $errors, true)) {
                try { $calculated = self::calculateMonth($inputs); }
                catch (DomainException $e) { $localErrors[] = 'CALCULATION: '.$e->getMessage(); }
            }
            if ($calculated) {
                foreach ([
                    ['gross_basic_arrear','Gross Basic Arrear','addition'],
                    ['old_increment_adjustment','Old-scale Increment Adjustment','deduction'],
                    ['special_benefit_recovery','Special Benefit Recovery','deduction'],
                    ['house_rent_adjustment','House Rent Difference','addition'],
                    ['additional_gpf','Additional GPF Contribution','deduction'],
                ] as [$code,$title,$direction]) {
                    $amount = (float) $calculated[$code];
                    if ($amount < 0) $direction = $direction === 'addition' ? 'deduction' : 'addition';
                    $lines[] = ['year' => $year, 'month' => $month, 'code' => $code,
                        'title' => $title, 'direction' => $direction, 'amount' => abs($amount),
                        'source_reference' => $paid['source_type'].'#'.($paid['source_record_id'] ?? 'manual')];
                }
                foreach ($manual->get($key, collect()) as $entry) {
                    $lines[] = ['year' => $year, 'month' => $month, 'code' => 'manual_'.$entry->id,
                        'title' => $entry->title, 'direction' => $entry->direction,
                        'amount' => (float) $entry->amount, 'source_reference' => $entry->evidence_reference];
                }
            }
            $months[] = ['year' => $year, 'month' => $month, 'paid' => $paid,
                'entitled_basic' => $schedule?->payable_basic,
                'calculated' => $calculated, 'errors' => $localErrors];
            foreach ($localErrors as $err) $errors[] = $key.': '.$err;
            $cursor->addMonth();
        }
        return $this->finish($userId, $periodId, $months, $lines, $errors, $warnings);
    }

    private function finish(int $userId, int $periodId, array $months, array $lines, array $errors, array $warnings): array
    {
        $sum = 0;
        foreach ($months as $month) $sum += self::cents($month['calculated']['net_arrear'] ?? 0);
        $status = $errors ? 'BLOCKED' : ($warnings ? 'REVIEW' : 'PASS');
        return [
            'user_id' => $userId, 'period_id' => $periodId, 'months' => $months,
            'lines' => $lines, 'net_arrear' => $errors ? null : self::money($sum),
            'status' => $status, 'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}
