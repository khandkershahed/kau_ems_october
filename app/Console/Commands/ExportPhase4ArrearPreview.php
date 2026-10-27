<?php

namespace App\Console\Commands;

use App\Services\SalaryArrearRuleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Employee-by-month read-only audit: no salary/arrear/GPF posting. */
final class ExportPhase4ArrearPreview extends Command
{
    protected $signature = 'kau:export-phase4-arrear {--year=2026} {--month=10}';
    protected $description = 'Export per-employee published arrear rule values, UNKNOWN sources and evidence errors.';

    public function handle(SalaryArrearRuleService $service): int
    {
        $year = (int) $this->option('year'); $month = (int) $this->option('month');
        if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
            $this->error('Invalid payroll period.'); return self::FAILURE;
        }
        if (!Schema::hasTable('salary_arrear_rules')) {
            $this->error('Phase 4 migration is missing.'); return self::FAILURE;
        }
        $file = storage_path(sprintf('app/kau_phase4_arrear_preview_%04d_%02d.csv', $year, $month));
        $handle = fopen($file, 'wb');
        fputcsv($handle, ['employee_profile_id','user_id','employee_code','salary_month','rule_id','rule_title',
            'direction','status','automatic_amount','source_periods','error']);
        $count=0;
        DB::table('employee_infos')->orderBy('id')->select(['id','user_id','employeeCode'])->chunk(100, function ($profiles) use ($service,$year,$month,$handle,&$count) {
            foreach ($profiles as $employee) {
                $rules = $service->preview((int)$employee->user_id, $year, $month);
                if (!$rules) $rules = [['rule_id'=>null,'title'=>'No published arrear rules','direction'=>'-',
                    'calculated_amount'=>null,'months'=>[], 'error'=>'NO_PUBLISHED_RULES']];
                foreach ($rules as $rule) {
                    fputcsv($handle, [$employee->id,$employee->user_id,$employee->employeeCode,
                        sprintf('%04d-%02d',$year,$month),$rule['rule_id'],$rule['title'],$rule['direction'],
                        $rule['error']?'UNKNOWN':'CALCULATED', $rule['calculated_amount'],
                        implode(';',$rule['months']??[]),$rule['error']]);$count++;
                }
            }
        });
        fclose($handle);
        $this->info("Read-only audit: {$count} rows, file {$file}. Nothing posted to Salary Bill, Arrear or GPF ledgers.");
        return self::SUCCESS;
    }
}
