<?php

namespace App\Console\Commands;

use App\Services\PayrollExceptionReviewClassifier as Classifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 9: provide current LIVE database context for the existing October audit.
 * Reports are read-only; no UPDATE, INSERT, DELETE, posting or approval occurs.
 */
final class PreparePayrollExceptionReview extends Command
{
    protected $signature = 'kau:prepare-payroll-exception-review {--year=2026} {--month=10}';
    protected $description = 'Read-only Step 9 HR exception worklist with current scale, profile, bank IDs and approved historical salary evidence';

    public function handle(): int
    {
        $year = filter_var($this->option('year'), FILTER_VALIDATE_INT);
        $month = filter_var($this->option('month'), FILTER_VALIDATE_INT);
        if ($year === false || $year < 2020 || $year > 2100 || $month === false || $month < 1 || $month > 12) {
            $this->error('Invalid year/month.');
            return self::FAILURE;
        }
        foreach (['employee_infos', 'users', 'grades', 'payroll_groups', 'pay_scales', 'bank_accounts', 'salary_generates'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error("Required source table missing: {$table}");
                return self::FAILURE;
            }
        }
        $folder = storage_path('app');
        $auditFile = $folder.'/kau_payroll_release_audit_'.sprintf('%04d_%02d', $year, $month).'.csv';
        if (!is_file($auditFile) || !is_readable($auditFile)) {
            $this->error("Run kau:audit-payroll-release first: {$auditFile}");
            return self::FAILURE;
        }
        $handle = fopen($auditFile, 'rb');
        if (!$handle) return self::FAILURE;
        $header = fgetcsv($handle);
        $required = ['profile_id', 'employee_code', 'user_id', 'salary_month', 'regular_status',
            'issues', 'bank_review_status', 'resolved_bank_account_id'];
        if (!$header || array_diff($required, $header)) {
            fclose($handle);
            $this->error('Required audit CSV columns missing; regenerate Step 8 audit.');
            return self::FAILURE;
        }
        $records = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count($values) !== count($header)) {
                fclose($handle);
                $this->error('Incomplete audit CSV row. No report generated.');
                return self::FAILURE;
            }
            $r = array_combine($header, $values);
            if ($r['salary_month'] !== sprintf('%04d-%02d', $year, $month)) {
                fclose($handle);
                $this->error('Audit month does not match the requested month.');
                return self::FAILURE;
            }
            $records[] = $r;
        }
        fclose($handle);

        $profiles = DB::table('employee_infos as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('pay_scales as ps', 'ps.id', '=', 'e.pay_scale_id')
            ->leftJoin('payroll_groups as pg', 'pg.id', '=', 'e.payroll_group_id')
            ->leftJoin('grades as g', 'g.id', '=', 'pg.grade_id')
            ->leftJoin('pay_scales as gs', 'gs.id', '=', 'g.pay_scale_id')
            ->select('e.id as profile_id', 'e.user_id', 'e.employeeCode', 'e.pay_scale_id',
                'e.payroll_group_id', 'e.bank_account_id', 'e.additionObjects',
                'u.isActive as user_active', 'ps.scale_name as employee_scale',
                'g.id as grade_id', 'g.grade_name', 'g.pay_scale_id as grade_scale_id',
                'gs.scale_name as grade_scale')
            ->get()->keyBy('profile_id');
        if (count($records) !== $profiles->count()
            || count(array_unique(array_column($records, 'profile_id'))) !== count($records)) {
            $this->error('Audit and LIVE employee profile counts/identities differ. Regenerate the Step 8 audit first.');
            return self::FAILURE;
        }
        foreach ($records as $r) {
            $p = $profiles->get($r['profile_id']);
            if (!$p || (int) $p->user_id !== (int) $r['user_id']
                || (string) $p->employeeCode !== (string) $r['employee_code']) {
                $this->error('Employee profile changed after audit. Regenerate the Step 8 audit first.');
                return self::FAILURE;
            }
        }
        $bank = DB::table('bank_accounts')->select('id','user_id','isSalaryAccount')->get()->groupBy('user_id');
        $latestBills = DB::table('salary_generates')
            ->where('reviewStatus', 'approved')
            ->where(function ($query) use ($year, $month) {
                $query->where('salaryGenerationYear', '<', $year)
                    ->orWhere(function ($q) use ($year, $month) {
                        $q->where('salaryGenerationYear', $year)->where('monthId', '<=', $month);
                    });
            })
            ->orderByDesc('salaryGenerationYear')
            ->orderByDesc('monthId')
            ->orderByDesc('id')
            ->select('id','user_id','salaryGenerationYear','monthId','salaryAllowanceCalcObject')
            ->get()->groupBy('user_id');

        $output = $folder.'/kau_step9_hr_exception_worklist_'.sprintf('%04d_%02d', $year, $month).'.csv';
        $tmp = $output.'.tmp';
        $out = fopen($tmp, 'wb');
        if (!$out) {
            $this->error('Cannot open output CSV for writing.');
            return self::FAILURE;
        }
        fputcsv($out, [
            'profile_id','employee_code','user_id','regular_status','issue_codes','hr_review_actions',
            'employee_scale_id','employee_scale_name','payroll_group_id','grade_id','grade_name',
            'grade_scale_id','grade_scale_name','user_active','profile_basic_reference',
            'last_approved_salary_bill_id','last_approved_salary_month','last_approved_salary_basic_reference',
            'linked_bank_account_id','salary_flagged_bank_account_ids','resolved_bank_account_id','bank_review_status',
            'hr_decision','hr_evidence_reference','note',
        ]);
        $counts = ['BLOCKED'=>0,'REVIEW'=>0];
        foreach ($records as $r) {
            if (!in_array($r['regular_status'], ['BLOCKED','REVIEW'], true)) continue;
            $p = $profiles->get($r['profile_id']);
            $bill = $latestBills->get($p->user_id, collect())->first();
            $flagged = $bank->get($p->user_id, collect())
                ->filter(fn ($a) => (bool) $a->isSalaryAccount)
                ->pluck('id')->sort()->values()->implode(';');
            $historicalBasic = $bill ? Classifier::historicalBasic($bill->salaryAllowanceCalcObject) : null;
            $profileBasic = Classifier::historicalBasic($p->additionObjects);
            fputcsv($out, [
                $r['profile_id'], $r['employee_code'], $r['user_id'], $r['regular_status'], $r['issues'],
                implode(';', Classifier::actions((string)$r['issues'])),
                $p->pay_scale_id, $p->employee_scale, $p->payroll_group_id,
                $p->grade_id, $p->grade_name, $p->grade_scale_id, $p->grade_scale,
                $p->user_active, $profileBasic,
                $bill->id ?? '', $bill ? sprintf('%04d-%02d', $bill->salaryGenerationYear, $bill->monthId) : '',
                $historicalBasic, $p->bank_account_id, $flagged, $r['resolved_bank_account_id'],
                $r['bank_review_status'], '', '',
                'Historical approved bill is a comparison reference, not proof of bank payment or authorization to change October payroll.',
            ]);
            $counts[$r['regular_status']]++;
        }
        fclose($out);
        if (!rename($tmp, $output)) {
            @unlink($tmp);
            $this->error('Could not finalize the worklist.');
            return self::FAILURE;
        }
        $this->info('READ-ONLY Step 9 HR worklist created. No database rows modified.');
        $this->table(['Status','Profiles'], [['BLOCKED',$counts['BLOCKED']],['REVIEW',$counts['REVIEW']]]);
        $this->line('Output: '.$output);
        $this->warn('Do not auto-apply pay scale, Basic, bank flags, active status or profile mergers from this worklist.');
        return self::SUCCESS;
    }
}
