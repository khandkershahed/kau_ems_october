<?php

namespace App\Console\Commands;

use App\Support\SalaryArrearTemplateCatalog;
use Database\Seeders\SalaryArrearTemplatesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Preview is always non-writing; --apply only inserts missing, unpublished templates. */
final class SeedSalaryArrearTemplates extends Command
{
    protected $signature = 'kau:seed-arrear-templates {--apply : Insert missing UNPUBLISHED rule templates; never modify existing ones}';
    protected $description = 'Preview or idempotently insert missing Arrear templates (never approve or publish)';

    public function handle(): int
    {
        if (!Schema::hasTable('salary_arrear_rules') || !Schema::hasTable('salary_rule_events')) {
            $this->error('Missing Phase 4 financial migrations; no writes performed.');
            return self::FAILURE;
        }
        try {
            $plan = SalaryArrearTemplateCatalog::plan(DB::table('salary_arrear_rules')->pluck('code')->filter()->all());
        } catch (\DomainException $e) {
            $this->error($e->getMessage()); return self::FAILURE;
        }
        $this->table(['Code','Title','Action'],array_map(fn ($r)=>array_values($r),$plan));
        $missing=count(array_filter($plan, fn($r)=>$r['action']==='INSERT_UNPUBLISHED'));
        if (!$this->option('apply')) {
            $this->warn('READ ONLY. '.$missing.' missing template(s). Use --apply after staging backup to insert missing, UNPUBLISHED rows.');
            return self::SUCCESS;
        }
        if ($missing===0) {
            $this->info('Nothing to insert. All existing values and publish statuses preserved.');
            return self::SUCCESS;
        }
        // Explicitly avoid creating or modifying records on the live server
        // before an operator verifies the preview. Only missing definitions are inserted.
        $this->call('db:seed',['--class'=>SalaryArrearTemplatesSeeder::class,'--no-interaction'=>true]);
        $this->info('Inserted missing templates as UNPUBLISHED; no Salary Bills, GPF or account data changed.');
        return self::SUCCESS;
    }
}
