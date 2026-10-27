<?php

namespace App\Console\Commands;

use App\Services\PayrollEffectivePolicy;
use App\Services\PayrollPreviewCalculator;
use App\Services\SalaryArrearPostingService;
use App\Services\SalaryBillReconciliation;
use App\Services\SalaryBankAccountResolver;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only employee-by-employee release preflight. Writes only its local CSV report.
 * It does not approve, generate, post, or pay a salary bill.
 */
final class AuditPayrollRelease extends Command
{
    protected $signature = 'kau:audit-payroll-release {--year=2026} {--month=10}';
    protected $description = 'Read-only October payroll and posted arrear financial-integrity audit';

    private static function decodeItems(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        if (is_string($decoded)) $decoded = json_decode($decoded, true);
        if (!is_array($decoded)) throw new DomainException('Salary component JSON is missing or invalid.');
        return $decoded;
    }

    private static function paisa(mixed $amount): int
    {
        if (!is_numeric($amount) || !is_finite((float) $amount)) {
            throw new DomainException('Invalid salary financial amount.');
        }
        return (int) round((float) $amount * 100, 0, PHP_ROUND_HALF_UP);
    }

    public function handle(): int
    {
        $year = filter_var($this->option('year'), FILTER_VALIDATE_INT);
        $month = filter_var($this->option('month'), FILTER_VALIDATE_INT);
        if ($year === false || $year < 2020 || $year > 2100 || $month === false || $month < 1 || $month > 12) {
            $this->error('Invalid year/month. No payroll data was modified.');
            return self::FAILURE;
        }
        if (PayrollEffectivePolicy::uses2026PayScale($year, $month)) {
            $this->error('This October/2015-scale preflight does not certify a post-activation 2026-scale payroll.');
            return self::FAILURE;
        }
        foreach (['users', 'employee_infos', 'pay_scales', 'grades', 'payroll_groups',
            'bank_accounts', 'general_provident_funds',
            'salary_generates', 'salary_arrear_snapshots', 'salary_arrear_disbursements',
            'salary_arrear_lines', 'salary_arrear_gpf_adjustments'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error("Missing required table: {$table}. No payroll data was modified.");
                return self::FAILURE;
            }
        }

        $profiles = DB::table('employee_infos as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('pay_scales as s', 's.id', '=', 'e.pay_scale_id')
            ->leftJoin('payroll_groups as pg', 'pg.id', '=', 'e.payroll_group_id')
            ->leftJoin('grades as g', 'g.id', '=', 'pg.grade_id')
            ->select('e.id as profile_id', 'e.user_id', 'e.employeeCode', 'e.additionObjects',
                'e.substractionObjects', 'e.pay_scale_id', 'e.bank_account_id', 'u.name', 'u.isActive as user_active',
                's.scale_name', 'g.pay_scale_id as grade_scale_id')
            ->orderBy('e.employeeCode')->orderBy('e.id')->get();
        $profileCounts = $profiles->countBy('user_id');
        $bills = DB::table('salary_generates')->where('monthId', $month)
            ->where('salaryGenerationYear', $year)->get()->groupBy('user_id');
        $septemberBills = $year === 2026 && $month === 10
            ? DB::table('salary_generates')->where('salaryGenerationYear', 2026)
                ->where('monthId', 9)->pluck('user_id')->flip()
            : collect();
        $approvedArrears = DB::table('salary_arrear_snapshots')->where('status', 'approved')
            ->get()->groupBy('user_id');
        $postings = DB::table('salary_arrear_disbursements as disbursement')
            ->join('salary_generates as bill', 'bill.id', '=', 'disbursement.salary_generate_id')
            ->where('disbursement.status', 'posted')
            ->where('bill.monthId', $month)->where('bill.salaryGenerationYear', $year)
            ->select('disbursement.*')->get()->groupBy('user_id');
        $manualByUser = Schema::hasTable('salary_manual_adjustments')
            ? DB::table('salary_manual_adjustments')->where('salary_month', $month)
                ->where('salary_year', $year)->get()->groupBy('user_id') : collect();
        $joiningByUser = Schema::hasTable('joining_dates')
            ? DB::table('joining_dates')->get()->groupBy('user_id') : collect();
        // The salary bank account and regular GPF ledger must be reconciled
        // independently from the supplementary arrear/GPF posting ledger.
        $salaryBankAccounts = DB::table('bank_accounts')->get()->groupBy('user_id');
        $monthlyGpf = DB::table('general_provident_funds')
            ->where('year', $year)->where('month', $month)
            ->where('type', 'monthly_deduction')->get()->groupBy('user_id');
        $control = ['regular_gross' => 0, 'regular_deductions' => 0,
            'regular_net' => 0, 'arrear_additions' => 0, 'arrear_deductions' => 0,
            'arrear_net' => 0, 'supplementary_gpf' => 0, 'combined_net' => 0];
        $reconciledBillCount = 0;

        $folder = storage_path('app');
        if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
            $this->error('Unable to create local audit report directory.');
            return self::FAILURE;
        }
        $csv = $folder.'/kau_payroll_release_audit_'.sprintf('%04d_%02d', $year, $month).'.csv';
        $handle = fopen($csv, 'wb');
        if ($handle === false) {
            $this->error('Unable to write local audit CSV.');
            return self::FAILURE;
        }
        fputcsv($handle, [
            'profile_id', 'employee_code', 'user_id', 'employee_name', 'salary_month',
            'regular_status', 'regular_basic', 'regular_allowances', 'regular_deductions',
            'regular_gpf', 'regular_net', 'existing_salary_bill_id', 'existing_bill_status',
            'arrear_status', 'approved_arrear_snapshot_count', 'posted_arrear_count',
            'missing_september_history', 'manual_adjustment_count',
            'salary_bank_accounts', 'regular_gpf_ledger_rows', 'bill_component_reconciliation',
            'bill_regular_net', 'bill_arrear_net', 'bill_combined_net', 'issues',
            'salary_calculation_status', 'resolved_bank_account_id', 'bank_review_status',
        ]);
        $counts = ['REGULAR_DRAFT' => 0, 'REVIEW' => 0, 'BLOCKED' => 0];
        $postedAnomalies = 0;
        try {
            foreach ($profiles as $profile) {
                $errors = [];
                $warnings = [];
                $uid = (int) $profile->user_id;
                if (!$profile->name) $errors[] = 'USER_MISSING';
                if (!(bool) $profile->user_active) $errors[] = 'USER_INACTIVE';
                if ((int) ($profileCounts[$profile->user_id] ?? 0) !== 1) $errors[] = 'DUPLICATE_USER_PROFILES';
                if (!$profile->scale_name || !str_contains((string) $profile->scale_name, '2015')) {
                    $errors[] = 'OCTOBER_REQUIRES_2015_SCALE';
                }
                if (!$profile->grade_scale_id || (int) $profile->grade_scale_id !== (int) $profile->pay_scale_id) {
                    $errors[] = 'GRADE_SCALE_RELATION_MISMATCH';
                }
                $userBills = $bills->get($profile->user_id, collect());
                if ($userBills->count() > 1) $errors[] = 'DUPLICATE_MONTHLY_BILLS';
                $bill = $userBills->first();
                if ($bill) $warnings[] = 'EXISTING_BILL_REQUIRES_HR_RECONCILIATION';
                $joinRows = $joiningByUser->get($profile->user_id, collect());
                if ($joinRows->count() > 1) $errors[] = 'AMBIGUOUS_JOINING_DATE';
                $joiningDate = $joinRows->first()->joining_date ?? null;
                if ($joiningDate && (string) $joiningDate > date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $month)))) {
                    $errors[] = 'EMPLOYEE_NOT_JOINED_IN_PAYROLL_MONTH';
                }
                $manualItems = $manualByUser->get($profile->user_id, collect());
                if ($manualItems->isNotEmpty()) $warnings[] = 'MANUAL_ITEMS_REQUIRE_HR_RECONCILIATION';
                $financial = null;
                if (!$errors) {
                    try {
                        $additions = self::decodeItems($profile->additionObjects);
                        $deductions = self::decodeItems($profile->substractionObjects);
                        foreach ($manualItems as $manual) {
                            $item = ['name' => (string) $manual->title,
                                'amount' => $manual->amount, 'calcType' => 'fixed',
                                'component_type' => $manual->direction === 'addition' ? 'allowance' : 'deduction',
                                'manual_adjustment_id' => $manual->id];
                            if ($manual->direction === 'addition') $additions[] = $item;
                            elseif ($manual->direction === 'deduction') $deductions[] = $item;
                            else throw new DomainException('Invalid manual salary direction.');
                        }
                        $financial = PayrollPreviewCalculator::calculate($additions, $deductions);
                    } catch (DomainException $exception) {
                        $errors[] = 'SALARY_SETUP: '.$exception->getMessage();
                    }
                }
                // Salary arithmetic readiness is independent of bank-payment
                // verification. A second flagged bank account is not automatically
                // an incorrect salary calculation when an explicit linked bank exists.
                $salaryCalculationStatus = $errors ? 'BLOCKED' : 'CALCULABLE';
                $primaryAccounts = $salaryBankAccounts->get($profile->user_id, collect());
                $bankSelection = SalaryBankAccountResolver::resolve(
                    $uid,
                    $profile->bank_account_id === null ? null : (int) $profile->bank_account_id,
                    $primaryAccounts->all()
                );
                foreach ($bankSelection['errors'] as $error) $errors[] = 'BANK: '.$error;
                foreach ($bankSelection['warnings'] as $warning) $warnings[] = 'BANK: '.$warning;
                $bankReviewStatus = $bankSelection['errors'] ? 'BLOCKED'
                    : ($bankSelection['warnings'] ? 'REVIEW' : 'LINKED_ACCOUNT_CONFIRMED');
                $gpfRows = $monthlyGpf->get($profile->user_id, collect());
                $billReconciliation = null;
                if ($bill) {
                    try {
                        $billAdditions = self::decodeItems($bill->salaryAllowanceCalcObject);
                        $billDeductions = self::decodeItems($bill->salaryDeductionCalcObject);
                        $billReconciliation = SalaryBillReconciliation::verify(
                            $bill, $billAdditions, $billDeductions);
                        foreach ($billReconciliation['issues'] as $issue) {
                            $errors[] = 'BILL_COMPONENTS: '.$issue;
                        }
                        if ($gpfRows->count() > 1) {
                            $errors[] = 'MULTIPLE_MONTHLY_GPF_LEDGER_ROWS';
                        } elseif ($gpfRows->isEmpty()
                            && self::paisa($billReconciliation['regular_gpf']) > 0) {
                            $errors[] = 'MONTHLY_GPF_LEDGER_MISSING';
                        } elseif ($gpfRows->count() === 1) {
                            $gpf = $gpfRows->first();
                            if ((int) $gpf->salary_generate_id !== (int) $bill->id
                                || self::paisa($gpf->contributionAmount)
                                    !== self::paisa($billReconciliation['regular_gpf'])) {
                                $errors[] = 'MONTHLY_GPF_LEDGER_BILL_OR_AMOUNT_MISMATCH';
                            }
                            if (self::paisa($gpf->openingBalance) + self::paisa($gpf->netDeposit)
                                !== self::paisa($gpf->closingAmount)) {
                                $errors[] = 'MONTHLY_GPF_CLOSING_BALANCE_MISMATCH';
                            }
                        }
                        if (count($billReconciliation['arrear_snapshot_ids'])
                            && $postings->get($profile->user_id, collect())->isEmpty()) {
                            $errors[] = 'BILL_CONTAINS_ARREARS_WITHOUT_DISBURSEMENT';
                        }
                        if (!$billReconciliation['issues']) {
                            foreach (array_keys($control) as $key) {
                                $control[$key] += self::paisa($billReconciliation[$key]);
                            }
                            ++$reconciledBillCount;
                        }
                    } catch (DomainException $exception) {
                        $errors[] = 'INVALID_EXISTING_BILL_COMPONENTS: '.$exception->getMessage();
                    }
                } elseif ($gpfRows->isNotEmpty()) {
                    $warnings[] = 'GPF_LEDGER_EXISTS_WITHOUT_SELECTED_MONTH_SALARY_BILL';
                }
                $userPostings = $postings->get($profile->user_id, collect());
                $arrearStatus = $userPostings->isNotEmpty() ? 'POSTED_REQUIRES_BANK_CERTIFICATION'
                    : ($approvedArrears->get($profile->user_id, collect())->isNotEmpty() ? 'APPROVED_NOT_POSTED' : 'NOT_APPROVED');
                foreach ($userPostings as $posted) {
                    $postingBill = $bills->get($profile->user_id, collect())
                        ->first(fn ($record) => (int) $record->id === (int) $posted->salary_generate_id);
                    if (!$postingBill) {
                        $errors[] = 'POSTED_ARREAR_BILL_NOT_IN_SELECTED_SALARY_MONTH';
                        ++$postedAnomalies;
                        continue;
                    }
                    try {
                        $sourceLines = DB::table('salary_arrear_lines')->where('snapshot_id', $posted->snapshot_id)
                            ->orderBy('id')->get();
                        SalaryArrearPostingService::assertPostedBillItems(
                            $sourceLines,
                            self::decodeItems($postingBill->salaryAllowanceCalcObject),
                            self::decodeItems($postingBill->salaryDeductionCalcObject),
                            (int) $posted->snapshot_id
                        );
                        $expectedGpf = $sourceLines->filter(fn ($line) => $line->code === 'additional_gpf'
                            && self::paisa($line->amount) !== 0);
                        $actualGpf = DB::table('salary_arrear_gpf_adjustments')
                            ->where('disbursement_id', $posted->id)->get();
                        if ($expectedGpf->count() !== $actualGpf->count()) {
                            throw new DomainException('GPF adjustment count does not match posted arrear source.');
                        }
                        foreach ($expectedGpf as $line) {
                            $record = $actualGpf->first(fn ($row) => (int) $row->entitlement_year === (int) $line->year
                                && (int) $row->entitlement_month === (int) $line->month);
                            $signed = self::paisa($line->amount) * ($line->direction === 'deduction' ? 1 : -1);
                            $snapshot = DB::table('salary_arrear_snapshots')->where('id', $posted->snapshot_id)->first();
                            if (!$record || !$snapshot || self::paisa($record->signed_amount) !== $signed
                                || (int) $record->snapshot_id !== (int) $posted->snapshot_id
                                || (int) $record->salary_generate_id !== (int) $posted->salary_generate_id
                                || !hash_equals((string) $snapshot->source_hash, (string) $record->source_hash)) {
                                throw new DomainException('GPF adjustment amount/provenance differs from posted arrear source.');
                            }
                        }
                    } catch (DomainException $exception) {
                        $errors[] = 'POSTED_ARREAR_INTEGRITY: '.$exception->getMessage();
                        ++$postedAnomalies;
                    }
                }
                $missingSeptember = $year === 2026 && $month === 10 && !$septemberBills->has($profile->user_id);
                if ($missingSeptember && ($userPostings->isNotEmpty()
                    || $approvedArrears->get($profile->user_id, collect())->isNotEmpty())) {
                    $warnings[] = 'SEPTEMBER_HISTORY_MISSING_FOR_ARREAR_RECONCILIATION';
                }
                $status = $errors ? 'BLOCKED' : ($warnings ? 'REVIEW' : 'REGULAR_DRAFT');
                $counts[$status]++;
                fputcsv($handle, [
                    $profile->profile_id, $profile->employeeCode, $profile->user_id, $profile->name,
                    sprintf('%04d-%02d', $year, $month), $status,
                    $financial['basic'] ?? '', $financial['total_allowance'] ?? '',
                    $financial['total_deduction'] ?? '', $financial['gpf_deduction'] ?? '',
                    $financial['net_salary'] ?? '', $bill->id ?? '', $bill->reviewStatus ?? '',
                    $arrearStatus, $approvedArrears->get($profile->user_id, collect())->count(),
                    $userPostings->count(), $missingSeptember ? 'yes' : 'no', $manualItems->count(),
                    $primaryAccounts->count(), $gpfRows->count(),
                    $billReconciliation ? ($billReconciliation['issues'] ? 'MISMATCH' : 'MATCH') : 'NO_BILL',
                    $billReconciliation['regular_net'] ?? '',
                    $billReconciliation['arrear_net'] ?? '',
                    $billReconciliation['combined_net'] ?? '',
                    implode(' | ', array_merge($errors, $warnings)),
                    $salaryCalculationStatus, $bankSelection['selected_id'], $bankReviewStatus,
                ]);
            }
        } catch (Throwable $exception) {
            fclose($handle);
            $this->error('Read-only audit stopped: '.$exception->getMessage());
            return self::FAILURE;
        }
        fclose($handle);
        $this->info('Read-only payroll audit complete. No salary, arrear, or GPF records changed.');
        $this->table(['Status', 'Profiles'], collect($counts)->map(fn ($value, $key) => [$key, $value])->values()->all());
        $this->line('Posted arrear integrity exceptions: '.$postedAnomalies);
        $this->line('Saved bills with matching component totals: '.$reconciledBillCount);
        $this->line('Audit CSV: '.$csv);
        $controlPath = $folder.'/kau_payroll_financial_controls_'.sprintf('%04d_%02d', $year, $month).'.csv';
        $controlFile = fopen($controlPath, 'wb');
        if ($controlFile !== false) {
            fputcsv($controlFile, ['control', 'amount_bdt', 'scope']);
            foreach ($control as $label => $cents) {
                fputcsv($controlFile, [$label, number_format($cents / 100, 2, '.', ''),
                    'Existing salary bills whose component totals reconcile; not bank-certified']);
            }
            fputcsv($controlFile, ['reconciled_salary_bills', $reconciledBillCount,
                'Counts saved bills with mathematical component agreement']);
            fclose($controlFile);
            $this->line('Financial control totals CSV: '.$controlPath);
        } else {
            $this->warn('Could not write financial control totals CSV.');
        }
        $this->warn('This is NOT a bank-payment authorization or production readiness certificate.');
        return $counts['BLOCKED'] > 0 || $postedAnomalies > 0 ? self::FAILURE : self::SUCCESS;
    }
}
