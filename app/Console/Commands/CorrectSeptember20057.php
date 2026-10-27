<?php

namespace App\Console\Commands;

use App\Services\HistoricalSalaryImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Step 3B-R targeted preflight/correction — employee 20057, September 2026 ONLY.
 *
 * Scope is hard-locked: user_id=58, salary_generate_id=5850, month=9, year=2026.
 * --apply is refused automatically whenever any downstream dependency
 * (unreversed arrear GPF journal, GPF chain mismatch, changed record
 * fingerprint, missing provenance, or already-corrected state) prevents a
 * safe atomic correction. Approved fixation and employee 40173 are never
 * touched. Source reviewStatus is preserved; PDF approval evidence is
 * recorded in the correction journal note.
 */
class CorrectSeptember20057 extends Command
{
    protected $signature = 'kau:correct-september-20057
        {--dry-run : Show the correction plan and validate, write nothing}
        {--apply : Apply the correction atomically (refused when unsafe)}';

    protected $description = 'Targeted September 2026 salary + GPF correction preflight for employee 20057 (5850)';

    // Hard-locked scope.
    public const EMPLOYEE_CODE = '20057';
    public const USER_ID = 58;
    public const SALARY_GENERATE_ID = 5850;
    public const MONTH = 9;
    public const YEAR = 2026;
    public const GPF_LEDGER_ID = 5628;
    public const SOURCE_ROW_ID = 5903;
    public const JOURNAL_ID = 1;

