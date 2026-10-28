<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Evidence-only report. No salary calculation, financial posting or workflow writes. */
final class SalaryOctober18ReadinessAudit extends Command
{
    protected $signature = 'kau:audit-october-18 {--json : Print machine-readable per-employee evidence}';
    protected $description = 'Read-only readiness and financial evidence inventory for the 18 October 2026 employees';

    private const CODES = ['1003','10046','10108','20029','20033','20038','20049','20057','20082','20083','30081','30098','30133','30254','40033','40140','40158','40274'];

    public function handle(): int
    {
        foreach (['employee_infos','salary_monthly_workflows','salary_calculation_versions','salary_generates'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error('Required source table missing: '.$table);
                return self::FAILURE;
            }
        }
        $rows = [];
        foreach (self::CODES as $code) {
            try {
                $profiles = DB::table('employee_infos')->where('employeeCode', $code)->limit(3)->pluck('user_id');
                $uid = $profiles->count() === 1 ? (int)$profiles->first() : null;
                $duplicateUser = $uid ? DB::table('employee_infos')->where('user_id',$uid)->count() !== 1 : null;
                $workflow = $uid ? DB::table('salary_monthly_workflows')->where('user_id',$uid)->where('salary_year',2026)->where('salary_month',10)->first() : null;
                $versions = $uid ? DB::table('salary_calculation_versions')->where('user_id',$uid)->where('salary_year',2026)->where('salary_month',10)->where('status','approved')->count() : 0;
                $posted = $uid ? DB::table('salary_generates')->where('user_id',$uid)->where('salaryGenerationYear',2026)->where('monthId',10)->count() : 0;
                $history = [];
                foreach ([6,7,8,9] as $month) {
                    $history[sprintf('2026-%02d',$month)] = $uid ? DB::table('salary_generates')->where('user_id',$uid)->where('salaryGenerationYear',2026)->where('monthId',$month)->count() : 0;
                }
                $rows[] = ['code'=>$code,'user_id'=>$uid,'profile_count'=>$profiles->count(),'user_has_duplicate_profile'=>$duplicateUser,
                    'workflow'=>$workflow?->workflow_status ?? 'NOT_INITIALIZED','approved_versions'=>$versions,'october_posted_bills'=>$posted,
                    'historical_bill_counts'=>$history,
                    'issue'=>$profiles->count() !== 1 ? 'EMPLOYEE_CODE_NOT_UNIQUE_OR_MISSING' : ($duplicateUser ? 'DUPLICATE_USER_PROFILE' : ($posted > 1 ? 'DUPLICATE_OCTOBER_BILLS' : ($workflow ? null : 'ROSTER_NOT_INITIALIZED')))];
            } catch (Throwable $e) {
                $rows[] = ['code'=>$code,'issue'=>'DATABASE_READ_ERROR','detail'=>$e->getMessage()];
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode(['period'=>'2026-10','read_only'=>true,'employees'=>$rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Employee','User','Profiles','Workflow','Versions','Oct bills','Issue'],array_map(static fn($r)=>[
                $r['code'], $r['user_id']??'-',$r['profile_count']??'-',$r['workflow']??'-',
                $r['approved_versions']??'-',$r['october_posted_bills']??'-',$r['issue']??'NONE'], $rows));
            $this->info('Read-only: history counts in --json. No salary amounts were inferred.');
        }
        return self::SUCCESS;
    }
}
