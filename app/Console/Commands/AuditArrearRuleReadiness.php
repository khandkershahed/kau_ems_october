<?php

namespace App\Console\Commands;

use App\Support\SalaryArrearRuleReadiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** No --apply option, no update statements, and no payroll posting. */
final class AuditArrearRuleReadiness extends Command
{
    protected $signature = 'kau:audit-arrear-rule-readiness {--json : Machine-readable summary}';
    protected $description = 'Read-only inspection of current published arrear formulas, dates and source readiness.';

    public function handle(): int
    {
        if (!Schema::hasTable('salary_arrear_rules')) {
            $this->error('salary_arrear_rules does not exist. No changes.');
            return self::FAILURE;
        }
        $records = DB::table('salary_arrear_rules')->orderBy('display_order')->orderBy('id')->get();
        $results = [];
        foreach ($records as $record) {
            $row = SalaryArrearRuleReadiness::inspect($record);
            $row['id'] = $record->id;
            $row['revision'] = $record->revision ?? null;
            $row['published'] = (bool)$record->published;
            $results[] = $row;
        }
        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($results as $row) {
                $this->line($row['code'].' [ID '.$row['id'].'] '.$row['status'].': '.implode(', ', $row['issues']));
            }
        }
        $this->line('READ ONLY — no rules, salaries, GPF or arrears changed.');
        return count(array_filter($results, static fn($r) => $r['status'] === 'REVIEW_REQUIRED'))
            ? self::FAILURE : self::SUCCESS;
    }
}
