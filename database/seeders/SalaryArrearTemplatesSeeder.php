<?php

namespace Database\Seeders;

use App\Services\SalaryArrearFormulaEngine;
use App\Support\SalaryArrearTemplateCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * EXPLICIT, repeat-safe template seeder. Not part of DatabaseSeeder (which
 * inserts demo entities). Existing rows, publications and audit events stay intact.
 */
final class SalaryArrearTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['salary_arrear_rules', 'salary_rule_events'] as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException('Run Phase 4 migrations first: missing '.$table);
            }
        }

        // MySQL advisory lock makes concurrent invocations safe even if the
        // catalogue is empty (row-level gap locking alone is not sufficient).
        $lockName = 'kau_arrear_template_seed_v1';
        $locked = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
        if ((int) ($locked->acquired ?? 0) !== 1) {
            throw new RuntimeException('Arrear seeding lock unavailable; nothing changed.');
        }
        try {
        DB::transaction(function (): void {
            // Recheck all codes under transaction after obtaining the lock.
            $all = DB::table('salary_arrear_rules')->lockForUpdate()->get(['id','code']);
            $codes = $all->pluck('code')->filter()->all();
            $plan = SalaryArrearTemplateCatalog::plan($codes);
            foreach (SalaryArrearTemplateCatalog::definitions() as $index => $definition) {
                if ($plan[$index]['action'] !== 'INSERT_UNPUBLISHED') {
                    continue;
                }
                // Never publish by seeding, even if a month has evidence.
                app(SalaryArrearFormulaEngine::class)->validate($definition['formula']);
                $row = [
                    'code' => $definition['code'], 'title' => $definition['title'],
                    'direction' => $definition['direction'], 'mode' => 'formula',
                    'amount' => null, 'formula' => $definition['formula'],
                    'start_month' => '2026-07-01', 'end_month' => '2026-09-01',
                    'selected_months' => json_encode([]), 'recurring' => false,
                    'scope' => 'all', 'employee_ids' => json_encode([]),
                    'published' => false, 'archived' => false,
                    'display_order' => $index + 1, 'revision' => 1,
                    'updated_by' => null, 'created_at' => now(), 'updated_at' => now(),
                ];
                $id = DB::table('salary_arrear_rules')->insertGetId($row);
                DB::table('salary_rule_events')->insert([
                    'rule_kind' => 'arrear', 'rule_id' => $id,
                    'action' => 'template_seed_created',
                    'before_value' => null,
                    'after_value' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'actor_user_id' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }, 3);
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }
}
