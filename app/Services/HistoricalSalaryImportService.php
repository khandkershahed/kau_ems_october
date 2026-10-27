<?php

namespace App\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Phase 1 — Historical salary importer for kau-app(4).sql (June–September 2026).
 *
 * Guarantees:
 *  - Never executes the SQL dump; parses only the INSERT INTO `salary_generates` rows.
 *  - Employee matching is by employeeCode -> employee_infos -> users.id (code is not a unique identity by itself;
 *    the mapping is validated against the users table and duplicates are reported).
 *  - Existing employee-month records are never overwritten automatically.
 *  - Identical existing records are skipped; conflicting records are reported.
 *  - Imported historical bills are marked approved (approval does not imply payment).
 *  - Every write is recorded in salary_import_runs / salary_import_records for provenance and rollback.
 */
final class HistoricalSalaryImportService
{
    /** Column order of the salary_generates INSERT tuples in the dump (matches both schemas). */
    private const COLUMNS = [
        'id', 'user_id', 'reviewStatus', 'name', 'age', 'gender', 'payScale', 'grade', 'designation',
        'employeeCode', 'bankAccountNo', 'bankAddress', 'employeePhoneNumber', 'joiningDate',
        'salaryGenerationDate', 'monthId', 'salaryGenerationYear',
        'salaryAllowanceCalcObject', 'salaryDeductionCalcObject',
        'totalAllowance', 'totalDeduction', 'taxDeduction', 'gpfDeduction', 'salaryTotal',
        'bonusAllowanceCalcObject', 'bonusDeductionCalcObject',
        'bonusAllowance', 'bonusDeduction', 'bonusTaxDeduction', 'bonusTotal',
        'loanInstallmentDeduction', 'loanInstallmentCount', 'loanInstallmentRemaining',
        'created_at', 'updated_at',
    ];

    /** Fields compared to decide identical vs conflict (id, created_at, updated_at are excluded). */
    private const COMPARED = [
        'name', 'age', 'gender', 'payScale', 'grade', 'designation', 'employeeCode',
        'bankAccountNo', 'bankAddress', 'employeePhoneNumber', 'joiningDate',
        'salaryGenerationDate', 'monthId', 'salaryGenerationYear',
        'salaryAllowanceCalcObject', 'salaryDeductionCalcObject',
        'totalAllowance', 'totalDeduction', 'taxDeduction', 'gpfDeduction', 'salaryTotal',
        'bonusAllowanceCalcObject', 'bonusDeductionCalcObject',
        'bonusAllowance', 'bonusDeduction', 'bonusTaxDeduction', 'bonusTotal',
        'loanInstallmentDeduction', 'loanInstallmentCount', 'loanInstallmentRemaining',
    ];

    public function __construct(private string $sourceFile = 'database/sql/kau-app.sql') {}

    public function setSourceFile(string $path): void
    {
        $this->sourceFile = $path;
    }

    public function sourceSha256(): string
    {
        return hash_file('sha256', base_path($this->sourceFile));
    }

