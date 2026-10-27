<?php

namespace App\Console\Commands;

use App\Support\SalaryArrearTemplateCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use DomainException;

/**
 * Explicitly authorized correction of the *five known published* July-Sep
 * arrear rule revisions, with before/after event history. Never auto-run.
 */
final class ReviewOctoberArrearRules extends Command
{
    protected $signature = 'kau:review-october-arrear-rules
        {--apply : Persist only after HR has reviewed dry-run output}
        {--actor= : Existing HR approver user ID, required for --apply}
        {--evidence= : Approved rule-change memo/reference, required for --apply}';
    protected $description = 'Review and optionally revise the five 2026 July-September arrear formula records. No posting.';

    private const OLD = [
        'BASIC_ARREAR_GROSS' => 'SUM_MONTHS(OCTOBER_BASIC - JUNE_BASIC) * 3',
        'BASIC_INCREMENT_ADJUSTMENT' => 'SUM_MONTHS(JULY_INCREMENT - JUNE_INCREMENT) * 3',
        'SPECIAL_BENEFIT_RECOVERY' => 'SUM_MONTHS(JULY_SPECIAL_BENEFIT) * 3',
        'HOUSE_RENT_RECOVERY' => 'SUM_MONTHS(OCTOBER_HOUSE_RENT - JUNE_HOUSE_RENT) * 3',
        'ADDITIONAL_GPF' => 'SUM_MONTHS(OCTOBER_GPF - JUNE_GPF) * 3',
    ];
    private const MONTHS = ['2026-07', '2026-08', '2026-09'];

    public function handle(): int
    {
        if (!Schema::hasTable('salary_arrear_rules') || !Schema::hasTable('salary_rule_events')) {
            $this->error('Required rule/audit tables absent. No changes.');
            return self::FAILURE;
        }
        $desired = array_column(SalaryArrearTemplateCatalog::definitions(), 'formula', 'code');
        $apply = (bool)$this->option('apply');
        $actor = (string)$this->option('actor');
        $evidence = trim((string)$this->option('evidence'));
        if ($apply && (!ctype_digit($actor) || (int)$actor < 1 || strlen($evidence) < 6 || strlen($evidence) > 500)) {
            $this->error('--apply requires --actor=<existing HR user ID> and --evidence=<approved memo>.');
            return self::FAILURE;
        }
        if ($apply && !DB::table('users')->where('id', (int)$actor)->exists()) {
            $this->error('Actor user not found. No changes.');
            return self::FAILURE;
        }
        try {
            DB::transaction(function () use ($desired, $apply, $actor, $evidence) {
                $rows = DB::table('salary_arrear_rules')
                    ->whereIn('code', array_keys(self::OLD))
                    ->lockForUpdate()->get();
                if ($rows->count() !== 5) {
                    throw new DomainException('Expected precisely five known rules; found '.$rows->count());
                }
                foreach (self::OLD as $code => $expectedOld) {
                    $matched = $rows->where('code', $code);
                    if ($matched->count() !== 1) throw new DomainException('Missing/duplicate '.$code);
                    $row = $matched->first();
                    if ((int)$row->published !== 1 || (int)$row->archived !== 0 || $row->mode !== 'formula'
                        || $row->scope !== 'all' || (int)$row->recurring !== 0) {
                        throw new DomainException($code.' differs from expected publication state.');
                    }
                    $oldMonths = json_decode((string)$row->selected_months, true);
                    $isLegacy = $row->formula === $expectedOld && $oldMonths === [];
                    $isCorrect = $row->formula === $desired[$code] && $oldMonths === self::MONTHS;
                    if (!$isLegacy && !$isCorrect) {
                        throw new DomainException($code.' was edited independently; manual reconciliation required.');
                    }
                    $this->line($code.': '.($isCorrect ? 'ALREADY_CORRECT' : 'REQUIRES_REVIEW'));
                    if (!$isCorrect) {
                        $this->line('  OLD: '.(string)$row->formula);
                        $this->line('  NEW: '.$desired[$code]);
                        $this->line('  PERIOD: '.implode(', ', self::MONTHS));
                    }
                    if (!$apply || $isCorrect) continue;
                    $before = (array)$row;
                    $after = array_replace($before, [
                        'formula'=>$desired[$code], 'selected_months'=>self::MONTHS,
                        'start_month'=>'2026-07-01', 'end_month'=>'2026-09-01',
                        'revision'=>(int)$row->revision + 1, 'updated_by'=>(int)$actor,
                    ]);
                    DB::table('salary_arrear_rules')->where('id', $row->id)
                        ->where('revision', $row->revision)
                        ->update([
                            'formula'=>$desired[$code],
                            'selected_months'=>json_encode(self::MONTHS),
                            'start_month'=>'2026-07-01', 'end_month'=>'2026-09-01',
                            'revision'=>(int)$row->revision + 1,
                            'updated_by'=>(int)$actor,'updated_at'=>now(),
                        ]);
                    DB::table('salary_rule_events')->insert([
                        'rule_kind'=>'arrear','rule_id'=>$row->id,'action'=>'revised',
                        'before_value'=>json_encode($before, JSON_INVALID_UTF8_SUBSTITUTE),
                        'after_value'=>json_encode(['rule'=>$after,'evidence_reference'=>$evidence], JSON_INVALID_UTF8_SUBSTITUTE),
                        'actor_user_id'=>(int)$actor,'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
            }, 1);
        } catch (\Throwable $e) {
            $this->error('NO RULES COMMITTED: '.$e->getMessage());
            return self::FAILURE;
        }
        $this->info($apply ? 'Rule correction committed with audit events; NO arrear payments posted.'
            : 'DRY RUN ONLY: no changes. Verify historical records and obtain HR approval before --apply.');
        return self::SUCCESS;
    }
}
