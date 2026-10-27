<?php

namespace App\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * October 2026 structured entitlement, read-only. No published spreadsheet expression
 * is executed for the five standard components. No payroll/GPF/fixation write.
 * Existing published identities and revisions are retained for snapshot integrity.
 */
final class SalaryStructuredArrearService
{
    public const CODES = [
        'BASIC_ARREAR_GROSS', 'BASIC_INCREMENT_ADJUSTMENT',
        'SPECIAL_BENEFIT_RECOVERY', 'HOUSE_RENT_RECOVERY', 'ADDITIONAL_GPF',
    ];

    /** Memoised historical dump rows keyed by source row id (populated lazily). */
    private ?array $historicalDumpCache = null;

    public function __construct(private readonly ApprovedFixationResolver $fixation) {}

    public static function applicable(int $year, int $month): bool
    {
        return $year === 2026 && $month === 10;
    }

    /** Pure financial projection: never assume missing payment is zero. */
    public static function compute(array $evidence): array
    {
        $months = SalaryPayScaleArrearEngine::MONTHS;
        $result = [];
        $june = $evidence['june_basic'] ?? null;
        $increment = $evidence['old_increment'] ?? null;
        $revised = $evidence['revised_basics'] ?? [];
        $paid = $evidence['paid_months'] ?? [];
        $financial = $evidence['financial_months'] ?? [];
        $money = static function (mixed $value): ?int {
            if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) return null;
            return (int) round((float) $value * 100, 0, PHP_ROUND_HALF_UP);
        };
        $juneCents = $money($june);
        $incrementCents = $money($increment);
        $gross = 0;
        $hasGross = $juneCents !== null;
        $components = [
            'BASIC_ARREAR_GROSS' => ['total' => 0, 'complete' => $hasGross, 'sources' => []],
            'BASIC_INCREMENT_ADJUSTMENT' => ['total' => 0, 'complete' => $juneCents !== null && ($incrementCents !== null || $paid !== []), 'has_unverified_payment' => false, 'sources' => []],
            'SPECIAL_BENEFIT_RECOVERY' => ['total' => 0, 'complete' => true, 'has_unverified_payment' => false, 'sources' => []],
            'HOUSE_RENT_RECOVERY' => ['total' => 0, 'complete' => true, 'has_unverified_payment' => false, 'sources' => []],
            'ADDITIONAL_GPF' => ['total' => 0, 'complete' => true, 'has_unverified_payment' => false, 'sources' => []],
        ];
        foreach ($months as $period) {
            $new = $money($revised[$period] ?? null);
            if ($new === null || $juneCents === null) $components['BASIC_ARREAR_GROSS']['complete'] = false;
            else $components['BASIC_ARREAR_GROSS']['total'] += $new - $juneCents;
            $components['BASIC_ARREAR_GROSS']['sources'][$period] = [
                'revised_basic' => $revised[$period] ?? null, 'june_basic' => $june,
                'source' => $evidence['revised_sources'][$period] ?? null,
            ];
            // Prefer the actual month-by-month 2015 Basic over a stage prediction.
            // Keep the legacy increment reference only for pure calculation inputs
            // with no historical paid_months (avoids breaking existing pure tests).
            $actualOldBasic = $money($paid[$period]['paid_basic'] ?? null);
            if ($actualOldBasic !== null && $juneCents !== null && $actualOldBasic >= $juneCents) {
                $components['BASIC_INCREMENT_ADJUSTMENT']['total'] += $actualOldBasic - $juneCents;
            } elseif ($paid === [] && $incrementCents !== null) {
                $components['BASIC_INCREMENT_ADJUSTMENT']['total'] += $incrementCents;
            } else {
                $components['BASIC_INCREMENT_ADJUSTMENT']['complete'] = false;
            }
            if (($paid[$period]['payment_verified'] ?? true) === false) {
                $components['BASIC_INCREMENT_ADJUSTMENT']['has_unverified_payment'] = true;
            }
            $components['BASIC_INCREMENT_ADJUSTMENT']['sources'][$period] = [
                'june_basic' => $june,
                'historical_month_basic' => $paid[$period]['paid_basic'] ?? null,
                'source_record_id' => $paid[$period]['source_record_id'] ?? null,
                'stage_reference_increment' => $increment,
            ];
            // Recovery of benefits must reflect actual previously paid amount, or
            // a separately documented employee confirmation. Never infer from Basic.
            $special = $money($paid[$period]['paid_special_benefit'] ?? null);
            // Historical dump rows are reported amounts, NOT proof of completed
            // payment. Propagate the verification flag to the component result
            // so unverified sources can never silently become approval-eligible.
            if (($paid[$period]['payment_verified'] ?? true) === false) {
                $components['SPECIAL_BENEFIT_RECOVERY']['has_unverified_payment'] = true;
                $components['HOUSE_RENT_RECOVERY']['has_unverified_payment'] = true;
                $components['ADDITIONAL_GPF']['has_unverified_payment'] = true;
            }
            if ($special === null) $components['SPECIAL_BENEFIT_RECOVERY']['complete'] = false;
            else $components['SPECIAL_BENEFIT_RECOVERY']['total'] += $special;
            $components['SPECIAL_BENEFIT_RECOVERY']['sources'][$period] = ['amount' => $special === null ? null : $special / 100,
                'payment_source' => $paid[$period]['source_record_id'] ?? null];

            // Compare the October revised entitlement against the June old rent
            // when the configured Khulna reference is available; otherwise
            // preserve the existing verified month-specific entitlement behavior.
            $housePaid = $money($paid[$period]['paid_house_rent'] ?? null);
            $houseEntitled = $money($financial[$period]['entitled_house_rent'] ?? null);
            $juneBaseline = $money($financial[$period]['house_rent_baseline'] ?? null);
            // For the user-specified October-versus-June calculation,
            // a negative internal total means payable (existing sign convention).
            $houseReference = $juneBaseline ?? $housePaid;
            if ($houseReference === null || $houseEntitled === null) $components['HOUSE_RENT_RECOVERY']['complete'] = false;
            else $components['HOUSE_RENT_RECOVERY']['total'] += $houseReference - $houseEntitled;
            $components['HOUSE_RENT_RECOVERY']['sources'][$period] = [
                'june_or_historical_rent' => $houseReference === null ? null : $houseReference / 100,
                'revised_entitlement' => $houseEntitled === null ? null : $houseEntitled / 100,
                'june_baseline_used' => $juneBaseline !== null,
                'source_record_id' => $paid[$period]['source_record_id'] ?? null,
            ];

            $requiredGpf = $money($financial[$period]['required_gpf'] ?? null);
            $paidGpf = $money($paid[$period]['paid_gpf'] ?? null);
            if ($requiredGpf === null || $paidGpf === null || $requiredGpf < $paidGpf) {
                $components['ADDITIONAL_GPF']['complete'] = false;
            } else $components['ADDITIONAL_GPF']['total'] += $requiredGpf - $paidGpf;
            $components['ADDITIONAL_GPF']['sources'][$period] = ['required' => $requiredGpf === null ? null : $requiredGpf / 100,
                'already_deducted' => $paidGpf === null ? null : $paidGpf / 100];
        }
        foreach ($components as $code => $entry) {
            $value = $entry['complete'] ? $entry['total'] : null;
            $direction = $code === 'BASIC_ARREAR_GROSS' ? 'payable' : 'deduction';
            if ($value !== null && $value < 0) {
                if ($code === 'HOUSE_RENT_RECOVERY') { $direction = 'payable'; $value = -$value; }
                else { $value = null; }
            }
            $result[$code] = [
                'amount' => $value === null ? null : round($value / 100, 2),
                'direction' => $direction,
                'status' => $value === null ? 'UNKNOWN' : (
                    $code === 'BASIC_ARREAR_GROSS' ? 'APPROVED_FIXATION_ENTITLEMENT' :
                    ($code === 'BASIC_INCREMENT_ADJUSTMENT' ? ($entry['has_unverified_payment'] ? 'PAYMENT_UNVERIFIED' : ($paid === [] ? ($evidence['increment_status'] ?? 'RECONSTRUCTED_NOT_PAYMENT') : 'HISTORICAL_BASIC_RECONCILED')) :
                        // Reported-but-unverified payment must remain explicitly
                        // flagged so it cannot pass final approval automatically.
                        ($entry['has_unverified_payment'] ? 'PAYMENT_UNVERIFIED' : 'SOURCE_RECONCILED'))),
                'month_sources' => $entry['sources'],
            ];
        }
        return $result;
    }

    /** Data sources only. Failure to find a source is an UNKNOWN component, not zero. */
    private function sources(int $userId): array
    {
        $evidence = ['revised_basics' => [], 'revised_sources' => [], 'paid_months' => [], 'financial_months' => []];
        foreach (SalaryPayScaleArrearEngine::MONTHS as $period) {
            [$year, $month] = array_map('intval', explode('-', $period));
            $fix = $this->fixation->forUser($userId, $year, $month);
            $evidence['revised_basics'][$period] = $fix['basic'] ?? null;
            $evidence['revised_sources'][$period] = $fix['salary_fixation_id'] ?? null;
        }
        $first = $this->fixation->forUser($userId, 2026, 7);
        if ($first && Schema::hasTable('salary_fixations')) {
            $record = DB::table('salary_fixations')->where('id', $first['salary_fixation_id'])
                ->where('user_id', $userId)->where('status', 'approved')->first();
            if ($record && is_numeric($record->old_basic ?? null) && (float) $record->old_basic > 0) {
                $evidence['june_basic'] = (float) $record->old_basic;
                $eligibility = json_decode((string) ($record->prepared_data ?? '{}'), true);
                $eligibility = is_array($eligibility) ? $eligibility : [];
                $grade = (int)($record->grade_number ?? 0);
                $stages = config('kau_fixation_2026.'.$grade.'.stages2015', []);
                $old = SalaryPayScaleArrearEngine::oldScaleIncrement(is_array($stages) ? $stages : [], $record->old_basic, $eligibility);
                $evidence['old_increment'] = $old['amount'];
                $evidence['increment_status'] = $old['status'];
                $evidence['old_stage_source'] = $old;
            }
        }
        if (Schema::hasTable('salary_generates')) {
            // June 2026 is the old-scale Basic/House Rent baseline. Only
            // an unambiguous approved arrear-free bill may establish it.
            $juneBills = DB::table('salary_generates')->where('user_id', $userId)
                ->where('salaryGenerationYear', 2026)->where('monthId', 6)->limit(2)->get();
            if ($juneBills->count() === 1) {
                try {
                    $juneSource = SalaryArrearCalculator::paidFromPayroll($juneBills->first());
                    $juneBasic = $juneSource['paid_basic'] ?? null;
                    if (is_numeric($juneBasic) && (float) $juneBasic > 0) {
                        if (isset($evidence['june_basic']) && abs((float) $evidence['june_basic'] - (float) $juneBasic) > 0.005) {
                            $evidence['source_conflicts'] = true;
                        } else {
                            $evidence['june_basic'] = (float) $juneBasic;
                            $evidence['june_source_record_id'] = $juneSource['source_record_id'] ?? null;
                        }
                    }
                    $evidence['june_house_rent'] = $juneSource['paid_house_rent'] ?? null;
                } catch (DomainException $ignored) {
                    // No fabricated June source.
                }
            }
            foreach (SalaryPayScaleArrearEngine::MONTHS as $period) {
                [$year, $month] = array_map('intval', explode('-', $period));
                $records = DB::table('salary_generates')->where('user_id', $userId)
                    ->where('salaryGenerationYear', $year)->where('monthId', $month)->limit(2)->get();
                if ($records->count() !== 1) continue;
                try { $evidence['paid_months'][$period] = SalaryArrearCalculator::paidFromPayroll($records->first()); }
                catch (DomainException $e) {
                    // The current bill is not usable as paid-historical evidence
                    // (pending or arrear-containing revised bill). Fall back to the
                    // indexed import provenance (salary_import_records) and read the
                    // original historical dump row â€” never invent amounts, and never
                    // label an unverified historical source as approved payment.
                    $fallback = $this->historicalFallback($userId, $records->first());
                    if ($fallback !== null) {
                        $evidence['paid_months'][$period] = $fallback;
                    }
                }
            }
        }
        // October 2026 three-month policy reference for KAU's Khulna station.
        // The 40% house rent comes from the supplied Khulna location schedule;
        // the GPF 10% is the user-specified employee calculation rule.
        // Rates are centrally configurable and cannot fill a missing approved
        // revised fixation or a missing historical amount.
        $rentRate = config('kau_arrear_reference_2026.khulna_house_rent_rate');
        $gpfRate = config('kau_arrear_reference_2026.revised_gpf_rate');
        $juneRent = $evidence['june_house_rent'] ?? null;
        foreach (SalaryPayScaleArrearEngine::MONTHS as $period) {
            $revisedBasic = $evidence['revised_basics'][$period] ?? null;
            if (!is_numeric($revisedBasic) || (float) $revisedBasic <= 0) continue;
            $evidence['financial_months'][$period] ??= [];
            if (is_numeric($rentRate) && is_numeric($juneRent)
                && !array_key_exists('entitled_house_rent', $evidence['financial_months'][$period])) {
                // The existing compute() expects paid - entitled for rent.
                // To calculate October revised rent - June original rent, the
                // revised rent is the 'paid' reference and June rent entitlement.
                // Instead expose these as separate signed references below.
                $evidence['financial_months'][$period]['entitled_house_rent'] =
                    round((float) $revisedBasic * (float) $rentRate, 2, PHP_ROUND_HALF_UP);
                $evidence['financial_months'][$period]['house_rent_baseline'] = (float) $juneRent;
            }
            if (is_numeric($gpfRate)
                && !array_key_exists('required_gpf', $evidence['financial_months'][$period])) {
                $evidence['financial_months'][$period]['required_gpf'] =
                    round((float) $revisedBasic * (float) $gpfRate, 0, PHP_ROUND_HALF_UP);
            }
        }
        // Step 12: month-specific, evidence-backed entitlement/payment overrides.
        // These are already audited by SalaryArrearController and are never
        // generated from provisional September drafts or inferred rates.
        if (Schema::hasTable('salary_arrear_periods') && Schema::hasTable('salary_arrear_source_overrides')) {
            $periodIds = DB::table('salary_arrear_periods')
                ->whereDate('entitlement_start', '<=', '2026-07-01')
                ->whereDate('entitlement_end', '>=', '2026-09-01')
                ->pluck('id')->all();
            if ($periodIds) {
                $rows = DB::table('salary_arrear_source_overrides')
                    ->whereIn('period_id', $periodIds)
                    ->where('user_id', $userId)
                    ->where('year', 2026)
                    ->whereIn('month', [7, 8, 9])
                    ->get()->all();
                $evidence = SalaryStructuredArrearEvidenceOverlay::apply($evidence, $rows);
            }
        }
        return $evidence;
    }

    /**
     * Narrowly scoped, auditable historical fallback. When the current
     * salary_generates bill cannot be used as paid-historical evidence, this
     * resolves the original historical dump row through the import provenance
     * index (salary_import_records) and extracts the originally reported
     * components. The source is explicitly labelled unverified â€” payment is not
     * assumed. Returns null when no provenance or dump row can be resolved.
     */
    private function historicalFallback(int $userId, object $bill): ?array
    {
        if (!Schema::hasTable('salary_import_records')) {
            return null;
        }
        $provenance = DB::table('salary_import_records')
            ->where('target_salary_generate_id', $bill->id)
            ->where('user_id', $userId)
            ->whereIn('action', ['inserted', 'skipped', 'conflict', 'replaced'])
            ->orderBy('id')->get();
        if ($provenance->count() !== 1) {
            return null; // ambiguous or missing provenance â€” no invented source.
        }
        $sourceRowId = (int) $provenance->first()->source_row_id;
        $rows = $this->historicalDumpRows();
        $row = $rows[$sourceRowId] ?? null;
        if ($row === null) {
            return null;
        }
        $add = json_decode((string) ($row['salaryAllowanceCalcObject'] ?? ''), true);
        $ded = json_decode((string) ($row['salaryDeductionCalcObject'] ?? ''), true);
        if (!is_array($add) || !is_array($ded)) {
            return null;
        }
        $cents = static function (array $items, string $needle): ?int {
            $total = 0;
            $found = false;
            foreach ($items as $item) {
                $name = strtolower(trim((string) ($item['name'] ?? '')));
                if (!str_contains($name, $needle)) continue;
                $value = $item['calculated_amount'] ?? $item['amount'] ?? null;
                if (!is_numeric($value)) return null;
                $total += (int) round((float) $value * 100, 0, PHP_ROUND_HALF_UP);
                $found = true;
            }
            return $found ? $total : null;
        };
        $basic = $cents($add, 'basic');
        $house = $cents($add, 'house rent');
        $special = $cents($add, 'special benefit');
        $gpf = $cents($ded, 'general provident fund');
        if ($basic === null || $basic <= 0) {
            return null;
        }
        return [
            'paid_basic' => $basic / 100,
            'paid_special_benefit' => $special === null ? null : $special / 100,
            'paid_house_rent' => $house === null ? null : $house / 100,
            'paid_gpf' => $gpf === null ? null : $gpf / 100,
            'source_type' => 'historical_dump_row_unverified',
            'source_record_id' => $sourceRowId,
            'payment_verified' => false,
            'payment_status' => $row['reviewStatus'] ?? 'unknown',
        ];
    }

    /** Memoised parse of the historical dump (Juneâ€“September 2026), keyed by row id. */
    private function historicalDumpRows(): array
    {
        if ($this->historicalDumpCache === null) {
            $rows = [];
            try {
                foreach ((new HistoricalSalaryImportService())->parse() as $row) {
                    $rows[(int) $row['id']] = $row;
                }
            } catch (\Throwable) {
                $rows = []; // dump missing â€” no invented source.
            }
            $this->historicalDumpCache = $rows;
        }
        return $this->historicalDumpCache;
    }

    /**
     * Phase 3C-Fix: narrow read-only evidence summary for the calculator UI.
     * Exposes only display-safe fields; never leaks full employee/financial
     * record internals. Preserves null for missing monetary evidence.
     */
    public function evidenceSummary(int $userId): array
    {
        $e = $this->sources($userId);
        return [
            'june_basic' => $e['june_basic'] ?? null,
            'old_increment' => $e['old_increment'] ?? null,
            'increment_status' => $e['increment_status'] ?? 'UNKNOWN',
            'revised_basics' => $e['revised_basics'] ?? [],
            'revised_sources' => $e['revised_sources'] ?? [],
            'paid_months' => $e['paid_months'] ?? [],
            'financial_months' => $e['financial_months'] ?? [],
            'source_conflicts' => ! empty($e['source_conflicts']),
        ];
    }

    /**
     * Phase 3C-Fix: canonical five-component rule set. When a period has no
     * configured salary_arrear_rules rows, display-only fallback definitions
     * (code/title/formula only â€” never amounts or policies) keep all five
     * rows visible. Configured rules always win, preserving their revisions.
     *
     * @param array<int,object|array> $rules
     * @return array<int,object>
     */
    public static function canonicalRuleSet(array $rules): array
    {
        $fallback = [
            'BASIC_ARREAR_GROSS' => 'Gross Basic Pay Arrear â€” Sum of (Revised Basic âˆ’ June Basic) for July, August, September.',
            'BASIC_INCREMENT_ADJUSTMENT' => 'Old-Scale Increment Adjustment â€” Sum of the historical old-scale increment for eligible months (Julyâ€“September).',
            'SPECIAL_BENEFIT_RECOVERY' => 'Special Benefit Recovery â€” Sum of documented historical special benefit amounts under the applicable recovery policy.',
            'HOUSE_RENT_RECOVERY' => 'House Rent Difference â€” Verified revised entitlement âˆ’ historical House Rent per month. Positive = payable, negative = deduction.',
            'ADDITIONAL_GPF' => 'Additional GPF Contribution â€” Verified required GPF âˆ’ historical GPF deducted per month.',
        ];
        $byCode = [];
        foreach ($rules as $rule) {
            $rule = is_object($rule) ? $rule : (object) $rule;
            $code = (string) ($rule->code ?? '');
            if ($code !== '') $byCode[$code] = $rule;
        }
        $out = [];
        foreach (self::CODES as $code) {
            if (isset($byCode[$code])) {
                $out[] = $byCode[$code];
                continue;
            }
            $out[] = (object) [
                'id' => null, 'code' => $code, 'title' => $code,
                'formula' => $fallback[$code] ?? null, 'amount' => null, 'revision' => 1,
            ];
        }
        return $out;
    }
    /**
     * Phase 3C-Fix: structured five-component preview. Purely read-only; uses
     * canonical rule set (configured rules or display-only fallbacks).
     *
     * @param array<int,object|array> $rules
     * @return array<int,array>
     */
    public function preview(int $userId, array $rules): array
    {
        $evidence = $this->sources($userId);
        $calculated = self::compute($evidence);
        if (!empty($evidence['source_conflicts'])) {
            // Never present any totals as fully verified after conflicting evidence.
            foreach ($calculated as &$entry) {
                $entry['amount'] = null;
                $entry['status'] = 'SOURCE_CONFLICT_REVIEW_REQUIRED';
            }
            unset($entry);
        }
        $out = [];
        foreach ($rules as $sourceRule) {
            $rule = (object) $sourceRule;
            $code = (string) ($rule->code ?? '');
            if (!isset($calculated[$code])) continue;
            $entry = $calculated[$code];
            $out[] = [
                'rule_id' => $rule->id ?? null, 'code' => $code,
                'title' => $rule->title ?? $code,
                'direction' => $entry['direction'],
                'mode' => 'formula', 'formula' => $rule->formula ?? null,
                'amount' => $rule->amount ?? null,
                'revision' => (int) ($rule->revision ?? 1),
                'months' => SalaryPayScaleArrearEngine::MONTHS,
                'calculated_amount' => $entry['amount'],
                'month_sources' => $entry['month_sources'],
                'source_status' => $entry['status'],
                'calculation_method' => 'STRUCTURED_PAY_SCALE_2026',
                'error' => $entry['amount'] === null ? 'SOURCE_UNVERIFIED: structured entitlement available only where required references are documented.' : null,
                'missing_evidence' => $entry['amount'] === null,
                'approval_eligible' => $entry['amount'] !== null && !in_array($entry['status'], [
                    'RECONSTRUCTED_2015_STAGE_NOT_PAYMENT', 'OLD_SCALE_STAGE_REFERENCE_MISSING',
                    'PAYMENT_UNVERIFIED', 'UNKNOWN', 'SOURCE_CONFLICT_REVIEW_REQUIRED',
                ], true),
            ];
        }
        return $out;
    }
}