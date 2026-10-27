<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\GeneralProvidentFund;
use App\Models\SalaryGenerate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GeneralProvidentFundService
{
    /**
     * Detect any GPF, PF, or Provident fund component from an employee's deduction objects.
     */
    public function detectGpfComponent($substractionObjects): ?array
    {
        $deductions = is_string($substractionObjects)
            ? json_decode($substractionObjects, true)
            : ($substractionObjects ?? []);

        if (!is_array($deductions)) {
            return null;
        }

        foreach ($deductions as $item) {
            $name = strtolower($item['name'] ?? $item['payroll_component_type']['name'] ?? '');
            $type = strtolower($item['component_type'] ?? '');
            if (str_contains($name, 'gpf') || str_contains($name, 'provident') || str_contains($name, 'pf') || str_contains($type, 'provident')) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Calculate GPF deduction amount from basic pay based on component configuration.
     */
    public function calculateGpfDeduction(float $basicPayAmount, array $gpfComponent): float
    {
        $calcType = strtolower($gpfComponent['calcType'] ?? 'fixed');
        $rawAmt = (float) ($gpfComponent['amount'] ?? 0);

        if ($calcType === 'percentage') {
            return round($basicPayAmount * ($rawAmt / 100.0), 2);
        }

        return round($rawAmt, 2);
    }

    /**
     * Determine Bangladesh Financial/Fiscal Year string (July 1 to June 30).
     * Example: Month 7 (July) 2025 -> "2025-2026", Month 3 (March) 2026 -> "2025-2026".
     */
    public function getFinancialYear($date = null, ?int $month = null, ?int $year = null): string
    {
        if ($date) {
            $c = Carbon::parse($date);
            $month = (int) $c->format('n');
            $year = (int) $c->format('Y');
        } else {
            $month = $month ?? (int) date('n');
            $year = $year ?? (int) date('Y');
        }

        if ($month >= 7) {
            return "{$year}-" . ($year + 1);
        }

        return ($year - 1) . "-{$year}";
    }

    /**
     * Get preceding closing balance for an employee before the specified year and month.
     */
    public function getPreviousClosingBalance(int $userId, int $year, int $month): float
    {
        $lastRecord = GeneralProvidentFund::forUser($userId)
            ->where(function ($q) use ($year, $month) {
                $q->where('year', '<', $year)
                  ->orWhere(function ($sq) use ($year, $month) {
                      $sq->where('year', '=', $year)
                         ->where('month', '<', $month);
                  });
            })
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return $lastRecord ? (float) $lastRecord->closingAmount : 0.00;
    }

    /**
     * Record a GPF contribution generated during monthly salary calculation.
     */
    public function recordSalaryGpfContribution(
        User $user,
        SalaryGenerate $salaryGenerate,
        float $gpfDeduction,
        int $monthId,
        int $year,
        string $salaryDate
    ): ?GeneralProvidentFund {
        if ($gpfDeduction <= 0) {
            return null;
        }

        return DB::transaction(function () use ($user, $salaryGenerate, $gpfDeduction, $monthId, $year, $salaryDate) {
            $employeeInfo = $user->employeeInfo;
            $employeeId = (string) ($employeeInfo?->employeeCode ?? $user->id);
            $financialYear = $this->getFinancialYear($salaryDate, $monthId, $year);

            $gpfComp = $this->detectGpfComponent($employeeInfo?->substractionObjects);
            $benefitPercentage = 0.00;
            if ($gpfComp && strtolower($gpfComp['calcType'] ?? '') === 'percentage') {
                $benefitPercentage = (float) ($gpfComp['amount'] ?? 0);
            }

            $openingBalance = $this->getPreviousClosingBalance($user->id, $year, $monthId);
            $interestAmount = 0.00;
            $netDeposit = round($gpfDeduction + $interestAmount, 2);
            $closingAmount = round($openingBalance + $netDeposit, 2);

            $gpf = GeneralProvidentFund::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'month' => $monthId,
                    'year' => $year,
                    'type' => 'monthly_deduction',
                ],
                [
                    'salary_generate_id' => $salaryGenerate->id,
                    'employeeId' => $employeeId,
                    'contributionDate' => $salaryDate,
                    'generationDate' => now()->toDateString(),
                    'financialYear' => $financialYear,
                    'openingBalance' => $openingBalance,
                    'contributionAmount' => $gpfDeduction,
                    'benefitPercentage' => $benefitPercentage,
                    'interestAmount' => $interestAmount,
                    'netDeposit' => $netDeposit,
                    'closingAmount' => $closingAmount,
                    'isSettled' => false,
                    'remarks' => "Monthly salary GPF deduction for {$monthId}/{$year}",
                ]
            );

            // Sync with pivot table general_provident_fund_users
            $gpf->users()->syncWithoutDetaching([$user->id]);

            return $gpf;
        });
    }

    /**
     * Store a past / historical GPF contribution for an employee.
     * Allows entering historical records with opening balance, past financial year,
     * past month/year, contribution, interest, remarks, and audit comment.
     */
    public function storePastContribution(array $data, ?int $authUserId = null): GeneralProvidentFund
    {
        return DB::transaction(function () use ($data, $authUserId) {
            $userId = (int) $data['user_id'];
            $user = User::with('employeeInfo')->findOrFail($userId);
            $employeeId = (string) ($user->employeeInfo?->employeeCode ?? $data['employeeId'] ?? $user->id);

            $month = (int) $data['month'];
            $year = (int) $data['year'];
            $contributionDate = $data['contributionDate'] ?? "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
            $financialYear = $data['financialYear'] ?? $this->getFinancialYear($contributionDate, $month, $year);

            $openingBalance = (float) ($data['openingBalance'] ?? 0.00);
            $contributionAmount = (float) ($data['contributionAmount'] ?? 0.00);
            $interestAmount = (float) ($data['interestAmount'] ?? 0.00);
            $benefitPercentage = (float) ($data['benefitPercentage'] ?? 0.00);
            $type = $data['type'] ?? 'historical_entry';

            $netDeposit = round($contributionAmount + $interestAmount, 2);
            $closingAmount = isset($data['closingAmount']) && (float) $data['closingAmount'] > 0
                ? (float) $data['closingAmount']
                : round($openingBalance + $netDeposit, 2);

            $gpf = GeneralProvidentFund::create([
                'user_id' => $user->id,
                'employeeId' => $employeeId,
                'contributionDate' => $contributionDate,
                'generationDate' => now()->toDateString(),
                'financialYear' => $financialYear,
                'month' => $month,
                'year' => $year,
                'openingBalance' => $openingBalance,
                'contributionAmount' => $contributionAmount,
                'benefitPercentage' => $benefitPercentage,
                'interestAmount' => $interestAmount,
                'netDeposit' => $netDeposit,
                'closingAmount' => $closingAmount,
                'type' => $type,
                'isSettled' => (bool) ($data['isSettled'] ?? false),
                'settlementDate' => $data['settlementDate'] ?? null,
                'remarks' => $data['remarks'] ?? 'Historical GPF record entry',
            ]);

            // Attach user via pivot table
            $gpf->users()->syncWithoutDetaching([$user->id]);

            // If an audit comment is provided, store in comments and link via pivot
            if (!empty($data['comment'])) {
                $comment = Comment::create([
                    'user_id' => $authUserId ?? $user->id,
                    'purpose' => 'gpf_past_entry',
                    'comment' => $data['comment'],
                ]);
                $gpf->comments()->syncWithoutDetaching([$comment->id]);
            }

            return $gpf;
        });
    }

    /**
     * Retrieve complete chronological GPF ledger / personal statement for an employee,
     * including full 12-month Bangladesh financial year view and available fiscal years.
     */
    public function getUserLedger(User $user, ?string $financialYear = null): array
    {
        $allRecords = GeneralProvidentFund::forUser($user->id)
            ->with(['comments.user', 'salaryGenerate'])
            ->orderBy('year', 'asc')
            ->orderBy('month', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Available financial years from records + current financial year
        $userFys = $allRecords->pluck('financialYear')->filter()->unique()->values()->all();
        $currentFy = $this->getFinancialYear();
        $availableFys = array_values(array_unique(array_merge([$currentFy], $userFys)));
        sort($availableFys);

        // Determine target FY
        if (!empty($financialYear) && $financialYear !== 'all') {
            $targetFy = $financialYear;
        } else {
            // Pick latest FY with records, or current FY
            $targetFy = !empty($userFys) ? end($userFys) : $currentFy;
        }

        // Parse start and end years of targetFy (e.g. "2024-2025")
        $fyParts = explode('-', $targetFy);
        if (count($fyParts) === 2 && is_numeric($fyParts[0]) && is_numeric($fyParts[1])) {
            $startYear = (int) $fyParts[0];
            $endYear = (int) $fyParts[1];
        } else {
            $startYear = is_numeric($targetFy) ? (int) $targetFy : (int) date('Y');
            $endYear = $startYear + 1;
        }

        // 12 months of Bangladesh fiscal year: July (7) of $startYear to June (6) of $endYear
        $fiscalMonthsMeta = [
            1 => ['month' => 7, 'year' => $startYear, 'name' => 'July'],
            2 => ['month' => 8, 'year' => $startYear, 'name' => 'August'],
            3 => ['month' => 9, 'year' => $startYear, 'name' => 'September'],
            4 => ['month' => 10, 'year' => $startYear, 'name' => 'October'],
            5 => ['month' => 11, 'year' => $startYear, 'name' => 'November'],
            6 => ['month' => 12, 'year' => $startYear, 'name' => 'December'],
            7 => ['month' => 1, 'year' => $endYear, 'name' => 'January'],
            8 => ['month' => 2, 'year' => $endYear, 'name' => 'February'],
            9 => ['month' => 3, 'year' => $endYear, 'name' => 'March'],
            10 => ['month' => 4, 'year' => $endYear, 'name' => 'April'],
            11 => ['month' => 5, 'year' => $endYear, 'name' => 'May'],
            12 => ['month' => 6, 'year' => $endYear, 'name' => 'June'],
        ];

        // Filter records for this target financial year
        $fyRecords = $allRecords->filter(function ($r) use ($targetFy, $startYear, $endYear) {
            if ($r->financialYear === $targetFy) return true;
            if ($r->year === $startYear && $r->month >= 7) return true;
            if ($r->year === $endYear && $r->month <= 6) return true;
            return false;
        })->values();

        // Index fyRecords by "month_year" for fast lookup
        $recordsByMonthYear = [];
        foreach ($fyRecords as $r) {
            $recordsByMonthYear["{$r->month}_{$r->year}"] = $r;
        }

        $fyMonths = [];
        $runningBalance = 0.00;
        $firstRecordFound = false;
        $fyOpeningBalance = 0.00;

        foreach ($fiscalMonthsMeta as $seq => $meta) {
            $m = $meta['month'];
            $y = $meta['year'];
            $mName = $meta['name'];
            $key = "{$m}_{$y}";

            if (isset($recordsByMonthYear[$key])) {
                $rec = $recordsByMonthYear[$key];
                if (!$firstRecordFound) {
                    $fyOpeningBalance = (float) $rec->openingBalance;
                    $firstRecordFound = true;
                }
                $runningBalance = (float) $rec->closingAmount;

                $fyMonths[] = [
                    'sl' => $seq,
                    'month' => $m,
                    'year' => $y,
                    'month_name' => $mName,
                    'period' => "{$mName} {$y}",
                    'has_record' => true,
                    'record_id' => $rec->id,
                    'contribution_date' => $rec->contributionDate?->format('Y-m-d'),
                    'opening_balance' => (float) $rec->openingBalance,
                    'contribution_amount' => (float) $rec->contributionAmount,
                    'interest_amount' => (float) $rec->interestAmount,
                    'net_deposit' => (float) $rec->netDeposit,
                    'closing_amount' => (float) $rec->closingAmount,
                    'type' => $rec->type,
                    'type_label' => $rec->type === 'historical_entry' ? 'Historical' : 'Salary Bill',
                    'is_settled' => (bool) $rec->isSettled,
                    'remarks' => $rec->remarks,
                    'comments' => $rec->comments->map(function ($c) {
                        return [
                            'id' => $c->id,
                            'comment' => $c->comment,
                            'purpose' => $c->purpose ?? 'gpf_note',
                            'author' => $c->user?->name ?? 'System User',
                            'author_email' => $c->user?->email,
                            'created_at' => $c->created_at ? $c->created_at->format('d M Y, h:i A') : '',
                        ];
                    })->values()->all(),
                ];
            } else {
                $fyMonths[] = [
                    'sl' => $seq,
                    'month' => $m,
                    'year' => $y,
                    'month_name' => $mName,
                    'period' => "{$mName} {$y}",
                    'has_record' => false,
                    'record_id' => null,
                    'contribution_date' => null,
                    'opening_balance' => $runningBalance,
                    'contribution_amount' => 0.00,
                    'interest_amount' => 0.00,
                    'net_deposit' => 0.00,
                    'closing_amount' => $runningBalance,
                    'type' => null,
                    'type_label' => 'Not Contributed',
                    'is_settled' => false,
                    'remarks' => '—',
                    'comments' => [],
                ];
            }
        }

        // Summary calculations
        $activeRecords = (!empty($financialYear) && $financialYear !== 'all') ? $fyRecords : $allRecords;
        $totalContribution = (float) $activeRecords->sum('contributionAmount');
        $totalInterest = (float) $activeRecords->sum('interestAmount');
        $totalNetDeposit = (float) $activeRecords->sum('netDeposit');

        $latestOverallRecord = $allRecords->last();
        $currentBalance = $latestOverallRecord ? (float) $latestOverallRecord->closingAmount : 0.00;
        $isSettled = $latestOverallRecord ? (bool) $latestOverallRecord->isSettled : false;

        $fyClosingBalance = $fyRecords->isNotEmpty() ? (float) $fyRecords->last()->closingAmount : $runningBalance;

        // Phase 5 supplementary arrear GPF is journaled separately and must
        // never be silently absorbed into historical closing balances.
        $supplementaryGpf=[];
        if (\Illuminate\Support\Facades\Schema::hasTable('salary_bill_arrear_gpf_entries')) {
            $supplementaryGpf=\Illuminate\Support\Facades\DB::table('salary_bill_arrear_gpf_entries')
                ->where('user_id',$user->id)
                ->where(function($query)use($startYear,$endYear){
                    $query->where(fn($q)=>$q->where('pay_year',$startYear)->where('pay_month','>=',7))
                        ->orWhere(fn($q)=>$q->where('pay_year',$endYear)->where('pay_month','<=',6));
                })->orderBy('pay_year')->orderBy('pay_month')->get()
                ->map(fn($r)=>[
                    'id'=>$r->id,'bill_id'=>$r->salary_generate_id,
                    'pay_year'=>$r->pay_year,'pay_month'=>$r->pay_month,
                    'amount'=>(float)$r->signed_amount,
                    'entitlement_months'=>json_decode((string)$r->entitlement_months,true)??[],
                    'evidence_reference'=>$r->evidence_reference,
                    'reverses_entry_id'=>$r->reverses_entry_id,
                ])->all();
        }
        return [
            'supplementary_arrear_gpf'=>$supplementaryGpf,
            'supplementary_arrear_gpf_total'=>round(array_sum(array_column($supplementaryGpf,'amount')),2),
            'supplementary_gpf_reconciliation_status'=>'SEPARATE_JOURNAL_NOT_INCLUDED_IN_HISTORICAL_CLOSING',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'employee_code' => $user->employeeInfo?->employeeCode ?? 'N/A',
                'designation' => $user->employeeInfo?->payrollGroup?->payrollGroupType?->group_name ?? 'N/A',
                'department' => $user->employeeInfo?->departmentOffice?->name ?? 'N/A',
                'joining_date' => $user->joiningDate?->joining_date ?? 'N/A',
            ],
            'financial_year' => $targetFy,
            'available_financial_years' => $availableFys,
            'fy_months' => $fyMonths,
            'summary' => [
                'total_records' => $activeRecords->count(),
                'fy_opening_balance' => $fyOpeningBalance,
                'total_contribution' => $totalContribution,
                'total_interest' => $totalInterest,
                'total_net_deposit' => $totalNetDeposit,
                'fy_closing_balance' => $fyClosingBalance,
                'current_balance' => $currentBalance,
                'is_settled' => $isSettled,
                'settlement_date' => $latestOverallRecord?->settlementDate?->format('Y-m-d'),
            ],
            'records' => $allRecords->map(function ($r) {
                return [
                    'id' => $r->id,
                    'employee_id' => $r->employeeId,
                    'financial_year' => $r->financialYear,
                    'month' => $r->month,
                    'year' => $r->year,
                    'contribution_date' => $r->contributionDate?->format('Y-m-d'),
                    'opening_balance' => (float) $r->openingBalance,
                    'contribution_amount' => (float) $r->contributionAmount,
                    'benefit_percentage' => (float) $r->benefitPercentage,
                    'interest_amount' => (float) $r->interestAmount,
                    'net_deposit' => (float) $r->netDeposit,
                    'closing_amount' => (float) $r->closingAmount,
                    'type' => $r->type,
                    'is_settled' => (bool) $r->isSettled,
                    'settlement_date' => $r->settlementDate?->format('Y-m-d'),
                    'remarks' => $r->remarks,
                    'comments' => $r->comments->map(function ($c) {
                        return [
                            'id' => $c->id,
                            'comment' => $c->comment,
                            'purpose' => $c->purpose ?? 'gpf_note',
                            'author' => $c->user?->name ?? 'System User',
                            'author_email' => $c->user?->email,
                            'created_at' => $c->created_at ? $c->created_at->format('d M Y, h:i A') : '',
                        ];
                    })->values()->all(),
                ];
            }),
        ];
    }

    /**
     * Recalculate chronological running balances (opening & closing amounts) for an employee.
     * Ensures integrity across historical archive entries and newly added records.
     */
    public function recalculateUserBalances(int $userId): void
    {
        $records = GeneralProvidentFund::forUser($userId)
            ->orderBy('year', 'asc')
            ->orderBy('month', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $runningBalance = 0.00;
        foreach ($records as $record) {
            $opening = $runningBalance;
            $interest = (float) ($record->interestAmount ?? 0.00);
            $contribution = (float) ($record->contributionAmount ?? 0.00);
            $netDeposit = round($contribution + $interest, 2);
            $closing = round($opening + $netDeposit, 2);
            $runningBalance = $closing;

            if (abs((float) $record->openingBalance - $opening) > 0.001 ||
                abs((float) $record->netDeposit - $netDeposit) > 0.001 ||
                abs((float) $record->closingAmount - $closing) > 0.001) {
                $record->openingBalance = $opening;
                $record->netDeposit = $netDeposit;
                $record->closingAmount = $closing;
                $record->save();
            }
        }
    }
}
