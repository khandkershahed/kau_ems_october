<?php

namespace App\Console\Commands;

use App\Services\HistoricalSalaryImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Phase 1 — import historical June–September 2026 salary bills from kau-app.sql. Never touches other tables. */
final class ImportHistoricalSalaries extends Command
{
    protected $signature = 'kau:import-historical-salaries
        {--dry-run : Classify and report without writing salary_generates}
        {--apply : Write the missing non-conflicting historical bills}
        {--rollback-token= : Rollback the rows inserted by a previous apply run id}
        {--report= : Optional CSV report path under docs/}';

    protected $description = 'Import historical June–September 2026 salary bills from kau-app.sql into codershat_kau. Dry-run by default; apply requires --apply.';

    public function handle(HistoricalSalaryImportService $service): int
    {
        if ($token = $this->option('rollback-token')) {
            $deleted = $service->rollback((int) $token);
            $this->info("Rolled back {$deleted} rows inserted by run {$token}.");
            return self::SUCCESS;
        }

        if (! $this->option('dry-run') && ! $this->option('apply')) {
            $this->info('No action requested. Use --dry-run or --apply.');
            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');

        $this->info('Verifying resolved database connection...');
        $this->line('  DB='.DB::connection()->getDatabaseName()
            .' HOST='.config('database.connections.mysql.host')
            .' PORT='.config('database.connections.mysql.port'));
        $selectDb = DB::selectOne('select database() as d')->d;
        $this->line('  SELECT DATABASE() = '.$selectDb);
        if ($selectDb !== config('database.connections.mysql.database')) {
            $this->error('Resolved connection does not match the configured database; aborting.');
            return self::FAILURE;
        }

        $sha = $service->sourceSha256();
        $this->info('Source file: '.$sha);
        $expected = '8fb1574a705e658c7f44263fa9df7b298ef590f2253c288dcc3e6461ba95fae2';
        if (strtolower($sha) !== $expected) {
            $this->warn('WARNING: source SHA256 differs from the recorded reference SHA256. Inspect before importing.');
        }

        $this->info('Parsing historical salary_generates rows for June–September 2026...');
        $rows = $service->parse();
        $this->line('  Historical rows found: '.count($rows));
        foreach ([6, 7, 8, 9] as $m) {
            $count = count(array_filter($rows, fn ($r) => (int) $r['monthId'] === $m));
            $this->line("    month {$m}: {$count}");
        }

        $this->info('Classifying against codershat_kau...');
        $plan = $service->classify($rows);
        $this->line('  insert (missing): '.count($plan['insert']));
        $this->line('  skip (identical): '.count($plan['skip']));
        $this->line('  conflict (differs, NOT overwritten): '.count($plan['conflict']));
        $this->line('  unmatched (no employeeCode match): '.count($plan['unmatched']));
        $this->line('  ambiguous (duplicate employeeCode): '.count($plan['ambiguous']));

        foreach ($plan['unmatched'] as $row) {
            $this->warn('  UNMATCHED: id='.$row['id'].' code='.$row['employeeCode'].' name='.$row['name']
                .' '.$row['salaryGenerationYear'].'-'.$row['monthId']);
        }
        foreach ($plan['ambiguous'] as $row) {
            $this->warn('  AMBIGUOUS: id='.$row['id'].' code='.$row['employeeCode'].' name='.$row['name']);
        }
        foreach ($plan['conflict'] as $item) {
            $s = $item['source'];
            $this->warn('  CONFLICT: source id='.$s['id'].' code='.$s['employeeCode'].' '
                .$s['salaryGenerationYear'].'-'.$s['monthId']
                .' existing_id='.($item['existing_id'] ?? 'duplicate-rows')
                .' source_net='.$s['salaryTotal']);
        }

        if ($this->option('report')) {
            $this->writeCsvReport($plan, base_path($this->option('report')));
        }

        if (! $apply) {
            $this->info('DRY-RUN complete. No database rows written.');
            return self::SUCCESS;
        }

        $summary = $service->run($plan, true);
        $this->info('APPLY complete. Run id: '.($summary['run_id'] ?? 'n/a'));
        $this->line('  inserted: '.$summary['insert']);
        $this->line('  skipped:  '.$summary['skip']);
        $this->line('  conflict: '.$summary['conflict']);
        $this->line('  unmatched: '.$summary['unmatched']);
        if ($summary['conflict'] > 0) {
            $this->warn('Conflicts were NOT overwritten. Review the report, then run');
            $this->warn('php artisan kau:replace-historical-conflicts --apply');
        }
        return self::SUCCESS;
    }

    private function writeCsvReport(array $plan, string $path): void
    {
        $out = fopen($path, 'w');
        fputcsv($out, ['action', 'source_row_id', 'target_salary_generate_id', 'employee_code',
            'month_id', 'year', 'user_id', 'source_review_status', 'name', 'source_net', 'note']);
        foreach ($plan['insert'] as $item) {
            fputcsv($out, ['insert', $item['source']['id'], null, $item['source']['employeeCode'],
                $item['source']['monthId'], $item['source']['salaryGenerationYear'], $item['user_id'],
                $item['source']['reviewStatus'], $item['name'], $item['source']['salaryTotal'], '']);
        }
        foreach ($plan['skip'] as $item) {
            fputcsv($out, ['skip', $item['source']['id'], $item['existing_id'], $item['source']['employeeCode'],
                $item['source']['monthId'], $item['source']['salaryGenerationYear'], $item['user_id'],
                $item['source']['reviewStatus'], $item['name'], $item['source']['salaryTotal'], 'identical']);
        }
        foreach ($plan['conflict'] as $item) {
            fputcsv($out, ['conflict', $item['source']['id'], $item['existing_id'], $item['source']['employeeCode'],
                $item['source']['monthId'], $item['source']['salaryGenerationYear'], $item['user_id'],
                $item['source']['reviewStatus'], $item['name'], $item['source']['salaryTotal'],
                $item['note'] ?? 'differs']);
        }
        foreach ($plan['unmatched'] as $row) {
            fputcsv($out, ['unmatched', $row['id'], null, $row['employeeCode'], $row['monthId'],
                $row['salaryGenerationYear'], null, $row['reviewStatus'], $row['name'], $row['salaryTotal'],
                'no matching current employee']);
        }
        foreach ($plan['ambiguous'] as $row) {
            fputcsv($out, ['ambiguous', $row['id'], null, $row['employeeCode'], $row['monthId'],
                $row['salaryGenerationYear'], null, $row['reviewStatus'], $row['name'], $row['salaryTotal'],
                'duplicate employeeCode']);
        }
        fclose($out);
        $this->info('Report written to '.$path);
    }
}
