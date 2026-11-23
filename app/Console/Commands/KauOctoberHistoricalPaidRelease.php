<?php

namespace App\Console\Commands;

use App\Models\EmployeeInfo;
use App\Models\SalaryGenerate;
use App\Models\User;
use App\Services\GeneralProvidentFundService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Historical registration of an externally paid period. Never recalculates a saved salary. */
final class KauOctoberHistoricalPaidRelease extends Command
{
    protected $signature = 'kau:october-historical-paid-release
        {--actor= : The real authenticated KAU operator user ID}
        {--reference-sha256= : Exact frozen 413-row CSV SHA256}
        {--commit : Make an atomic all-or-nothing historical registration}
        {--acknowledge-20057-existing-entitlements : Link existing 20057 arrears without creating duplicates}';

    protected $description = 'October 2026 historical paid salary: immutable 413-person snapshot, verified one-time posting';

    private const YEAR = 2026;
    private const MONTH = 10;
    private const COUNT = 413;
    private const EXPECTED_CENTS = 2254832485;
    private const EXPECTED_REFERENCE_SHA256 = '52f0ae82ac1bb65aa7357d6a827a4e7f1e4c1e33e95310f91ba7c4aa3c6b5cc6';
    private const CODES = [
        'BASIC_ARREAR_GROSS', 'BASIC_INCREMENT_ADJUSTMENT',
        'SPECIAL_BENEFIT_RECOVERY', 'HOUSE_RENT_RECOVERY', 'ADDITIONAL_GPF',
    ];

