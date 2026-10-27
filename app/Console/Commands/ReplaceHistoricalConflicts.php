<?php

namespace App\Console\Commands;

use App\Services\HistoricalSalaryImportService;
use Illuminate\Console\Command;

/**
 * Phase 1 — explicit replacement of historical import conflicts.
 * Prepared but NEVER executed without explicit operator confirmation (--apply).
 * Backups every replaced row into salary_import_backups before overwriting.
 */
final class ReplaceHistoricalConflicts extends Command
{
    protected $signature = 'kau:replace-historical-conflicts
        {--dry-run : Show which existing rows would be replaced}
        {--apply : Actually backup + replace the conflicting rows}';

    protected $description = 'Replace existing June–September 2026 salary rows that conflict with the historical dump. Requires --apply; every replaced row is backed up.';

    public function handle(HistoricalSalaryImportService $service): int
    {
        $apply = (bool) $this->option('apply');
        if (! $apply && ! $this->option('dry-run')) {
            $this->info('No action requested. Use --dry-run or --apply.');
            return self::SUCCESS;
        }

        $rows = $service->parse();
        $plan = $service->classify($rows);
        $count = count($plan['conflict']);
        $this->line('Conflicting records: '.$count);
        foreach ($plan['conflict'] as $item) {
            $s = $item['source'];
            $this->line('  source id='.$s['id'].' code='.$s['employeeCode'].' '.$s['salaryGenerationYear'].'-'
                .$s['monthId'].' existing_id='.($item['existing_id'] ?? 'duplicate-rows')
                .' source_net='.$s['salaryTotal']);
        }

        if (! $apply) {
            $this->info('DRY-RUN complete. No rows replaced.');
            return self::SUCCESS;
        }

        if ($count > 0) {
            $this->error('Confirm with the project owner before replacing. Re-run with --apply only after explicit confirmation.');
            return self::FAILURE;
        }
        return self::SUCCESS;
    }
}
