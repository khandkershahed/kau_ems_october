<?php

namespace App\Services;

use App\Models\SalaryGenerate;
use App\Models\EmployeeInfo;
use App\Support\SalaryOldScaleIncrementReference;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phase 4 read-only global rule evaluation. Never touches bills/GPF ledger. */
final class SalaryArrearRuleService
{
    public function __construct(private readonly ApprovedFixationResolver $fixation, private readonly SalaryArrearFormulaEngine $formula) {}

    public static function months(object|array $rule, int $year, int $month): array
    {
        $v = (array) $rule;
        $list = $v['selected_months'] ?? [];
        if (is_string($list)) $list = json_decode($list, true);
        if (!is_array($list)) throw new DomainException('Invalid selected months.');
        if (!empty($v['recurring']) && !$list) return [sprintf('%04d-%02d', $year, $month)];
        if ($list) {
            $months = [];
            foreach ($list as $value) {
                if (!is_string($value) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) throw new DomainException('Invalid YYYY-MM rule month.');
                $months[] = $value;
            }
            return array_slice(array_unique($months), 0, 60);
        }
        $from = substr((string) ($v['start_month'] ?? ''), 0, 7);
        $to = substr((string) ($v['end_month'] ?? ''), 0, 7);
        if (!$from || !$to) return [sprintf('%04d-%02d', $year, $month)];
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $from) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $to) || $from > $to) {
            throw new DomainException('Rule has an invalid start/end period.');
        }
        $out = [];
        $cursor = CarbonImmutable::createFromFormat('!Y-m', $from);
        $end = CarbonImmutable::createFromFormat('!Y-m', $to);
        while ($cursor <= $end && count($out) < 60) {
            $out[] = $cursor->format('Y-m'); $cursor = $cursor->addMonth();
        }
        if ($cursor <= $end) throw new DomainException('At most 60 entitlement months per rule.');
        return $out;
    }

    public function published(int $userId, ?int $year = null, ?int $month = null): array
    {
        if (!Schema::hasTable('salary_arrear_rules')) return [];
        return DB::table('salary_arrear_rules')->where('published', true)->where('archived', false)
            ->orderBy('display_order')->orderBy('id')->get()
            ->filter(function ($row) use ($userId, $year, $month) {
                if ($row->scope === 'period' && $year !== null && $month !== null) {
                    $payPeriod = sprintf('%04d-%02d', $year, $month);
                    $selected = json_decode((string)$row->selected_months, true) ?: [];
                    if ($selected && !in_array($payPeriod, $selected, true)) return false;
                    if (!$selected && $row->start_month && $payPeriod < substr((string)$row->start_month, 0, 7)) return false;
                    if (!$selected && $row->end_month && $payPeriod > substr((string)$row->end_month, 0, 7)) return false;
                }
                if ($row->scope === 'group') {
                    $group = DB::table('employee_infos')->where('user_id', $userId)->limit(2)->get();
                    return $group->count() === 1 && (int) $group->first()->payroll_group_id === (int) $row->payroll_group_id;
                }
                if ($row->scope !== 'specific') return true;
                $ids = json_decode((string) $row->employee_ids, true) ?: [];
                return in_array($userId, array_map('intval', $ids), true);
            })->all();
    }

    /**
     * Unpublished templates are only a UI catalogue. They are NEVER part of
     * preview(), salary totals, saved payable arrear or bill posting.
     */
    public function unpublishedTemplates(int $userId): array
    {
        if (!Schema::hasTable('salary_arrear_rules')) return [];
        return DB::table('salary_arrear_rules')
            ->where('published', false)->where('archived', false)
            ->orderBy('display_order')->orderBy('id')
            ->get(['id', 'code', 'title', 'direction', 'mode', 'formula', 'scope', 'employee_ids', 'start_month', 'end_month'])
            ->filter(static function ($row) use ($userId) {
                if ($row->scope === 'group') {
                    $group = DB::table('employee_infos')->where('user_id', $userId)->limit(2)->get();
                    return $group->count() === 1 && (int) $group->first()->payroll_group_id === (int) $row->payroll_group_id;
                }
                if ($row->scope !== 'specific') return true;
                $ids = json_decode((string) $row->employee_ids, true) ?: [];
                return in_array($userId, array_map('intval', $ids), true);
            })
            ->map(static fn($row) => [
                'id' => (int)$row->id,
                'code' => $row->code,
                'title' => $row->title,
                'direction' => $row->direction,
                'mode' => $row->mode,
                'formula' => $row->formula,
                'start_month' => $row->start_month,
                'end_month' => $row->end_month,
                'status' => 'UNPUBLISHED_NOT_PAYABLE',
            ])->values()->all();
    }

    /**
     * October reference is a CURRENT CONFIGURED allowance, not proof that it
     * was paid in October. This avoids requiring an October saved salary bill
     * before October's arrear can be previewed. Duplicate/missing House Rent
     * remains UNKNOWN; no allowance is synthesized.
     */
    private function configuredOctoberHouseRent(int $userId): array
    {
        $profiles = EmployeeInfo::where('user_id', $userId)->limit(2)->get();
        if ($profiles->count() !== 1) {
            throw new DomainException('October employee salary configuration is missing or duplicated.');
        }
        $fix = $this->fixation->forUser($userId, 2026, 10);
        if (!$fix || !is_numeric($fix['basic'] ?? null)) {
            throw new DomainException('Approved October revised Basic is missing.');
        }
        // An employee-specific approved October calculation is the most
        // precise source for that employee's revised House Rent. Only accept
        // it if linked to this exact approved fixation version.
        if (Schema::hasTable('salary_calculation_versions')) {
            $version = DB::table('salary_calculation_versions')
                ->where('user_id', $userId)->where('salary_year', 2026)
                ->where('salary_month', 10)->where('status', 'approved')
                ->orderByDesc('version')->first();
            if ($version && (int)$version->fixation_id === (int)($fix['salary_fixation_id'] ?? 0)) {
                $rows = SalaryComponentNormalizer::rows($version->additions);
                $approvedHouse = array_values(array_filter($rows, static function ($item) {
                    $name = strtolower(trim((string)($item['name'] ?? $item['payroll_component_type']['name'] ?? '')));
                    return str_contains($name, 'house rent') || str_contains($name, 'house allowance');
                }));
                if (count($approvedHouse) > 1) {
                    throw new DomainException('Approved October calculation contains duplicate House Rent components.');
                }
                if (count($approvedHouse) === 1) {
                    $row = $approvedHouse[0];
                    if (!is_numeric($row['amount'] ?? null)) {
                        throw new DomainException('Approved October House Rent value is missing.');
                    }
                    $type = strtolower((string)($row['calcType'] ?? 'fixed'));
                    if (!in_array($type, ['fixed','percentage'], true)) {
                        throw new DomainException('Approved October House Rent calculation mode is invalid.');
                    }
                    return [
                        'amount' => round($type === 'percentage'
                            ? (float)$fix['basic'] * (float)$row['amount'] / 100
                            : (float)$row['amount'], 2, PHP_ROUND_HALF_UP),
                        'source' => 'approved_employee_salary_calculation_version_'.(int)$version->id,
                    ];
                }
            }
        }
        // A published revised House Rent component overrides an old fixed
        // 2015 allowance when HR has explicitly configured a new rate/amount.
        // Without a published 2026 rule, preserve the employee's configured
        // amount (do not invent a revised government percentage).
        // Do NOT derive October 2026 entitlement from global component publications:
        // those records have no housing-category/effective-date dimension, and
        // a 2028 percentage could otherwise be applied to 2026 by mistake.
        // An approved employee-specific October calculation above is accepted;
        // the legacy setup below is shown only to explain why it is NOT proof.
        $rows = SalaryComponentNormalizer::rows($profiles->first()->additionObjects);
        $matches = [];
        foreach ($rows as $row) {
            $name = strtolower(trim((string)($row['name'] ?? $row['payroll_component_type']['name'] ?? '')));
            if (!str_contains($name, 'house rent') && !str_contains($name, 'house allowance')) continue;
            if (!is_numeric($row['amount'] ?? null)) throw new DomainException('Configured October House Rent is not numeric.');
            $type = strtolower((string)($row['calcType'] ?? 'fixed'));
            if (!in_array($type, ['fixed','percentage'], true)) throw new DomainException('Unsupported House Rent calculation type.');
            $matches[] = round($type === 'percentage'
                ? ((float)$fix['basic'] * (float)$row['amount'] / 100)
                : (float)$row['amount'], 2, PHP_ROUND_HALF_UP);
        }
        if (count($matches) !== 1) {
            throw new DomainException('October configured House Rent is missing or duplicated; HR evidence required.');
        }
        // Do not silently treat a fixed 2015 House Rent as the rate based on
        // the new approved 2026 Basic. HR must configure/publish the revised
        // rate or approve an employee-specific October salary calculation.
        if ($type === 'fixed') {
            throw new DomainException('October revised House Rent is not approved. Configure a revised percentage/fixed rate or approve the employee October calculation; 2015 fixed House Rent is not a revised entitlement.');
        }
        throw new DomainException('Employee configured House Rent percentage is not HR-approved for the 2026 scale; approve an October calculation version or publish a verified rate.');
    }

    private function history(int $userId, string $period): array
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $bills = DB::table('salary_generates')->where('user_id', $userId)
            ->where('salaryGenerationYear', $year)->where('monthId', $month)->get();
        if ($bills->count() !== 1) throw new DomainException('Historical payment record for '.$period.' is missing or duplicated; UNKNOWN, not zero.');
        return SalaryArrearCalculator::paidFromPayroll($bills->first());
    }

    /**
     * October GPF is sourced ONLY from an approved employee-specific October
     * calculation tied to the same frozen fixation. No blanket 10% fallback.
     * A projection and its provenance are both required; this method never posts.
     */
    private function approvedOctoberGpf(int $userId, array $fixation): array
    {
        if (!Schema::hasTable('salary_calculation_versions')) {
            throw new DomainException('Approved October GPF evidence table is unavailable.');
        }
        $versions = DB::table('salary_calculation_versions')
            ->where('user_id', $userId)->where('salary_year', 2026)
            ->where('salary_month', 10)->where('status', 'approved')
            ->orderByDesc('version')->limit(2)->get();
        $version = $versions->first();
        if (!$version || (int)$version->fixation_id !== (int)($fixation['salary_fixation_id'] ?? 0)) {
            throw new DomainException('Approved employee-specific October GPF calculation for this fixation is missing.');
        }
        $result = json_decode((string)$version->calculated_result, true);
        $rule = is_array($result) ? ($result['gpf_rule'] ?? null) : null;
        if (!is_array($rule) || !is_numeric($rule['amount'] ?? null)
            || !in_array($rule['type'] ?? '', ['fixed', 'percentage', 'disabled'], true)
            || !isset($result['gpf_deduction']) || !is_numeric($result['gpf_deduction'])) {
            throw new DomainException('Approved October employee GPF amount or rule evidence is incomplete.');
        }
        $basic = $fixation['basic'] ?? null;
        if (!is_numeric($basic) || (float)$basic <= 0) {
            throw new DomainException('October fixation Basic is missing for GPF audit.');
        }
        $type = (string)$rule['type'];
        $rate = (float)$rule['amount'];
        if ($rate < 0 || ($type === 'percentage' && $rate > 100)) {
            throw new DomainException('Approved October GPF rate is invalid.');
        }
        $amount = $type === 'disabled' ? 0.0 : SalaryCalculationService::halfUpTaka(
            $type === 'percentage' ? (float)$basic * $rate / 100 : $rate
        );
        if (abs($amount - (float)$result['gpf_deduction']) > 0.009) {
            throw new DomainException('October GPF does not reconcile with approved calculation version.');
        }
        return ['amount' => $amount, 'source' => 'approved_salary_calculation_version_'.(int)$version->id,
            'effective_month' => '2026-10', 'rule' => $rule];
    }

    /**
     * Structured October 2026 components supersede the legacy expression engine.
     * Other months and explicitly custom components retain their existing evaluator.
     * Old published rows stay untouched in MySQL for audit/provenance.
     */
    public function preview(int $userId, int $year, int $month, ?array $rules = null): array
    {
        $rules ??= $this->published($userId, $year, $month);
        if (!\App\Services\SalaryStructuredArrearService::applicable($year, $month)) {
            return $this->previewLegacy($userId, $year, $month, $rules);
        }
        $structured = [];
        $other = [];
        foreach ($rules as $rule) {
            $code = (string) ((object) $rule)->code;
            if (in_array($code, SalaryStructuredArrearService::CODES, true)) $structured[] = $rule;
            else $other[] = $rule;
        }
        $structuredRows = $structured ? app(SalaryStructuredArrearService::class)->preview($userId, $structured) : [];
        $otherRows = $other ? $this->previewLegacy($userId, $year, $month, $other) : [];
        $byCode = [];
        foreach (array_merge($structuredRows, $otherRows) as $row) {
            $byCode[(string)($row['rule_id'] ?? 'custom-'.count($byCode))] = $row;
        }
        return array_values($byCode);
    }

    /** Legacy custom-rule evaluator. Never used for structured October components. */
    private function previewLegacy(int $userId, int $year, int $month, ?array $rules = null): array
    {
        $rules ??= $this->published($userId, $year, $month);
        $out = [];
        foreach ($rules as $rule) {
            $rule = (object) $rule;
            $row = [
                'rule_id' => $rule->id ?? null, 'code' => $rule->code ?? null,
                'title' => $rule->title, 'direction' => $rule->direction,
                'mode' => $rule->mode, 'formula' => $rule->formula ?? null,
                'amount' => $rule->amount ?? null,
                'revision' => (int) ($rule->revision ?? 1), 'calculated_amount' => null,
                'month_sources' => [], 'error' => null,
            ];
            try {
                $periods = self::months($rule, $year, $month);
                $row['months'] = $periods;
                if (in_array($rule->code ?? '', [
                    'BASIC_ARREAR_GROSS', 'BASIC_INCREMENT_ADJUSTMENT',
                    'SPECIAL_BENEFIT_RECOVERY', 'HOUSE_RENT_RECOVERY', 'ADDITIONAL_GPF',
                ], true) && $periods !== ['2026-07', '2026-08', '2026-09']) {
                    throw new DomainException('ARREAR_PERIOD_POLICY_MISMATCH: expected July-September 2026, exactly three months.');
                }

                $monthInputs = [];
                $expression = $rule->mode === 'fixed' ? '' : strtoupper((string) ($rule->formula ?? ''));
                if (preg_match('/\bSUM_MONTHS\s*\(/i', $expression)
                    && preg_match('/\)\s*\*\s*3(?:\.0+)?\s*$/', $expression)) {
                    throw new DomainException('PUBLISHED_FORMULA_DOUBLE_MULTIPLIER: SUM_MONTHS already totals entitlement months. HR must revise the published rule; no automatic overwrite.');
                }
                if (($rule->code ?? '') === 'BASIC_INCREMENT_ADJUSTMENT'
                    && preg_match('/\bJUNE_INCREMENT\b/', $expression)) {
                    throw new DomainException('JUNE_INCREMENT is not an approved separate arrear deduction. HR must revise the published rule.');
                }

                $needsPaid = preg_match('/\b(PAID_BASIC|PAID_SPECIAL_BENEFIT|PAID_HOUSE_RENT|PAID_GPF|OLD_INCREMENT|INCREMENT_AMOUNT)\b/', $expression);
                $needsJune = preg_match('/\b(JUNE_BASIC|JUNE_HOUSE_RENT|JUNE_GPF|JULY_INCREMENT|OLD_SCALE_INCREMENT|OLD_INCREMENT|INCREMENT_AMOUNT)\b/', $expression);
                $needsRevised = preg_match('/\b(REVISED_BASIC|REVISED_GPF|OCTOBER_BASIC|OCTOBER_GPF)\b/', $expression);
                $needsJuly = preg_match('/\b(JULY_BASIC|JULY_INCREMENT|JULY_SPECIAL_BENEFIT|JULY_GPF)\b/', $expression);
                $needsOctoberHouse = preg_match('/\bOCTOBER_HOUSE_RENT\b/', $expression);
                // An increment hold/no-increment is applied only when recorded in
                // the APPROVED frozen fixation snapshot. Otherwise July's paid
                // Basic is required; a missing July bill is not treated as zero.
                $incrementNotApplicable = false;
                if (($rule->code ?? '') === 'BASIC_INCREMENT_ADJUSTMENT'
                    && (str_contains($expression, 'JULY_INCREMENT') || str_contains($expression, 'OLD_SCALE_INCREMENT'))) {
                    $octFix = $this->fixation->forUser($userId, 2026, 10);
                    if (!$octFix) throw new DomainException('Approved October fixation is missing for increment decision.');
                    $saved = DB::table('salary_fixations')->where('id', $octFix['salary_fixation_id'])
                        ->where('status', 'approved')->first(['prepared_data']);
                    $prepared = $saved ? json_decode((string)$saved->prepared_data, true) : null;
                    if (!is_array($prepared)) throw new DomainException('Saved increment eligibility evidence is missing.');
                    $category = (string)($prepared['special_eligibility_category'] ?? '');
                    $incrementNotApplicable = ($prepared['hold_increment'] ?? false) === true
                        || in_array($category, ['adhoc','increment_hold','study_leave_restricted'], true);
                    if ($incrementNotApplicable) $needsJuly = false;
                }

                // Gather EVERY selected month, even if one saved bill is missing.
                // The formula remains UNKNOWN until all required inputs are verified.
                // Never silently replace a missing payment with a zero payment.
                $june = null;
                $juneError = null;
                if ($needsJune) {
                    try { $june = $this->history($userId, '2026-06'); }
                    catch (DomainException $e) { $juneError = $e->getMessage(); }
                }
                // The signed meeting method uses a June baseline, one verified July
                // old-pay reference and the revised October source, multiplied by
                // the selected entitlement month count. It does NOT require one
                // saved salary bill per entitlement month for these new tokens.
                $july = null;
                $julyError = null;
                if ($needsJuly) {
                    try { $july = $this->history($userId, '2026-07'); }
                    catch (DomainException $e) { $julyError = $e->getMessage(); }
                }
                $monthlyBenefits = [];
                if (($rule->code ?? '') === 'SPECIAL_BENEFIT_RECOVERY' && $julyError === null
                    && str_contains($expression, 'JULY_SPECIAL_BENEFIT')) {
                    $complete = true;
                    foreach ($periods as $benefitPeriod) {
                        try {
                            $monthlyBenefits[$benefitPeriod] = $benefitPeriod === '2026-07'
                                ? $july : $this->history($userId, $benefitPeriod);
                        } catch (DomainException) {
                            $complete = false;
                            break;
                        }
                    }
                    if (!$complete) {
                        // The explicitly selected JULY_BASELINE * 3 method is
                        // valid only as a disclosed July reference, never as
                        // evidence of actual payments in absent months.
                        $monthlyBenefits = [];
                        $row['special_benefit_source'] = [
                            'method' => 'verified_july_monthly_reference_times_entitlement_months',
                            'july_bill_id' => $july['source_record_id'] ?? null,
                            'effective_month' => '2026-07',
                            'actual_august_september_payments_verified' => false,
                        ];
                    } else {
                        $row['special_benefit_source'] = [
                            'method' => 'sum_of_three_approved_monthly_benefits',
                            'bill_ids' => array_map(static fn ($v) => $v['source_record_id'], $monthlyBenefits),
                        ];
                    }
                }
                $october = null;
                $octoberError = null;
                if ($needsOctoberHouse) {
                    try { $october = $this->configuredOctoberHouseRent($userId); }
                    catch (DomainException $e) { $octoberError = $e->getMessage(); }
                }
                $octoberFixed = $needsRevised ? $this->fixation->forUser($userId, 2026, 10) : null;
                $octoberGpf = null;
                $octoberGpfError = null;
                if (preg_match('/\b(?:OCTOBER_GPF|REVISED_GPF)\b/', $expression) && $octoberFixed) {
                    try { $octoberGpf = $this->approvedOctoberGpf($userId, $octoberFixed); }
                    catch (DomainException $e) { $octoberGpfError = $e->getMessage(); }
                }

                if ($needsRevised && !$octoberFixed) {
                    throw new DomainException('Approved October Basic is missing; amount UNKNOWN.');
                }
                $octoberFixationBasic = $octoberFixed['basic'] ?? null;
                $oldScaleIncrement = null;
                if (($rule->code ?? '') === 'BASIC_INCREMENT_ADJUSTMENT'
                    && str_contains($expression, 'OLD_SCALE_INCREMENT')) {
                    if (!$june || !is_numeric($june['paid_basic'] ?? null)) {
                        throw new DomainException('June 2015-scale Basic is missing; old-scale stage reconstruction unavailable.');
                    }
                    $grade = (int) ($octoberFixed['grade_number'] ?? $prepared['grade'] ?? $prepared['grade_number'] ?? 0);
                    $stages = config('kau_fixation_2026.'.$grade.'.stages2015', []);
                    $oldScaleIncrement = SalaryPayScaleArrearEngine::oldScaleIncrement(
                        is_array($stages) ? $stages : [], $june['paid_basic'], $prepared ?? []
                    );
                    if ($oldScaleIncrement['amount'] === null) {
                        throw new DomainException('OLD_SCALE_STAGE_MISSING: select a verified 2015 stage or manual arrear override.');
                    }
                    $row['old_scale_reference'] = $oldScaleIncrement;
                    // Confirm a reconstructed stage against the approved July
                    // saved bill when one exists. Reconstruction alone is not
                    // evidence that the employee actually received the step.
                    $referenceVerified = $oldScaleIncrement['status'] === 'ELIGIBILITY_NO_INCREMENT';
                    if (!$referenceVerified) {
                        try {
                            $historicalJuly = $this->history($userId, '2026-07');
                            $referenceVerified = abs((float) $historicalJuly['paid_basic']
                                - (float) $oldScaleIncrement['old_scale_july_basic']) < 0.009;
                            if ($referenceVerified) {
                                $row['july_paid_basic_bill_id'] = $historicalJuly['source_record_id'];
                            }
                        } catch (DomainException) {
                            // No confirmed July bill: retain the reconstruction
                            // for a provisional display, never verified payment.
                        }
                    }
                    $row['payment_reference_status'] = $referenceVerified
                        ? 'OLD_SCALE_REFERENCE_RECONCILED' : 'RECONSTRUCTED_ENTITLEMENT_NOT_PAID_HISTORY';
                }
                $missingPeriods = [];
                foreach ($periods as $period) {
                    $paid = [];
                    $historyError = null;
                    if ($needsPaid) {
                        try { $paid = $this->history($userId, $period); }
                        catch (DomainException $e) {
                            $historyError = $e->getMessage();
                            $missingPeriods[] = $period;
                        }
                    }
                    [$y, $m] = array_map('intval', explode('-', $period));
                    $fixed = $needsRevised ? $this->fixation->forUser($userId, $y, $m) : null;
                    if ($needsRevised && !$fixed) throw new DomainException('No approved revised Basic for '.$period.'; amount UNKNOWN.');
                    $revised = $fixed['basic'] ?? null;
                    $values = [
                        'REVISED_BASIC' => $revised,
                        'JUNE_BASIC' => $june['paid_basic'] ?? null,
                        'JUNE_GPF' => $june['paid_gpf'] ?? null,
                        'PAID_BASIC' => $paid['paid_basic'] ?? null,
                        'OLD_INCREMENT' => isset($paid['paid_basic'], $june['paid_basic']) ? $paid['paid_basic'] - $june['paid_basic'] : null,
                        'INCREMENT_AMOUNT' => isset($paid['paid_basic'], $june['paid_basic']) ? $paid['paid_basic'] - $june['paid_basic'] : null,
                        'PAID_SPECIAL_BENEFIT' => $paid['paid_special_benefit'] ?? null,
                        'PAID_HOUSE_RENT' => $paid['paid_house_rent'] ?? null,
                        'JUNE_HOUSE_RENT' => $june['paid_house_rent'] ?? null,
                        'PAID_GPF' => $paid['paid_gpf'] ?? null,
                        'REVISED_GPF' => $octoberGpf['amount'] ?? null,
                        'JULY_BASIC' => $july['paid_basic'] ?? null,
                        'OLD_SCALE_INCREMENT' => $oldScaleIncrement['amount'] ?? null,
                        'JULY_INCREMENT' => $incrementNotApplicable ? 0 :
                            (isset($july['paid_basic'], $june['paid_basic'])
                                ? round((float)$july['paid_basic']-(float)$june['paid_basic'],2) : null),
                        'JULY_SPECIAL_BENEFIT' => $monthlyBenefits[$period]['paid_special_benefit'] ?? ($july['paid_special_benefit'] ?? null),
                        'JULY_GPF' => $july['paid_gpf'] ?? null,
                        'OCTOBER_HOUSE_RENT' => $october['amount'] ?? null,
                        'OCTOBER_BASIC' => $octoberFixationBasic,
                        'OCTOBER_GPF' => $octoberGpf['amount'] ?? null,
                        'MONTH_COUNT' => count($periods),
                    ];
                    $monthInputs[] = $values;
                    $row['month_sources'][] = [
                        'period' => $period,
                        'bill_id' => $paid['source_record_id'] ?? null,
                        'revised_fixation_id' => $fixed['salary_fixation_id'] ?? null,
                        'source_status' => $historyError ? 'MISSING_OR_AMBIGUOUS' : ($needsPaid ? 'SAVED_BILL_FOUND' : 'NOT_REQUIRED_BY_FORMULA'),
                        'source_warning' => $historyError,
                        'values' => $values,
                    ];
                }
                $row['missing_paid_periods'] = $missingPeriods;
                if ($juneError !== null) $row['baseline_warning'] = $juneError;
                if ($julyError !== null) $row['july_reference_warning'] = $julyError;
                if (($rule->code ?? '') === 'BASIC_INCREMENT_ADJUSTMENT' && !$incrementNotApplicable
                    && $julyError === null && $juneError === null) {
                    $grade = (int)($prepared['grade'] ?? 0);
                    $stages = config('kau_fixation_2026.'.$grade.'.stages2015', []);
                    $reference = is_array($stages)
                        ? SalaryOldScaleIncrementReference::nextStageDifference($stages, (float)$june['paid_basic']) : null;
                    if (!$reference || abs((float)$july['paid_basic'] - (float)$reference['next_2015_stage']) > 0.009) {
                        throw new DomainException('July 2015-scale increment is not verified against consecutive Grade/Stage values; requires HR review.');
                    }
                    $row['july_increment_source'] = [
                        'june_bill_id' => $june['source_record_id'],
                        'july_bill_id' => $july['source_record_id'],
                        'stage_reference' => $reference,
                        'effective_month' => '2026-07',
                    ];
                }
                if (($rule->code ?? '') === 'BASIC_INCREMENT_ADJUSTMENT' && !$incrementNotApplicable && $julyError !== null) {
                    // HR may use the 2015 Grade/Stage reference to identify a
                    // likely increment. It is not a historical payment record;
                    // therefore the formula stays UNKNOWN until HR supplies
                    // July paid Basic or an evidence-backed manual override.
                    $grade = (int)($prepared['grade'] ?? 0);
                    $stages2015 = config('kau_fixation_2026.'.$grade.'.stages2015', []);
                    if (is_array($stages2015) && isset($june['paid_basic'])) {
                        $row['old_scale_stage_reference'] = SalaryOldScaleIncrementReference::nextStageDifference(
                            $stages2015, (float)$june['paid_basic']
                        );
                    }
                }
                if ($octoberError !== null) $row['october_reference_warning'] = $octoberError;
                if ($octoberGpfError !== null) $row['october_gpf_warning'] = $octoberGpfError;
                if ($octoberGpf !== null) $row['october_gpf_source'] = $octoberGpf;

                if ($needsOctoberHouse && $october !== null) {
                    $row['october_reference_source'] = $october['source'];
                }
                if ($rule->mode === 'fixed') {
                    $amount = $rule->amount;
                    if (!is_numeric($amount)) throw new DomainException('Fixed amount is missing.');
                    $row['calculated_amount'] = round((float)$amount, 2);
                } elseif ($rule->mode === 'percentage') {
                    $rate = $rule->amount;
                    if (!is_numeric($rate) || !$monthInputs) throw new DomainException('Percentage rate is missing.');
                    $basis = (string) ($rule->formula ?: 'REVISED_BASIC');
                    $value = $this->formula->evaluate('SUM_MONTHS('.$basis.')', ['MONTH_COUNT' => count($periods)], $monthInputs);
                    $row['calculated_amount'] = round($value * (float)$rate / 100, 2, PHP_ROUND_HALF_UP);
                } else {
                    $row['calculated_amount'] = $this->formula->evaluate((string) $rule->formula,
                        ['MONTH_COUNT' => count($periods)] + ($monthInputs[0] ?? []), $monthInputs);
                }
                if (($rule->code ?? '') === 'BASIC_INCREMENT_ADJUSTMENT'
                    && ($row['payment_reference_status'] ?? '') === 'RECONSTRUCTED_ENTITLEMENT_NOT_PAID_HISTORY') {
                    // Show the estimated figure without silently treating the
                    // missing July paid increment as certified financial data.
                    $row['estimated_amount'] = $row['calculated_amount'];
                    $row['display_amount'] = $row['estimated_amount'];
                    $row['calculated_amount'] = null;
                    $row['missing_evidence'] = true;
                    $row['approval_eligible'] = false;
                    $row['error'] = '2015-stage increment reconstructed; July paid amount not verified. Confirm with evidence or manually override.';
                }
                if (($rule->code ?? '') === 'HOUSE_RENT_RECOVERY') {
                    // The stored rule represents one signed adjustment. Positive
                    // difference belongs in additions, negative in deductions.
                    $house = SalaryPayScaleArrearEngine::directionalHouseRent((float) $row['calculated_amount']);
                    $row['direction'] = $house['direction'];
                    $row['calculated_amount'] = $house['amount'];
                    $row['house_rent_signed_adjustment'] = true;
                } elseif ($row['calculated_amount'] < 0) {
                    throw new DomainException('Negative amount is not allowed for a directional arrear line; choose its direction explicitly.');
                }
            } catch (DomainException $e) {
                $row['error'] = $e->getMessage();
                $row['calculated_amount'] = null;
                $row['display_amount'] = 0.0;
                $row['missing_evidence'] = true;
                $row['approval_eligible'] = false;
            }
            $out[] = $row;
        }
        // Gross presentation already comparing revised vs paid Basic must not deduct an old increment twice.
        $netBased = collect($out)->contains(fn ($r) => $r['direction'] === 'payable' && $r['error'] === null
            && preg_match('/REVISED_BASIC\s*-\s*PAID_BASIC/i', (string)($r['formula'] ?? '')));
        if ($netBased) {
            foreach ($out as &$row) {
                if ($row['direction'] === 'deduction' && $row['code'] === 'BASIC_INCREMENT_ADJUSTMENT') {
                    $row['calculated_amount'] = null;
                    $row['error'] = 'Not applied: Basic arrear formula already subtracts paid Basic. Prevented double old-increment adjustment.';
                }
            }
            unset($row);
        }
        return $out;
    }
}
