<?php

namespace App\Services;

use App\Models\SalaryGenerate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 07: reads immutable posted salary data only. NO pay-scale, current
 * component, fixation or GPF recalculation and NO financial database writes.
 */
final class SalaryOfficialReportSource
{
    private static function money(mixed $value, string $field): int
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
            throw new DomainException('Invalid posted monetary value: '.$field);
        }
        return (int) round((float) $value * 100, 0, PHP_ROUND_HALF_UP);
    }

    private static function entries(mixed $payload, string $direction): array
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (!is_array($payload)) {
            throw new DomainException('Posted '.$direction.' component source is not readable.');
        }
        $rows = [];
        foreach ($payload as $item) {
            if (!is_array($item)) throw new DomainException('Malformed posted component row.');
            $title = trim((string) ($item['name'] ?? $item['payroll_component_type']['name'] ?? ''));
            if ($title === '') throw new DomainException('Posted component is missing a title.');
            // An old percentage *rate* is not a saved monetary amount. Do not
            // infer a payment from today's basic or designation rules.
            $raw = $item['calculated_amount'] ?? (strtolower((string) ($item['calcType'] ?? 'fixed')) === 'percentage' ? null : ($item['amount'] ?? null));
            $cents = $raw === null ? null : self::money($raw, $title);
            $isArrear = !empty($item['arrear_phase5']) || !empty($item['arrear_snapshot_id']);
            $rows[] = [
                'title' => $title,
                'direction' => $direction,
                'current' => $isArrear ? 0.0 : ($cents === null ? null : $cents / 100.0),
                'arrear' => $isArrear ? ($cents === null ? null : $cents / 100.0) : 0.0,
                'total' => $cents === null ? null : $cents / 100.0,
                'code' => $item['arrear_code'] ?? null,
                'months' => array_values($item['arrear_entitlement_months'] ?? []),
                'evidence' => $item['arrear_source_reference'] ?? null,
                'unverified' => $cents === null,
            ];
        }
        return $rows;
    }

    private static function sumRows(array $rows): ?int
    {
        $sum = 0;
        foreach ($rows as $row) {
            if ($row['total'] === null) return null;
            $sum += self::money($row['total'], 'component total');
        }
        return $sum;
    }

    /**
     * @return array<string,mixed>
     */
    public function fromPostedBill(SalaryGenerate $bill): array
    {
        $link = null;
        $snapshot = null;
        if (Schema::hasTable('salary_bill_source_links')) {
            $links = DB::table('salary_bill_source_links')->where('salary_generate_id', $bill->id)->limit(2)->get();
            if ($links->count() > 1) throw new DomainException('Duplicate posted salary source links.');
            $link = $links->first();
            if ($link) {
                if ((int) $link->user_id !== (int) $bill->user_id
                    || (int) $link->salary_month !== (int) $bill->monthId
                    || (int) $link->salary_year !== (int) $bill->salaryGenerationYear) {
                    throw new DomainException('Posted bill and source-link employee/period do not match.');
                }
                $snapshot = json_decode((string) $link->source_snapshot, true);
                if (!is_array($snapshot)) {
                    throw new DomainException('Posted source snapshot is not readable.');
                }
                if (($snapshot['calculation_method'] ?? null) === 'REGULAR_ONLY_PREVIEW') {
                    // October bulk writer freezes a regular-only preview whose
                    // calculation_source_hash covers the payload BEFORE the hash
                    // field is appended. Verify that exact legacy writer format.
                    $embedded = (string) ($snapshot['calculation_source_hash'] ?? '');
                    $payload = $snapshot;
                    unset($payload['calculation_source_hash']);
                    $actual = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    if ($embedded === '' || !hash_equals((string) $link->source_hash, $embedded)
                        || !hash_equals($embedded, $actual)) {
                        throw new DomainException('Frozen regular-only source snapshot hash mismatch.');
                    }
                    if ((int) ($snapshot['user_id'] ?? 0) !== (int) $bill->user_id
                        || (int) ($snapshot['year'] ?? 0) !== (int) $bill->salaryGenerationYear
                        || (int) ($snapshot['month'] ?? 0) !== (int) $bill->monthId) {
                        throw new DomainException('Regular-only frozen source employee/period mismatch.');
                    }
                } else {
                    if (!hash_equals((string) $link->source_hash, (string) ($snapshot['source_hash'] ?? ''))) {
                        throw new DomainException('Posted source snapshot hash reference is missing or mismatched.');
                    }
                    $original = $snapshot;
                    unset($original['source_hash'], $original['statement']);
                    $actual = hash('sha256', json_encode($original, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    if (!hash_equals((string) $link->source_hash, $actual)) {
                        throw new DomainException('Posted salary financial snapshot content was modified.');
                    }
                }
            }
        }

        $allowances = self::entries($bill->salaryAllowanceCalcObject, 'allowance');
        $deductions = self::entries($bill->salaryDeductionCalcObject, 'deduction');
        $gross = self::money($bill->totalAllowance, 'posted gross');
        $ded = self::money($bill->totalDeduction, 'posted deductions');
        $net = self::money($bill->salaryTotal, 'posted net salary');
        if ($gross - $ded !== $net) throw new DomainException('Posted bill gross/deduction/net do not reconcile.');
        $allowSum = self::sumRows($allowances);
        $dedSum = self::sumRows($deductions);
        if (($allowSum !== null && $allowSum !== $gross) || ($dedSum !== null && $dedSum !== $ded)) {
            throw new DomainException('Frozen posted component lines do not match the saved salary totals.');
        }

        $basic = null;
        foreach ($allowances as $line) {
            if (preg_match('/\bbasic\b/i', $line['title'])) {
                if ($basic !== null) throw new DomainException('Duplicate saved Basic Pay component.');
                $basic = $line['current'];
            }
        }
        $grossCurrent = $grossArrear = $dedCurrent = $dedArrear = null;
        if ($allowSum !== null && $dedSum !== null) {
            $grossArrear = array_sum(array_map(static fn ($r) => $r['arrear'] ?? 0, $allowances));
            $dedArrear = array_sum(array_map(static fn ($r) => $r['arrear'] ?? 0, $deductions));
            $grossCurrent = $gross / 100.0 - $grossArrear;
            $dedCurrent = $ded / 100.0 - $dedArrear;
        }
        if ($snapshot) {
            $expectedFields = ($snapshot['calculation_method'] ?? null) === 'REGULAR_ONLY_PREVIEW'
                ? ['regular_gross' => $gross, 'regular_deductions' => $ded, 'regular_net' => $net]
                : ['gross' => $gross, 'total_deduction' => $ded, 'net_payable' => $net];
            foreach ($expectedFields as $key => $expected) {
                if (!array_key_exists($key, $snapshot) || self::money($snapshot[$key], $key) !== $expected) {
                    throw new DomainException('Saved posted bill and source snapshot differ: '.$key);
                }
            }
            // A source statement may be used as provenance, but the itemized saved
            // salary bill is the official printed amount. Never re-run today's engine.
        }
        $name = (string) $bill->name;
        $user = $bill->user;
        $department = $user?->employeeInfo?->departmentOffice?->name;
        $arrearRows = [];
        if ($snapshot && is_array($snapshot['arrear_rows'] ?? null)) {
            foreach ($snapshot['arrear_rows'] as $item) {
                if (!is_array($item)) continue;
                $arrearRows[] = [
                    'title' => (string) ($item['title'] ?? 'Arrear Adjustment'),
                    'code' => (string) ($item['code'] ?? ''),
                    'direction' => (string) ($item['direction'] ?? ''),
                    'total' => is_numeric($item['final_amount'] ?? null) ? (float) $item['final_amount'] : null,
                    'months' => array_values($item['months'] ?? []),
                    'evidence' => (string) ($item['evidence_reference'] ?? ''),
                    'source_state' => (string) ($item['source_state'] ?? 'SAVED_POSTED_AMOUNT'),
                    // Without a frozen breakdown, a 3-month aggregate cannot
                    // responsibly be split into three equal fictional payments.
                    'month_amounts' => is_array($item['month_amounts'] ?? null) ? $item['month_amounts'] : null,
                ];
            }
        } else {
            foreach (array_merge($allowances, $deductions) as $r) {
                if (!$r['months'] && $r['arrear'] === 0.0) continue;
                if ($r['arrear'] === null || $r['arrear'] === 0.0) continue;
                $arrearRows[] = [
                    'title' => $r['title'], 'code' => (string) ($r['code'] ?? ''),
                    'direction' => $r['direction'] === 'allowance' ? 'payable' : 'deduction',
                    'total' => $r['arrear'], 'months' => $r['months'],
                    'evidence' => (string) ($r['evidence'] ?? ''),
                    'source_state' => 'SAVED_POSTED_AMOUNT', 'month_amounts' => null,
                ];
            }
        }
        return [
            'id' => (int) $bill->id,
            'user_id' => (int) $bill->user_id,
            'employee_code' => (string) $bill->employeeCode,
            'name' => $name,
            'designation' => (string) $bill->designation,
            'department' => $department ?: 'Current department unavailable',
            'department_is_current' => true,
            'grade' => (string) $bill->grade,
            'pay_scale' => (string) $bill->payScale,
            'bank_account_no' => (string) $bill->bankAccountNo,
            'bank_address' => (string) $bill->bankAddress,
            'year' => (int) $bill->salaryGenerationYear,
            'month' => (int) $bill->monthId,
            'basic' => $basic,
            'allowances' => $allowances,
            'deductions' => $deductions,
            'arrear_rows' => $arrearRows,
            'current_gross' => $grossCurrent,
            'current_deductions' => $dedCurrent,
            'current_net' => $grossCurrent === null ? null : round($grossCurrent - $dedCurrent, 2),
            'arrear_payable' => $grossArrear,
            'arrear_deductions' => $dedArrear,
            'net_arrear' => $grossArrear === null ? null : round($grossArrear - $dedArrear, 2),
            'gross' => $gross / 100.0,
            'total_deductions' => $ded / 100.0,
            'net' => $net / 100.0,
            'source' => $link ? 'FROZEN_SOURCE_SNAPSHOT' : 'LEGACY_POSTED_BILL',
            'source_hash' => $link?->source_hash,
            'details_complete' => $allowSum !== null && $dedSum !== null,
            'remarks' => $allowSum === null || $dedSum === null
                ? 'Legacy percentage entries have no frozen calculated amount; component details are UNKNOWN, saved totals remain authoritative.'
                : '',
        ];
    }
}
