<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Recovery of legacy September DRAFT calculations only. Never marks paid,
 * never approves, and never inserts GPF or arrear ledger entries.
 * Explicit opt-in and isolated staging database are mandatory.
 */
class Step10SeptemberDraftRecoverySeeder extends Seeder
{
    public function run(): void
    {
        if (getenv('KAU_STEP10_ALLOW_SEPTEMBER_DRAFT_SEED') !== '1') {
            throw new RuntimeException('Set KAU_STEP10_ALLOW_SEPTEMBER_DRAFT_SEED=1 after taking a staging backup.');
        }

        $database = (string) DB::connection()->getDatabaseName();
        if (!preg_match('/(staging|_test)/i', $database) || !app()->environment(['local', 'staging', 'testing'])) {
            throw new RuntimeException('Step 10 recovery is staging/test only. Current database/application environment rejected.');
        }

        foreach (['salary_generates', 'employee_infos', 'users'] as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException('Missing staging table '.$table);
            }
        }

        $file = database_path('seeders/data/step10_september_draft_recovery.json');
        $payload = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $items = $payload['rows'] ?? [];
        if (!is_array($items) || count($items) !== 410) {
            throw new RuntimeException('Unexpected September recovery payload size.');
        }

        $inserted = 0;
        $skipped = 0;
        DB::transaction(function () use ($items, &$inserted, &$skipped): void {
            foreach ($items as $item) {
                $row = $item['row'] ?? [];
                $userId = (int) ($row['user_id'] ?? 0);
                $code = (int) ($row['employeeCode'] ?? 0);
                $augustId = (int) ($item['august_reference_id'] ?? 0);
                if ($userId < 1 || $code < 1 || (int) ($row['salaryGenerationYear'] ?? 0) !== 2026
                    || (string) ($row['monthId'] ?? '') !== '9' || ($row['reviewStatus'] ?? null) !== 'pending') {
                    throw new RuntimeException('Untrusted September recovery payload row.');
                }
                if (!DB::table('employee_infos')->where('user_id', $userId)->where('employeeCode', $code)->exists()) {
                    throw new RuntimeException('Employee identity mismatch for code '.$code);
                }
                $august = DB::table('salary_generates')->where('id', $augustId)->where('user_id', $userId)
                    ->where('employeeCode', (string) $code)->where('salaryGenerationYear', 2026)
                    ->where('monthId', '8')->where('reviewStatus', 'approved')->first();
                if (!$august) {
                    throw new RuntimeException('Approved August source changed/missing for employee '.$code);
                }
                foreach (['totalAllowance', 'totalDeduction', 'salaryTotal', 'taxDeduction', 'gpfDeduction'] as $column) {
                    if (!is_numeric($row[$column] ?? null) ||
                        (int) round((float) $row[$column] * 100) !== (int) round((float) $august->{$column} * 100)) {
                        throw new RuntimeException('August financial verification mismatch: '.$code.' / '.$column);
                    }
                }
                if (abs(((float) $row['totalAllowance'] - (float) $row['totalDeduction']) - (float) $row['salaryTotal']) > .009) {
                    throw new RuntimeException('September arithmetic does not reconcile for '.$code);
                }
                foreach (['salaryAllowanceCalcObject', 'salaryDeductionCalcObject'] as $column) {
                    $candidate = json_decode((string) ($row[$column] ?? ''), true, 512, JSON_THROW_ON_ERROR);
                    $approved = json_decode((string) ($august->{$column} ?? ''), true, 512, JSON_THROW_ON_ERROR);
                    if ($candidate != $approved) {
                        throw new RuntimeException('Historical component JSON differs from approved August: '.$code);
                    }
                }
                $existing = DB::table('salary_generates')->where('user_id', $userId)
                    ->where('salaryGenerationYear', 2026)->where('monthId', '9')->exists();
                if ($existing) {
                    $skipped++;
                    continue;
                }
                DB::table('salary_generates')->insert($row);
                $inserted++;
            }
        }, 3);
        $this->command?->info("Recovered pending September salary drafts: inserted={$inserted}, existing skipped={$skipped}. No financial posting or GPF writes.");
    }
}