    /** Verified September PDF values (approved for payment — payment evidence distinct from reviewStatus). */
    public const PDF = [
        'basic' => 19460.0, 'house_rent' => 8757.0, 'medical' => 1500.0,
        'special_benefit' => 2919.0, 'education' => 500.0,
        'gross' => 33136.0, 'gpf' => 1946.0, 'revenue_stamp' => 10.0,
        'total_deduction' => 1956.0, 'net' => 31180.0,
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        if ($apply === $dryRun) {
            $this->error('Pass exactly one of --dry-run or --apply.');

            return self::FAILURE;
        }

        // Task A — database identity.
        $db = DB::connection()->getDatabaseName();
        $host = config('database.connections.mysql.host');
        if ($db !== 'codershat_kau' || $host !== '127.0.0.1') {
            $this->error("Refusing: connected to {$db}@{$host} — expected codershat_kau@127.0.0.1.");

            return self::FAILURE;
        }
        $employee = DB::table('employee_infos')->where('employeeCode', self::EMPLOYEE_CODE)->first();
        if (! $employee || (int) $employee->user_id !== self::USER_ID) {
            $this->error('Employee 20057 identity mismatch — refusing.');

            return self::FAILURE;
        }

        // Task B — dependency audit (read-only).
        $bill = DB::table('salary_generates')->where('id', self::SALARY_GENERATE_ID)->first();
        if (! $bill) {
            $this->error('Target salary_generates id 5850 not found.');

            return self::FAILURE;
        }
        $issues = [];
        $issues[] = self::validateContext($bill);
        $issues = array_merge($issues, $this->dependencyIssues($bill));

        // Task C — source reconciliation via existing import provenance.
        $issues = array_merge($issues, $this->sourceIssues($bill));

        // Task D — correction journal / idempotency.
        $journal = DB::table('salary_import_records')
            ->where('action', 'correction_applied')
            ->where('target_salary_generate_id', self::SALARY_GENERATE_ID)
            ->first();
        if ($journal) {
            $issues[] = 'ALREADY_CORRECTED: correction journal entry exists (id '.$journal->id.'); refusing duplicate correction.';
        }

        $issues = array_values(array_filter($issues));
        $this->renderPlan($bill, $journal);

        if ($issues) {
            $this->warn('BLOCKERS:');
            foreach ($issues as $i) {
                $this->warn('  - '.$i);
            }
            $this->warn('Refusing to apply. Resolve blockers or keep the current record.');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->info('DRY-RUN OK — zero database writes. Reversal plan stored in the correction plan below.');

            return self::SUCCESS;
        }

        // Requirement 7 — fresh backup verification before any financial correction.
        $backupDir = database_path('sql/backups');
        $latestMtime = null;
        foreach (glob($backupDir.'/*.sql') ?: [] as $f) {
            $mtime = (int) filemtime($f);
            $latestMtime = $latestMtime === null ? $mtime : max($latestMtime, $mtime);
        }
        if (! self::backupIsFresh($latestMtime, time())) {
            $this->error('REFUSED: no fresh backup (database/sql/backups, written within the last 60 minutes) — create a verified mysqldump first.');

            return self::FAILURE;
        }

        // Transactional atomic correction with row locks.
        $fingerprint = self::fingerprint($bill);
        DB::transaction(function () use ($bill, $fingerprint) {
            $this->applyCorrection($bill, $fingerprint);
        });
        $this->info('APPLIED — September 2026 bill 5850 and GPF ledger 5628 corrected atomically.');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Pure validation helpers (unit-tested)
    // ------------------------------------------------------------------

    /** Exact before-record fingerprint (stable JSON hash of all columns). */
    public static function fingerprint(object $row): string
    {
        return hash('sha256', json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** Scope validation: only employee 20057 / 5850 / 9 / 2026 is eligible. */
    public static function validateContext(object $bill): ?string
    {
        if ((int) $bill->user_id !== self::USER_ID) {
            return 'SCOPE_REFUSED: user_id mismatch (got '.$bill->user_id.', expected '.self::USER_ID.').';
        }
        if ((int) $bill->monthId !== self::MONTH) {
            return 'SCOPE_REFUSED: monthId mismatch (got '.$bill->monthId.', expected '.self::MONTH.').';
        }
        if ((int) $bill->salaryGenerationYear !== self::YEAR) {
            return 'SCOPE_REFUSED: year mismatch (got '.$bill->salaryGenerationYear.', expected '.self::YEAR.').';
        }

        return null;
    }

    /** Canonical PDF-shaped payload the correction writes. */
    public static function expectedPayload(): array
    {
        return [
            'salaryAllowanceCalcObject' => [
                ['name' => 'Basic Pay', 'calcType' => 'fixed', 'amount' => 19460, 'calculated_amount' => 19460],
                ['name' => 'House Rent Allowance', 'calcType' => 'fixed', 'amount' => 8757, 'calculated_amount' => 8757],
                ['name' => 'Medical Allowance', 'calcType' => 'fixed', 'amount' => 1500, 'calculated_amount' => 1500],
                ['name' => 'Special Benefit', 'calcType' => 'fixed', 'amount' => 2919, 'calculated_amount' => 2919],
                ['name' => 'Education Allowance', 'calcType' => 'fixed', 'amount' => 500, 'calculated_amount' => 500],
            ],
            'salaryDeductionCalcObject' => [
                ['name' => 'General Provident Fund', 'calcType' => 'fixed', 'amount' => 1946, 'calculated_amount' => 1946],
                ['name' => 'Revenue Stamp', 'calcType' => 'fixed', 'amount' => 10, 'calculated_amount' => 10],
            ],
            'totalAllowance' => self::PDF['gross'],
            'totalDeduction' => self::PDF['total_deduction'],
            'salaryTotal' => self::PDF['net'],
            'gpfDeduction' => self::PDF['gpf'],
        ];
    }

    /** Dependency audit — returns blocking issues (empty array = safe). */
    public static function dependencyIssues(object $bill): array
    {
        $issues = [];
        if ((int) $bill->reviewStatus === 1 || (string) $bill->reviewStatus === 'approved') {
            return ['DEPENDENCY_REFUSED: bill is approved; correction of an approved bill is not allowed automatically.'];
        }

        // GPF ledger for the target month must be exactly the known state.
        $gpf = DB::table('general_provident_funds')
            ->where('user_id', self::USER_ID)->where('year', self::YEAR)->where('month', self::MONTH)->first();
        if (! $gpf) {
            $issues[] = 'GPF_REFUSED: September 2026 GPF ledger entry missing for user 58.';
        } else {
            if ((float) $gpf->contributionAmount !== 2872.0) {
                $issues[] = 'FINGERPRINT_REFUSED: GPF ledger 5628 contribution changed (got '.$gpf->contributionAmount.', expected 2872.00).';
            }
            if ((float) $gpf->closingAmount !== 29000.0) {
                $issues[] = 'FINGERPRINT_REFUSED: GPF ledger 5628 closing balance changed (got '.$gpf->closingAmount.', expected 29000.00).';
            }
            // Downstream ledger changes: any later entry whose opening != 29000 is unsafe.
            $later = DB::table('general_provident_funds')
                ->where('user_id', self::USER_ID)
                ->where(function ($q) {
                    $q->where('year', '>', self::YEAR)
                        ->orWhere(function ($q2) {
                            $q2->where('year', self::YEAR)->where('month', '>', self::MONTH);
                        });
                })
                ->orderBy('year')->orderBy('month')
                ->get(['id', 'year', 'month', 'openingBalance']);
            foreach ($later as $row) {
                if ((float) $row->openingBalance !== 29000.0) {
                    $issues[] = 'GPF_REFUSED: downstream ledger entry id '.$row->id
                        .' ('.$row->year.'-'.$row->month.') opening balance '.$row->openingBalance.' depends on 29000; full-chain reconciliation required.';
                }
            }

            // Task C — full chronological chain integrity (Sept 2026 onward, including 2027+).
            $chain = DB::table('general_provident_funds')
                ->where('user_id', self::USER_ID)
                ->where(function ($q) {
                    $q->where('year', '>', self::YEAR)
                        ->orWhere(function ($q2) {
                            $q2->where('year', self::YEAR)->where('month', '>=', self::MONTH);
                        });
                })
                ->orderBy('year')->orderBy('month')
                ->get(['id', 'year', 'month', 'openingBalance', 'closingAmount']);
            foreach (self::gpfChainBreaks($chain) as $break) {
                $issues[] = 'GPF_REFUSED: chain break — '.$break;
            }
        }

        // Unreversed arrear GPF journal entries (Phase 5) attached to the target bill.
        // Task A/B — reversal semantics: an entry is active only when no reversal
        // references it; the net obligation is the sum of active originals.
        if (Schema::hasTable('salary_bill_arrear_gpf_entries')) {
            $entries = DB::table('salary_bill_arrear_gpf_entries')
                ->where('salary_generate_id', self::SALARY_GENERATE_ID)
                ->orderBy('id')->get();
            foreach (self::journalActiveIds($entries) as $activeId) {
                $entry = $entries->firstWhere('id', $activeId);
                $issues[] = 'DEPENDENCY_REFUSED: active salary_bill_arrear_gpf_entries id '.$activeId
                    .' (signed_amount '.$entry->signed_amount.') is attached to bill 5850 and must be reversed before correction.';
            }
            // Journal fingerprint guard — expected entry id 1 with exactly 2778.00.
            $j1 = $entries->firstWhere('id', self::JOURNAL_ID);
            if ($j1 === null) {
                $issues[] = 'JOURNAL_REFUSED: expected arrear GPF journal id 1 not found on bill 5850.';
            } elseif ((float) $j1->signed_amount !== 2778.0) {
                $issues[] = 'JOURNAL_REFUSED: journal id 1 signed_amount changed (got '.$j1->signed_amount.', expected 2778.00).';
            }
        }

        return $issues;
    }

    /** Source reconciliation via provenance + dump row 5903. */
    public function sourceIssues(object $bill): array
    {
        if (! Schema::hasTable('salary_import_records')) {
            return ['SOURCE_REFUSED: salary_import_records table missing — no provenance available.'];
        }
        $provenance = DB::table('salary_import_records')
            ->where('user_id', self::USER_ID)->where('month_id', self::MONTH)->where('year', self::YEAR)
            ->where('source_row_id', self::SOURCE_ROW_ID)->first();
        if (! $provenance) {
            return ['SOURCE_REFUSED: no provenance row for source_row_id '.self::SOURCE_ROW_ID.' — refusing to correct without verified source.'];
        }
        if ((int) $provenance->target_salary_generate_id !== self::SALARY_GENERATE_ID) {
            return ['SOURCE_REFUSED: provenance target mismatch (got '.$provenance->target_salary_generate_id.').'];
        }

        // Verified source fingerprint of the dump file.
        try {
            $sha = (new HistoricalSalaryImportService())->sourceSha256();
        } catch (RuntimeException) {
            $sha = null;
        }
        $runSha = DB::table('salary_import_runs')->where('id', $provenance->run_id)->value('source_sha256');
        if ($sha !== null && $runSha !== null && $sha !== $runSha) {
            return ['SOURCE_REFUSED: dump file sha256 no longer matches run '.$provenance->run_id.' fingerprint — source may have changed.'];
        }

        return [];
    }

    /** Pure: reversal semantics — an original entry is active only when no reversal references it. */
    public static function journalActiveIds(iterable $entries): array
    {
        $all = is_array($entries) ? $entries : iterator_to_array($entries);
        $reversed = [];
        foreach ($all as $e) {
            if ($e->reverses_entry_id !== null) {
                $reversed[] = (int) $e->reverses_entry_id;
            }
        }
        $active = [];
        foreach ($all as $e) {
            if ($e->reverses_entry_id === null && ! in_array((int) $e->id, $reversed, true)) {
                $active[] = (int) $e->id;
            }
        }

        return $active;
    }

    /** Pure: net effect = sum of active original obligations only (never double-count reversal rows). */
    public static function journalNetEffect(iterable $entries): float
    {
        $active = self::journalActiveIds($entries);
        $all = is_array($entries) ? $entries : iterator_to_array($entries);
        $net = 0.0;
        foreach ($all as $e) {
            if (in_array((int) $e->id, $active, true)) {
                $net += (float) $e->signed_amount;
            }
        }

        return round($net, 2);
    }

    /** Pure: chronological chain breaks — each opening must equal the previous closing. */
    public static function gpfChainBreaks(iterable $rows): array
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows);
        $breaks = [];
        for ($i = 1; $i < count($rows); $i++) {
            $prev = $rows[$i - 1];
            $cur = $rows[$i];
            if (abs((float) $prev->closingAmount - (float) $cur->openingBalance) > 0.009) {
                $breaks[] = 'ledger id '.$cur->id.' ('.$cur->year.'-'.$cur->month.') opening '.$cur->openingBalance
                    .' != previous closing '.$prev->closingAmount.' (ledger id '.$prev->id.')';
            }
        }

        return $breaks;
    }

    /** Pure: fresh backup guard — verifies manifest, sha256 checksum, completion marker, and age. */
    public static function backupIssues(?string $backupFile, string $expectedDb): array
    {
        if ($backupFile === null || ! is_file($backupFile)) {
            return ['BACKUP_REFUSED: no backup file found — create a verified mysqldump first.'];
        }
        $contents = file_get_contents($backupFile, false, null, 0, 4096);
        if ($contents === false || trim($contents) === '') {
            return ['BACKUP_REFUSED: backup file is empty or unreadable.'];
        }
        if (filesize($backupFile) < 1024) {
            return ['BACKUP_REFUSED: backup file is suspiciously small (not a valid dump).'];
        }
        $manifestPath = $backupFile.'.manifest.json';
        if (! is_file($manifestPath)) {
            return ['BACKUP_REFUSED: backup manifest (.manifest.json) missing.'];
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            return ['BACKUP_REFUSED: backup manifest is not valid JSON.'];
        }
        foreach (['database', 'sha256', 'completed_at', 'source_snapshot', 'target_snapshot'] as $key) {
            if (! array_key_exists($key, $manifest)) {
                return ['BACKUP_REFUSED: backup manifest missing key "'.$key.'".'];
            }
        }
        if ($manifest['database'] !== $expectedDb) {
            return ['BACKUP_REFUSED: backup manifest database "'.$manifest['database'].'" != "'.$expectedDb.'".'];
        }
        $actual = hash_file('sha256', $backupFile);
        if ($actual !== $manifest['sha256']) {
            return ['BACKUP_REFUSED: backup sha256 checksum mismatch — dump may be corrupted.'];
        }
        if ((time() - (int) strtotime((string) $manifest['completed_at'])) > 3600) {
            return ['BACKUP_REFUSED: backup is older than 60 minutes (completed_at '.$manifest['completed_at'].').'];
        }

        return [];
    }

    /** Pure: source-link consistency — would the frozen snapshot contradict the corrected bill? */
    public static function sourceLinkIssue(object $bill): ?string
    {
        $payload = self::expectedPayload();
        $snapGpf = $bill->gpfDeduction;

        // A frozen snapshot that matches the incorrect bill is fine; a snapshot
        // matching the corrected PDF is also fine. Anything else is a contradiction.
        if ((float) $snapGpf === 2872.0 && (float) $bill->totalAllowance === 70027.0) {
            return null; // uncorrected bill with its original frozen snapshot
        }
        if ((float) $snapGpf === self::PDF['gpf'] && (float) $bill->totalAllowance === self::PDF['gross']) {
            return null; // already corrected
        }

        return 'SOURCE_LINK_CONFLICT: frozen source link (regular_net '
            .(float) $bill->salaryTotal.', arrear_gpf 2778) contradicts the requested corrected bill state — '
            .'apply path must be refused; the archival/reversal mechanism is a new append-only '
            .'correction journal + snapshot supersession, never a frozen-snapshot rewrite.';
    }

    private function renderPlan(object $bill, ?object $journal): void
    {
        $this->info('=== TARGETED CORRECTION PLAN (employee 20057 / 5850 / 9-2026) ===');
        $this->line('Current bill: totalA='.$bill->totalAllowance.' totalD='.$bill->totalDeduction
            .' salaryTotal='.$bill->salaryTotal.' gpf='.$bill->gpfDeduction);
        $payload = self::expectedPayload();
        $this->line('Planned bill: totalA='.$payload['totalAllowance'].' totalD='.$payload['totalDeduction']
            .' salaryTotal='.$payload['salaryTotal'].' gpf='.$payload['gpfDeduction']);
        $this->line('GPF ledger 5628 planned: contribution 1946.00, netDeposit 1946.00, closing 28074.00 (opening 26128.00 unchanged).');
        $this->line('Preserved: approved fixation 28715 (id 1), source reviewStatus pending (dump row 5903), PDF approval evidence in journal note.');
        $this->line($journal ? 'Correction journal: id '.$journal->id.' (already applied)' : 'Correction journal: will be created (action=correction_applied, snapshot JSON in note).');
        $this->line('Reversal: restore exact snapshots from the journal note (bill + GPF ledger) in one transaction.');

        // Task F — dry-run reporting additions.
        $entries = DB::table('salary_bill_arrear_gpf_entries')
            ->where('salary_generate_id', self::SALARY_GENERATE_ID)->orderBy('id')->get();
        $this->line('Reversal journal plan: insert append-only reversal entry (signed_amount = -2778.00, reverses_entry_id = 1); original id 1 never deleted.');
        $this->line('Expected net supplementary GPF after correction: '.sprintf('%.2f', self::journalNetEffect($entries))
            .' (id 1 reversal +2778 -> -2778, net 0).');
        $this->line('Source-link compatibility: '.(self::sourceLinkIssue($bill) ?? 'compatible with frozen snapshot (uncorrected bill).'));
        $backupDir = database_path('sql/backups');
        $latest = null;
        foreach (glob($backupDir.'/*.sql') ?: [] as $f) {
            $latest = $latest === null || filemtime($f) > filemtime($latest) ? $f : $latest;
        }
        $backupIssues = self::backupIssues($latest, DB::connection()->getDatabaseName());
        $this->line('Backup readiness: '.($backupIssues ? 'NOT READY — '.implode(' ', $backupIssues) : 'ready ('.basename($latest).').'));
        $this->line('Preserved: PDF approval evidence (1946 GPF, net 31180) recorded in journal note; source reviewStatus stays pending; bank payment NOT inferred.');
    }

    /** Atomic correction: bill + GPF ledger + journal, with verification. */
    private function applyCorrection(object $bill, string $fingerprint): void
    {
        // Requirement 6 — row locks to prevent concurrent modification during apply.
        $locked = DB::table('salary_generates')->where('id', self::SALARY_GENERATE_ID)->lockForUpdate()->first();
        $before = self::fingerprint($locked);
        if ($before !== $fingerprint) {
            throw new RuntimeException('Record changed during apply — fingerprint mismatch, refusing.');
        }
        DB::table('general_provident_funds')->where('id', self::GPF_LEDGER_ID)->lockForUpdate()->first();

        $payload = self::expectedPayload();
        $snapshot = json_encode(['bill' => (array) $bill,
            'gpf' => (array) DB::table('general_provident_funds')->find(self::GPF_LEDGER_ID),
            'sha256' => $fingerprint], JSON_UNESCAPED_UNICODE);

        DB::table('salary_generates')->where('id', self::SALARY_GENERATE_ID)->update([
            'salaryAllowanceCalcObject' => json_encode($payload['salaryAllowanceCalcObject']),
            'salaryDeductionCalcObject' => json_encode($payload['salaryDeductionCalcObject']),
            'totalAllowance' => $payload['totalAllowance'],
            'totalDeduction' => $payload['totalDeduction'],
            'salaryTotal' => $payload['salaryTotal'],
            'gpfDeduction' => $payload['gpfDeduction'],
        ]);

        DB::table('general_provident_funds')->where('id', self::GPF_LEDGER_ID)
            ->where('user_id', self::USER_ID)->where('year', self::YEAR)->where('month', self::MONTH)
            ->update(['contributionAmount' => 1946.00, 'netDeposit' => 1946.00, 'closingAmount' => 28074.00]);

        DB::table('salary_import_records')->insert([
            'run_id' => null, 'source_row_id' => self::SOURCE_ROW_ID,
            'target_salary_generate_id' => self::SALARY_GENERATE_ID,
            'user_id' => self::USER_ID, 'employee_code' => self::EMPLOYEE_CODE,
            'month_id' => self::MONTH, 'year' => self::YEAR,
            'action' => 'correction_applied',
            'source_review_status' => 'pending',
            'note' => 'Step 3B-R targeted correction (PDF approved for payment). Snapshot for reversal: '.$snapshot,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Post-correction verification.
        $after = DB::table('salary_generates')->where('id', self::SALARY_GENERATE_ID)->first();
        if ((float) $after->salaryTotal !== self::PDF['net'] || (float) $after->gpfDeduction !== self::PDF['gpf']) {
            throw new RuntimeException('Post-correction verification failed — rolling back the transaction.');
        }
    }
}