    private static function cents(mixed $value): int
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
            throw new DomainException('Invalid financial amount in historical snapshot.');
        }
        return (int) round((float) $value * 100, 0, PHP_ROUND_HALF_UP);
    }

    private static function amount(int $cents): float
    {
        return $cents / 100;
    }

    private static function json(mixed $value): array
    {
        $result = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result)) throw new DomainException('Malformed saved financial JSON.');
        return $result;
    }

    private function reference(): array
    {
        $file = base_path('resources/kau_references/KAU_2026_10_FROZEN_413.csv');
        if (!is_file($file)) throw new DomainException('Frozen paid reference missing.');
        $hash = hash_file('sha256', $file);
        if (!hash_equals(self::EXPECTED_REFERENCE_SHA256, $hash)
            || !hash_equals($hash, strtolower(trim((string) $this->option('reference-sha256'))))) {
            throw new DomainException('Frozen reference SHA256 did not match operator-confirmed hash.');
        }
        $handle = fopen($file, 'rb');
        if (!$handle) throw new DomainException('Frozen reference not readable.');
        try {
            $header = fgetcsv($handle);
            if (!is_array($header)) throw new DomainException('Frozen header missing.');
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $rows = [];
            while (($items = fgetcsv($handle)) !== false) {
                if (count($items) !== count($header)) throw new DomainException('Malformed frozen reference row.');
                $row = array_combine($header, $items);
                $code = trim((string) ($row['employee_code'] ?? ''));
                if (!$code || isset($rows[$code])) throw new DomainException('Duplicate or missing employee code in paid reference.');
                $rows[$code] = $row;
            }
        } finally {
            fclose($handle);
        }
        if (count($rows) !== self::COUNT) throw new DomainException('Frozen reference has wrong employee count.');
        $sum = array_sum(array_map(fn ($r) => self::cents($r['final_net'] ?? null), $rows));
        if ($sum !== self::EXPECTED_CENTS) throw new DomainException('Frozen October paid total changed.');
        return [$rows, $hash];
    }

    private function prepare(array $ref, object $person): array
    {
        $code = (string) $person->employeeCode;
        $userId = (int) $person->user_id;
        if ((int) ($ref['user_id'] ?? 0) !== $userId) throw new DomainException("{$code}: frozen employee identity changed.");
        $version = DB::table('salary_calculation_versions')->where('user_id', $userId)
            ->where('salary_year', self::YEAR)->where('salary_month', self::MONTH)
            ->orderByDesc('version')->first();
        if (!$version || (int) $version->id !== (int) $ref['latest_calc_version_id']) {
            throw new DomainException("{$code}: frozen calculation version changed.");
        }
        $adds = self::json($version->additions);
        $deds = self::json($version->deductions);
        $regular = self::json($version->calculated_result);
        $arrear = self::json($version->arrear_snapshot);
        self::json($version->baseline_result);
        $hash = hash('sha256', json_encode([
            'user_id' => $userId, 'year' => self::YEAR, 'month' => self::MONTH,
            'fixation_id' => (int) ($version->fixation_id ?? 0),
            'additions' => $adds, 'deductions' => $deds,
            'result' => $regular, 'arrear' => $arrear,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (!hash_equals((string) $version->snapshot_hash, $hash)) {
            throw new DomainException("{$code}: saved snapshot hash mismatch.");
        }
        if (!is_array($regular['allowances'] ?? null) || !is_array($regular['deductions'] ?? null) || !is_array($arrear['rows'] ?? null)) {
            throw new DomainException("{$code}: saved financial lines are missing.");
        }
        $grossLines = array_sum(array_map(fn ($r) => self::cents($r['calculated_amount'] ?? null), $regular['allowances']));
        $dedLines = array_sum(array_map(fn ($r) => self::cents($r['calculated_amount'] ?? null), $regular['deductions']));
        if ($grossLines !== self::cents($regular['total_allowance'] ?? null)
            || $dedLines !== self::cents($regular['total_deduction'] ?? null)
            || $grossLines - $dedLines !== self::cents($regular['net_salary'] ?? null)) {
            throw new DomainException("{$code}: saved regular component totals do not reconcile.");
        }
        $checks = [
            'regular_basic' => $regular['basic'] ?? null,
            'regular_gross' => $regular['total_allowance'] ?? null,
            'regular_deduction' => $regular['total_deduction'] ?? null,
            'regular_gpf' => $regular['gpf_deduction'] ?? null,
            'regular_net' => $regular['net_salary'] ?? null,
        ];
        foreach ($checks as $key => $value) {
            if (self::cents($value) !== self::cents($ref[$key] ?? null)) {
                throw new DomainException("{$code}: frozen saved amount mismatch: {$key}.");
            }
        }
        $rules = DB::table('salary_arrear_rules')->pluck('code', 'id')->all();
        $a = $d = 0;
        $totals = array_fill_keys(self::CODES, 0);
        $arrearAdd = $arrearDed = [];
        foreach ($arrear['rows'] as $item) {
            $direction = (string) ($item['direction'] ?? '');
            if (!in_array($direction, ['payable', 'deduction'], true)) throw new DomainException("{$code}: invalid saved arrear direction.");
            $ruleId = (int) ($item['rule_id'] ?? 0);
            $componentCode = (string) ($item['code'] ?? ($rules[$ruleId] ?? ''));
            $value = self::cents($item['final_amount'] ?? null);
            if ($componentCode !== '' && !in_array($componentCode, self::CODES, true)) {
                if ($value !== 0) throw new DomainException("{$code}: unknown nonzero saved arrear component.");
            } elseif ($componentCode !== '') {
                $totals[$componentCode] += $value;
            } elseif ($value !== 0) {
                throw new DomainException("{$code}: nonzero arrear without a verified code.");
            }
            $row = [
                'name' => (string) ($item['title'] ?? ''),
                'calcType' => 'fixed', 'amount' => self::amount($value),
                'calculated_amount' => self::amount($value),
                'component_type' => $direction === 'payable' ? 'allowance' : 'deduction',
                'arrear_phase5' => true, 'arrear_rule_id' => $ruleId ?: null,
                'arrear_code' => $componentCode, 'arrear_entitlement_months' => $item['months'] ?? [],
                'arrear_source_reference' => $item['evidence_reference'] ?? null,
            ];
            if (!$row['name']) throw new DomainException("{$code}: saved arrear title missing.");
            if ($direction === 'payable') { $arrearAdd[] = $row; $a += $value; }
            else { $arrearDed[] = $row; $d += $value; }
        }
        foreach (self::CODES as $key) {
            if ($totals[$key] !== self::cents($ref[$key] ?? null)) {
                throw new DomainException("{$code}: frozen arrear component mismatch: {$key}.");
            }
        }
        $expected = [
            'arrear_payable' => $a, 'arrear_deduction' => $d,
            'total_gross_incl_arrear' => $grossLines + $a,
            'total_deductions_incl_arrear' => $dedLines + $d,
            'final_net' => $grossLines + $a - $dedLines - $d,
        ];
        foreach ($expected as $key => $value) {
            if ($value !== self::cents($ref[$key] ?? null)) throw new DomainException("{$code}: frozen {$key} mismatch.");
        }
        if ($expected['final_net'] !== self::cents($ref['reference_final_net'] ?? null)) {
            throw new DomainException("{$code}: October paid amount differs from immutable reference.");
        }
        $profile = EmployeeInfo::with(['payScale', 'payrollGroup.grade', 'payrollGroup.payrollGroupType'])
            ->where('user_id', $userId)->first();
        $user = User::find($userId);
        if (!$profile || !$user || !$user->isActive || (int) $profile->id !== (int) $version->employee_info_id) {
            throw new DomainException("{$code}: active employee/profile identity mismatch.");
        }
        $bank = DB::table('bank_accounts')->where('id', $profile->bank_account_id)
            ->where('user_id', $userId)->first();
        if (!$bank || !preg_match('/^[0-9]{10,20}$/', trim((string) $bank->account_no))) {
            throw new DomainException("{$code}: canonical bank account is missing or invalid.");
        }
        $source = [
            'historical_paid_period' => '2026-10', 'employee_code' => $code,
            'name' => $user->name,
            'pay_scale' => $version->fixation_id ? 'Govt. Pay Scale 2026' : ($profile->payScale?->scale_name ?? 'N/A'),
            'grade' => $profile->payrollGroup?->grade?->grade_name ?? 'N/A',
            'designation' => $profile->payrollGroup?->payrollGroupType?->group_name ?? 'N/A',
            'user_id' => $userId, 'year' => self::YEAR, 'month' => self::MONTH,
            'employee_info_id' => (int) $profile->id,
            'calculation_version_id' => (int) $version->id,
            'calculation_snapshot_hash' => $hash,
            'fixation_id' => $version->fixation_id,
            'bank_account_id' => (int) $bank->id,
            'bank_account_no' => (string) $bank->account_no,
            'regular' => $regular, 'regular_additions' => $regular['allowances'],
            'regular_deductions' => $regular['deductions'],
            'arrear_additions' => $arrearAdd, 'arrear_deductions' => $arrearDed,
            'arrear_rows' => $arrear['rows'],
            'arrear_payable' => self::amount($a),
            'arrear_deduction' => self::amount($d),
            'arrear_gpf' => self::amount($totals['ADDITIONAL_GPF']),
            'gross' => self::amount($grossLines + $a),
            'total_deduction' => self::amount($dedLines + $d),
            'net_payable' => self::amount($expected['final_net']),
            'frozen_reference_final_net' => (string) $ref['final_net'],
            'release_reference_sha256' => self::EXPECTED_REFERENCE_SHA256,
        ];
        if ($code === '20057') {
            $priorGpf = DB::table('salary_bill_arrear_gpf_entries')
                ->where('user_id', $userId)->orderBy('id')->get(['id', 'pay_year', 'pay_month', 'signed_amount']);
            $source['20057_prior_additional_gpf_journal'] = $priorGpf->map(fn ($r) => [
                'id' => (int) $r->id, 'year' => (int) $r->pay_year,
                'month' => (int) $r->pay_month, 'signed_amount' => (string) $r->signed_amount,
            ])->all();
            $source['20057_pending_historical_gpf_difference'] =
                self::amount($totals['ADDITIONAL_GPF']) -
                (float) $priorGpf->sum('signed_amount');
        }
        $source['release_authorization'] = 'KAU external October 2026 paid reference; independent historical operator authorization required';
        $source['source_hash'] = hash('sha256', json_encode($source, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return ['user' => $user, 'profile' => $profile, 'bank' => $bank, 'source' => $source];
    }

    private function assertNoExistingPosting(array $prepared): void
    {
        $userId = (int) $prepared['user']->id;
        $code = $prepared['source']['employee_code'];
        if (DB::table('salary_generates')->where('user_id', $userId)->where('salaryGenerationYear', 2026)->where('monthId', 10)->exists()
            || DB::table('salary_bill_source_links')->where('user_id', $userId)->where('salary_year', 2026)->where('salary_month', 10)->exists()
            || DB::table('general_provident_funds')->where('user_id', $userId)->where('year', 2026)->where('month', 10)->where('type', 'monthly_deduction')->exists()) {
            throw new DomainException("{$code}: an October bill, source link, or GPF contribution already exists.");
        }
        if (DB::table('general_provident_funds')->where('user_id', $userId)->where(function ($q) {
            $q->where('year', '>', 2026)->orWhere(fn ($s) => $s->where('year', 2026)->where('month', '>', 10));
        })->exists()) throw new DomainException("{$code}: a later GPF balance exists; backdated posting cannot safely proceed.");
        $bank = (string) $prepared['bank']->account_no;
        $duplicates = DB::table('employee_infos as e')->join('users as u', 'u.id', '=', 'e.user_id')
            ->join('bank_accounts as b', 'b.id', '=', 'e.bank_account_id')
            ->where('u.isActive', 1)->where('b.account_no', $bank)->count();
        if ($duplicates !== 1) throw new DomainException("{$code}: canonical bank account is not unique.");
    }

    private function postOne(array $prepared, int $actor, string $referenceHash): int
    {
        $user = $prepared['user'];
        $userId = (int) $user->id;
        $profile = $prepared['profile'];
        $source = $prepared['source'];
        $code = $source['employee_code'];
        $user->load(['age', 'joiningDate', 'phoneNumbers']);
        $date = CarbonImmutable::create(2026, 10, 1)->endOfMonth()->toDateString();
        $regular = $source['regular'];
        $additions = array_merge($source['regular_additions'], $source['arrear_additions']);
        $deductions = array_merge($source['regular_deductions'], $source['arrear_deductions']);
        $bill = SalaryGenerate::create([
            'user_id' => $userId, 'reviewStatus' => 'pending', 'name' => $user->name,
            'age' => (string) ($user->age?->age ?? 'N/A'), 'gender' => (string) ($user->gender ?? 'N/A'),
            'payScale' => $source['pay_scale'],
            'grade' => (string) $source['grade'],
            'designation' => (string) $source['designation'],
            'employeeCode' => (string) $code, 'bankAccountNo' => (string) $source['bank_account_no'],
            'bankAddress' => (string) ($prepared['bank']->address ?? 'N/A'),
            'employeePhoneNumber' => (string) ($user->phoneNumbers->first()?->phoneNumber ?? 'N/A'),
            'joiningDate' => $user->joiningDate?->joining_date ?? $date,
            'salaryGenerationDate' => $date, 'monthId' => 10, 'salaryGenerationYear' => 2026,
            'salaryAllowanceCalcObject' => $additions,
            'salaryDeductionCalcObject' => $deductions,
            'totalAllowance' => $source['gross'], 'totalDeduction' => $source['total_deduction'],
            'taxDeduction' => $regular['tax_deduction'] ?? 0,
            'gpfDeduction' => $regular['gpf_deduction'], 'salaryTotal' => $source['net_payable'],
            'bonusAllowanceCalcObject' => [], 'bonusDeductionCalcObject' => [],
            'bonusAllowance' => 0, 'bonusDeduction' => 0, 'bonusTaxDeduction' => 0,
            'bonusTotal' => 0, 'loanInstallmentDeduction' => 0,
            'loanInstallmentCount' => null, 'loanInstallmentRemaining' => null,
        ]);
        $link = DB::table('salary_bill_source_links')->insertGetId([
            'salary_generate_id' => $bill->id, 'user_id' => $userId,
            'salary_year' => 2026, 'salary_month' => 10,
            'salary_calculation_version_id' => $source['calculation_version_id'],
            'fixation_id' => $source['fixation_id'], 'bank_account_id' => $source['bank_account_id'],
            'source_hash' => $source['source_hash'],
            'regular_net' => $regular['net_salary'], 'arrear_payable' => $source['arrear_payable'],
            'arrear_deduction' => $source['arrear_deduction'],
            'net_payable' => $source['net_payable'],
            'source_snapshot' => json_encode($source, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (self::cents($regular['gpf_deduction']) > 0) {
            $entry = app(GeneralProvidentFundService::class)->recordSalaryGpfContribution(
                $user, $bill, (float) $regular['gpf_deduction'], 10, 2026, $date
            );
            if (!$entry || (int) $entry->salary_generate_id !== (int) $bill->id) {
                throw new DomainException("{$code}: October GPF ledger not linked correctly.");
            }
        }
        $priorIds = [];
        $legacyPeriods = DB::table('salary_arrear_disbursements as d')
            ->join('salary_arrear_periods as p', 'p.id', '=', 'd.period_id')
            ->where('d.user_id', $userId)->where('d.status', 'posted')
            ->get(['p.entitlement_start', 'p.entitlement_end']);
        foreach (array_merge($source['arrear_additions'], $source['arrear_deductions']) as $item) {
            if (self::cents($item['calculated_amount']) === 0) continue;
            foreach (($item['arrear_entitlement_months'] ?: ['2026-10']) as $period) {
                foreach ($legacyPeriods as $legacy) {
                    if ($period >= substr((string) $legacy->entitlement_start, 0, 7)
                        && $period <= substr((string) $legacy->entitlement_end, 0, 7)
                        && ($code !== '20057' || !$this->option('acknowledge-20057-existing-entitlements'))) {
                        throw new DomainException("{$code}: legacy arrear disbursement overlap in {$period}.");
                    }
                }
            }
        }
        foreach (array_merge($source['arrear_additions'], $source['arrear_deductions']) as $item) {
            if (self::cents($item['calculated_amount']) === 0) continue;
            $months = array_unique($item['arrear_entitlement_months'] ?: ['2026-10']);
            $isGpf = ($item['arrear_code'] ?? '') === 'ADDITIONAL_GPF';
            $allExisting = true;
            foreach ($months as $period) {
                if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $period)) {
                    throw new DomainException("{$code}: bad arrear entitlement period.");
                }
                $fingerprint = hash('sha256', json_encode([
                    'user_id' => $userId, 'rule_id' => $item['arrear_rule_id'],
                    'title' => $item['name'], 'direction' => $item['component_type'],
                    'entitlement_period' => $period,
                    'evidence' => $item['arrear_source_reference'],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $previous = DB::table('salary_bill_arrear_entitlements')->where('user_id', $userId)
                    ->where(function ($q) use ($item, $period, $fingerprint) {
                        $q->where('entitlement_fingerprint', $fingerprint);
                        if (!empty($item['arrear_rule_id'])) {
                            $q->orWhere(fn ($sq) => $sq->where('rule_id', $item['arrear_rule_id'])
                                ->where('entitlement_month', $period));
                        }
                    })->first();
                if ($previous) {
                    if ($code !== '20057' || !$this->option('acknowledge-20057-existing-entitlements')) {
                        throw new DomainException("{$code}: previously posted arrear conflicts with {$period}.");
                    }
                    $priorIds[] = (int) $previous->id;
                    continue;
                }
                $allExisting = false;
                DB::table('salary_bill_arrear_entitlements')->insert([
                    'salary_bill_source_link_id' => $link, 'user_id' => $userId,
                    'rule_id' => $item['arrear_rule_id'], 'entitlement_month' => $period,
                    'title' => $item['name'], 'direction' => $item['component_type'],
                    'aggregate_row_amount' => $item['calculated_amount'],
                    'entitlement_fingerprint' => $fingerprint,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if ($isGpf && !$allExisting) {
                if ($priorIds && $code === '20057') {
                    throw new DomainException('20057: partially duplicated additional GPF requires separate ledger reconciliation.');
                }
                DB::table('salary_bill_arrear_gpf_entries')->insert([
                    'salary_bill_source_link_id' => $link, 'salary_generate_id' => $bill->id,
                    'user_id' => $userId, 'pay_year' => 2026, 'pay_month' => 10,
                    'entitlement_months' => json_encode($months, JSON_THROW_ON_ERROR),
                    'signed_amount' => $item['calculated_amount'],
                    'source_hash' => hash('sha256', json_encode($item, JSON_THROW_ON_ERROR)),
                    'evidence_reference' => $item['arrear_source_reference'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $row = DB::table('salary_monthly_workflows')->where('user_id', $userId)
            ->where('salary_year', 2026)->where('salary_month', 10)->lockForUpdate()->first();
        if (!$row) {
            $id = DB::table('salary_monthly_workflows')->insertGetId([
                'user_id' => $userId, 'salary_year' => 2026, 'salary_month' => 10,
                'workflow_status' => 'BLOCKED', 'revision' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('salary_monthly_workflows')->where('id', $id)->first();
        }
        if ($row->workflow_status === 'GENERATED') throw new DomainException("{$code}: workflow already generated.");
        $revision = (int) $row->revision + 1;
        DB::table('salary_monthly_workflows')->where('id', $row->id)->update([
            'workflow_status' => 'GENERATED', 'posted_salary_generate_id' => $bill->id,
            'posting_error' => null, 'revision' => $revision, 'updated_at' => now(),
        ]);
        DB::table('salary_workflow_events')->insert([
            'salary_monthly_workflow_id' => $row->id, 'user_id' => $userId,
            'salary_year' => 2026, 'salary_month' => 10,
            'action' => 'historical_paid_release', 'from_status' => $row->workflow_status,
            'to_status' => 'GENERATED', 'actor_id' => $actor,
            'reason' => 'KAU confirmed October 2026 externally paid; immutable reference '
                .$referenceHash.'; previous entitlement row IDs: '.implode(',', array_unique($priorIds))
                .'; no historical two-stage approval asserted.',
            'before_hash' => $row->ready_snapshot_hash, 'after_hash' => $source['source_hash'],
            'revision' => $revision, 'created_at' => now(),
        ]);
        return self::cents($bill->salaryTotal);
    }

    public function handle(): int
    {
        try {
            $actor = (int) $this->option('actor');
            $operator = DB::table('users')->where('id', $actor)
                ->where('isActive', 1)->first(['id', 'name', 'email']);
            $legacySuperadmin = $operator && (int) $operator->id === 1
                && strcasecmp((string) $operator->email, 'superadmin@kau.ac.bd') === 0
                && strcasecmp((string) $operator->name, 'Superadmin') === 0;
            $assignedAdmin = $operator && DB::table('user_roles as ur')
                ->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $actor)->where('r.is_admin', 1)
                ->where('r.isActive', 1)->exists();
            if (!$operator || (!$legacySuperadmin && !$assignedAdmin)) {
                throw new DomainException('Active authorized KAU superadmin operator --actor ID is required.');
            }
            foreach (['salary_generates', 'salary_bill_source_links', 'salary_bill_arrear_entitlements',
                'salary_bill_arrear_gpf_entries', 'salary_monthly_workflows', 'salary_workflow_events',
                'salary_calculation_versions', 'general_provident_funds'] as $table) {
                if (!Schema::hasTable($table)) throw new DomainException('Required financial table is missing: '.$table);
            }
            [$reference, $referenceHash] = $this->reference();
            $roster = DB::table('employee_infos as e')->join('users as u', 'u.id', '=', 'e.user_id')
                ->where('u.isActive', 1)->select('e.employeeCode', 'e.user_id')->orderBy('e.employeeCode')->get();
            if ($roster->count() !== self::COUNT) throw new DomainException('Active employee count is not 413.');
            $prepared = [];
            $seen = [];
            $total = 0;
            foreach ($roster as $person) {
                $code = (string) $person->employeeCode;
                if (isset($seen[$code]) || !isset($reference[$code])) throw new DomainException('Unexpected/duplicate employee: '.$code);
                $seen[$code] = true;
                $item = $this->prepare($reference[$code], $person);
                $this->assertNoExistingPosting($item);
                $prepared[] = $item;
                $total += self::cents($item['source']['net_payable']);
            }
            if ($total !== self::EXPECTED_CENTS) throw new DomainException('Frozen 413-person Net total differs.');
            $this->line('Frozen October source verified for 413 active employees.');
            $this->line('Exact Net Total: BDT '.number_format(self::amount($total), 2));
            $this->line('Reference SHA256: '.$referenceHash);
            if (!$this->option('commit')) {
                $this->warn('READ-ONLY result. Nothing was generated. Use --commit only after database backup and staging test.');
                return self::SUCCESS;
            }
            if (!$this->option('acknowledge-20057-existing-entitlements')) {
                throw new DomainException('20057 existing historical entitlements need explicit acknowledgement.');
            }
            DB::transaction(function () use ($prepared, $actor, $referenceHash, $total) {
                $sum = 0;
                foreach ($prepared as $item) {
                    User::whereKey($item['user']->id)->lockForUpdate()->firstOrFail();
                    $this->assertNoExistingPosting($item);
                    // Validate identical saved source again after acquiring employee lock.
                    $latest = DB::table('salary_calculation_versions')->where('user_id', $item['user']->id)
                        ->where('salary_year', 2026)->where('salary_month', 10)->max('id');
                    if ((int) $latest !== (int) $item['source']['calculation_version_id']) {
                        throw new DomainException('Calculation changed after preflight; whole October release rolled back.');
                    }
                    $sum += $this->postOne($item, $actor, $referenceHash);
                }
                if ($sum !== $total || $sum !== self::EXPECTED_CENTS) {
                    throw new DomainException('Persisted October total mismatch; entire transaction rolled back.');
                }
                $billCount = DB::table('salary_generates')->where('salaryGenerationYear', 2026)
                    ->where('monthId', 10)->count();
                $linkCount = DB::table('salary_bill_source_links')->where('salary_year', 2026)
                    ->where('salary_month', 10)->count();
                if ($billCount !== 413 || $linkCount !== 413) {
                    throw new DomainException('October persisted bill/source count mismatch; rolled back.');
                }
            }, 1);
            $this->info('COMMITTED: October 2026, 413 historical salary bills GENERATED, immutable paid Net BDT '.number_format(self::amount($total), 2));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('STOP — no successful release: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
