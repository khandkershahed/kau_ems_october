<?php

namespace App\Services;

use App\Support\SalaryOldScaleIncrementReference;
use DomainException;

/**
 * Step 05: pure pay-scale entitlement reconciliation (no DB, posting or role gates).
 * A 2015-stage reconstruction is an expected old-scale entitlement, NOT proof of payment.
 * Each entitlement month is independently reconciled and unknown payments remain null.
 */
final class SalaryPayScaleArrearEngine
{
    public const MONTHS = ['2026-07', '2026-08', '2026-09'];

    private static function cents(mixed $amount, string $label): int
    {
        if (!is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0) {
            throw new DomainException('Missing or invalid '.$label.'; UNKNOWN is not a verified zero.');
        }
        return (int) round((float) $amount * 100, 0, PHP_ROUND_HALF_UP);
    }

    public static function oldScaleIncrement(array $stages2015, mixed $juneBasic, array $eligibility): array
    {
        $june = self::cents($juneBasic, 'June 2015-scale Basic');
        $category = strtolower((string) ($eligibility['special_eligibility_category'] ?? ''));
        $hold = ($eligibility['hold_increment'] ?? false) === true
            || in_array($category, ['adhoc', 'increment_hold', 'study_leave_restricted'], true);
        $allowed = ($eligibility['increment_2026'] ?? null) === 'allow'
            || ($eligibility['first_increment_exception'] ?? false) === true
            || ($eligibility['study_leave_increment_allowed'] ?? false) === true;
        if ($category === 'adhoc' || ($hold && !$allowed)) {
            return ['amount' => 0.0, 'old_scale_july_basic' => $june / 100,
                'status' => 'ELIGIBILITY_NO_INCREMENT', 'payment_verified' => false];
        }
        $reference = SalaryOldScaleIncrementReference::nextStageDifference($stages2015, $june / 100);
        if (!$reference) {
            return ['amount' => null, 'old_scale_july_basic' => null,
                'status' => 'OLD_SCALE_STAGE_REFERENCE_MISSING', 'payment_verified' => false];
        }
        return ['amount' => $reference['candidate_increment'],
            'old_scale_july_basic' => $reference['next_2015_stage'],
            'status' => 'RECONSTRUCTED_2015_STAGE_NOT_PAYMENT',
            'payment_verified' => false, 'stage_reference' => $reference];
    }

    /**
     * Expected phased basic is a reference check only. Approved fixation schedules
     * determine the actual 2026 entitlement; no increment is added twice here.
     */
    public static function phasedReference(int $grade, string $month, mixed $oldBasic, mixed $fullNewBasic): array
    {
        if ($grade < 1 || $grade > 20 || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new DomainException('Invalid grade or salary month.');
        }
        $old = self::cents($oldBasic, '2015 Basic');
        $new = self::cents($fullNewBasic, '2026 full Basic');
        if ($month < '2026-07') return ['amount' => $old / 100, 'rate' => 0];
        $rate = $month < '2027-01' ? ($grade <= 9 ? 40 : 50)
            : ($month < '2027-07' ? ($grade <= 9 ? 70 : 75) : 100);
        return ['amount' => round(($old + ($new - $old) * $rate / 100) / 100, 2, PHP_ROUND_HALF_UP),
            'rate' => $rate, 'status' => 'REFERENCE_ONLY_APPROVED_FIXATION_TAKES_PRIORITY'];
    }

