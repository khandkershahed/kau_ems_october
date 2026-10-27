<?php

namespace App\Services;

use App\Models\SalaryGenerate;
use App\Models\SalaryFixationAdjustment;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Isolated pay-fixation estimate; never writes employee_infos or salary_generates.
 * Stage data comes from the user-supplied 2015/2026 fixation reference.
 * Final payroll acceptance requires an independent official verification.
 */
class SalaryFixationService
{
    public const SOURCE_DATE = '2026-06-30';

    public function prepare(User $user): array
    {
        $user->loadMissing([
            'employeeInfo.payScale',
            'employeeInfo.payrollGroup.grade',
            'employeeInfo.payrollGroup.payrollGroupType',
            'employeeInfo.departmentOffice',
            'joiningDate', 'age', 'nid', 'phoneNumbers', 'promotions',
        ]);

        $info = $user->employeeInfo;
        $adjustment = SalaryFixationAdjustment::where('user_id', $user->id)->first();
        $warnings = [];
        $blockers = []; // Production-data mismatches must not be treated as validated fixation inputs.
        // A user may have more than one employee_infos record in the imported production data.
        // Never certify whichever profile the hasOne relationship happened to resolve first.
        $employeeProfileCount = DB::table('employee_infos')->where('user_id', $user->id)->count();
        if ($employeeProfileCount > 1) {
            $warnings[] = 'More than one employee profile is linked to this user; HR must reconcile the identity.';
            $blockers[] = 'Ambiguous employee identity: multiple employee profiles share this user ID.';
        }
        if (!$info) {
            return ['ready' => false, 'warnings' => ['Employee salary profile is missing.']];
        }

        $gradeName = (string) ($info->payrollGroup?->grade?->grade_name ?? '');
        $grade = $this->gradeNumber($gradeName);
        // HR may supply a documented grade when the legacy payroll mapping is
        // ambiguous. This affects fixation only, never employee_infos or old bills.
        if ($adjustment?->grade_override !== null) {
            $grade = (int) $adjustment->grade_override;
            $gradeName = 'Grade '.$grade;
            $warnings[] = 'Fixation grade selected directly by HR; evidence: '
                .($adjustment->evidence_reference ?: 'NOT PROVIDED');
        }
        $scales = config('kau_fixation_2026', []);
        if (!$grade || !isset($scales[$grade])) {
            $warnings[] = 'The existing payroll group does not map unambiguously to a Grade 1–20.';
            $blockers[] = 'No recognized 2015-grade mapping.';
        }

        $payScaleName = (string) ($info->payScale?->scale_name ?? '');
        if (!preg_match('/\b2015\b/u', $payScaleName)) {
            $warnings[] = 'Assigned existing pay scale is not identifiable as the 2015 scale; confirm the applicable prior scale.';
            $blockers[] = 'Employee is not assigned to a recognizable 2015 pay scale.';
        }
        $groupScaleId = $info->payrollGroup?->grade?->pay_scale_id;
        if (!$groupScaleId || (int) $groupScaleId !== (int) $info->pay_scale_id) {
            $warnings[] = 'The employee pay-scale assignment conflicts with the scale attached to their payroll-group grade.';
            $blockers[] = 'Employee pay scale and grade pay scale disagree.';
        }

        if (!$user->isActive) {
            $warnings[] = 'Employee account is inactive; confirm eligibility.';
            $blockers[] = 'Employee account inactive.';
        }
        if ($user->promotions->isNotEmpty()) {
            $warnings[] = 'Promotion records exist but do not contain effective dates; this needs manual service-history review.';
        }

        $join = $user->joiningDate?->joining_date;
        if (!$join) {
            $warnings[] = 'Original joining date is not available.';
            $blockers[] = 'Missing joining date.';
        } elseif (Carbon::parse($join)->gt(Carbon::parse(self::SOURCE_DATE))) {
            $warnings[] = 'Employee joined after 30 June 2026; ordinary existing-staff fixation may not apply.';
            $blockers[] = 'Joined after the cutoff date.';
        }

        // Optional evidence-backed historical baseline for special HR cases (for
        // example, Newton: October 2025 instead of the ordinary June 2026).
        // No employee is selected by name, and no historical record is rewritten.
        $baselinePeriod = $adjustment?->baseline_period ?: '2026-06';
        [$baselineYear, $baselineMonth] = array_map('intval', explode('-', $baselinePeriod));
        $baselineDate = Carbon::create($baselineYear, $baselineMonth, 1)->endOfMonth()->toDateString();
        $juneSalary = SalaryGenerate::query()
            ->where('user_id', $user->id)
            ->where('salaryGenerationYear', $baselineYear)
            ->where('monthId', $baselineMonth)
            ->latest('id')->first();

        $source = $juneSalary
            ? ($baselinePeriod === '2026-06' ? 'june_2026_payroll' : 'historical_payroll_override')
            : 'current_salary_setup';
        if ($baselinePeriod !== '2026-06') {
            $warnings[] = 'Nonstandard historical baseline '.$baselinePeriod.' explicitly selected by HR.';
            if (!$adjustment?->reason || !$adjustment?->evidence_reference) {
                $blockers[] = 'Historical baseline override requires an HR reason and evidence reference.';
            }
        }
        $sourceId = $juneSalary?->id;
        $allowances = $juneSalary ? $juneSalary->salaryAllowanceCalcObject : $info->additionObjects;
        $deductions = $juneSalary ? $juneSalary->salaryDeductionCalcObject : $info->substractionObjects;
        // Some production employee setup rows are double-encoded JSON.
        // Decode only for the fallback display; historical source eligibility still fails closed.
        if (is_string($allowances)) {
            $allowances = json_decode($allowances, true);
            $warnings[] = 'Current salary component JSON required decoding; verify its original record.';
        }
        if (is_string($deductions)) {
            $deductions = json_decode($deductions, true);
            $warnings[] = 'Current deduction component JSON required decoding; verify its original record.';
        }
        $allowances = is_array($allowances) ? $allowances : [];
        $deductions = is_array($deductions) ? $deductions : [];

        if (!$juneSalary) {
            $warnings[] = 'No payroll record for selected baseline '.$baselinePeriod.'. Current setup is not historical proof.';
            $blockers[] = $baselinePeriod === '2026-06'
                ? 'No approved June 2026 payroll source.'
                : 'Selected historical baseline payroll record is missing.';
        } else {
            if ($juneSalary->reviewStatus !== 'approved') {
                $warnings[] = 'Selected baseline payroll snapshot has not been approved.';
                $blockers[] = 'Selected baseline payroll snapshot unapproved.';
            }
            $juneGrade = $this->gradeNumber((string) $juneSalary->grade);
            if (!$juneGrade || $juneGrade !== $grade) {
                $warnings[] = 'Baseline paid grade differs from the current employee grade; requires historical personnel review.';
                $blockers[] = 'Baseline paid grade does not match current assigned grade.';
            }
            if (!$juneSalary->salaryGenerationDate || Carbon::parse($juneSalary->salaryGenerationDate)->format('Y-m-d') !== $baselineDate) {
                $warnings[] = 'Baseline payroll record date does not match the selected month end.';
                $blockers[] = 'Baseline payroll source date mismatch.';
            }
        }

        $basicMatches = $this->matches($allowances, '/\bbasic\b/iu');
        if (count($basicMatches) !== 1) {
            $warnings[] = 'Exactly one Basic Pay component is required; found '.count($basicMatches).'.';
            $blockers[] = 'Missing or ambiguous basic-pay component.';
        }
        $basicObject = $basicMatches[0] ?? null;
        $basic = $basicObject ? $this->componentValue($basicObject) : null;
        if ($basicObject && strtolower((string) ($basicObject['calcType'] ?? 'fixed')) === 'percentage') {
            $warnings[] = 'Basic Pay component is percentage-based; cannot establish a reliable fixed basic.';
            $blockers[] = 'Basic-pay source is percentage-based.';
            $basic = null;
        }
        if ($basic === null || $basic <= 0) {
            $warnings[] = 'Valid historical basic salary is missing.';
            $blockers[] = 'Invalid or missing historic basic salary.';
        }

        $originalBasic = $basic;
        if ($adjustment?->old_basic_override !== null) {
            $basic = (float) $adjustment->old_basic_override;
            $warnings[] = 'Historical basic manually corrected; evidence: '.($adjustment->evidence_reference ?: 'MISSING');
            if ($adjustment->evidence_reference && $adjustment->reason) {
                $blockers = array_values(array_diff($blockers, [
                    'No approved June 2026 payroll source.',
                    'June payroll snapshot unapproved.',
                    'Invalid or missing historic basic salary.',
                    'Missing or ambiguous basic-pay component.',
                    'Historic basic does not match a verified 2015 pay stage.',
                ]));
            } else {
                $blockers[] = 'Manual correction requires reason and official evidence reference.';
            }
            if ($baselinePeriod !== '2026-06' && !$juneSalary) {
                $blockers[] = 'Selected historical baseline payroll record is missing.';
            }
        }
        $ppMatches = $this->matches($allowances, '/\bpersonal\s*pay\b|^\s*PP\s*$/iu');
        if (count($ppMatches) > 1) {
            $warnings[] = 'Multiple Personal Pay components; review before accepting.';
            $blockers[] = 'Ambiguous personal-pay components.';
        }
        $pp = count($ppMatches) === 1 ? $this->componentValue($ppMatches[0]) : 0;
        if ($pp === null) {
            $warnings[] = 'Personal Pay component contains an invalid value.';
            $blockers[] = 'Invalid personal-pay amount.';
            $pp = 0;
        }
        if ($adjustment?->personal_pay_override !== null) {
            $pp = (float) $adjustment->personal_pay_override;
            $warnings[] = 'Personal pay was manually corrected with an audit record.';
        }
        if (!$ppMatches) {
            $warnings[] = 'Personal Pay (PP) is not separately documented in the salary components; confirm whether PP is zero.';
        }

        $house = $this->componentFromPatterns($allowances, ['/house\s*rent|house\s*allowance|বাড়ি\s*ভাড়া|বাড়ি\s*ভাড়া/iu'], $basic ?? 0);
        $medical = $this->componentFromPatterns($allowances, ['/medical|চিকিৎসা/iu'], $basic ?? 0);
        $totalDeduction = 0.0;
        foreach ($deductions as $item) {
            if (is_array($item)) {
                $totalDeduction += $this->componentValue($item, $basic ?? 0) ?? 0;
            }
        }
        if ($house === null || $medical === null) {
            $warnings[] = 'House rent or medical allowance is unavailable. Gross/net projections in the HTML are incomplete.';
        }
        $warnings[] = 'The 2028 house-rent category and future deductions cannot be determined from the current database.';
        $warnings[] = 'Original HTML is an unofficial formula preview, not a verified iBAS++ calculation or approval.';
        // Resolve exceptions from the supplied employee-code lists and report archive.
        // Unlisted employees retain their existing database behavior.
        $eligibility = config('kau_fixation_eligibility', []);
        $employeeCode = trim((string) $info->employeeCode);
        $originalReports = DB::table('salary_fixation_reference_reports')
            ->where('employee_code', $employeeCode)->orderBy('id')
            ->get()->map(fn ($r) => (array) $r)->all();
        $singleReport = count($originalReports) === 1 ? $originalReports[0] : null;
        $studyLeave = $eligibility['study_leave'][$employeeCode] ?? null;
        $isHoldListed = array_key_exists($employeeCode, $eligibility['increment_hold'] ?? []);
        $isAdhocListed = array_key_exists($employeeCode, $eligibility['adhoc'] ?? []);
        $hasListedException = is_array($studyLeave) && ($studyLeave['first_increment_allowed'] ?? false) === true;
        $isStudyLeaveRestricted = is_array($studyLeave) && !$hasListedException;
        $specialCategory = $isHoldListed ? 'increment_hold' : ($isAdhocListed ? 'adhoc' : ($hasListedException ? 'study_leave_exception' : ($isStudyLeaveRestricted ? 'study_leave_restricted' : 'ordinary')));

        $conflictingCategories = (int) $isHoldListed + (int) $isAdhocListed + (int) is_array($studyLeave);
        if ($conflictingCategories > 1) {
            $blockers[] = 'Employee appears in conflicting special eligibility lists.';
        }
        if ($isHoldListed || $isAdhocListed || $isStudyLeaveRestricted) {
            $warnings[] = 'The first July 2026 increment is suppressed by the supplied special-category reference.';
        }
        if ($hasListedException) {
            $warnings[] = 'Education-leave first-increment exception explicitly recorded in the supplied workbook.';
        }
        if (is_array($studyLeave)) {
            $warnings[] = 'No original PDF for this education-leave employee was supplied; comparison against an original report is impossible.';
            $blockers[] = 'Education-leave employee has no supplied original fixation PDF for validation.';
        }
        if ($info->holdIncrement && !$isHoldListed && !$isAdhocListed && !is_array($studyLeave)
            && !$singleReport && (!$adjustment || $adjustment->increment_2026 === 'automatic')) {
            $warnings[] = 'holdIncrement is true in the database but this employee is not in the 12-person hold list; eligibility requires manual verification.';
            $blockers[] = 'Database holdIncrement conflicts with the supplied special-category lists.';
        }
        if (!$info->holdIncrement && ($isHoldListed || $isAdhocListed)) {
            $warnings[] = 'Database holdIncrement is false but the supplied official-category report requires the increment to be withheld.';
        }
        // The existing engine models withheld increments in both 2026 and 2027.
        // This remains a provisional value for study-leave cases until individual reports clarify 2027 entitlement.
        $effectiveHoldIncrement = $isHoldListed || $isAdhocListed || $isStudyLeaveRestricted || (!$hasListedException && (bool) $info->holdIncrement);
        if ($singleReport && !$isHoldListed && !$isAdhocListed && !is_array($studyLeave)) {
            // Official employee-specific report entitlement takes precedence over the
            // default-on holdIncrement flag, but every calculated amount is still
            // independently tested against that report before approval.
            $effectiveHoldIncrement = (float) $singleReport['increment_2026'] <= 0;
            if ((bool) $info->holdIncrement !== $effectiveHoldIncrement) {
                $warnings[] = 'Default database holdIncrement differs from the supplied employee fixation report.';
            }
        }
        if ($hasListedException && $info->holdIncrement) {
            $warnings[] = 'The explicit education-leave exception overrides a potentially stale database holdIncrement flag for preview only.';
        }
        if ($info->incrementDate && !Carbon::parse($info->incrementDate)->isSameDay(Carbon::parse('2026-07-01'))) {
            $warnings[] = 'Stored incrementDate does not equal 1 July 2026; review increment entitlement.';
        }

        $firstIncrementHeld = $effectiveHoldIncrement;
        $secondIncrementHeld = $effectiveHoldIncrement;
        if ($adjustment && $adjustment->increment_2026 !== 'automatic') {
            $firstIncrementHeld = $adjustment->increment_2026 === 'hold';
            $warnings[] = '2026 increment entitlement manually set by authorized staff.';
        }
        if ($adjustment && $adjustment->increment_2027 !== 'automatic') {
            $secondIncrementHeld = $adjustment->increment_2027 === 'hold';
            $warnings[] = '2027 increment entitlement manually set by authorized staff.';
        }
        // Five explicitly listed Study Leave exceptions receive ONE increment in
        // July 2026, and NO second increment in July 2027. No other employee
        // category is affected by this exception. Preserve conflicting manual
        // adjustment history rather than silently rewriting it.
        $oneIncrementException = self::studyLeaveSingleIncrementHolds($studyLeave);
        if ($oneIncrementException !== null) {
            $firstIncrementHeld = $oneIncrementException['hold_2026'];
            $secondIncrementHeld = $oneIncrementException['hold_2027'];
            if ($adjustment && in_array($adjustment->increment_2026, ['hold'], true)) {
                $blockers[] = 'Manual 2026 increment HOLD conflicts with the verified Study Leave one-increment exception.';
            }
            if ($adjustment && in_array($adjustment->increment_2027, ['allow'], true)) {
                $blockers[] = 'Manual 2027 increment ALLOW conflicts with the verified Study Leave one-increment exception.';
            }
            $warnings[] = 'Study Leave: first increment allowed in July 2026; second increment NOT allowed in July 2027.';
        } elseif (is_array($studyLeave) && (!$adjustment || $adjustment->increment_2027 === 'automatic')) {
            $blockers[] = 'Study-leave 2027 increment entitlement requires an explicit HR decision.';
        }
        if ($adjustment && ($adjustment->increment_2026 !== 'automatic' || $adjustment->increment_2027 !== 'automatic')
            && (!$adjustment->reason || !$adjustment->evidence_reference)) {
            $blockers[] = 'Increment eligibility override needs a reason and evidence reference.';
        }
        // Calculate against the database-backed 2026 grade steps, not an
        // unverified browser dropdown. The config is a historical comparison.
        $databaseStageVerified = false;
        $stageOverride = null;
        if ($grade && isset($scales[$grade])) {
            $scale = DB::table('pay_scales')->where('scale_name', 'Govt. Pay Scale 2026')->first();
            $dbGrade = $scale ? DB::table('grades')->where('pay_scale_id', $scale->id)
                ->where('grade_name', 'Grade '.$grade)->first() : null;
            $dbStages = $dbGrade ? DB::table('pay_scale_stages')->where('grade_id', $dbGrade->id)
                ->orderBy('stage_number')->pluck('basic_amount')->map(fn ($v) => (float) $v)->all() : [];
            $databaseStageVerified = $dbStages === array_map('floatval', $scales[$grade]['stages2026']);
            if (!$databaseStageVerified) {
                $blockers[] = '2026 database pay scale stages missing or inconsistent; run and verify the stage seeder.';
            } else {
                $stageOverride = $scales[$grade];
                $stageOverride['stages2026'] = $dbStages;
                $stageOverride['init2026'] = (float) $dbGrade->min;
                $stageOverride['max2026'] = (float) $dbGrade->max;
            }
        }
        $result = null;
        if ($grade && isset($scales[$grade]) && $basic !== null && $basic > 0) {
            $result = $this->calculate($grade, $basic, $pp, $firstIncrementHeld, $secondIncrementHeld, $stageOverride);
            if (!in_array((float) $basic, array_map('floatval', $scales[$grade]['stages2015']), true)) {
                $warnings[] = 'Historic basic is not a listed 2015 stage for this grade; review special/increment cases.';
                $blockers[] = 'Historic basic does not match a verified 2015 pay stage.';
            }
            if ($result['above_ceiling']) {
                $warnings[] = 'Calculated fitment exceeded the top listed new grade stage; requires manual treatment.';
                $blockers[] = 'Calculated fitment exceeds the 2026 grade ceiling.';
            }
            if ($result['at_last_stage'] && $grade !== 1 && !$effectiveHoldIncrement) {
                $warnings[] = 'At the new grade ceiling: increments beyond its final listed stage are not auto-approved.';
            }
        }

        // Never auto-approve a result that disagrees with the user's original
        // employee-specific PDF. Absent and ambiguous references fail closed.
        $reportRows = $originalReports;
        $referenceStatus = 'missing';
        if (count($reportRows) > 1) {
            $referenceStatus = 'ambiguous';
            $blockers[] = 'Multiple original reports share this employee code; reconcile the identity before approval.';
        } elseif (count($reportRows) === 1 && $result) {
            $ref = $reportRows[0];
            $different = [];
            foreach ([
                'old_basic' => $basic,
                'increment_2026' => $result['increment_2026'],
                'phase_2026_07' => $result['phase_2026_07'],
                'phase_2027_01' => $result['phase_2027_01'],
                'phase_2027_07' => $result['phase_2027_07'],
            ] as $field => $computed) {
                if (abs((float) $ref[$field] - (float) $computed) > 0.01) $different[] = $field;
            }
            $referenceStatus = $different ? 'mismatch' : 'matched';
            if ($different) {
                $blockers[] = 'Original fixation PDF disagrees: '.implode(', ', $different).'.';
                $warnings[] = 'Source: '. $ref['report_file'];
            }
        } else {
            $blockers[] = 'No original employee fixation PDF is available for comparison.';
        }

        $sourceHash = hash('sha256', json_encode([
            $source, $sourceId, $baselinePeriod, $baselineDate,
            $juneSalary?->grade, $juneSalary?->reviewStatus,
            $info->id, $info->pay_scale_id, $info->payroll_group_id,
            $info->holdIncrement, $employeeCode, $specialCategory, $effectiveHoldIncrement,
            $info->incrementDate, $join, $allowances, $deductions,
            $adjustment?->updated_at?->toISOString(), $adjustment?->getAttributes(),
            $scales[$grade]['stages2026'] ?? null,
            $originalReports, // A changed PDF reference invalidates a saved review snapshot.
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'ready' => $result !== null && count($blockers) === 0,
            'blockers' => array_values(array_unique($blockers)),
            'user_id' => $user->id,
            'employee_code' => $info->employeeCode,
            'name' => $user->name,
            'grade' => $grade,
            'grade_name' => $gradeName,
            'pay_scale_name' => $payScaleName,
            'designation' => $info->payrollGroup?->payrollGroupType?->group_name ?? '',
            'department' => $info->departmentOffice?->name ?? '',
            'basic' => $basic,
            'personal_pay' => (float) $pp,
            'house_rent_old' => $house,
            'medical' => $medical,
            'deductions' => $totalDeduction,
            'source_type' => $source,
            'baseline_period' => $baselinePeriod,
            'baseline_date' => $baselineDate,
            'employee_profile_count' => $employeeProfileCount,
            'source_record_id' => $sourceId,
            'source_sha256' => $sourceHash,
            'hold_increment' => $firstIncrementHeld,
            'hold_increment_2027' => $secondIncrementHeld,
            'manual_fields' => $adjustment?->additional_fields ?? [],
            'manual_evidence' => $adjustment?->evidence_reference,
            'database_stage_verified' => $databaseStageVerified,
            'reference_match_status' => $referenceStatus,
            'reference_report_files' => array_column($reportRows, 'report_file'),
            'database_hold_increment' => (bool) $info->holdIncrement,
            'special_eligibility_category' => $specialCategory,
            'first_increment_exception' => $hasListedException,
            'joining_date' => $join,
            'nid' => $user->nid?->nid_no ?? '',
            'phone' => $user->phoneNumbers->first()?->phoneNumber ?? '',
            'dob' => $this->dateIfPresent($user->age?->age),
            'warnings' => array_values(array_unique($warnings)),
            'calculation' => $result,
        ];
    }

    /**
     * Source-identified Study Leave exception: exactly one July 2026 increment;
     * no additional July 2027 increment. Null means no such exception applies.
     * This pure decision does not change an existing approved fixation in MySQL.
     * @return array{hold_2026:bool,hold_2027:bool}|null
     */
    public static function studyLeaveSingleIncrementHolds(mixed $entry): ?array
    {
        return is_array($entry) && ($entry['first_increment_allowed'] ?? null) === true
            ? ['hold_2026' => false, 'hold_2027' => true]
            : null;
    }

    public function calculate(int $grade, float $oldBasic, float $personalPay = 0, bool $holdIncrement = false, ?bool $holdIncrement2027 = null, ?array $scaleOverride = null): array
    {
        $row = $scaleOverride ?? (config('kau_fixation_2026')[$grade] ?? null);
        if (!$row) {
            throw new \InvalidArgumentException('Unknown salary grade.');
        }
        $oldTotal = round($oldBasic + $personalPay, 2);
        $oldDiff = round($oldTotal - $row['init2015'], 2);
        $threshold = round($row['init2026'] + $oldDiff, 2);
        $stages = $row['stages2026'];
        $idx = null;
        foreach ($stages as $i => $stage) {
            if ($stage >= $threshold) {
                $idx = $i;
                break;
            }
        }
        $aboveCeiling = $idx === null;
        $idx = $idx ?? count($stages) - 1;
        $fit = $stages[$idx];
        $holdIncrement2027 = $holdIncrement2027 ?? $holdIncrement;
        $step2026 = ($grade === 1 || $holdIncrement) ? 0 : 1;
        $step2027 = ($grade === 1 || $holdIncrement2027) ? 0 : 1;
        $next = $stages[min($idx + $step2026, count($stages) - 1)];
        $nextNext = $stages[min($idx + $step2026 + $step2027, count($stages) - 1)];
        $inc1 = $next - $fit;
        $inc2 = $nextNext - $next;
        $difference = max(0, round($fit - $oldTotal, 2));
        $rate1 = $grade <= 9 ? .40 : .50;
        $rate2 = $grade <= 9 ? .70 : .75;
        return [
            'old_total' => $oldTotal,
            'old_initial' => $row['init2015'],
            'new_initial' => $row['init2026'],
            'initial_difference' => $oldDiff,
            'threshold' => $threshold,
            'stage_index' => $idx + 1,
            'fitment_basic' => $fit,
            'increase_difference' => $difference,
            'increment_2026' => $inc1,
            'increment_2027' => $inc2,
            'phase_2026_07' => round($oldTotal + $rate1 * $difference + $inc1, 2),
            'phase_2027_01' => round($oldTotal + $rate2 * $difference + $inc1, 2),
            'phase_2027_07' => $nextNext,
            'phase_2027_07_before_increment' => $next,
            'rate_2026' => $rate1,
            'rate_2027' => $rate2,
            'above_ceiling' => $aboveCeiling,
            'at_last_stage' => $idx === count($stages) - 1,
            'hold_increment' => $holdIncrement,
            'hold_increment_2027' => $holdIncrement2027,
        ];
    }

    private function gradeNumber(string $name): ?int
    {
        if (preg_match('/(?:grade|গ্রেড)\s*[-:：]?\s*0*(\d{1,2})/iu', $name, $m)) {
            $no = (int) $m[1];
            return $no >= 1 && $no <= 20 ? $no : null;
        }
        return null;
    }

    private function matches(array $items, string $pattern): array
    {
        return array_values(array_filter($items, function ($item) use ($pattern) {
            if (!is_array($item)) {
                return false;
            }
            $name = (string) ($item['name'] ?? $item['payroll_component_type']['name'] ?? '');
            return preg_match($pattern, $name) === 1;
        }));
    }

    private function componentFromPatterns(array $items, array $patterns, float $basic): ?float
    {
        foreach ($patterns as $pattern) {
            $matched = $this->matches($items, $pattern);
            if ($matched) {
                return $this->componentValue($matched[0], $basic);
            }
        }
        return null;
    }

    private function componentValue(array $item, float $basic = 0): ?float
    {
        if (!isset($item['amount']) || !is_numeric($item['amount'])) {
            return null;
        }
        $raw = (float) $item['amount'];
        if ($raw < 0) {
            return null;
        }
        return round(strtolower((string) ($item['calcType'] ?? 'fixed')) === 'percentage'
            ? $basic * $raw / 100 : $raw, 2);
    }

    private function dateIfPresent(?string $value): string
    {
        if (!$value || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }
        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable $e) {
            Log::debug('Invalid stored employee date of birth value.');
            return '';
        }
    }
}
