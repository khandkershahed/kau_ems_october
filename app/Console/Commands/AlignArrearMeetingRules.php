<?php

namespace App\Console\Commands;

use App\Support\SalaryArrearTemplateCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Explicit, audited migration of unchanged default formulas to the HR meeting method. */
final class AlignArrearMeetingRules extends Command
{
    protected $signature = 'kau:align-arrear-meeting-rules {--apply : Update only unchanged original default formulas and append audit events}';
    protected $description = 'Preview or apply meeting-style June/July/October arrear formulas without overwriting HR edits';

    public function handle(): int
    {
        if (!Schema::hasTable('salary_arrear_rules') || !Schema::hasTable('salary_rule_events')) {
            $this->error('Phase 4 arrear tables are missing.');
            return self::FAILURE;
        }
        $old = [
            'BASIC_ARREAR_GROSS' => ['SUM_MONTHS(REVISED_BASIC - JUNE_BASIC)'],
            'BASIC_INCREMENT_ADJUSTMENT' => [
                'SUM_MONTHS(PAID_BASIC - JUNE_BASIC)', 'SUM_MONTHS(JULY_BASIC - JUNE_BASIC)',
                'SUM_MONTHS(JULY_INCREMENT - JUNE_INCREMENT) * 3',
                'SUM_MONTHS(JULY_INCREMENT - JUNE_INCREMENT)*3'],
            'SPECIAL_BENEFIT_RECOVERY' => [
                'SUM_MONTHS(PAID_SPECIAL_BENEFIT)', 'SUM_MONTHS(JULY_SPECIAL_BENEFIT)'],
            'HOUSE_RENT_RECOVERY' => [
                'SUM_MONTHS(MAX(0, PAID_HOUSE_RENT - JUNE_HOUSE_RENT))',
                'SUM_MONTHS(MAX(0, OCTOBER_HOUSE_RENT - JUNE_HOUSE_RENT))'],
            'ADDITIONAL_GPF' => [
                'SUM_MONTHS(MAX(0, REVISED_GPF - PAID_GPF))',
                'SUM_MONTHS(MAX(0, OCTOBER_GPF - JULY_GPF))'],
        ];
        $definitions = collect(SalaryArrearTemplateCatalog::definitions())->keyBy('code');
        $rows = DB::table('salary_arrear_rules')->whereIn('code', array_keys($old))->get();
        $actions = [];
        foreach ($definitions as $code => $definition) {
            $matches = $rows->where('code', $code);
            if ($matches->count() !== 1) {
                $actions[] = [$code, 'REVIEW_MISSING_OR_DUPLICATE']; continue;
            }
            $row = $matches->first();
            if ((string)$row->formula === $definition['formula']) $action = 'ALREADY_ALIGNED';
            elseif (in_array((string)$row->formula, $old[$code], true) && $row->mode === 'formula' && !$row->archived) $action = 'UPDATE_DEFAULT';
            else $action = 'PRESERVE_HR_CUSTOMIZATION';
            $actions[] = [$code, $action];
        }
        $this->table(['Code','Action'], $actions);
        if (!$this->option('apply')) {
            $this->warn('READ ONLY. Backup and test in isolated staging before --apply. Published rules remain published.');
            return self::SUCCESS;
        }
        $updated = 0;
        DB::transaction(function () use (&$updated, $old, $definitions): void {
            foreach ($definitions as $code => $definition) {
                $matches = DB::table('salary_arrear_rules')->where('code', $code)->lockForUpdate()->get();
                if ($matches->count() !== 1) continue;
                $current = $matches->first();
                if ($current->archived || $current->mode !== 'formula' || !in_array($current->formula, $old[$code], true)
                    || $current->formula === $definition['formula']) continue;
                $after = ['formula' => $definition['formula'], 'revision' => $current->revision + 1, 'updated_at' => now()];
                DB::table('salary_arrear_rules')->where('id', $current->id)->update($after);
                DB::table('salary_rule_events')->insert([
                    'rule_kind' => 'arrear', 'rule_id' => $current->id,
                    'action' => 'meeting_formula_revision',
                    'before_value' => json_encode($current, JSON_UNESCAPED_UNICODE),
                    'after_value' => json_encode($after, JSON_UNESCAPED_UNICODE),
                    'actor_user_id' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                ++$updated;
            }
        }, 3);
        $this->info("Updated {$updated} unchanged default rule(s). No salary bill or payment changed.");
        return self::SUCCESS;
    }
}
