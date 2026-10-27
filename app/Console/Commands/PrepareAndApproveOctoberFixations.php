<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SalaryFixationApprovalPolicy;
use App\Services\SalaryFixationService;
use App\Services\SalaryFixationWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Existing fixation workflow, one employee at a time. The default is a
 * read-only HR review CSV. No direct status update and no payroll posting.
 */
final class PrepareAndApproveOctoberFixations extends Command
{
    protected $signature = 'kau:prepare-october-fixations
        {--apply : Approve calculable, source-complete employee fixations}
        {--actor-id= : Existing user ID recorded in fixation workflow audit}
        {--attestation= : Exact HR attestation FIXATIONS_VERIFIED_BY_HR}
        {--allow-production : Explicitly allow a production approval after staging QA}
        {--limit=0 : 0 means every active profile user}
        {--employee-codes= : Optional comma-separated employee codes}';

    protected $description = 'Preview or approve verified October fixation calculations using the existing audited workflow';

    public function handle(SalaryFixationService $calculator, SalaryFixationWorkflowService $workflow): int
    {
        foreach (['users', 'employee_infos', 'salary_fixations', 'salary_fixation_pay_schedules',
            'salary_fixation_workflow_events', 'salary_fixation_reference_reports'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error('Required table is missing: '.$table);
                return self::FAILURE;
            }
        }
        $limitRaw = (string)$this->option('limit');
        if (!ctype_digit($limitRaw)) { $this->error('Invalid --limit.'); return self::FAILURE; }
        $limit = (int)$limitRaw;
        $apply = (bool)$this->option('apply');
        $db = (string)DB::connection()->getDatabaseName();
        $isProduction = app()->environment('production') || preg_match('/^(codershat_kau|kau-app)$/i', $db);
        $actorId = filter_var($this->option('actor-id'), FILTER_VALIDATE_INT);
        if ($apply && ($this->option('attestation') !== 'FIXATIONS_VERIFIED_BY_HR'
            || !$actorId || $actorId <= 0 || !User::whereKey($actorId)->exists()
            || ($isProduction && !$this->option('allow-production')))) {
            $this->error('Approval refused. Supply --actor-id=EXISTING_USER --attestation=FIXATIONS_VERIFIED_BY_HR; on production also --allow-production. Test staging first.');
            return self::FAILURE;
        }
        $codes = trim((string)$this->option('employee-codes'));
        $parsedCodes = [];
        if ($codes !== '') {
            $parsedCodes = array_map('trim', explode(',', $codes));
            foreach ($parsedCodes as $code) {
                if (!preg_match('/^\d{3,12}$/', $code)) {
                    $this->error('Invalid employee code list.'); return self::FAILURE;
                }
            }
        }
        $query = DB::table('employee_infos as e')->join('users as u','u.id','=','e.user_id')
            ->where('u.isActive', 1)->select('e.user_id','e.employeeCode')
            ->orderBy('e.user_id')->orderBy('e.id');
        if ($parsedCodes) $query->whereIn('e.employeeCode', $parsedCodes);
        $rows = $query->get();
        $profileCounts = DB::table('employee_infos')->select('user_id',DB::raw('COUNT(*) as n'))
            ->groupBy('user_id')->pluck('n','user_id');
        $already = [];
        $processed = 0;
        $summary = ['DRY_RUN_ELIGIBLE'=>0, 'APPROVED'=>0, 'ALREADY_APPROVED'=>0, 'NEEDS_SOURCE_REVIEW'=>0, 'NOT_CALCULABLE'=>0, 'ERROR'=>0];
        $folder = storage_path('app');
        if (!is_dir($folder) && !mkdir($folder,0775,true) && !is_dir($folder)) {
            $this->error('Cannot create audit output directory.'); return self::FAILURE;
        }
        $path = $folder.'/kau_october_fixation_approval_'.($apply?'apply':'dry_run').'.csv';
        $fp = fopen($path,'wb');
        if (!$fp) { $this->error('Cannot create CSV report.'); return self::FAILURE; }
        fputcsv($fp,['user_id','employee_code','status','grade','phase_2026_07','increment_2026',
            'increment_2027','phase_2027_07','source_hash','reference_match_status','issues']);
        try {
            foreach ($rows as $row) {
                $uid = (int)$row->user_id;
                if (isset($already[$uid])) continue;
                $already[$uid]=true;
                if ($limit !== 0 && $processed >= $limit) break;
                $processed++;
                $status = 'NEEDS_SOURCE_REVIEW'; $issues = [];
                $data = null;
                try {
                    if ((int)($profileCounts[$uid] ?? 0) !== 1) {
                        $issues[]='Duplicate employee profiles: manual identity reconciliation required.';
                    } else {
                        $employee = User::findOrFail($uid);
                        $data = $calculator->prepare($employee);
                        $isCalculable = SalaryFixationApprovalPolicy::isCalculable($data);
                        if (!$isCalculable) {
                            $status='NOT_CALCULABLE';
                            $issues[]='Invalid or incomplete fixation Basic values.';
                        } elseif (!($data['ready'] ?? false)) {
                            // Never convert warnings/blockers into fake verification.
                            $issues = array_merge($issues, (array)($data['blockers'] ?? []));
                        } else {
                            $status=$apply?'APPROVED':'DRY_RUN_ELIGIBLE';
                            if ($apply) {
                                $before = DB::table('salary_fixations')->where('user_id',$uid)
                                    ->where('status','approved')->orderByDesc('id')->first();
                                $record=$workflow->approveNow($employee,(int)$actorId);
                                if ($before && (int)$before->id === (int)$record->id) {
                                    $status='ALREADY_APPROVED';
                                }
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $status='ERROR';
                    $issues[]=$e->getMessage();
                    report($e);
                }
                $summary[$status]++;
                $calc = (array)($data['calculation'] ?? []);
                fputcsv($fp,[$uid,$row->employeeCode,$status,$data['grade']??'',
                    $calc['phase_2026_07']??'',$calc['increment_2026']??'',
                    $calc['increment_2027']??'',$calc['phase_2027_07']??'',
                    $data['source_sha256']??'',$data['reference_match_status']??'',
                    implode(' | ', array_unique($issues))]);
            }
        } finally { fclose($fp); }
        $this->info('Report: '.$path);
        foreach ($summary as $key=>$count) $this->line($key.': '.$count);
        $this->warn($apply?'No salary bills or GPF ledger entries were written by this command.':'DRY RUN: no fixation approvals, salary bills, or GPF were written.');
        return $summary['ERROR'] ? self::FAILURE : self::SUCCESS;
    }
}
