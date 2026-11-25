<?php

namespace App\Services;

use App\Models\EmployeeInfo;
use App\Models\PayrollComponentType;
use App\Models\SalaryMonthlyComponentAdjustment;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Computes and persists the 19-component monthly payroll breakdown per employee and (year, month).
 * Monthly arrears and rule overrides are strictly month-scoped (non-carry-forward) and never mutate
 * recurring employee_infos defaults unless saveAsRecurringDefault() is explicitly called.
 */
final class MonthlySalarySetupService
{
    /** Employee codes exempt from GPF (Vice Chancellor, Treasurer). */
    private const GPF_EXEMPT_CODES = ['20082', '20137'];

    public static function classifyDirection(string $name): string
    {
        $lower = strtolower(trim($name));
        if (
            str_contains($lower, 'deduction')
            || str_contains($lower, 'gpf')
            || str_contains($lower, 'provident')
            || str_contains($lower, 'tax')
            || str_contains($lower, 'revenue stamp')
        ) {
            return 'deduction';
        }

        return 'allowance';
    }

    public static function isBasicPay(string $name): bool
    {
        return preg_match('/\bbasic(?:\s+pay|\s+salary)?\b/i', $name) === 1;
    }

    public static function isSpecialBenefit(string $name): bool
    {
        return str_contains(strtolower($name), 'special benefit');
    }

    public static function isRevenueStamp(string $name): bool
    {
        return str_contains(strtolower($name), 'revenue stamp');
    }

    /**
     * Read the full 19-component breakdown for an employee in a specific (year, month).
     *
     * @return array<string, mixed>
     */
    public function getSetupForEmployee(User $user, int $year, int $month): array
    {
        $this->assertValidPeriod($year, $month);

        $profile = $this->loadProfile($user);
        $types = PayrollComponentType::query()->orderBy('id')->get();
        $adjustments = Schema::hasTable('salary_monthly_component_adjustments')
            ? SalaryMonthlyComponentAdjustment::query()
                ->where('user_id', $user->id)
                ->where('year', $year)
                ->where('month', $month)
                ->get()
                ->keyBy('payroll_component_type_id')
            : collect();

        return $this->computeSetupFromLoaded((int) $user->id, $profile, $types, $adjustments, $year, $month);
    }

    /**
     * Batch-compute monthly payroll summaries for multiple employees in a single (year, month).
     * Shares the exact same calculation logic as getSetupForEmployee() with minimal DB queries.
     *
     * @param array<int, int> $userIds
     * @return array<int, array<string, mixed>> Keyed by user_id
     */
    public function getSummariesForUsers(array $userIds, int $year, int $month): array
    {
        $this->assertValidPeriod($year, $month);
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }

        $relations = $this->profileRelations();
        $profileQuery = EmployeeInfo::query()->whereIn('user_id', $userIds)->orderBy('id');
        if ($relations !== []) {
            $profileQuery->with($relations);
        }
        $profilesByUser = [];
        foreach ($profileQuery->get() as $profile) {
            $uid = (int) $profile->user_id;
            if (!isset($profilesByUser[$uid])) {
                $profilesByUser[$uid] = $profile;
            }
        }

        $types = PayrollComponentType::query()->orderBy('id')->get();
        $adjustmentsByUser = Schema::hasTable('salary_monthly_component_adjustments')
            ? SalaryMonthlyComponentAdjustment::query()
                ->whereIn('user_id', $userIds)
                ->where('year', $year)
                ->where('month', $month)
                ->get()
                ->groupBy('user_id')
            : collect();

        $results = [];
        foreach ($userIds as $uid) {
            if (!isset($profilesByUser[$uid])) {
                continue;
            }
            $userAdjustments = ($adjustmentsByUser->get($uid) ?? collect())->keyBy('payroll_component_type_id');
            $setup = $this->computeSetupFromLoaded($uid, $profilesByUser[$uid], $types, $userAdjustments, $year, $month);

            $basicPay = 0.0;
            foreach ($setup['allowances'] as $row) {
                if (self::isBasicPay((string) $row['name'])) {
                    $basicPay = (float) $row['current_amount'];
                    break;
                }
            }

            $results[$uid] = [
                'user_id' => $uid,
                'pay_scale' => $setup['pay_scale'],
                'grade' => $setup['grade'],
                'basic_pay' => $basicPay,
                'regular_allowances' => $setup['regular_allowances'],
                'arrear_allowances' => $setup['arrear_allowances'],
                'total_allowances' => $setup['total_allowances'],
                'regular_deductions' => $setup['regular_deductions'],
                'arrear_deductions' => $setup['arrear_deductions'],
                'total_deductions' => $setup['total_deductions'],
                'regular_net' => $setup['regular_net'],
                'net_arrear' => $setup['net_arrear'],
                'net_payable' => $setup['net_payable'],
                'allowances' => $setup['allowances'],
                'deductions' => $setup['deductions'],
            ];
        }

