<?php

namespace App\Services;

/**
 * Read-only bridge to EXISTING audited salary_arrear_source_overrides.
 * A value is financial evidence only when it has been explicitly certified,
 * referenced, and does not conflict with an approved historical salary bill.
 * No assumed zero, no 2028 rates, and no payroll/GPF writes.
 */
final class SalaryStructuredArrearEvidenceOverlay
{
    private const FIELDS = [
        'paid_special_benefit' => ['paid_months', 'paid_special_benefit'],
        'paid_house_rent' => ['paid_months', 'paid_house_rent'],
        'paid_gpf' => ['paid_months', 'paid_gpf'],
        'revised_house_rent' => ['financial_months', 'entitled_house_rent'],
        'revised_gpf' => ['financial_months', 'required_gpf'],
    ];

    /** @param array<int,object|array<string,mixed>> $rows */
    public static function apply(array $evidence, array $rows): array
    {
        $seen = [];
        foreach ($rows as $raw) {
            $row = (array) $raw;
            $period = sprintf('%04d-%02d', (int) ($row['year'] ?? 0), (int) ($row['month'] ?? 0));
            $field = (string) ($row['field'] ?? '');
            if (!in_array($period, SalaryPayScaleArrearEngine::MONTHS, true)
                || !array_key_exists($field, self::FIELDS)) continue;
            [$group, $key] = self::FIELDS[$field];
            $identity = $period.':'.$field;
            $previous = $evidence[$group][$period][$key] ?? null;
            if (isset($seen[$identity])) {
                // Ambiguous across multiple arrear periods: fail closed.
                unset($evidence[$group][$period][$key]);
                $evidence['source_conflicts'][] = $identity.':DUPLICATE_CERTIFICATION';
                $seen[$identity]++;
                continue;
            }
            $seen[$identity] = 1;
            if ((int) ($row['certified'] ?? 0) !== 1
                || trim((string) ($row['evidence_reference'] ?? '')) === ''
                || !is_numeric($row['amount'] ?? null)
                || !is_finite((float) ($row['amount'] ?? null))
                || (float) ($row['amount'] ?? null) < 0) {
                // An uncertified draft must not override an approved payroll value.
                $evidence['uncertified_sources'][] = $identity;
                continue;
            }
            $value = round((float) $row['amount'], 2, PHP_ROUND_HALF_UP);
            if ($previous !== null && round((float)$previous, 2, PHP_ROUND_HALF_UP) !== $value) {
                unset($evidence[$group][$period][$key]);
                $evidence['source_conflicts'][] = $identity.':APPROVED_HISTORY_MISMATCH';
                continue;
            }
            $evidence[$group][$period][$key] = $value;
            $evidence['certified_source_references'][$period][$field] = [
                'source_override_id' => (int) ($row['id'] ?? 0),
                'evidence_reference' => (string)$row['evidence_reference'],
            ];
            if ($group === 'paid_months' && $previous === null) {
                $evidence['paid_months'][$period]['source_type'] = 'certified_arrear_source_override';
                $evidence['paid_months'][$period]['source_record_id'] = (int) ($row['id'] ?? 0);
            }
        }
        return $evidence;
    }
}
