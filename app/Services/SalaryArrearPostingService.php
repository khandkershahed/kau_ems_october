<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use DomainException;

/** Posts one approved, source-verified arrear snapshot to an existing pending salary bill. */
final class SalaryArrearPostingService
{
    private static function paisa($amount): int
    {
        if (!is_numeric($amount) || !is_finite((float) $amount)) {
            throw new DomainException('Invalid financial amount.');
        }
        return (int) round(((float) $amount) * 100, 0, PHP_ROUND_HALF_UP);
    }

    public static function summarizeLines(iterable $lines): array
    {
        $add = 0;
        $ded = 0;
        $gpf = 0;
        foreach ($lines as $line) {
            $direction = (string) $line->direction;
            $value = self::paisa($line->amount);
            if ($value < 0 || !in_array($direction, ['addition', 'deduction'], true)) {
                throw new DomainException('Invalid signed arrear source line.');
            }
            if ($direction === 'addition') $add += $value;
            else $ded += $value;
            if ($line->code === 'additional_gpf') {
                $gpf += ($direction === 'deduction' ? $value : -$value);
            }
        }
        return ['additions' => $add, 'deductions' => $ded, 'gpf' => $gpf, 'net' => $add - $ded];
    }

    /**
     * The approved source hash covers the calculated preview, not arbitrary edits to
     * salary_arrear_lines. Reconcile every persisted line before touching a bill.
     */
    public static function assertSnapshotLinesMatch(iterable $storedLines, array $expectedLines): void
    {
        $stored = is_array($storedLines) ? array_values($storedLines) : iterator_to_array($storedLines, false);
        if (count($stored) !== count($expectedLines)) {
            throw new DomainException('Arrear lines differ from the approved calculation.');
        }
        foreach ($stored as $index => $line) {
            $expected = $expectedLines[$index];
            foreach (['year', 'month'] as $field) {
                if ((int) $line->{$field} !== (int) ($expected[$field] ?? -1)) {
                    throw new DomainException('Arrear line period differs from the approved calculation.');
                }
            }
            foreach (['code', 'title', 'direction', 'source_reference'] as $field) {
                if ((string) ($line->{$field} ?? '') !== (string) ($expected[$field] ?? '')) {
                    throw new DomainException('Arrear line details differ from the approved calculation.');
                }
            }
            if (self::paisa($line->amount) !== self::paisa($expected['amount'] ?? null)) {
                throw new DomainException('Arrear line amount differs from the approved calculation.');
            }
        }
    }

    /** A rerun is idempotent only when every nonzero approved line remains intact. */
    public static function assertPostedBillItems(iterable $storedLines, array $allowances, array $deductions, int $snapshotId): void
    {
        $expected = [];
        foreach ($storedLines as $line) {
            if (self::paisa($line->amount) === 0) continue;
            $expected[(int) $line->id] = $line;
        }
        $found = [];
        foreach (['addition' => $allowances, 'deduction' => $deductions] as $direction => $items) {
            foreach ($items as $item) {
                if ((int) ($item['arrear_snapshot_id'] ?? 0) !== $snapshotId) continue;
                $lineId = (int) ($item['arrear_line_id'] ?? 0);
                if (!isset($expected[$lineId]) || isset($found[$lineId])) {
                    throw new DomainException('Posted salary bill has extra or duplicated arrear items.');
                }
                $line = $expected[$lineId];
                if ((string) $line->direction !== $direction
                    || (string) ($item['arrear_code'] ?? '') !== (string) $line->code
                    || (string) ($item['name'] ?? '') !== (string) $line->title
                    || (string) ($item['arrear_entitlement_month'] ?? '') !== sprintf('%04d-%02d', $line->year, $line->month)
                    || self::paisa($item['calculated_amount'] ?? null) !== self::paisa($line->amount)) {
                    throw new DomainException('Posted salary bill arrear item no longer matches its approved source.');
                }
                $found[$lineId] = true;
            }
        }
        if (count($found) !== count($expected)) {
            throw new DomainException('Posted salary bill is missing approved arrear items.');
        }
    }

