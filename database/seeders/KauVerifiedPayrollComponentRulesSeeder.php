<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Production-safe, approval-manifest-driven rule publisher.
 *
 * Default invocation is a READ-ONLY dry run. This seeder NEVER activates a
 * PayScale, changes a historical payroll component/employee JSON, posts a
 * salary/arrear/GPF transaction, or writes global salary_component_publications.
 *
 * A verified review CSV covering every source designation component is required.
 * Only an all-reviewed manifest (APPROVED or EXCLUDED) may be applied.
 * An APPROVED row requires an exact rate/formula, dates, frequency and evidence.
 * EXCLUDED means deliberately non-payable, not an active zero-value component.
 *
 * Apply deliberately with both environment variables:
 *   KAU_COMPONENT_SEED_APPLY=YES_I_APPROVED
 *   KAU_COMPONENT_SEED_ACTOR_ID=<existing admin user id>
 * php artisan db:seed --class=KauVerifiedPayrollComponentRulesSeeder --force
 */
final class KauVerifiedPayrollComponentRulesSeeder extends Seeder
{
    private const COLUMNS = [
        'pay_scale', 'grade', 'designation', 'component', 'direction',
        'calc_type', 'value', 'basis', 'frequency', 'effective_from',
        'effective_until', 'status', 'evidence_reference',
    ];

    private function fail(string $message): never
    {
        throw new RuntimeException('KAU component seeder: '.$message);
    }

    private function key(array $row): string
    {
        return implode('|', array_map(static fn (string $column): string =>
            strtolower(trim((string) ($row[$column] ?? ''))),
            ['pay_scale', 'grade', 'designation', 'component']
        ));
    }

