<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 01: strictly READ-ONLY employee payroll configuration baseline audit.
 * Only SELECT queries are issued; the sole side effect is writing the CSV file.
 */
final class AuditPayrollBaseline extends Command
{
    protected $signature = 'kau:audit-payroll-baseline
        {--output= : Absolute path of the CSV file to write (defaults to storage/app/audit/payroll_baseline_audit.csv)}';

    protected $description = 'Read-only baseline audit of employee payroll configuration (writes a CSV only)';

    private const COLUMNS = ['employee_id', 'user_id', 'name', 'category_linked', 'is_active', 'joining_date',
        'job_status', 'grade', 'hold_increment', 'basic_source', 'oct_basic', 'profile_basic', 'json_status',
        'missing_component_names', 'special_benefit_eligible', 'expected_sb_rate', 'gpf_eligible',
        'revenue_stamp_count', 'flags'];

    /**
     * Verified Special Benefit policy rate:
     * - 2026 PayScale => 0
     * - 2015 PayScale & Grade 1-9 => 10
     * - 2015 PayScale & Grade 10-20 => 15
     */
    public static function resolveExpectedSbRate(?string $scaleName, ?string $gradeName): ?int
    {
        if ($scaleName === null || $scaleName === '') {
            return null;
        }
        if (str_contains($scaleName, '2026')) {
            return 0;
        }
        if (str_contains($scaleName, '2015')) {
            if ($gradeName !== null && preg_match('/\b(\d{1,2})\b/', $gradeName, $m)) {
                $grade = (int) $m[1];
                if ($grade >= 1 && $grade <= 9) {
                    return 10;
                }
                if ($grade >= 10 && $grade <= 20) {
                    return 15;
                }
            }
        }
        return null;
    }

    public function handle(): int
    {
        $output = (string) $this->option('output');
        if ($output === '') {
            $output = storage_path('app/audit/payroll_baseline_audit.csv');
        }
        foreach (['users', 'employee_infos', 'user_user_categories', 'salary_generates', 'payroll_component_types'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error("Required table missing: {$table}");
                return self::FAILURE;
            }
        }
        $dir = dirname($output);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->error("Cannot create output directory: {$dir}");
            return self::FAILURE;
        }
        $handle = fopen($output, 'wb');
        if (!$handle) {
            $this->error('Cannot open output file for writing.');
            return self::FAILURE;
        }

        $typeNames = DB::table('payroll_component_types')->pluck('name', 'id')->all();
        $users = DB::table('users')->get(['id', 'name', 'isActive'])->keyBy('id');
        $categoryUsers = array_flip(DB::table('user_user_categories')->pluck('user_id')->unique()->all());
        $joiningById = DB::table('joining_dates')->pluck('joining_date', 'id')->all();
        $joiningByUser = [];
        foreach (DB::table('joining_dates')->orderBy('id')->get(['user_id', 'joining_date']) as $row) {
            $joiningByUser[$row->user_id] ??= $row->joining_date;
        }
        $payScales = Schema::hasTable('pay_scales')
            ? DB::table('pay_scales')->pluck('scale_name', 'id')->all()
            : [];
        $grades = [];
        $gradePayScaleIds = [];
        foreach (DB::table('payroll_groups as pg')->join('grades as g', 'g.id', '=', 'pg.grade_id')
            ->get(['pg.id', 'g.grade_name', 'g.pay_scale_id']) as $gRow) {
            $grades[$gRow->id] = $gRow->grade_name;
            $gradePayScaleIds[$gRow->id] = $gRow->pay_scale_id;
        }
        $octBills = [];
        foreach (DB::table('salary_generates')->where('salaryGenerationYear', 2026)->where('monthId', 10)
            ->orderBy('id')->get(['user_id', 'salaryAllowanceCalcObject', 'salaryDeductionCalcObject']) as $bill) {
            $octBills[$bill->user_id] = $bill; // latest id wins
        }