    /** @param array<string,array<string,mixed>> $months keyed YYYY-MM */
    public static function reconcile(array $months): array
    {
        if (array_keys($months) !== self::MONTHS) {
            throw new DomainException('Expected exactly July, August and September 2026 in chronological order.');
        }
        $totals = ['basic_gross' => 0, 'old_increment' => 0, 'basic_net' => 0,
            'special_recovery' => 0, 'house_adjustment' => 0, 'additional_gpf' => 0];
        $details = [];
        $missing = [];
        foreach ($months as $period => $source) {
            $june = self::cents($source['june_basic'] ?? null, $period.' June Basic');
            $entitled = self::cents($source['entitled_basic'] ?? null, $period.' phased entitlement');
            $old = self::cents($source['old_scale_basic'] ?? null, $period.' reconstructed old Basic');
            $gross = $entitled - $june;
            $increment = $old - $june;
            if ($increment < 0) throw new DomainException($period.': old-scale Basic fell below June; employee-specific grade/leave reconciliation required.');
            $basicNet = $gross - $increment;
            if ($basicNet !== $entitled - $old) throw new DomainException('Old increment deducted twice.');
            $row = [
                'basic_gross' => (float) ($gross / 100),
                'old_increment' => (float) ($increment / 100),
                'basic_net' => (float) ($basicNet / 100),
                'old_basic_source' => $source['old_basic_source'] ?? 'PAY_SCALE_RECONSTRUCTED_NOT_PAID',
                'payment_verified' => ($source['paid_basic_verified'] ?? false) === true,
            ];
            foreach ([
                'special_recovery' => ['paid_special_benefit'],
                'house_adjustment' => ['entitled_house_rent', 'paid_house_rent'],
                'additional_gpf' => ['required_gpf', 'paid_gpf'],
            ] as $component => $fields) {
                $values = [];
                foreach ($fields as $field) {
                    if (!array_key_exists($field, $source) || $source[$field] === null) {
                        $missing[] = $period.':'.$field;
                        $values = null;
                        break;
                    }
                    $values[] = self::cents($source[$field], $period.' '.$field);
                }
                $row[$component] = $values === null ? null : match ($component) {
                    'special_recovery' => (float) ($values[0] / 100),
                    'house_adjustment' => (float) (($values[0] - $values[1]) / 100),
                    'additional_gpf' => (float) (($values[0] - $values[1]) / 100),
                };
                if ($component === 'additional_gpf' && $row[$component] !== null && $row[$component] < 0) {
                    $missing[] = $period.':negative_additional_gpf_needs_refund_policy';
                    $row[$component] = null; // Never create an automatic GPF refund.
                }
            }
            foreach ($totals as $key => $_) {
                if ($row[$key] !== null) $totals[$key] += (int) round($row[$key] * 100);
            }
            $details[$period] = $row;
        }
        $total = array_map(static fn (int $cents): float => round($cents / 100, 2), $totals);
        $net = $missing ? null : round($total['basic_net'] - $total['special_recovery']
            + $total['house_adjustment'] - $total['additional_gpf'], 2, PHP_ROUND_HALF_UP);
        $paymentsVerified = !$missing
            && count(array_filter($details, static fn (array $row): bool => $row['payment_verified'])) === count($details)
            && count(array_filter($months, static fn (array $row): bool =>
                ($row['paid_benefits_verified'] ?? false) === true
                && ($row['paid_house_rent_verified'] ?? false) === true
                && ($row['paid_gpf_verified'] ?? false) === true)) === count($months);
        return ['months' => $details, 'totals' => $total, 'net_arrear' => $net,
            'status' => $missing ? 'PROVISIONAL_MISSING_PAYMENT_EVIDENCE'
                : ($paymentsVerified ? 'VERIFIED_RECONCILIATION' : 'ESTIMATED_RECONCILIATION'),
            'missing_evidence' => array_values(array_unique($missing)),
            'payment_reconciliation_complete' => $paymentsVerified];
    }

    /** Signed House Rent: positive = payable, negative = recovery deduction. */
    public static function directionalHouseRent(float $difference): array
    {
        if (!is_finite($difference)) throw new DomainException('Invalid House Rent difference.');
        return ['direction' => $difference > 0 ? 'payable' : 'deduction',
            'amount' => abs(round($difference, 2, PHP_ROUND_HALF_UP))];
    }
}
