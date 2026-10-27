<?php

namespace App\Console\Commands;

use App\Models\EmployeeInfo;
use App\Services\ApprovedFixationResolver;
use App\Services\SalaryCalculationService;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only employee-wise post-migration financial comparison. */
final class AuditSalaryPhase3 extends Command
{
    protected $signature = 'kau:audit-salary-phase3 {--year=2026} {--month=10}';
    protected $description = 'Read-only audit of approved effective salary, draft versions and default GPF.';

    public function handle(ApprovedFixationResolver $resolver, SalaryCalculationService $engine): int
    {
        $year = (int) $this->option('year'); $month = (int) $this->option('month');
        try { ApprovedFixationResolver::cutoff($year,$month); }
        catch (DomainException $e) { $this->error($e->getMessage()); return self::FAILURE; }
        $path = storage_path(sprintf('app/kau_phase3_salary_comparison_%04d_%02d.csv', $year, $month));
        $fp = fopen($path, 'w');
        if (!$fp) { $this->error('Unable to open output CSV.'); return self::FAILURE; }
        fputcsv($fp, ['Profile ID','User ID','Employee Code','Employee Name','Period','Legacy Pay Scale',
            'Legacy Basic','Effective Scale','Effective Grade','Effective Basic','Approved Fixation ID',
            'Draft Version','Draft Status','Default GPF 10% HALF UP','Gross Preview','Deductions Preview',
            'Net Preview','Status / Issue']);
        $count = 0;
        $profiles = EmployeeInfo::with(['user','payScale','payrollGroup.grade'])->orderBy('id')->get();
        $profileCounts = $profiles->groupBy('user_id')->map(fn ($rows) => $rows->count());
        $fixations = $resolver->forUsers($profiles->pluck('user_id')->all(), $year, $month);
        $hasVersions = Schema::hasTable('salary_calculation_versions');
        foreach ($profiles as $row) {
            $fixation = $fixations[$row->user_id] ?? null;
            $legacyBasic = $row->basic_pay_amount;
            $status = 'PREVIEW'; $finance = null;
            try {
                if (($profileCounts[$row->user_id] ?? 0) !== 1) throw new DomainException('DUPLICATE_EMPLOYEE_PROFILE');
                $adds = is_array($row->additionObjects) ? $row->additionObjects : [];
                $ded = is_array($row->substractionObjects) ? $row->substractionObjects : [];
                $finance = $engine->calculate($adds,$ded,$fixation);
            } catch (DomainException $e) { $status = 'REVIEW: '.$e->getMessage(); }
            $saved = $hasVersions ? DB::table('salary_calculation_versions')
                ->where('user_id',$row->user_id)->where('salary_year',$year)->where('salary_month',$month)
                ->orderByDesc('version')->first() : null;
            fputcsv($fp, [$row->id, $row->user_id, $row->employeeCode, $row->user?->name,
                sprintf('%04d-%02d',$year,$month), $row->payScale?->scale_name,
                $legacyBasic, $fixation['pay_scale'] ?? $row->payScale?->scale_name,
                $fixation['grade'] ?? $row->payrollGroup?->grade?->grade_name,
                $finance['basic'] ?? ($fixation['basic'] ?? ''), $fixation['salary_fixation_id'] ?? '',
                $saved->version ?? '', $saved->status ?? '',
                $finance['gpf_deduction'] ?? '',$finance['total_allowance'] ?? '',
                $finance['total_deduction'] ?? '',$finance['net_salary'] ?? '',$status]);
            $count++;
        }
        fclose($fp);
        $this->info("Read-only salary comparison complete: {$count} profiles. No salary/GPF record changed.");
        $this->line("File: {$path}");
        $this->warn('This is NOT a payable salary or bank authorization. Approved calculation versions and HR evidence require separate reconciliation.');
        return self::SUCCESS;
    }
}