        fputcsv($handle, self::COLUMNS);
        $summary = ['rows' => 0];
        foreach (DB::table('employee_infos')->orderBy('id')->get() as $p) {
            $flags = [];
            [$adds, $addState] = $this->decode($p->additionObjects);
            [$deds, $dedState] = $this->decode($p->substractionObjects);
            $states = array_unique([$addState, $dedState]);
            $jsonStatus = in_array('INVALID', $states, true) ? 'INVALID'
                : (in_array('DOUBLE_ENCODED', $states, true) ? 'DOUBLE_ENCODED'
                : (in_array('EMPTY', $states, true) ? 'EMPTY' : 'OK'));
            if ($jsonStatus !== 'OK') $flags[] = 'JSON_' . $jsonStatus;

            [$addRows, $addIdOnly] = $this->named($adds, $typeNames);
            [$dedRows, $dedIdOnly] = $this->named($deds, $typeNames);
            if ($addIdOnly + $dedIdOnly > 0) $flags[] = 'ID_ONLY_COMPONENT_CONFIG';

            $user = $users->get($p->user_id);
            $category = isset($categoryUsers[$p->user_id]);
            if (!$category) $flags[] = 'MISSING_USER_CATEGORY';
            $active = $user ? (int) $user->isActive : null;
            if ($user === null) $flags[] = 'USER_MISSING';
            if ((string) $p->employeeCode === '30235' && $active === 1) $flags[] = 'ACTIVE_STATUS_REVIEW_30235';

            $profileBasic = $this->basic($addRows);
            $bill = $octBills[$p->user_id] ?? null;
            $octAdds = $bill ? $this->named($this->decode($bill->salaryAllowanceCalcObject)[0], $typeNames)[0] : [];
            $octDeds = $bill ? $this->named($this->decode($bill->salaryDeductionCalcObject)[0], $typeNames)[0] : [];
            $octBasic = $bill ? $this->basic($octAdds) : null;
            if (!$bill) $flags[] = 'NO_OCT_BILL';
            if ($profileBasic === null) $flags[] = 'PROFILE_BASIC_MISSING';
            if ($octBasic !== null && $profileBasic !== null && abs($octBasic - $profileBasic) > 0.009) $flags[] = 'PROFILE_BASIC_DIFFERS_FROM_OCT';

            // Components billed in October that the current profile no longer carries.
            $configured = array_map('strtolower', array_column(array_merge($addRows, $dedRows), 'name'));
            $missing = [];
            foreach (array_merge($octAdds, $octDeds) as $row) {
                $key = strtolower($row['name']);
                if (preg_match('/arrear|recovery|adjust|additional|manual/i', $row['name'])) continue;
                if (!in_array($key, $configured, true) && !in_array($row['name'], $missing, true)) $missing[] = $row['name'];
            }
            if ($missing) $flags[] = 'MISSING_COMPONENTS_VS_OCT';

            foreach (array_merge($addRows, $octAdds) as $row) {
                if (preg_match('/medical/i', $row['name']) && abs($row['amount']) < 0.005) { $flags[] = 'MA_ZERO'; break; }
            }

            $stamps = count(array_filter($dedRows, fn ($r) => preg_match('/revenue\s*stamp/i', $r['name'])));
            if ($stamps === 0) $flags[] = 'REVENUE_STAMP_MISSING';
            if ($stamps > 1) $flags[] = 'REVENUE_STAMP_DUPLICATE';

            if ((string) $p->employeeCode === '10028') {
                $tax = array_sum(array_column(array_filter($dedRows, fn ($r) => preg_match('/tax/i', $r['name'])), 'amount'));
                $octTax = array_sum(array_column(array_filter($octDeds, fn ($r) => preg_match('/tax/i', $r['name'])), 'amount'));
                $flags[] = sprintf('TAX_REVIEW_10028(profile=%.2f;oct=%.2f)', $tax, $octTax);
            }

            $gradeName = $grades[$p->payroll_group_id] ?? '';
            $scaleId = $p->pay_scale_id ?? ($gradePayScaleIds[$p->payroll_group_id] ?? null);
            $scaleName = $scaleId !== null ? ($payScales[$scaleId] ?? null) : null;
            $expectedSbRate = self::resolveExpectedSbRate($scaleName, $gradeName);

            $sb = array_values(array_filter(array_merge($addRows, $octAdds),
                fn ($r) => preg_match('/special\s*benefit/i', $r['name']) && !preg_match('/recovery/i', $r['name'])));
            $sbEligible = $sb !== [];
            if ($sbEligible && $octBasic > 0 && $expectedSbRate !== null) {
                $octSb = array_values(array_filter($octAdds, fn ($r) => preg_match('/special\s*benefit/i', $r['name']) && !preg_match('/recovery/i', $r['name'])));
                if ($octSb && abs($octSb[0]['amount'] / $octBasic * 100 - (float) $expectedSbRate) > 0.01) {
                    $flags[] = 'SB_OCT_RATE_MISMATCH';
                }
            }

            $gpfConfigured = count(array_filter($dedRows, fn ($r) => preg_match('/gpf|provident/i', $r['name']))) > 0;
            if ($gpfConfigured && $p->jobStatus !== 'permanent') $flags[] = 'GPF_ON_NON_PERMANENT';

            $joining = $joiningById[$p->joining_date_id] ?? $joiningByUser[$p->user_id] ?? '';
            fputcsv($handle, [
                $p->employeeCode, $p->user_id, $user->name ?? '', $category ? 'yes' : 'no',
                $active === null ? '' : $active, $joining, $p->jobStatus, $gradeName,
                (int) $p->holdIncrement, $profileBasic !== null ? 'profile_json' : 'unresolved',
                $octBasic !== null ? number_format($octBasic, 2, '.', '') : '',
                $profileBasic !== null ? number_format($profileBasic, 2, '.', '') : '',
                $jsonStatus, implode('; ', $missing), $sbEligible ? 'yes' : 'no',
                $expectedSbRate !== null ? (string) $expectedSbRate : '', $gpfConfigured ? 'yes' : 'no',
                $stamps, implode('|', $flags),
            ]);
            $summary['rows']++;
            foreach ($flags as $flag) {
                $key = preg_replace('/\(.*$/', '', $flag);
                $summary[$key] = ($summary[$key] ?? 0) + 1;
            }
        }
        fclose($handle);

