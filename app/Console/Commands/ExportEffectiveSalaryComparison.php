<?php

namespace App\Console\Commands;

use App\Models\EmployeeInfo;
use App\Services\ApprovedFixationComponentProjector;
use App\Services\ApprovedFixationResolver;
use App\Services\PayrollPreviewCalculator;
use DomainException;
use Illuminate\Console\Command;

/** Read-only comparison exported from the CURRENT database, not an old SQL dump. */
final class ExportEffectiveSalaryComparison extends Command
{
    protected $signature = 'kau:compare-fixation-payroll {--year=2026} {--month=10}';
    protected $description = 'Export employee-wise legacy vs approved effective-dated payroll without modifying bills';

    public function handle(ApprovedFixationResolver $resolver): int
    {
        $year = filter_var($this->option('year'), FILTER_VALIDATE_INT);
        $month = filter_var($this->option('month'), FILTER_VALIDATE_INT);
        if ($year === false || $month === false) {
            $this->error('Invalid year and month.'); return self::FAILURE;
        }
        try { ApprovedFixationResolver::cutoff($year, $month); }
        catch (DomainException $e) { $this->error($e->getMessage()); return self::FAILURE; }
        $profiles = EmployeeInfo::with(['user', 'payScale', 'payrollGroup.grade'])->orderBy('id')->get();
        $fixations = $resolver->forUsers($profiles->pluck('user_id')->all(), $year, $month);
        $counts = $profiles->countBy('user_id');
        $directory = storage_path('app');
        if (!is_dir($directory)) mkdir($directory, 0750, true);
        $file = sprintf('%s/kau_phase2_effective_salary_%04d_%02d.csv', $directory, $year, $month);
        $handle = fopen($file, 'wb');
        if (!$handle) { $this->error('Cannot write report file.'); return self::FAILURE; }
        fputcsv($handle, ['profile_id','user_id','employee_code','employee_name','payroll_month',
            'legacy_pay_scale','legacy_grade','legacy_basic','effective_pay_scale','effective_grade',
            'approved_fixation_id','fixation_effective_date','effective_basic','gross','deductions','net',
            'status','reason']);
        foreach ($profiles as $profile) {
            $adds = is_array($profile->additionObjects) ? $profile->additionObjects : [];
            $ded = is_array($profile->substractionObjects) ? $profile->substractionObjects : [];
            $fixation = $fixations[$profile->user_id] ?? null;
            $legacyBasic = null;
            foreach ($adds as $component) {
                if (str_contains(strtolower((string) ($component['name'] ?? $component['payroll_component_type']['name'] ?? '')), 'basic')) {
                    $legacyBasic = $component['amount'] ?? null;
                    break;
                }
            }
            $status = 'DRAFT'; $reason = ''; $financial = null;
            if (($counts[$profile->user_id] ?? 0) > 1) {
                $status = 'BLOCKED'; $reason = 'Duplicate user profiles';
            } else {
                try {
                    if ($fixation) {
                        $projected = ApprovedFixationComponentProjector::project($adds, $ded, $fixation);
                        $adds = $projected['additions']; $ded = $projected['deductions'];
                    }
                    $financial = PayrollPreviewCalculator::calculate($adds, $ded);
                } catch (DomainException $e) { $status = 'BLOCKED'; $reason = $e->getMessage(); }
            }
            fputcsv($handle, [$profile->id,$profile->user_id,$profile->employeeCode,
                $profile->user?->name,sprintf('%04d-%02d',$year,$month),
                $profile->payScale?->scale_name,$profile->payrollGroup?->grade?->grade_name,$legacyBasic,
                $fixation['pay_scale'] ?? $profile->payScale?->scale_name,
                $fixation['grade'] ?? $profile->payrollGroup?->grade?->grade_name,
                $fixation['salary_fixation_id'] ?? '',$fixation['effective_date'] ?? '',
                $financial['basic'] ?? '',$financial['total_allowance'] ?? '',
                $financial['total_deduction'] ?? '',$financial['net_salary'] ?? '',$status,$reason]);
        }
        fclose($handle);
        $this->info('Read-only employee comparison saved: '.$file);
        $this->warn('This is an estimate, not a payment instruction; no database rows were changed.');
        return self::SUCCESS;
    }
}
