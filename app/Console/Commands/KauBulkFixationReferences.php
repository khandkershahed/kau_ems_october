<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SalaryFixationService;
use App\Services\SalaryFixationWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class KauBulkFixationReferences extends Command
{
    protected $signature = 'kau:bulk-fixation-references
        {--apply : Create and approve independently validated employee fixations}
        {--actor= : Actual HR approver user ID; required with --apply}
        {--employee= : Limit to one employee code}
        {--limit=0 : Limit number of reference employees evaluated}
        {--force-existing : NOT SUPPORTED; existing approved records never overwritten}';

    protected $description = 'Preview or approve 2026 fixation candidates matching unique reference reports and calculated source evidence';

    public function handle(SalaryFixationService $calc, SalaryFixationWorkflowService $workflow): int
    {
        if ($this->option('force-existing')) {
            $this->error('Existing approved fixation overwrite is not supported.');
            return self::FAILURE;
        }
        if (!Schema::hasTable('salary_fixation_reference_reports') || !Schema::hasTable('salary_fixations')) {
            $this->error('Required tables missing.');
            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $actor = filter_var($this->option('actor'), FILTER_VALIDATE_INT);
        if ($apply && (!$actor || !User::query()->whereKey($actor)->exists())) {
            $this->error('Supply --actor=<real authorized HR user ID>.');
            return self::FAILURE;
        }
        if ($apply && !$this->confirm('Approve ALL eligible, reference-matched records? This writes financial entitlement schedules.', false)) {
            $this->warn('Cancelled.');
            return self::SUCCESS;
        }
        $q = DB::table('salary_fixation_reference_reports')->select('employee_code')->distinct()->orderBy('employee_code');
        if ($code = $this->option('employee')) $q->where('employee_code', (string) $code);
        if ((int) $this->option('limit') > 0) $q->limit((int) $this->option('limit'));
        $rows = [];
        foreach ($q->get() as $entry) {
            $code = (string) $entry->employee_code;
            $base = ['code' => $code, 'user' => '', 'status' => '', 'reason' => ''];
            try {
                $refs = DB::table('salary_fixation_reference_reports')->where('employee_code', $code)->count();
                if ($refs !== 1) {
                    $rows[] = $base + ['status'=>'REVIEW', 'reason'=>'Ambiguous reference reports']; continue;
                }
                $info = DB::table('employee_infos')->where('employeeCode', $code)->get(['user_id']);
                if ($info->count() !== 1) {
                    $rows[] = array_merge($base, ['status'=>'REVIEW','reason'=>'Missing/duplicate employee profile']); continue;
                }
                $uid = (int) $info->first()->user_id;
                $base['user'] = $uid;
                if (DB::table('salary_fixations')->where('user_id', $uid)->where('status', 'approved')->exists()) {
                    $rows[] = array_merge($base, ['status'=>'EXISTING','reason'=>'Approved fixation preserved']); continue;
                }
                $user = User::query()->find($uid);
                if (!$user) {
                    $rows[] = array_merge($base, ['status'=>'REVIEW','reason'=>'No user record']); continue;
                }
                $candidate = $calc->prepare($user);
                if (($candidate['ready'] ?? false) !== true || ($candidate['reference_match_status'] ?? '') !== 'matched') {
                    $why = implode('; ', array_slice($candidate['blockers'] ?? [], 0, 3));
                    $rows[] = array_merge($base, ['status'=>'REVIEW','reason'=>'Source/approval mismatch: '.($why ?: ($candidate['reference_match_status'] ?? 'no matched report'))]);
                    continue;
                }
                if (!$apply) {
                    $rows[] = array_merge($base, ['status'=>'READY','reason'=>'Validated calculator and unique source reference']);
                    continue;
                }
                // Re-evaluate inside the existing workflow; it creates fixation, 3 schedules and audit events transactionally.
                $workflow->approveNow($user, (int) $actor);
                $rows[] = array_merge($base, ['status'=>'APPROVED','reason'=>'Workflow approval completed']);
            } catch (Throwable $e) {
                report($e);
                $rows[] = array_merge($base, ['status'=>'REVIEW','reason'=>substr($e->getMessage(), 0, 180)]);
            }
        }
        $file = storage_path('app/kau-bulk-fixation-'.($apply?'applied':'dry-run').'-'.date('Ymd_His').'.csv');
        $fh = fopen($file, 'wb');
        fputcsv($fh, ['employee_code','user_id','status','reason']);
        foreach ($rows as $r) fputcsv($fh, [$r['code'], $r['user'], $r['status'], $r['reason']]);
        fclose($fh);
        $counts = array_count_values(array_column($rows, 'status'));
        $this->line(json_encode($counts, JSON_UNESCAPED_UNICODE));
        $this->line('CSV: '.$file);
        if (!$apply) $this->info('DRY RUN: no fixation or schedule records created.');
        return self::SUCCESS;
    }
}
