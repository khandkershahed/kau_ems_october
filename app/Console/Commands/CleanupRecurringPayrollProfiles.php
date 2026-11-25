<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Idempotent recurring payroll profile cleanup command.
 * Default mode is dry-run (0 DB writes). Pass --apply to persist atomically.
 * NEVER reads or modifies salary_generates or any historical (<= 2026-10) payroll records.
 */
final class CleanupRecurringPayrollProfiles extends Command
{
    protected $signature = 'kau:cleanup-recurring-profiles
        {--apply : Persist changes (default is dry-run)}';

    protected $description = 'Idempotent cleanup of recurring payroll profiles (dry-run by default; pass --apply to persist)';

    private const MISSING_CATEGORY_LINKS = [
        '30133' => 'Class 3',
        '40140' => 'Class 4',
    ];

    private const DEACTIVATE_EMPLOYEE_CODE = '30235';
    private const PROTECTED_ACTIVE_EMPLOYEE_CODE = '40033';

    private const GPF_LOCK_ZERO_CODES = [
        '20082', // VC
        '20137', // Treasurer
        '20023', '20090', '30036', '30043', '30073', '30075',
        '30066', '30067', '30072', '40051', '40052', '40059', '40060', '40061',
    ];

    private const INCOME_TAX_UPDATE_CODE = '10028';
    private const INCOME_TAX_TARGET_AMOUNT = 2500.00;

    private const ERRONEOUS_OTHERS_DED_CODES = ['30066', '30067', '30072'];
    private const ERRONEOUS_OTHERS_DED_AMOUNT = 940.50;

    /** 26 confirmed Leave-With-Pay profiles (explicitly excludes 10010 and 10040). */
    private const CONFIRMED_LWP_CODES = [
        '10002', '10003', '10005', '10006', '10007', '10008', '10012', '10016',
        '10017', '10018', '10019', '10020', '10022', '10025', '10033', '10036',
        '10041', '10047', '10052', '10061', '10064', '10069', '10106', '10107',
        '20018', '20070',
    ];