        $this->info('Baseline audit written (read-only): ' . $output);
        foreach ($summary as $label => $count) $this->line(sprintf('  %-34s %d', $label, $count));
        return self::SUCCESS;
    }

    /** @return array{0: array, 1: string} rows and JSON state */
    private function decode(mixed $value): array
    {
        if ($value === null || $value === '') return [[], 'EMPTY'];
        $layers = 0;
        while (is_string($value)) {
            $value = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) return [[], 'INVALID'];
            if (++$layers > 4) return [[], 'INVALID'];
        }
        if (!is_array($value)) return [[], 'INVALID'];
        return [$value, $layers > 1 ? 'DOUBLE_ENCODED' : ($value === [] ? 'EMPTY' : 'OK')];
    }

    /** @return array{0: array<int,array{name:string,amount:float}>, 1: int} named rows and ID-only count */
    private function named(array $rows, array $typeNames): array
    {
        $out = [];
        $idOnly = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $name = trim((string) ($row['name'] ?? $row['payroll_component_type']['name'] ?? ''));
            if ($name === '') {
                $id = $row['payroll_component_type_id'] ?? $row['id'] ?? null;
                $idOnly++;
                $name = $id !== null ? (string) ($typeNames[$id] ?? 'UNKNOWN_TYPE_ID_' . $id) : 'UNNAMED';
            }
            $amount = $row['calculated_amount'] ?? $row['amount'] ?? 0;
            $out[] = ['name' => $name, 'amount' => is_numeric($amount) ? (float) $amount : 0.0];
        }
        return [$out, $idOnly];
    }

    private function basic(array $rows): ?float
    {
        foreach ($rows as $row) {
            if (preg_match('/^basic(\s+(pay|salary))?$/i', $row['name'])) return $row['amount'];
        }
        return null;
    }
}