    private function verifiedHash(object $period, int $userId, array $calculation): string
    {
        // Must remain identical to SalaryArrearController::sourceHash().
        return hash('sha256', json_encode([
            'period' => $period,
            'calculation' => $calculation,
            'manual' => DB::table('salary_arrear_manual_items')->where('period_id', $period->id)
                ->where('user_id', $userId)->orderBy('id')->get(),
            'overrides' => DB::table('salary_arrear_source_overrides')->where('period_id', $period->id)
                ->where('user_id', $userId)->orderBy('id')->get(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** Return posted vs already-posted distinctly; do not change approved or historical bills. */
    public function post(int $snapshotId, int $billId, int $actorUserId): array
    {
        return DB::transaction(function () use ($snapshotId, $billId, $actorUserId) {
            // A stable per-period lock serializes attempts across different snapshots for one employee.
            $snapshot = DB::table('salary_arrear_snapshots')->where('id', $snapshotId)->first();
            if (!$snapshot || $snapshot->status !== 'approved' || !$snapshot->approved_at) {
                throw new DomainException('HR-approved arrear snapshot is required.');
            }
            $period = DB::table('salary_arrear_periods')->where('id', $snapshot->period_id)->lockForUpdate()->first();
            if (!$period || $period->policy_status !== 'confirmed' || !$period->policy_evidence) {
                throw new DomainException('Approved retrospective entitlement policy is missing.');
            }
            $bill = DB::table('salary_generates')->where('id', $billId)->lockForUpdate()->first();
            if (!$bill || (int) $bill->user_id !== (int) $snapshot->user_id) {
                throw new DomainException('Salary bill and arrear employee do not match.');
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('salary_bill_source_links')
                && DB::table('salary_bill_source_links')->where('salary_generate_id',$billId)->exists()) {
                throw new DomainException('Phase 5 bill has a frozen salary/arrear source. Do not mutate it through the legacy arrear posting workflow.');
            }
            if (!$period->planned_payment_month || substr((string) $period->planned_payment_month, 0, 7)
                !== sprintf('%04d-%02d', (int) $bill->salaryGenerationYear, (int) $bill->monthId)) {
                throw new DomainException('Arrear period is not designated for this salary month.');
            }
            $existing = DB::table('salary_arrear_disbursements')->where('period_id', $period->id)
                ->where('user_id', $snapshot->user_id)->first();
            if ($existing) {
                if ((int) $existing->snapshot_id === $snapshotId && (int) $existing->salary_generate_id === $billId
                    && $existing->status === 'posted') {
                    $savedAdd = json_decode((string) $bill->salaryAllowanceCalcObject, true) ?: [];
                    $savedDed = json_decode((string) $bill->salaryDeductionCalcObject, true) ?: [];
                    $postedLines = DB::table('salary_arrear_lines')->where('snapshot_id', $snapshotId)
                        ->orderBy('id')->get();
                    self::assertPostedBillItems($postedLines, $savedAdd, $savedDed, $snapshotId);
                    $postedSummary = self::summarizeLines($postedLines);
                    if ($postedSummary['net'] !== self::paisa($existing->net_amount)
                        || $postedSummary['net'] !== self::paisa($snapshot->net_arrear)
                        || self::paisa($bill->totalAllowance) - self::paisa($bill->totalDeduction)
                            !== self::paisa($bill->salaryTotal)) {
                        throw new DomainException('Previously posted arrear or salary totals no longer reconcile.');
                    }
                    $postedGpf = $postedLines->filter(fn ($line) => $line->code === 'additional_gpf'
                        && self::paisa($line->amount) !== 0);
                    $ledgerRows = DB::table('salary_arrear_gpf_adjustments')
                        ->where('disbursement_id', $existing->id)->get();
                    if ($postedGpf->count() !== $ledgerRows->count()) {
                        throw new DomainException('Posted GPF ledger does not reconcile to approved arrear lines.');
                    }
                    foreach ($postedGpf as $line) {
                        $ledger = $ledgerRows->first(fn ($row) => (int) $row->entitlement_year === (int) $line->year
                            && (int) $row->entitlement_month === (int) $line->month);
                        $signed = ($line->direction === 'deduction' ? 1 : -1) * self::paisa($line->amount);
                        if (!$ledger || self::paisa($ledger->signed_amount) !== $signed
                            || (int) $ledger->snapshot_id !== $snapshotId
                            || (int) $ledger->salary_generate_id !== $billId
                            || !hash_equals((string) $snapshot->source_hash, (string) $ledger->source_hash)) {
                            throw new DomainException('Posted GPF ledger value/provenance differs from approved arrear line.');
                        }
                    }
                    return ['status' => 'already_posted', 'disbursement_id' => $existing->id];
                }
                throw new DomainException('Competing arrear disbursement exists for this employee/period.');
            }
            if ($bill->reviewStatus !== 'pending') {
                throw new DomainException('Only a pending salary bill can receive arrears.');
            }
            if (DB::table('employee_infos')->where('user_id', $snapshot->user_id)->count() !== 1) {
                throw new DomainException('Employee identity must have one unique profile.');
            }
            $calculator = app(SalaryArrearCalculator::class);
            $current = $calculator->preview($period, (int) $snapshot->user_id);
            $allowedReview = $current['status'] === 'REVIEW'
                && count(array_filter($current['warnings'], fn ($w) => !str_contains($w, 'CERTIFIED_MANUAL_PAYMENT_SOURCE'))) === 0;
            if (!($current['status'] === 'PASS' || $allowedReview)
                || !hash_equals((string) $snapshot->source_hash,
                $this->verifiedHash($period, (int) $snapshot->user_id, $current))) {
                throw new DomainException('Approved source data changed or has an unresolved evidence warning.');
            }
            $lines = DB::table('salary_arrear_lines')->where('snapshot_id', $snapshotId)->orderBy('id')->get();
            self::assertSnapshotLinesMatch($lines, $current['lines']);
            $summary = self::summarizeLines($lines);
            if ($summary['net'] !== self::paisa($snapshot->net_arrear)
                || $summary['net'] !== self::paisa($current['net_arrear'])) {
                throw new DomainException('Approved arrear lines do not reconcile to the snapshot amount.');
            }
            $additions = json_decode((string) $bill->salaryAllowanceCalcObject, true);
            $deductions = json_decode((string) $bill->salaryDeductionCalcObject, true);
            if (!is_array($additions) || !is_array($deductions)) {
                throw new DomainException('Original salary components are invalid.');
            }
            foreach (array_merge($additions, $deductions) as $item) {
                if ((int) ($item['arrear_snapshot_id'] ?? 0) === $snapshotId) {
                    throw new DomainException('This arrear snapshot is already represented in the salary bill.');
                }
            }
            $gpfRows = [];
            foreach ($lines as $line) {
                $amount = self::paisa($line->amount) / 100;
                if ($amount == 0) continue;
                $item = [
                    'name' => $line->title,
                    'amount' => $amount,
                    'calculated_amount' => $amount,
                    'calcType' => 'fixed',
                    'component_type' => $line->direction === 'addition' ? 'allowance' : 'deduction',
                    'arrear_snapshot_id' => $snapshotId,
                    'arrear_line_id' => $line->id,
                    'arrear_code' => $line->code,
                    'arrear_entitlement_month' => sprintf('%04d-%02d', $line->year, $line->month),
                    'source_reference' => $line->source_reference,
                ];
                if ($line->direction === 'addition') $additions[] = $item;
                else $deductions[] = $item;
                if ($line->code === 'additional_gpf') {
                    $gpfRows[] = [
                        'entitlement_year' => $line->year, 'entitlement_month' => $line->month,
                        'signed_amount' => ($line->direction === 'deduction' ? $amount : -$amount),
                        'evidence_reference' => $line->source_reference ?: (string) $period->policy_evidence,
                    ];
                }
            }
            $newAllowance = self::paisa($bill->totalAllowance) + $summary['additions'];
            $newDeduction = self::paisa($bill->totalDeduction) + $summary['deductions'];
            $newTotal = self::paisa($bill->salaryTotal) + $summary['net'];
            if ($newTotal < 0) {
                throw new DomainException('Arrear deductions would make net payable salary negative.');
            }
            if ($newAllowance - $newDeduction !== $newTotal) {
                throw new DomainException('Regular salary totals are inconsistent; posting cancelled.');
            }
            if (self::paisa($bill->gpfDeduction) + $summary['gpf'] < 0) {
                throw new DomainException('GPF recovery exceeds recorded contribution.');
            }
            DB::table('salary_generates')->where('id', $billId)->update([
                'salaryAllowanceCalcObject' => json_encode($additions, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'salaryDeductionCalcObject' => json_encode($deductions, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'totalAllowance' => $newAllowance / 100,
                'totalDeduction' => $newDeduction / 100,
                'salaryTotal' => $newTotal / 100,
                'gpfDeduction' => (self::paisa($bill->gpfDeduction) + $summary['gpf']) / 100,
                'updated_at' => now(),
            ]);
            $disbursementId = DB::table('salary_arrear_disbursements')->insertGetId([
                'period_id' => $period->id, 'user_id' => $snapshot->user_id,
                'snapshot_id' => $snapshotId, 'salary_generate_id' => $billId,
                'net_amount' => $summary['net'] / 100,
                'status' => 'posted', 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($gpfRows as $gpf) {
                DB::table('salary_arrear_gpf_adjustments')->insert([
                    'snapshot_id' => $snapshotId, 'disbursement_id' => $disbursementId,
                    'salary_generate_id' => $billId, 'user_id' => $snapshot->user_id,
                    'entitlement_year' => $gpf['entitlement_year'], 'entitlement_month' => $gpf['entitlement_month'],
                    'signed_amount' => $gpf['signed_amount'], 'source_hash' => $snapshot->source_hash,
                    'evidence_reference' => $gpf['evidence_reference'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('salary_arrear_reviews')->insert([
                'snapshot_id' => $snapshotId, 'actor_user_id' => $actorUserId, 'action' => 'posted_to_salary_bill',
                'note' => 'Arrear added to pending salary bill; bank transfer is not certified.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['status' => 'posted', 'disbursement_id' => $disbursementId,
                'salary_generate_id' => $billId, 'net_arrear' => $summary['net'] / 100,
                'additional_gpf' => $summary['gpf'] / 100];
        }, 3);
    }
}