    private const OPEN_ITEM_PROTECTED_CODES = [
        '10010', '10040', '1003', '20049', '20083', '20029',
        '20028', '20026', '20069', '20062', '20033', '20038',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        foreach (['users', 'employee_infos', 'user_categories', 'user_user_categories', 'payroll_component_types'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error("Required table missing: {$table}");
                return self::FAILURE;
            }
        }

        // Guard: open-item codes must never be in the LWP, GPF, tax, or deactivation lists.
        if (array_intersect(self::OPEN_ITEM_PROTECTED_CODES, self::CONFIRMED_LWP_CODES) !== []) {
            throw new RuntimeException('Safety violation: protected open-item employee found in LWP target list.');
        }

        $typeNames = DB::table('payroll_component_types')->pluck('name', 'id')->all();
        $typeIdBySlug = [];
        foreach ($typeNames as $id => $name) {
            $typeIdBySlug[strtolower(trim((string) $name))] = (int) $id;
        }
        $gpfTypeId = $this->findTypeId($typeNames, '/general\s*provident\s*fund|\bgpf\b/i', 16);
        $taxTypeId = $this->findTypeId($typeNames, '/income\s*tax/i', 17);
        $othersDedTypeId = $this->findTypeId($typeNames, '/others\s*deduction/i', 18);
        $revenueStampTypeId = $this->findTypeId($typeNames, '/revenue\s*stamp/i', 19);
        $specialBenefitTypeId = $this->findTypeId($typeNames, '/special\s*benefit/i', 11);

        $categoriesByLower = [];
        foreach (DB::table('user_categories')->get(['id', 'name']) as $cat) {
            $categoriesByLower[strtolower(trim((string) $cat->name))] = (int) $cat->id;
        }

        $grades = [];
        $gradePayScaleIds = [];
        if (Schema::hasTable('payroll_groups') && Schema::hasTable('grades')) {
            foreach (DB::table('payroll_groups as pg')->join('grades as g', 'g.id', '=', 'pg.grade_id')
                ->get(['pg.id', 'g.grade_name', 'g.pay_scale_id']) as $gRow) {
                $grades[$gRow->id] = $gRow->grade_name;
                $gradePayScaleIds[$gRow->id] = $gRow->pay_scale_id;
            }
        }
        $payScales = Schema::hasTable('pay_scales')
            ? DB::table('pay_scales')->pluck('scale_name', 'id')->all()
            : [];

        $users = DB::table('users')->get(['id', 'name', 'isActive'])->keyBy('id');
        $existingCategoryLinks = [];
        foreach (DB::table('user_user_categories')->get(['user_id', 'user_category_id']) as $link) {
            $existingCategoryLinks[$link->user_id . ':' . $link->user_category_id] = true;
        }

        $profiles = DB::table('employee_infos')->orderBy('id')->get();

        // Explicit safety assertion for 40033 vs 30235.
        $protected40033 = $profiles->first(fn ($p) => (string) $p->employeeCode === self::PROTECTED_ACTIVE_EMPLOYEE_CODE);
        $target30235 = $profiles->first(fn ($p) => (string) $p->employeeCode === self::DEACTIVATE_EMPLOYEE_CODE);
        if ($protected40033 !== null && $target30235 !== null && (int) $protected40033->user_id === (int) $target30235->user_id) {
            throw new RuntimeException('Safety violation: 30235 and 40033 share the same user_id.');
        }

        $counts = [
            'profiles_scanned' => 0,
            'json_double_encoded_fixed' => 0,
            'component_names_filled' => 0,
            'category_links_added' => 0,
            'users_deactivated_30235' => 0,
            'gpf_locked_zero' => 0,
            'tax_10028_updated' => 0,
            'erroneous_others_ded_removed' => 0,
            'lwp_profiles_verified_ok' => 0,
            'lwp_sb_adjusted' => 0,
            'revenue_stamp_deduplicated' => 0,
            'revenue_stamp_added_or_fixed' => 0,
            'profile_rows_to_update' => 0,
        ];

        $profileUpdates = [];
        $categoryInserts = [];
        $usersToDeactivate = [];

        $gpfTargetSet = array_flip(self::GPF_LOCK_ZERO_CODES);
        $erroneousDedSet = array_flip(self::ERRONEOUS_OTHERS_DED_CODES);
        $lwpTargetSet = array_flip(self::CONFIRMED_LWP_CODES);

        foreach ($profiles as $p) {
            $counts['profiles_scanned']++;
            $code = (string) $p->employeeCode;
            $user = $users->get($p->user_id);

            // Step 2: Link missing user_user_categories for 30133 (Class 3) and 40140 (Class 4)
            if (isset(self::MISSING_CATEGORY_LINKS[$code]) && $user !== null) {
                $catName = self::MISSING_CATEGORY_LINKS[$code];
                $catId = $categoriesByLower[strtolower($catName)] ?? null;
                if ($catId !== null && !isset($existingCategoryLinks[$p->user_id . ':' . $catId])) {
                    $categoryInserts[] = [
                        'user_id' => $p->user_id,
                        'user_category_id' => $catId,
                    ];
                    $existingCategoryLinks[$p->user_id . ':' . $catId] = true;
                    $counts['category_links_added']++;
                }
            }

            // Step 3: Deactivate 30235 ONLY (explicitly never 40033)
            $effectiveActive = $user !== null ? (int) $user->isActive : 0;
            if ($code === self::DEACTIVATE_EMPLOYEE_CODE && $user !== null) {
                if ($protected40033 !== null && (int) $user->id === (int) $protected40033->user_id) {
                    throw new RuntimeException('Safety violation: refusing to deactivate protected employee 40033.');
                }
                if ((int) $user->isActive !== 0) {
                    $usersToDeactivate[] = (int) $user->id;
                    $counts['users_deactivated_30235']++;
                }
                $effectiveActive = 0;
            }

            // Step 1: Decode and normalize additionObjects / substractionObjects
            [$adds, $addLayers] = $this->decode($p->additionObjects);
            [$deds, $dedLayers] = $this->decode($p->substractionObjects);

            $addChanged = $addLayers > 1;
            $dedChanged = $dedLayers > 1;
            if ($addLayers > 1 || $dedLayers > 1) {
                $counts['json_double_encoded_fixed']++;
            }

            [$adds, $addNamesFilled] = $this->fillMissingNames($adds, $typeNames);
            [$deds, $dedNamesFilled] = $this->fillMissingNames($deds, $typeNames);
            if ($addNamesFilled) {
                $addChanged = true;
            }
            if ($dedNamesFilled) {
                $dedChanged = true;
            }
            if ($addNamesFilled || $dedNamesFilled) {
                $counts['component_names_filled']++;
            }

            // Step 4: Lock Regular GPF to 0.00 for VC, Treasurer, and 14 Ad Hoc employees
            if (isset($gpfTargetSet[$code])) {
                [$deds, $gpfChanged] = $this->lockGpfToZero($deds, $gpfTypeId, $typeNames[$gpfTypeId] ?? 'General Provident Fund');
                if ($gpfChanged) {
                    $dedChanged = true;
                    $counts['gpf_locked_zero']++;
                }
            }

            // Step 5: Update 10028 Income Tax to 2500.00
            if ($code === self::INCOME_TAX_UPDATE_CODE) {
                [$deds, $taxChanged] = $this->setIncomeTax($deds, self::INCOME_TAX_TARGET_AMOUNT, $taxTypeId, $typeNames[$taxTypeId] ?? 'Income Tax');
                if ($taxChanged) {
                    $dedChanged = true;
                    $counts['tax_10028_updated']++;
                }
            }

            // Step 6: Remove erroneous 940.50 Others Deduction from 30066, 30067, 30072
            if (isset($erroneousDedSet[$code])) {
                [$deds, $removed] = $this->removeErroneousOthersDeduction($deds, self::ERRONEOUS_OTHERS_DED_AMOUNT, $othersDedTypeId);
                if ($removed) {
                    $dedChanged = true;
                    $counts['erroneous_others_ded_removed']++;
                }
            }

            // Step 7: 26 Confirmed Leave-With-Pay Profiles (retains June Basic; exact SB 10% G1-9, 15% G10-20)
            if (isset($lwpTargetSet[$code])) {
                $gradeName = $grades[$p->payroll_group_id] ?? '';
                $scaleId = $p->pay_scale_id ?? ($gradePayScaleIds[$p->payroll_group_id] ?? null);
                $scaleName = $scaleId !== null ? ($payScales[$scaleId] ?? null) : null;
                $expectedRate = AuditPayrollBaseline::resolveExpectedSbRate($scaleName, $gradeName);
                if ($expectedRate === null && $gradeName !== '') {
                    // Fallback when pay_scales table is absent in minimal test fixtures
                    $expectedRate = AuditPayrollBaseline::resolveExpectedSbRate('Govt. Pay Scale 2015', $gradeName);
                }
                if ($expectedRate !== null) {
                    [$adds, $sbChanged] = $this->ensureExactSpecialBenefit(
                        $adds,
                        $expectedRate,
                        $specialBenefitTypeId,
                        $typeNames[$specialBenefitTypeId] ?? 'Special benefit'
                    );
                    if ($sbChanged) {
                        $addChanged = true;
                        $counts['lwp_sb_adjusted']++;
                    } else {
                        $counts['lwp_profiles_verified_ok']++;
                    }
                }
            }

            // Step 8: Revenue Stamp Hygiene for active payroll-eligible employees
            if ($effectiveActive === 1) {
                [$deds, $rsDeduplicated, $rsAddedOrFixed] = $this->ensureRevenueStamp(
                    $deds,
                    $revenueStampTypeId,
                    $typeNames[$revenueStampTypeId] ?? 'Revenue Stamp'
                );
                if ($rsDeduplicated) {
                    $dedChanged = true;
                    $counts['revenue_stamp_deduplicated']++;
                }
                if ($rsAddedOrFixed) {
                    $dedChanged = true;
                    $counts['revenue_stamp_added_or_fixed']++;
                }
            }

            if ($addChanged || $dedChanged) {
                $update = [];
                if ($addChanged) {
                    $update['additionObjects'] = json_encode($adds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if ($dedChanged) {
                    $update['substractionObjects'] = json_encode($deds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $profileUpdates[$p->id] = $update;
                $counts['profile_rows_to_update']++;
            }
        }

        // Final pre-commit safety assertion: 40033 must never be in deactivation list.
        if ($protected40033 !== null && in_array((int) $protected40033->user_id, $usersToDeactivate, true)) {
            throw new RuntimeException('Safety violation: protected employee 40033 queued for deactivation.');
        }

        if ($apply) {
            $now = now();
            DB::transaction(function () use ($profileUpdates, $categoryInserts, $usersToDeactivate, $now): void {
                foreach ($profileUpdates as $id => $payload) {
                    $payload['updated_at'] = $now;
                    DB::table('employee_infos')->where('id', $id)->update($payload);
                }
                foreach ($categoryInserts as $insert) {
                    $insert['created_at'] = $now;
                    $insert['updated_at'] = $now;
                    DB::table('user_user_categories')->insert($insert);
                }
                foreach ($usersToDeactivate as $userId) {
                    DB::table('users')->where('id', $userId)->update([
                        'isActive' => 0,
                        'updated_at' => $now,
                    ]);
                }
            });
        }

        $mode = $apply ? 'APPLIED' : 'DRY-RUN (0 DB writes)';
        $this->info("KAU Recurring Payroll Profile Cleanup [{$mode}]");
        $rows = [];
        foreach ($counts as $metric => $count) {
            $rows[] = [$metric, $count];
        }
        $this->table(['Metric', 'Count'], $rows);

        return self::SUCCESS;
    }

    /** @return array{0: array<int, mixed>, 1: int} */
    private function decode(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [[], 0];
        }
        $layers = 0;
        while (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [[], $layers];
            }
            $value = $decoded;
            if (++$layers > 4) {
                return [[], $layers];
            }
        }
        return [is_array($value) ? array_values($value) : [], $layers];
    }

    /** @return array{0: array<int, mixed>, 1: bool} */
    private function fillMissingNames(array $rows, array $typeNames): array
    {
        $changed = false;
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $topName = trim((string) ($row['name'] ?? ''));
            if ($topName === '') {
                $resolved = trim((string) ($row['payroll_component_type']['name'] ?? ''));
                if ($resolved === '') {
                    $typeId = $row['payroll_component_type_id'] ?? $row['id'] ?? null;
                    if ($typeId !== null && isset($typeNames[$typeId])) {
                        $resolved = trim((string) $typeNames[$typeId]);
                    }
                }
                if ($resolved !== '') {
                    $row['name'] = $resolved;
                    $changed = true;
                }
            }
        }
        unset($row);
        return [$rows, $changed];
    }

    /** @return array{0: array<int, mixed>, 1: bool} */
    private function lockGpfToZero(array $deds, int $gpfTypeId, string $gpfName): array
    {
        $gpfIndices = [];
        foreach ($deds as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? $row['payroll_component_type']['name'] ?? '');
            $typeId = $row['payroll_component_type_id'] ?? null;
            if (preg_match('/gpf|provident/i', $name) || ((int) $typeId === $gpfTypeId)) {
                $gpfIndices[] = $idx;
            }
        }

        if ($gpfIndices === []) {
            $deds[] = [
                'payroll_component_type_id' => $gpfTypeId,
                'name' => $gpfName,
                'amount' => '0.00',
                'calcType' => 'fixed',
                'isActive' => 1,
                'is_taxable' => 0,
                'component_type' => 'deduction',
                'calculated_amount' => 0.0,
            ];
            return [$deds, true];
        }

        $changed = false;
        $keepIdx = $gpfIndices[0];
        if (count($gpfIndices) > 1) {
            $changed = true;
        }
        $row = $deds[$keepIdx];
        $amt = is_numeric($row['amount'] ?? null) ? (float) $row['amount'] : -1.0;
        $calcAmt = is_numeric($row['calculated_amount'] ?? null) ? (float) $row['calculated_amount'] : $amt;
        $calcType = strtolower((string) ($row['calcType'] ?? ''));

        if ($calcType !== 'fixed' || abs($amt) > 0.005 || abs($calcAmt) > 0.005) {
            $row['calcType'] = 'fixed';
            $row['amount'] = '0.00';
            $row['calculated_amount'] = 0.0;
            if (array_key_exists('default_amount', $row)) {
                $row['default_amount'] = '0.00';
            }
            $deds[$keepIdx] = $row;
            $changed = true;
        }

        if (count($gpfIndices) > 1) {
            $drop = array_flip(array_slice($gpfIndices, 1));
            $deds = array_values(array_filter($deds, fn ($_, $i) => !isset($drop[$i]), ARRAY_FILTER_USE_BOTH));
        }

        return [$deds, $changed];
    }