        return $results;
    }

    /**
     * @param \Illuminate\Support\Collection<int, PayrollComponentType> $types
     * @param \Illuminate\Support\Collection<int, SalaryMonthlyComponentAdjustment> $adjustments
     * @return array<string, mixed>
     */
    private function computeSetupFromLoaded(
        int $userId,
        EmployeeInfo $profile,
        $types,
        $adjustments,
        int $year,
        int $month
    ): array {
        $retainedOn2015Scale = $this->isRetainedOn2015Scale($profile);
        $fixation = $retainedOn2015Scale ? null : $this->resolveFixation($userId, $year, $month);

        $rawProfileScale = (string) ($profile->payScale?->scale_name ?? '');
        $scaleName = $fixation !== null
            ? 'Govt. Pay Scale 2026'
            : ($retainedOn2015Scale && ($rawProfileScale === '' || str_contains($rawProfileScale, '2026'))
                ? 'Govt. Pay Scale 2015'
                : $rawProfileScale);
        $gradeName = $fixation['grade'] ?? ($profile->payrollGroup?->grade?->grade_name ?? null);
        $is2026Scale = !$retainedOn2015Scale && ($fixation !== null || str_contains($scaleName, '2026'));
        $is2015Scale = $retainedOn2015Scale || (!$is2026Scale && str_contains($scaleName, '2015'));

        $profileAdds = $this->safeNormalizeRows($profile->additionObjects);
        $profileDeds = $this->safeNormalizeRows($profile->substractionObjects);

        // Pass 1: resolve effective Basic Pay so percentage components, SB, and GPF use it.
        $profileBasicRow = $this->findBasicRow($profileAdds);
        $autoBasic = $fixation !== null
            ? round((float) $fixation['basic'], 2)
            : round((float) ($profileBasicRow['amount'] ?? 0.0), 2);

        $effectiveBasic = $autoBasic;
        foreach ($types as $type) {
            if (self::isBasicPay((string) $type->name)) {
                $adj = $adjustments->get($type->id);
                if ($adj && $adj->rule_override && $adj->override_current_amount !== null) {
                    $effectiveBasic = round((float) $adj->override_current_amount, 2);
                }
                break;
            }
        }

        // Pass 2: build all master components split into allowances (14) and deductions (5).
        $allowances = [];
        $deductions = [];

        $regularAllowancesCents = 0;
        $arrearAllowancesCents = 0;
        $regularDeductionsCents = 0;
        $arrearDeductionsCents = 0;

        foreach ($types as $type) {
            $typeId = (int) $type->id;
            $name = (string) $type->name;
            $direction = self::classifyDirection($name);
            $sourceRows = $direction === 'allowance' ? $profileAdds : $profileDeds;
            $profileRow = $this->matchProfileRow($sourceRows, $typeId, $name);

            $autoCurrent = $this->computeAutoCurrentAmount(
                $name,
                $profile,
                $profileRow,
                $effectiveBasic,
                $is2015Scale,
                $is2026Scale,
                $gradeName,
                $year,
                $month
            );

            $adj = $adjustments->get($typeId);
            $ruleOverride = (bool) ($adj?->rule_override ?? false);
            $overrideAmount = ($ruleOverride && $adj?->override_current_amount !== null)
                ? round((float) $adj->override_current_amount, 2)
                : null;
            $currentAmount = $overrideAmount ?? $autoCurrent;
            $arrearAmount = round((float) ($adj?->arrear_amount ?? 0.0), 2);
            $totalAmount = round($currentAmount + $arrearAmount, 2);
            $comment = $adj?->comment;

            $row = [
                'payroll_component_type_id' => $typeId,
                'name' => $name,
                'direction' => $direction,
                'current_amount' => $currentAmount,
                'arrear_amount' => $arrearAmount,
                'total_amount' => $totalAmount,
                'rule_override' => $ruleOverride,
                'override_current_amount' => $overrideAmount,
                'comment' => $comment,
            ];

            $currentCents = $this->toCents($currentAmount);
            $arrearCents = $this->toCents($arrearAmount);

            if ($direction === 'allowance') {
                $allowances[] = $row;
                $regularAllowancesCents += $currentCents;
                $arrearAllowancesCents += $arrearCents;
            } else {
                $deductions[] = $row;
                $regularDeductionsCents += $currentCents;
                $arrearDeductionsCents += $arrearCents;
            }
        }

        $totalAllowancesCents = $regularAllowancesCents + $arrearAllowancesCents;
        $totalDeductionsCents = $regularDeductionsCents + $arrearDeductionsCents;
        $regularNetCents = $regularAllowancesCents - $regularDeductionsCents;
        $netArrearCents = $arrearAllowancesCents - $arrearDeductionsCents;
        $netPayableCents = $totalAllowancesCents - $totalDeductionsCents;

        return [
            'user_id' => $userId,
            'year' => $year,
            'month' => $month,
            'pay_scale' => $scaleName !== '' ? $scaleName : null,
            'grade' => $gradeName,
            'allowances' => $allowances,
            'deductions' => $deductions,
            'regular_allowances' => $regularAllowancesCents / 100,
            'arrear_allowances' => $arrearAllowancesCents / 100,
            'total_allowances' => $totalAllowancesCents / 100,
            'regular_deductions' => $regularDeductionsCents / 100,
            'arrear_deductions' => $arrearDeductionsCents / 100,
            'total_deductions' => $totalDeductionsCents / 100,
            'regular_net' => $regularNetCents / 100,
            'net_arrear' => $netArrearCents / 100,
            'net_payable' => $netPayableCents / 100,
        ];
    }

    /**
     * Persist month-scoped arrears and/or rule overrides for a single (year, month).
     * Never mutates recurring employee_infos defaults.
     *
     * @param array<int, array<string, mixed>> $components
     * @return array<string, mixed>
     */
    public function saveForMonth(
        User $user,
        int $year,
        int $month,
        array $components,
        ?int $updatedBy = null
    ): array {
        $this->assertValidPeriod($year, $month);
        SalaryGenerationGuards::assertMonthMutable($year, $month, 'adjusted');

        $typeNames = PayrollComponentType::query()->pluck('name', 'id')->all();
        $validated = [];

        foreach ($components as $item) {
            if (!is_array($item)) {
                throw new DomainException('Invalid monthly component adjustment payload.');
            }
            $typeId = (int) ($item['payroll_component_type_id'] ?? 0);
            if ($typeId <= 0 || !isset($typeNames[$typeId])) {
                throw new DomainException('Unknown payroll_component_type_id: ' . $typeId);
            }
            $typeName = (string) $typeNames[$typeId];

            $rawArrear = $item['arrear_amount'] ?? 0.0;
            if (!is_numeric($rawArrear) || !is_finite((float) $rawArrear)) {
                throw new DomainException('Invalid arrear_amount for ' . $typeName);
            }
            $arrearAmount = round((float) $rawArrear, 2);

            $ruleOverride = (bool) ($item['rule_override'] ?? false);
            $overrideCurrent = null;
            if ($ruleOverride) {
                $rawOverride = $item['override_current_amount'] ?? $item['current_amount'] ?? null;
                if (!is_numeric($rawOverride) || !is_finite((float) $rawOverride) || (float) $rawOverride < 0) {
                    throw new DomainException('Valid non-negative override_current_amount is required when rule_override is enabled for ' . $typeName);
                }
                $overrideCurrent = round((float) $rawOverride, 2);
                if (self::isBasicPay($typeName) && $overrideCurrent <= 0) {
                    throw new DomainException('Basic Pay override_current_amount must be greater than zero.');
                }
            }

            $comment = isset($item['comment']) ? trim((string) $item['comment']) : null;
            if ($comment === '') {
                $comment = null;
            }

            if (self::isBasicPay($typeName) && $ruleOverride && $comment === null) {
                throw new DomainException('A non-empty comment is required when manually overriding Basic Pay.');
            }

            $validated[$typeId] = [
                'payroll_component_type_id' => $typeId,
                'arrear_amount' => $arrearAmount,
                'rule_override' => $ruleOverride,
                'override_current_amount' => $overrideCurrent,
                'comment' => $comment,
            ];
        }

        DB::transaction(function () use ($user, $year, $month, $validated, $updatedBy): void {
            foreach ($validated as $typeId => $row) {
                $isEmpty = abs($row['arrear_amount']) < 0.005
                    && !$row['rule_override']
                    && $row['comment'] === null;

                $existing = SalaryMonthlyComponentAdjustment::query()
                    ->where('user_id', $user->id)
                    ->where('year', $year)
                    ->where('month', $month)
                    ->where('payroll_component_type_id', $typeId)
                    ->first();

                if ($isEmpty) {
                    if ($existing) {
                        $existing->delete();
                    }
                    continue;
                }

                if ($existing) {
                    $existing->fill([
                        'arrear_amount' => $row['arrear_amount'],
                        'rule_override' => $row['rule_override'],
                        'override_current_amount' => $row['override_current_amount'],
                        'comment' => $row['comment'],
                        'updated_by' => $updatedBy,
                    ]);
                    $existing->save();
                } else {
                    SalaryMonthlyComponentAdjustment::create([
                        'user_id' => $user->id,
                        'year' => $year,
                        'month' => $month,
                        'payroll_component_type_id' => $typeId,
                        'arrear_amount' => $row['arrear_amount'],
                        'rule_override' => $row['rule_override'],
                        'override_current_amount' => $row['override_current_amount'],
                        'comment' => $row['comment'],
                        'updated_by' => $updatedBy,
                    ]);
                }
            }
        });

        return $this->getSetupForEmployee($user, $year, $month);
    }

    /**
     * Explicitly update recurring employee_infos JSON defaults.
     * NEVER copies arrear_amount, rule_override, override_current_amount, or comment.
     *
     * @param array<int, array<string, mixed>> $components
     */
    public function saveAsRecurringDefault(User $user, array $components): EmployeeInfo
    {
        $profile = $this->loadProfile($user);
        $typeNames = PayrollComponentType::query()->pluck('name', 'id')->all();

        $additions = [];
        $deductions = [];

        foreach ($components as $item) {
            if (!is_array($item)) {
                throw new DomainException('Invalid recurring component payload.');
            }
            $typeId = (int) ($item['payroll_component_type_id'] ?? 0);
            if ($typeId <= 0 || !isset($typeNames[$typeId])) {
                throw new DomainException('Unknown payroll_component_type_id: ' . $typeId);
            }
            $name = (string) $typeNames[$typeId];
            $direction = self::classifyDirection($name);
            $calcType = strtolower((string) ($item['calcType'] ?? 'fixed'));
            if (!in_array($calcType, ['fixed', 'percentage'], true)) {
                throw new DomainException('Invalid calcType for ' . $name);
            }

            $rawAmount = $item['amount'] ?? $item['current_amount'] ?? 0.0;
            if (!is_numeric($rawAmount) || !is_finite((float) $rawAmount) || (float) $rawAmount < 0) {
                throw new DomainException('Invalid recurring amount for ' . $name);
            }
            $amount = round((float) $rawAmount, 2);

            $isGpf = SalaryCalculationService::isGpf(['name' => $name]);
            $keepZero = $isGpf || self::isBasicPay($name) || self::isRevenueStamp($name);
            if ($amount <= 0.0 && !$keepZero) {
                continue;
            }

            $entry = [
                'payroll_component_type_id' => $typeId,
                'name' => $name,
                'calcType' => $calcType,
                'amount' => $amount,
            ];

            if ($direction === 'allowance') {
                $additions[] = $entry;
            } else {
                $deductions[] = $entry;
            }
        }

        $profile->additionObjects = $additions;
        $profile->substractionObjects = $deductions;
        $profile->save();

        return $profile->fresh();
    }

    private function computeAutoCurrentAmount(
        string $name,
        EmployeeInfo $profile,
        ?array $profileRow,
        float $effectiveBasic,
        bool $is2015Scale,
        bool $is2026Scale,
        ?string $gradeName,
        int $year,
        int $month
    ): float {
        if (self::isBasicPay($name)) {
            return $effectiveBasic;
        }

        if (self::isSpecialBenefit($name)) {
            if ($is2026Scale) {
                return 0.00;
            }
            if ($is2015Scale && $gradeName !== null && preg_match('/\b(\d{1,2})\b/', $gradeName, $m)) {
                $gradeNum = (int) $m[1];
                if ($gradeNum >= 1 && $gradeNum <= 9) {
                    return round($effectiveBasic * 0.10, 2);
                }
                if ($gradeNum >= 10 && $gradeNum <= 20) {
                    return round($effectiveBasic * 0.15, 2);
                }
            }
            return $this->evaluateProfileRow($profileRow, $effectiveBasic);
        }

        if (SalaryCalculationService::isGpf(['name' => $name])) {
            if ($this->isGpfExempt($profile, $profileRow)) {
                return 0.00;
            }
            return SalaryCalculationService::gpfAmount($effectiveBasic * 0.10, $year, $month);
        }

        if (self::isRevenueStamp($name)) {
            return 10.00;
        }

        return $this->evaluateProfileRow($profileRow, $effectiveBasic);
    }

    private function isGpfExempt(EmployeeInfo $profile, ?array $profileGpfRow): bool
    {
        if (in_array((string) $profile->employeeCode, self::GPF_EXEMPT_CODES, true)) {
            return true;
        }

        $designation = (string) ($profile->payrollGroup?->payrollGroupType?->group_name ?? '');
        if ($designation !== '' && preg_match('/\b(vice[\s-]*chancellor|vc|treasurer)\b/i', $designation) === 1) {
            return true;
        }

        $jobStatus = strtolower(str_replace(['-', ' ', '_'], '', (string) $profile->jobStatus));
        if (str_contains($jobStatus, 'adhoc')) {
            return true;
        }

        if (
            $profileGpfRow !== null
            && strtolower((string) ($profileGpfRow['calcType'] ?? 'fixed')) === 'fixed'
            && abs((float) ($profileGpfRow['amount'] ?? -1)) < 0.005
        ) {
            return true;
        }

        return false;
    }

    private function evaluateProfileRow(?array $row, float $basic): float
    {
        if ($row === null) {
            return 0.00;
        }
        $calcType = strtolower((string) ($row['calcType'] ?? 'fixed'));
        $amount = (float) ($row['amount'] ?? 0.0);

        return $calcType === 'percentage'
            ? round($basic * $amount / 100, 2)
            : round($amount, 2);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function matchProfileRow(array $rows, int $typeId, string $name): ?array
    {
        foreach ($rows as $row) {
            $rowTypeId = (int) ($row['payroll_component_type_id'] ?? $row['payroll_component_type']['id'] ?? 0);
            if ($rowTypeId > 0 && $rowTypeId === $typeId) {
                return $row;
            }
        }
        foreach ($rows as $row) {
            if (strcasecmp(trim((string) ($row['name'] ?? '')), trim($name)) === 0) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function findBasicRow(array $rows): ?array
    {
        foreach ($rows as $row) {
            if (self::isBasicPay((string) ($row['name'] ?? ''))) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function safeNormalizeRows(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        return SalaryComponentNormalizer::rows($value);
    }

    /** @return array<int, string> */
    private function profileRelations(): array
    {
        $relations = [];
        if (Schema::hasTable('pay_scales')) {
            $relations[] = 'payScale';
        }
        if (Schema::hasTable('payroll_groups') && Schema::hasTable('grades')) {
            $relations[] = 'payrollGroup.grade';
        }
        if (Schema::hasTable('payroll_groups') && Schema::hasTable('payroll_group_types')) {
            $relations[] = 'payrollGroup.payrollGroupType';
        }

        return $relations;
    }

    private function loadProfile(User $user): EmployeeInfo
    {
        $relations = $this->profileRelations();

        $query = EmployeeInfo::query()->where('user_id', $user->id);
        if ($relations !== []) {
            $query->with($relations);
        }

        $profile = $query->first();
        if (!$profile) {
            throw new DomainException('Employee payroll profile not found for user ID ' . $user->id);
        }

        return $profile;
    }

    /**
     * Leave-With-Pay-Without-Increment / 2015-retained employees stay on their
     * 2015 June Basic from employee_infos and receive 2015 Special Benefit
     * (10% Grades 1-9, 15% Grades 10-20), even if a 2026 fixation schedule exists.
     */
    private function isRetainedOn2015Scale(EmployeeInfo $profile): bool
    {
        $code = trim((string) $profile->employeeCode);
        if ($code !== '') {
            $studyLeave = config('kau_fixation_eligibility.study_leave', []);
            if (isset($studyLeave[$code]) && ($studyLeave[$code]['first_increment_allowed'] ?? false) === false) {
                return true;
            }
        }

        $normalizedJobStatus = strtolower(str_replace(['-', ' ', '_'], '', (string) $profile->jobStatus));
        if (
            (str_contains($normalizedJobStatus, 'leavewithpay') || str_contains($normalizedJobStatus, 'studyleavewithpay'))
            && (bool) $profile->holdIncrement
        ) {
            return true;
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function resolveFixation(int $userId, int $year, int $month): ?array
    {
        if (!Schema::hasTable('salary_fixation_pay_schedules') || !Schema::hasTable('salary_fixations')) {
            return null;
        }

        return app(ApprovedFixationResolver::class)->forUser($userId, $year, $month);
    }

    private function assertValidPeriod(int $year, int $month): void
    {
        if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
            throw new DomainException('Invalid salary year or month.');
        }
    }

    private function toCents(float $amount): int
    {
        return (int) round($amount * 100, 0, PHP_ROUND_HALF_UP);
    }
}