    /** Parse the dump and return the historical salary_generates rows for the given months/year. */
    public function parse(array $months = [6, 7, 8, 9], int $year = 2026): array
    {
        $path = base_path($this->sourceFile);
        if (! is_file($path)) {
            throw new RuntimeException("Historical source file not found: {$path}");
        }

        $rows = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}");
        }
        try {
            $inInsert = false;
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if (! $inInsert) {
                    if (str_starts_with($line, 'INSERT INTO `salary_generates` VALUES')) {
                        $inInsert = true;
                        $payload = substr($line, strlen('INSERT INTO `salary_generates` VALUES'));
                        $this->collectRows($payload, $months, $year, $rows);
                        continue;
                    }
                }
                // Continuation line(s) of the same INSERT statement.
                if ($inInsert) {
                    if (str_starts_with($line, '/*!40000 ALTER TABLE `salary_generates` ENABLE KEYS')
                        || str_starts_with($line, 'UNLOCK TABLES')) {
                        $inInsert = false;
                        continue;
                    }
                    $this->collectRows($line, $months, $year, $rows);
                }
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    private function collectRows(string $payload, array $months, int $year, array &$rows): void
    {
        foreach ($this->splitTuples($payload) as $tuple) {
            $row = $this->mapTuple($tuple);
            if ((int) $row['salaryGenerationYear'] === $year
                && in_array((int) $row['monthId'], $months, true)) {
                $rows[] = $row;
            }
        }
    }

    /** Split a VALUES payload into tuple strings, respecting quoted strings and backslash escapes. */
    private function splitTuples(string $payload): array
    {
        $tuples = [];
        $buffer = '';
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($payload);
        for ($i = 0; $i < $length; $i++) {
            $ch = $payload[$i];
            if ($inString) {
                $buffer .= $ch;
                if ($escaped) {
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === "'") {
                    $inString = false;
                }
                continue;
            }
            if ($ch === "'") {
                $inString = true;
                $buffer .= $ch;
                continue;
            }
            if ($ch === '(') {
                $depth++;
                if ($depth === 1) {
                    $buffer = '';
                    continue;
                }
                $buffer .= $ch;
                continue;
            }
            if ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $tuples[] = $buffer;
                    $buffer = '';
                    continue;
                }
                $buffer .= $ch;
                continue;
            }
            $buffer .= $ch;
        }
        return $tuples;
    }

    /** Split one tuple into fields at top-level commas, respecting quoted strings and backslash escapes. */
    private function splitFields(string $tuple): array
    {
        $fields = [];
        $buffer = '';
        $inString = false;
        $escaped = false;
        $length = strlen($tuple);
        for ($i = 0; $i < $length; $i++) {
            $ch = $tuple[$i];
            if ($inString) {
                $buffer .= $ch;
                if ($escaped) {
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === "'") {
                    $inString = false;
                }
                continue;
            }
            if ($ch === "'") {
                $inString = true;
                $buffer .= $ch;
                continue;
            }
            if ($ch === ',') {
                $fields[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        $fields[] = $buffer;
        return $fields;
    }

    private function mapTuple(string $tuple): array
    {
        $fields = $this->splitFields($tuple);
        if (count($fields) !== count(self::COLUMNS)) {
            throw new RuntimeException(
                'salary_generates tuple has '.count($fields).' fields, expected '.count(self::COLUMNS).'.'
            );
        }
        $row = [];
        foreach (self::COLUMNS as $index => $column) {
            $raw = $fields[$index];
            if (strlen($raw) >= 2 && $raw[0] === "'" && $raw[strlen($raw) - 1] === "'") {
                $raw = substr($raw, 1, -1);
                $raw = str_replace(["\\'", '\\\\', '\\n', '\\r', '\\Z', '\\"'],
                    ["'", '\\', "\n", "\r", "\x1a", '"'], $raw);
            }
            // MySQL dump NULL literals must become real PHP nulls for nullable columns.
            $row[$column] = ($raw === 'NULL') ? null : $raw;
        }
        return $row;
    }

    /** JSON normalization for deep comparison: numeric strings become numbers, component arrays sorted by name. */
    private function normalizeJson(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if ($decoded === null && $raw !== '[]') {
            return $raw;
        }
        return json_encode($this->normalizeValue($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $items = array_map(fn ($v) => $this->normalizeValue($v), $value);
                usort($items, function ($a, $b) {
                    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
                });
                return $items;
            }
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->normalizeValue($v);
            }
            return $out;
        }
        if (is_string($value) && is_numeric($value)) {
            return $value + 0;
        }
        return $value;
    }

    /** Compare a historical source row against the current database row for the same employee+month+year. */
    public function compare(array $source, object $existing): bool
    {
        $diffs = [];
        foreach (self::COMPARED as $field) {
            $a = $source[$field];
            $b = $existing->{$field} ?? null;
            if (in_array($field, ['salaryAllowanceCalcObject', 'salaryDeductionCalcObject',
                'bonusAllowanceCalcObject', 'bonusDeductionCalcObject'], true)) {
                if ($this->normalizeJson((string) $a) !== $this->normalizeJson((string) $b)) {
                    $diffs[] = $field;
                }
                continue;
            }
            if (in_array($field, ['totalAllowance', 'totalDeduction', 'taxDeduction', 'gpfDeduction', 'salaryTotal',
                'bonusAllowance', 'bonusDeduction', 'bonusTaxDeduction', 'bonusTotal',
                'loanInstallmentDeduction'], true)) {
                if (abs(((float) $a) - ((float) $b)) > 0.0000001) {
                    $diffs[] = $field;
                }
                continue;
            }
            if ((string) $a !== (string) $b) {
                $diffs[] = $field;
            }
        }
        return $diffs === [];
    }

    /** Employee matching report: employeeCode -> current users.id (via employee_infos). */
    public function matchEmployees(array $rows): array
    {
        $codes = [];
        foreach ($rows as $row) {
            $codes[$row['employeeCode']] = true;
        }
        $codes = array_keys($codes);
        $lookup = [];
        foreach (DB::table('employee_infos')
            ->whereIn('employeeCode', $codes)
            ->join('users', 'users.id', '=', 'employee_infos.user_id')
            ->get(['employee_infos.employeeCode', 'employee_infos.user_id', 'users.name']) as $r) {
            $lookup[$r->employeeCode][] = ['user_id' => $r->user_id, 'name' => $r->name];
        }

        $matched = [];
        $unmatched = [];
        $ambiguous = [];
        foreach ($rows as $row) {
            $code = $row['employeeCode'];
            if (! isset($lookup[$code])) {
                $unmatched[$code] = true;
            } elseif (count($lookup[$code]) > 1) {
                $ambiguous[$code] = $lookup[$code];
            } else {
                $matched[$code] = $lookup[$code][0];
            }
        }
        return [
            'lookup' => $lookup,
            'matched' => $matched,
            'unmatched' => array_keys($unmatched),
            'ambiguous' => $ambiguous,
        ];
    }

    /** Classify every historical row: insert / skip / conflict / unmatched / ambiguous. */
    public function classify(array $rows): array
    {
        $matching = $this->matchEmployees($rows);
        $plan = ['insert' => [], 'skip' => [], 'conflict' => [], 'unmatched' => [], 'ambiguous' => []];

        $userIds = array_map(fn ($m) => $m['user_id'], $matching['matched']);
        $existing = collect();
        if ($userIds) {
            $existing = DB::table('salary_generates')
                ->where('salaryGenerationYear', 2026)
                ->whereIn('monthId', [6, 7, 8, 9])
                ->whereIn('user_id', $userIds)
                ->get()
                ->groupBy(fn ($r) => $r->user_id.'-'.$r->monthId);
        }

        foreach ($rows as $row) {
            $code = $row['employeeCode'];
            if (! isset($matching['matched'][$code])) {
                if (isset($matching['ambiguous'][$code])) {
                    $plan['ambiguous'][] = $row;
                } else {
                    $plan['unmatched'][] = $row;
                }
                continue;
            }
            $user_id = $matching['matched'][$code]['user_id'];
            $name = $matching['matched'][$code]['name'];
            $key = $user_id.'-'.$row['monthId'];
            if (! isset($existing[$key])) {
                $plan['insert'][] = ['source' => $row, 'user_id' => $user_id, 'name' => $name];
            } elseif ($existing[$key]->count() > 1) {
                $plan['conflict'][] = [
                    'source' => $row, 'user_id' => $user_id, 'name' => $name, 'existing_id' => null,
                    'note' => 'Duplicate existing employee-month records in target database; manual resolution required.',
                ];
            } else {
                $existingRow = $existing[$key]->first();
                if ($this->compare($row, $existingRow)) {
                    $plan['skip'][] = ['source' => $row, 'user_id' => $user_id, 'existing_id' => $existingRow->id, 'name' => $name];
                } else {
                    $plan['conflict'][] = ['source' => $row, 'user_id' => $user_id, 'name' => $name, 'existing_id' => $existingRow->id];
                }
            }
        }
        return $plan;
    }

    /** Execute the classified plan. Only rows classified as "insert" are written when $apply is true. */
    public function run(array $plan, bool $apply): array
    {
        if (! Schema::hasTable('salary_import_runs')) {
            throw new RuntimeException('salary_import_runs table missing. Run the Phase 1 migration first.');
        }

        $summary = [
            'mode' => $apply ? 'apply' : 'dry_run',
            'source_sha256' => $this->sourceSha256(),
            'insert' => count($plan['insert']),
            'skip' => count($plan['skip']),
            'conflict' => count($plan['conflict']),
            'unmatched' => count($plan['unmatched']),
            'ambiguous' => count($plan['ambiguous']),
            'inserted_ids' => [],
        ];

        $runId = null;
        if ($apply) {
            $runId = DB::table('salary_import_runs')->insertGetId([
                'source_file' => $this->sourceFile,
                'source_sha256' => $this->sourceSha256(),
                'mode' => 'apply',
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (count($plan['insert']) > 0) {
                DB::transaction(function () use ($plan, $runId, &$summary) {
                    $this->insertBatch($plan['insert'], $runId, $summary);
                });
            }
            $this->recordDecisions($runId, $plan);
            DB::table('salary_import_runs')->where('id', $runId)
                ->update(['summary' => json_encode($summary)]);
        }

        $summary['run_id'] = $runId;
        return $summary;
    }

    private function insertBatch(array $items, int $runId, array &$summary): void
    {
        foreach ($items as $item) {
            $row = $item['source'];
            $id = DB::table('salary_generates')->insertGetId([
                'user_id' => $item['user_id'],
                'reviewStatus' => 'approved', // historical bills imported as approved; approval != payment
                'name' => $row['name'],
                'age' => $row['age'],
                'gender' => $row['gender'],
                'payScale' => $row['payScale'],
                'grade' => $row['grade'],
                'designation' => $row['designation'],
                'employeeCode' => $row['employeeCode'],
                'bankAccountNo' => $row['bankAccountNo'],
                'bankAddress' => $row['bankAddress'],
                'employeePhoneNumber' => $row['employeePhoneNumber'],
                'joiningDate' => $row['joiningDate'],
                'salaryGenerationDate' => $row['salaryGenerationDate'],
                'monthId' => $row['monthId'],
                'salaryGenerationYear' => $row['salaryGenerationYear'],
                'salaryAllowanceCalcObject' => $row['salaryAllowanceCalcObject'],
                'salaryDeductionCalcObject' => $row['salaryDeductionCalcObject'],
                'totalAllowance' => $row['totalAllowance'],
                'totalDeduction' => $row['totalDeduction'],
                'taxDeduction' => $row['taxDeduction'],
                'gpfDeduction' => $row['gpfDeduction'],
                'salaryTotal' => $row['salaryTotal'],
                'bonusAllowanceCalcObject' => $row['bonusAllowanceCalcObject'],
                'bonusDeductionCalcObject' => $row['bonusDeductionCalcObject'],
                'bonusAllowance' => $row['bonusAllowance'],
                'bonusDeduction' => $row['bonusDeduction'],
                'bonusTaxDeduction' => $row['bonusTaxDeduction'],
                'bonusTotal' => $row['bonusTotal'],
                'loanInstallmentDeduction' => $row['loanInstallmentDeduction'],
                'loanInstallmentCount' => $row['loanInstallmentCount'],
                'loanInstallmentRemaining' => $row['loanInstallmentRemaining'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $summary['inserted_ids'][] = $id;
            DB::table('salary_import_records')->insert([
                'run_id' => $runId,
                'source_row_id' => $row['id'],
                'target_salary_generate_id' => $id,
                'user_id' => $item['user_id'],
                'employee_code' => $row['employeeCode'],
                'month_id' => $row['monthId'],
                'year' => $row['salaryGenerationYear'],
                'action' => 'inserted',
                'source_review_status' => $row['reviewStatus'],
                'note' => 'Imported from historical dump; marked approved. Source user_id was '.$row['user_id'].'.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function recordDecisions(int $runId, array $plan): void
    {
        $records = [];
        foreach ($plan['skip'] as $item) {
            $records[] = ['run_id' => $runId, 'source_row_id' => $item['source']['id'],
                'target_salary_generate_id' => $item['existing_id'], 'user_id' => $item['user_id'],
                'employee_code' => $item['source']['employeeCode'], 'month_id' => $item['source']['monthId'],
                'year' => $item['source']['salaryGenerationYear'], 'action' => 'skipped',
                'source_review_status' => $item['source']['reviewStatus'],
                'note' => 'Identical existing record; not modified.'];
        }
        foreach ($plan['conflict'] as $item) {
            $records[] = ['run_id' => $runId, 'source_row_id' => $item['source']['id'],
                'target_salary_generate_id' => $item['existing_id'], 'user_id' => $item['user_id'],
                'employee_code' => $item['source']['employeeCode'], 'month_id' => $item['source']['monthId'],
                'year' => $item['source']['salaryGenerationYear'], 'action' => 'conflict',
                'source_review_status' => $item['source']['reviewStatus'],
                'note' => $item['note'] ?? 'Existing record differs from historical source; not overwritten.'];
        }
        foreach ($plan['unmatched'] as $row) {
            $records[] = ['run_id' => $runId, 'source_row_id' => $row['id'], 'target_salary_generate_id' => null,
                'user_id' => null, 'employee_code' => $row['employeeCode'], 'month_id' => $row['monthId'],
                'year' => $row['salaryGenerationYear'], 'action' => 'unmatched',
                'source_review_status' => $row['reviewStatus'],
                'note' => 'No current employee with this employeeCode.'];
        }
        foreach ($plan['ambiguous'] as $row) {
            $records[] = ['run_id' => $runId, 'source_row_id' => $row['id'], 'target_salary_generate_id' => null,
                'user_id' => null, 'employee_code' => $row['employeeCode'], 'month_id' => $row['monthId'],
                'year' => $row['salaryGenerationYear'], 'action' => 'ambiguous',
                'source_review_status' => $row['reviewStatus'],
                'note' => 'employeeCode matches multiple current employees; manual resolution required.'];
        }
        foreach ($records as $record) {
            $record['created_at'] = now();
            $record['updated_at'] = now();
            DB::table('salary_import_records')->insert($record);
        }
    }

    /** Rollback all rows inserted by a given run. Only exact provenance rows are deleted. */
    public function rollback(int $runId): int
    {
        $run = DB::table('salary_import_runs')->where('id', $runId)->first();
        if (! $run) {
            throw new DomainException("Import run {$runId} not found.");
        }
        if ($run->mode !== 'apply') {
            throw new DomainException("Run {$runId} was not an apply run; nothing to roll back.");
        }
        $targetIds = DB::table('salary_import_records')
            ->where('run_id', $runId)->where('action', 'inserted')
            ->pluck('target_salary_generate_id')->filter()->all();
        if (! $targetIds) {
            return 0;
        }
        $deleted = 0;
        DB::transaction(function () use ($targetIds, &$deleted, $runId) {
            foreach (array_chunk($targetIds, 200) as $chunk) {
                $deleted += DB::table('salary_generates')->whereIn('id', $chunk)->delete();
            }
            DB::table('salary_import_records')->where('run_id', $runId)->delete();
            DB::table('salary_import_runs')->where('id', $runId)->update(['status' => 'rolled_back']);
        });
        return $deleted;
    }

    /** Replace conflicting records: backup the current row, then overwrite with the historical source values. */
    public function replaceConflicts(array $plan): array
    {
        if (! Schema::hasTable('salary_import_backups')) {
            throw new RuntimeException('salary_import_backups table missing. Run the Phase 1 migration first.');
        }
        $replaced = 0;
        $backups = 0;
        DB::transaction(function () use ($plan, &$replaced, &$backups) {
            $replaceRunId = DB::table('salary_import_runs')->insertGetId([
                'source_file' => $this->sourceFile,
                'source_sha256' => $this->sourceSha256(),
                'mode' => 'replace',
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($plan['conflict'] as $item) {
                $existingId = $item['existing_id'] ?? null;
                if ($existingId === null) {
                    continue; // ambiguous duplicates cannot be auto-replaced
                }
                $current = DB::table('salary_generates')->where('id', $existingId)->first();
                if (! $current) {
                    continue;
                }
                DB::table('salary_import_backups')->insert([
                    'run_id' => $replaceRunId,
                    'salary_generate_id' => $existingId,
                    'backup_payload' => json_encode($current),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $backups++;
                $source = $item['source'];
                DB::table('salary_generates')->where('id', $existingId)->update([
                    'reviewStatus' => 'approved',
                    'name' => $source['name'],
                    'age' => $source['age'],
                    'gender' => $source['gender'],
                    'payScale' => $source['payScale'],
                    'grade' => $source['grade'],
                    'designation' => $source['designation'],
                    'employeeCode' => $source['employeeCode'],
                    'bankAccountNo' => $source['bankAccountNo'],
                    'bankAddress' => $source['bankAddress'],
                    'employeePhoneNumber' => $source['employeePhoneNumber'],
                    'joiningDate' => $source['joiningDate'],
                    'salaryGenerationDate' => $source['salaryGenerationDate'],
                    'monthId' => $source['monthId'],
                    'salaryGenerationYear' => $source['salaryGenerationYear'],
                    'salaryAllowanceCalcObject' => $source['salaryAllowanceCalcObject'],
                    'salaryDeductionCalcObject' => $source['salaryDeductionCalcObject'],
                    'totalAllowance' => $source['totalAllowance'],
                    'totalDeduction' => $source['totalDeduction'],
                    'taxDeduction' => $source['taxDeduction'],
                    'gpfDeduction' => $source['gpfDeduction'],
                    'salaryTotal' => $source['salaryTotal'],
                    'bonusAllowanceCalcObject' => $source['bonusAllowanceCalcObject'],
                    'bonusDeductionCalcObject' => $source['bonusDeductionCalcObject'],
                    'bonusAllowance' => $source['bonusAllowance'],
                    'bonusDeduction' => $source['bonusDeduction'],
                    'bonusTaxDeduction' => $source['bonusTaxDeduction'],
                    'bonusTotal' => $source['bonusTotal'],
                    'loanInstallmentDeduction' => $source['loanInstallmentDeduction'],
                    'loanInstallmentCount' => $source['loanInstallmentCount'],
                    'loanInstallmentRemaining' => $source['loanInstallmentRemaining'],
                    'updated_at' => now(),
                ]);
                DB::table('salary_import_records')->insert([
                    'run_id' => $replaceRunId,
                    'source_row_id' => $source['id'],
                    'target_salary_generate_id' => $existingId,
                    'user_id' => $item['user_id'],
                    'employee_code' => $source['employeeCode'],
                    'month_id' => $source['monthId'],
                    'year' => $source['salaryGenerationYear'],
                    'action' => 'replaced',
                    'source_review_status' => $source['reviewStatus'],
                    'note' => 'Existing row backed up then overwritten with historical values.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $replaced++;
            }
        });
        return ['replaced' => $replaced, 'backups' => $backups];
    }
}