    /** @return array<int,array<string,string>> */
    private function readCsv(string $path): array
    {
        if (!is_readable($path)) {
            $this->fail('Approval CSV unavailable: '.$path);
        }
        $file = fopen($path, 'rb');
        if ($file === false) {
            $this->fail('Could not open CSV.');
        }
        try {
            $header = fgetcsv($file, 0, ',', '"', '\\');
            if (!is_array($header)) {
                $this->fail('CSV has no header.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            if (array_diff(self::COLUMNS, $header) !== []) {
                $this->fail('Required CSV columns missing: '.implode(', ', array_diff(self::COLUMNS, $header)));
            }
            if (count($header) !== count(array_unique($header))) {
                $this->fail('CSV contains duplicate column names.');
            }
            $data = [];
            $line = 1;
            while (($cells = fgetcsv($file, 0, ',', '"', '\\')) !== false) {
                $line++;
                if ($cells === [null]) {
                    continue;
                }
                if (count($cells) !== count($header)) {
                    $this->fail('Invalid column count at CSV line '.$line);
                }
                $row = array_combine($header, $cells);
                $row = array_map(static fn ($v): string => trim((string) $v), $row);
                $row['_line'] = (string) $line;
                $data[] = $row;
            }
            return $data;
        } finally {
            fclose($file);
        }
    }

    private function dateOrNull(string $value, string $label): ?string
    {
        if ($value === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) {
            $this->fail('Invalid '.$label.': '.$value);
        }
        return $value;
    }

    private function prepare(array $row, object $source): array
    {
        $line = (string) $row['_line'];
        $direction = strtolower($row['direction']);
        if ($direction !== ($source->component_type === 'allowance' ? 'addition' : 'deduction')) {
            $this->fail('Direction does not match source at CSV line '.$line);
        }
        $mode = strtolower($row['calc_type']);
        if (!in_array($mode, ['fixed', 'percentage', 'calculated'], true)) {
            $this->fail('Invalid calculation mode at CSV line '.$line);
        }
        $basic = strcasecmp($row['component'], 'Basic Pay') === 0;
        $basis = strtolower($row['basis']);
        $value = null;
        if ($basic) {
            if ($mode !== 'calculated' || $row['value'] !== '' || $basis !== 'payable_basic') {
                $this->fail('Basic Pay must be calculated from entitlement, not hardcoded: line '.$line);
            }
        } else {
            if ($mode === 'calculated') {
                $this->fail('Only Basic Pay may use calculated mode at CSV line '.$line);
            }
            if ($row['value'] === '' || !is_numeric($row['value'])) {
                $this->fail('An explicitly approved numeric rate is required at CSV line '.$line);
            }
            $value = (float) $row['value'];
            if (!is_finite($value) || $value < 0 || $value > 999999999.99) {
                $this->fail('Invalid value at CSV line '.$line);
            }
            if ($mode === 'percentage') {
                if ($value > 100 || !in_array($basis, ['payable_basic', 'legacy_basic'], true)) {
                    $this->fail('Percentage requires verified basis and rate <= 100 at CSV line '.$line);
                }
            } elseif ($basis !== '' && $basis !== 'none') {
                $this->fail('Fixed amount must not define a percentage base at CSV line '.$line);
            }
        }
        $frequency = strtolower($row['frequency']);
        if (!in_array($frequency, ['monthly', 'annual', 'event', 'one_time'], true)) {
            $this->fail('Missing/invalid payment frequency at CSV line '.$line);
        }
        $from = $this->dateOrNull($row['effective_from'], 'effective_from');
        $until = $this->dateOrNull($row['effective_until'], 'effective_until');
        if ($from === null || ($until !== null && $until < $from)) {
            $this->fail('Missing/invalid effective period at CSV line '.$line);
        }
        if (strlen($row['evidence_reference']) < 6 || strlen($row['evidence_reference']) > 500) {
            $this->fail('Approved evidence reference is missing at CSV line '.$line);
        }
        if (strcasecmp($row['pay_scale'], 'Govt. Pay Scale 2026') === 0) {
            if (stripos($row['component'], 'special benefit') !== false) {
                $this->fail('Special Benefit is not an automatic new-scale component: line '.$line);
            }
            if (stripos($row['component'], 'house rent') !== false && $from < '2028-01-01') {
                $this->fail('2026 revised House Rent must not be effective before 2028-01-01: line '.$line);
            }
        }
        $rule = [
            'payroll_group_id' => (int) $source->payroll_group_id,
            'payroll_component_type_id' => (int) $source->payroll_component_type_id,
            'direction' => $direction,
            'calc_type' => $mode,
            'value' => $value,
            'basis' => $basis === '' ? null : $basis,
            'frequency' => $frequency,
            'effective_from' => $from,
            'effective_until' => $until,
            'published' => true,
            'evidence_reference' => $row['evidence_reference'],
        ];
        $rule['source_hash'] = hash('sha256', json_encode($rule, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $rule;
    }

    public function run(): void
    {
        foreach (['payroll_components', 'payroll_groups', 'grades', 'pay_scales',
            'payroll_group_types', 'payroll_component_types',
            'salary_component_effective_rules', 'salary_rule_events', 'users', 'roles', 'user_roles'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->fail('Required table is missing: '.$table.'; review migrations first.');
            }
        }
        $file = database_path('seeders/data/KAU_payroll_component_rules_REVIEW.csv');
        $records = $this->readCsv($file);
        $sources = DB::table('payroll_components AS pc')
            ->join('payroll_groups AS pg', 'pc.payroll_group_id', '=', 'pg.id')
            ->join('grades AS g', 'pg.grade_id', '=', 'g.id')
            ->join('pay_scales AS ps', 'g.pay_scale_id', '=', 'ps.id')
            ->join('payroll_group_types AS pgt', 'pg.payroll_group_type_id', '=', 'pgt.id')
            ->join('payroll_component_types AS pct', 'pc.payroll_component_type_id', '=', 'pct.id')
            ->select('pc.payroll_group_id', 'pc.payroll_component_type_id', 'pc.component_type',
                'ps.scale_name AS pay_scale', 'g.grade_name AS grade',
                'pgt.group_name AS designation', 'pct.name AS component')
            ->get();
        $available = [];
        foreach ($sources as $source) {
            $key = $this->key((array) $source);
            if (isset($available[$key])) {
                $this->fail('Ambiguous source component: '.$key);
            }
            $available[$key] = $source;
        }
        $seen = [];
        $approved = [];
        $pending = 0;
        $excluded = 0;
        foreach ($records as $row) {
            $key = $this->key($row);
            $source = $available[$key] ?? null;
            if (!$source) {
                $this->fail('CSV component is not in the live configuration (line '.$row['_line'].'): '.$key);
            }
            if (isset($seen[$key])) {
                $this->fail('Duplicate source component in CSV (line '.$row['_line'].'): '.$key);
            }
            $seen[$key] = true;
            $status = strtoupper($row['status']);
            if ($status === 'PENDING') {
                $pending++;
            } elseif ($status === 'EXCLUDED') {
                if (strlen($row['evidence_reference']) < 6) {
                    $this->fail('EXCLUDED requires evidence: line '.$row['_line']);
                }
                $excluded++;
            } elseif ($status === 'APPROVED') {
                $approved[] = $this->prepare($row, $source);
            } else {
                $this->fail('Status must be PENDING, APPROVED or EXCLUDED (line '.$row['_line'].')');
            }
        }
        if (count($seen) !== count($available)) {
            $missing = array_diff_key($available, $seen);
            $this->fail(count($missing).' live components are missing from the review inventory; examples: '.
                implode(', ', array_slice(array_keys($missing), 0, 3)));
        }
        $this->command?->line(sprintf(
            'Component review: %d source assignments, %d APPROVED, %d EXCLUDED, %d PENDING.',
            count($available), count($approved), $excluded, $pending
        ));
        if (getenv('KAU_COMPONENT_SEED_APPLY') !== 'YES_I_APPROVED') {
            $this->command?->warn('DRY RUN ONLY. No rows updated. Approval is required for each component.');
            return;
        }
        if ($pending !== 0) {
            $this->fail('Cannot publish partial payroll configuration. '.$pending.' components still PENDING.');
        }
        $actor = filter_var(getenv('KAU_COMPONENT_SEED_ACTOR_ID'), FILTER_VALIDATE_INT);
        if (!$actor || $actor < 1 || !DB::table('users AS u')
            ->join('user_roles AS ur', 'ur.user_id', '=', 'u.id')
            ->join('roles AS r', 'r.id', '=', 'ur.role_id')
            ->where('u.id', $actor)->where('u.isActive', true)
            ->where('r.is_admin', true)->where('r.isActive', true)->exists()) {
            $this->fail('KAU_COMPONENT_SEED_ACTOR_ID must be an active administrator with an active admin role.');
        }
        if (DB::getDriverName() !== 'mysql') {
            $this->fail('Apply is enabled only for the audited MySQL driver.');
        }
        $lock = 'kau_verified_component_rules_v1';
        $acquired = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lock]);
        if ((int) ($acquired->acquired ?? 0) !== 1) {
            $this->fail('Could not obtain the database publication lock.');
        }
        try {
            $inserted = DB::transaction(function () use ($approved, $actor): int {
                $count = 0;
                foreach ($approved as $rule) {
                    $existing = DB::table('salary_component_effective_rules')
                        ->where('payroll_group_id', $rule['payroll_group_id'])
                        ->where('payroll_component_type_id', $rule['payroll_component_type_id'])
                        ->where('effective_from', $rule['effective_from'])
                        ->lockForUpdate()->first();
                    if ($existing) {
                        if (!hash_equals((string) $existing->source_hash, $rule['source_hash'])
                            || !(bool) $existing->published) {
                            $this->fail('Existing published policy differs; use a reviewed policy revision, never overwrite.');
                        }
                        continue;
                    }
                    $overlap = DB::table('salary_component_effective_rules')
                        ->where('payroll_group_id', $rule['payroll_group_id'])
                        ->where('payroll_component_type_id', $rule['payroll_component_type_id'])
                        ->where('effective_from', '<=', $rule['effective_until'] ?? '9999-12-31')
                        ->where(function ($query) use ($rule): void {
                            $query->whereNull('effective_until')
                                ->orWhere('effective_until', '>=', $rule['effective_from']);
                        })->exists();
                    if ($overlap) {
                        $this->fail('Overlapping effective periods found; no records changed.');
                    }
                    $payload = $rule + [
                        'published_by' => (int) $actor,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $id = DB::table('salary_component_effective_rules')->insertGetId($payload);
                    DB::table('salary_rule_events')->insert([
                        'rule_kind' => 'effective_component',
                        'rule_id' => $id,
                        'action' => 'published',
                        'before_value' => null,
                        'after_value' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'actor_user_id' => (int) $actor,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $count++;
                }
                return $count;
            }, 3);
            $this->command?->info('Published '.$inserted.' verified effective component rules; no payroll or ledger writes.');
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
        }
    }
}
