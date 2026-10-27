<?php

namespace App\Console\Commands;

use App\Http\Controllers\SalaryGenerateController;
use App\Services\SalaryBankAccountResolver;
use App\Services\IsolatedPayrollRehearsalPolicy;
use DomainException;
use App\Services\SalaryBillReconciliation;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Isolated MySQL transaction-rollback rehearsal of the ACTUAL salary generator.
 * This command never marks bills approved and never certifies bank payments.
 */
final class RehearseOctoberPayroll extends Command
{
    protected $signature = 'kau:rehearse-october-payroll
        {--year=2026 : Salary year}
        {--month=10 : Salary month}
        {--user-id= : Restrict to one existing User ID}
        {--limit=25 : Maximum profiles; 0 means all}
        {--confirm-isolated : Explicit acknowledgement that this is a disposable MySQL clone}';

    protected $description = 'Run single/repeated salary generation in isolated MySQL and rollback ALL payroll changes';

    public function handle(): int
    {
        $year = filter_var($this->option('year'), FILTER_VALIDATE_INT);
        $month = filter_var($this->option('month'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $userId = $this->option('user-id');
        if ($year !== 2026 || $month !== 10 || $limit === false || $limit < 0
            || ($userId !== null && $userId !== '' && (!ctype_digit((string) $userId) || (int) $userId < 1))) {
            $this->error('This rehearsal is limited to valid October 2026 inputs.');
            return self::FAILURE;
        }

        // Three independent controls: explicit consent, environment, and database identity.
        // The regex deliberately rejects the actual kau-app and kau-app-new-local databases.
        $conn = DB::connection();
        $dbName = (string) $conn->getDatabaseName();
        $driver = (string) $conn->getDriverName();
        $host = (string) config('database.connections.'.config('database.default').'.host', '');
        try {
            IsolatedPayrollRehearsalPolicy::assertAllowed(
                (bool) $this->option('confirm-isolated'),
                (string) app()->environment(), $driver, $dbName, $host
            );
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());
            $this->line('Current DB name: '.$dbName.'; driver: '.$driver.'. No payroll action was attempted.');
            return self::FAILURE;
        }
        foreach (['users', 'employee_infos', 'salary_generates', 'general_provident_funds',
            'salary_arrear_disbursements', 'bank_accounts'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error('Missing staging table '.$table.'; rehearsal cancelled.');
                return self::FAILURE;
            }
        }

        $query = DB::table('employee_infos as e')->select('e.id as profile_id', 'e.user_id',
            'e.employeeCode')->orderBy('e.id');
        if ($userId !== null && $userId !== '') $query->where('e.user_id', (int) $userId);
        if ($limit > 0) $query->limit($limit);
        $profiles = $query->get();
        $folder = storage_path('app');
        if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
            $this->error('Cannot create CSV output directory.');
            return self::FAILURE;
        }
        $out = $folder.'/kau_step10_rehearsal_2026_10.csv';
        $file = fopen($out, 'wb');
        if ($file === false) {
            $this->error('Cannot create rehearsal report.');
            return self::FAILURE;
        }
        fputcsv($file, ['profile_id', 'user_id', 'employee_code', 'month', 'rehearsal_status',
            'generated_bill_id_inside_rolled_back_transaction', 'gross', 'deductions', 'regular_gpf',
            'net', 'bank_account_check', 'gpf_ledger_check', 'repeated_generation_check',
            'component_check', 'salary_pdf', 'arrear_posting', 'details']);

        $counts = [];
        $totalNetCents = 0;
        $controller = app(SalaryGenerateController::class);
        try {
            foreach ($profiles as $profile) {
                $user = (int) $profile->user_id;
                $status = 'UNTESTED';
                $billId = $gross = $ded = $gpf = $net = '';
                $bankCheck = $gpfCheck = $repeatCheck = $componentCheck = 'NOT_RUN';
                $detail = '';
                $oldBillCount = DB::table('salary_generates')->where('user_id', $user)
                    ->where('monthId', 10)->where('salaryGenerationYear', 2026)->count();
                $oldGpfCount = DB::table('general_provident_funds')->where('user_id', $user)
                    ->where('month', 10)->where('year', 2026)->count();
                if ($oldBillCount !== 0) {
                    $status = 'SKIPPED_EXISTING_SALARY_BILL';
                    $detail = 'No rehearsal generation: existing salary bill must be audited read-only.';
                } else {
                    $conn->beginTransaction();
                    try {
                        $request = new Request(['userId' => $user, 'monthId' => 10,
                            'salaryGenerationYear' => 2026, 'salaryGenerationDate' => '2026-10-31']);
                        $response = $controller->generateSalaryForEmployee($request);
                        $json = json_decode((string) $response->getContent(), true);
                        if (($json['status'] ?? null) != 200) {
                            $status = 'BLOCKED_BY_GENERATOR';
                            $detail = implode('; ', (array) ($json['errors'] ?? ['Generation refused']));
                        } else {
                            $bill = DB::table('salary_generates')->where('user_id', $user)
                                ->where('monthId', 10)->where('salaryGenerationYear', 2026)->first();
                            if (!$bill) {
                                throw new \RuntimeException('Successful generation returned no salary bill.');
                            }
                            $billId = (int) $bill->id;
                            $allowances = json_decode((string) $bill->salaryAllowanceCalcObject, true);
                            $deductions = json_decode((string) $bill->salaryDeductionCalcObject, true);
                            if (!is_array($allowances) || !is_array($deductions)) {
                                throw new \RuntimeException('Bill components are not valid JSON arrays.');
                            }
                            $verified = SalaryBillReconciliation::verify($bill, $allowances, $deductions);
                            $componentCheck = $verified['issues'] ? implode(';', $verified['issues']) : 'MATCH';
                            $gross = $verified['regular_gross'];
                            $ded = $verified['regular_deductions'];
                            $gpf = $verified['regular_gpf'];
                            $net = $verified['combined_net'];

                            $profileRow = DB::table('employee_infos')->where('id', $profile->profile_id)->first();
                            $accounts = DB::table('bank_accounts')->where('user_id', $user)->get()->all();
                            $bank = SalaryBankAccountResolver::resolve($user,
                                $profileRow->bank_account_id === null ? null : (int) $profileRow->bank_account_id,
                                $accounts);
                            $chosen = $bank['selected_id'];
                            $account = $chosen ? DB::table('bank_accounts')->where('id', $chosen)->first() : null;
                            $bankCheck = $account && !$bank['errors'] && (string) $bill->bankAccountNo === (string) $account->account_no
                                ? ($bank['warnings'] ? 'MATCH_REVIEW_FLAGS' : 'MATCH') : 'MISMATCH';

                            $monthlyGpf = DB::table('general_provident_funds')->where('user_id', $user)
                                ->where('month', 10)->where('year', 2026)
                                ->where('type', 'monthly_deduction')->get();
                            $expectedCount = abs((float) $verified['regular_gpf']) > 0.00001 ? 1 : 0;
                            $gpfCheck = $monthlyGpf->count() === $expectedCount ? 'MATCH' : 'MISMATCH_COUNT';
                            if ($gpfCheck === 'MATCH' && $expectedCount === 1) {
                                $entry = $monthlyGpf->first();
                                if ((int) $entry->salary_generate_id !== $billId
                                    || SalaryBillReconciliation::cents($entry->contributionAmount)
                                        !== SalaryBillReconciliation::cents($verified['regular_gpf'])
                                    || SalaryBillReconciliation::cents($entry->openingBalance)
                                        + SalaryBillReconciliation::cents($entry->netDeposit)
                                        !== SalaryBillReconciliation::cents($entry->closingAmount)) {
                                    $gpfCheck = 'MISMATCH_AMOUNT_OR_BALANCE';
                                }
                            }

                            // A repeated identical generation must not add a second bill or GPF record.
                            $again = $controller->generateSalaryForEmployee($request);
                            $againJson = json_decode((string) $again->getContent(), true);
                            $repeatedBill = DB::table('salary_generates')->where('user_id', $user)
                                ->where('monthId', 10)->where('salaryGenerationYear', 2026)->get();
                            $repeatedGpf = DB::table('general_provident_funds')->where('user_id', $user)
                                ->where('month', 10)->where('year', 2026)
                                ->where('type', 'monthly_deduction')->get();
                            $repeatCheck = (($againJson['status'] ?? null) == 200 || ($againJson['status'] ?? null) == 409)
                                && $repeatedBill->count() === 1
                                && (int) $repeatedBill->first()->id === $billId
                                && $repeatedGpf->count() === $expectedCount ? 'NO_DUPLICATES' : 'FAILED';
                            $allMatch = $componentCheck === 'MATCH' && str_starts_with($bankCheck, 'MATCH')
                                && $gpfCheck === 'MATCH' && $repeatCheck === 'NO_DUPLICATES';
                            $status = !$allMatch ? 'MISMATCH_TRANSACTION_ROLLED_BACK'
                                : ($bankCheck === 'MATCH_REVIEW_FLAGS'
                                    ? 'REVIEW_BANK_TRANSACTION_ROLLED_BACK' : 'PASS_TRANSACTION_ROLLED_BACK');
                            if ($status === 'PASS_TRANSACTION_ROLLED_BACK') {
                                $totalNetCents += SalaryBillReconciliation::cents($net);
                            }
                        }
                    } catch (Throwable $e) {
                        $status = 'ERROR_TRANSACTION_ROLLED_BACK';
                        $detail = $e->getMessage();
                    } finally {
                        // This outer rollback undoes the nested controller and GPF commits.
                        if ($conn->transactionLevel() > 0) $conn->rollBack(0);
                    }
                    $newBillCount = DB::table('salary_generates')->where('user_id', $user)
                        ->where('monthId', 10)->where('salaryGenerationYear', 2026)->count();
                    $newGpfCount = DB::table('general_provident_funds')->where('user_id', $user)
                        ->where('month', 10)->where('year', 2026)->count();
                    if ($newBillCount !== $oldBillCount || $newGpfCount !== $oldGpfCount) {
                        $status = 'CRITICAL_ROLLBACK_MISMATCH';
                        $detail .= '; pre/post transaction row counts differ; stop and inspect clone.';
                    }
                }
                fputcsv($file, [$profile->profile_id, $user, $profile->employeeCode, '2026-10',
                    $status, $billId, $gross, $ded, $gpf, $net, $bankCheck, $gpfCheck,
                    $repeatCheck, $componentCheck, 'NOT_TESTED_BROWSER',
                    'NOT_TESTED_NO_VERIFIED_APPROVED_SNAPSHOT', $detail]);
                $counts[$status] = ($counts[$status] ?? 0) + 1;
                if ($status === 'CRITICAL_ROLLBACK_MISMATCH') break;
            }
        } finally {
            fclose($file);
        }
        $this->table(['Rehearsal status', 'Profiles'], array_map(
            fn ($status, $n) => [$status, $n], array_keys($counts), $counts));
        $this->line('Isolated MySQL database: '.$dbName);
        $this->line('CSV: '.$out);
        $this->line('Sum of PASS rehearsal regular net (not payable): '.number_format($totalNetCents / 100, 2));
        $this->warn('All trial salary and GPF writes were rolled back. No bank payment or HR approval was performed.');
        return isset($counts['CRITICAL_ROLLBACK_MISMATCH']) ? self::FAILURE : self::SUCCESS;
    }
}