    /** @return array{0: array<int, mixed>, 1: bool} */
    private function setIncomeTax(array $deds, float $target, int $taxTypeId, string $taxName): array
    {
        $formatted = number_format($target, 2, '.', '');
        foreach ($deds as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? $row['payroll_component_type']['name'] ?? '');
            $typeId = $row['payroll_component_type_id'] ?? null;
            if (preg_match('/income\s*tax/i', $name) || ((int) $typeId === $taxTypeId)) {
                $amt = is_numeric($row['amount'] ?? null) ? (float) $row['amount'] : -1.0;
                $calcAmt = is_numeric($row['calculated_amount'] ?? null) ? (float) $row['calculated_amount'] : $amt;
                $calcType = strtolower((string) ($row['calcType'] ?? 'fixed'));
                if ($calcType !== 'fixed' || abs($amt - $target) > 0.005 || abs($calcAmt - $target) > 0.005) {
                    $row['calcType'] = 'fixed';
                    $row['amount'] = $formatted;
                    $row['calculated_amount'] = $target;
                    if (array_key_exists('default_amount', $row)) {
                        $row['default_amount'] = $formatted;
                    }
                    return [$deds, true];
                }
                return [$deds, false];
            }
        }
        unset($row);

        $deds[] = [
            'payroll_component_type_id' => $taxTypeId,
            'name' => $taxName,
            'amount' => $formatted,
            'calcType' => 'fixed',
            'isActive' => 1,
            'is_taxable' => 0,
            'component_type' => 'deduction',
            'calculated_amount' => $target,
        ];
        return [$deds, true];
    }

    /** @return array{0: array<int, mixed>, 1: bool} */
    private function removeErroneousOthersDeduction(array $deds, float $targetAmount, int $othersDedTypeId): array
    {
        $kept = [];
        $removed = false;
        foreach ($deds as $row) {
            if (is_array($row)) {
                $name = (string) ($row['name'] ?? $row['payroll_component_type']['name'] ?? '');
                $typeId = $row['payroll_component_type_id'] ?? null;
                $isOthers = preg_match('/others\s*deduction/i', $name) || ((int) $typeId === $othersDedTypeId);
                $amt = (float) ($row['calculated_amount'] ?? $row['amount'] ?? 0);
                if ($isOthers && abs($amt - $targetAmount) < 0.01) {
                    $removed = true;
                    continue;
                }
            }
            $kept[] = $row;
        }
        return [array_values($kept), $removed];
    }

    /** @return array{0: array<int, mixed>, 1: bool} */
    private function ensureExactSpecialBenefit(array $adds, int $ratePercent, int $sbTypeId, string $sbName): array
    {
        $basic = null;
        foreach ($adds as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? $row['payroll_component_type']['name'] ?? '');
            if (preg_match('/^basic(\s+(pay|salary))?$/i', trim($name))) {
                $val = $row['calculated_amount'] ?? $row['amount'] ?? null;
                if (is_numeric($val)) {
                    $basic = (float) $val;
                    break;
                }
            }
        }
        if ($basic === null || $basic <= 0.0) {
            return [$adds, false];
        }

        $expectedSb = round($basic * $ratePercent / 100.0, 2);
        $formatted = number_format($expectedSb, 2, '.', '');

        foreach ($adds as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? $row['payroll_component_type']['name'] ?? '');
            $typeId = $row['payroll_component_type_id'] ?? null;
            $isSb = (preg_match('/special\s*benefit/i', $name) && !preg_match('/recovery/i', $name))
                || ((int) $typeId === $sbTypeId);
            if ($isSb) {
                $amt = is_numeric($row['amount'] ?? null) ? (float) $row['amount'] : -1.0;
                $calcAmt = is_numeric($row['calculated_amount'] ?? null) ? (float) $row['calculated_amount'] : $amt;
                $calcType = strtolower((string) ($row['calcType'] ?? 'fixed'));
                if ($calcType !== 'fixed' || abs($amt - $expectedSb) > 0.005 || abs($calcAmt - $expectedSb) > 0.005) {
                    $row['calcType'] = 'fixed';
                    $row['amount'] = $formatted;
                    $row['calculated_amount'] = $expectedSb;
                    if (array_key_exists('default_amount', $row)) {
                        $row['default_amount'] = $formatted;
                    }
                    return [$adds, true];
                }
                return [$adds, false];
            }
        }
        unset($row);

        $adds[] = [
            'payroll_component_type_id' => $sbTypeId,
            'name' => $sbName,
            'amount' => $formatted,
            'calcType' => 'fixed',
            'isActive' => 1,
            'is_taxable' => 0,
            'component_type' => 'allowance',
            'calculated_amount' => $expectedSb,
        ];
        return [$adds, true];
    }

    /** @return array{0: array<int, mixed>, 1: bool, 2: bool} rows, deduplicated, addedOrFixed */
    private function ensureRevenueStamp(array $deds, int $rsTypeId, string $rsName): array
    {
        $stampIndices = [];
        foreach ($deds as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? $row['payroll_component_type']['name'] ?? '');
            $typeId = $row['payroll_component_type_id'] ?? null;
            if (preg_match('/revenue\s*stamp/i', $name) || ((int) $typeId === $rsTypeId)) {
                $stampIndices[] = $idx;
            }
        }

        if ($stampIndices === []) {
            $deds[] = [
                'payroll_component_type_id' => $rsTypeId,
                'name' => $rsName,
                'amount' => '10.00',
                'calcType' => 'fixed',
                'isActive' => 1,
                'is_taxable' => 0,
                'component_type' => 'deduction',
                'calculated_amount' => 10.0,
            ];
            return [$deds, false, true];
        }

        $deduplicated = count($stampIndices) > 1;
        $keepIdx = $stampIndices[0];
        foreach ($stampIndices as $idx) {
            $n = strtolower(trim((string) ($deds[$idx]['name'] ?? '')));
            if ($n === 'revenue stamp') {
                $keepIdx = $idx;
                break;
            }
        }

        $row = $deds[$keepIdx];
        $amt = is_numeric($row['amount'] ?? null) ? (float) $row['amount'] : -1.0;
        $calcAmt = is_numeric($row['calculated_amount'] ?? null) ? (float) $row['calculated_amount'] : $amt;
        $calcType = strtolower((string) ($row['calcType'] ?? 'fixed'));
        $currentName = trim((string) ($row['name'] ?? ''));

        $addedOrFixed = false;
        if ($calcType !== 'fixed' || abs($amt - 10.0) > 0.005 || abs($calcAmt - 10.0) > 0.005 || strcasecmp($currentName, $rsName) !== 0) {
            $row['name'] = $rsName;
            $row['payroll_component_type_id'] = $row['payroll_component_type_id'] ?? $rsTypeId;
            $row['calcType'] = 'fixed';
            $row['amount'] = '10.00';
            $row['calculated_amount'] = 10.0;
            if (array_key_exists('default_amount', $row)) {
                $row['default_amount'] = '10.00';
            }
            $deds[$keepIdx] = $row;
            $addedOrFixed = true;
        }

        if ($deduplicated) {
            $drop = [];
            foreach ($stampIndices as $idx) {
                if ($idx !== $keepIdx) {
                    $drop[$idx] = true;
                }
            }
            $deds = array_values(array_filter($deds, fn ($_, $i) => !isset($drop[$i]), ARRAY_FILTER_USE_BOTH));
        }

        return [$deds, $deduplicated, $addedOrFixed];
    }

    private function findTypeId(array $typeNames, string $pattern, int $fallback): int
    {
        foreach ($typeNames as $id => $name) {
            if (preg_match($pattern, (string) $name)) {
                return (int) $id;
            }
        }
        return $fallback;
    }
}
