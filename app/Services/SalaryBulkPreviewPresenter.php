<?php

namespace App\Services;

use DomainException;

/** Read-only presentation of the authoritative employee/month composition. */
final class SalaryBulkPreviewPresenter
{
    private static function money(mixed $value): float
    {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new DomainException('Missing/invalid bulk preview amount.');
        }
        return round((float) $value, 2, PHP_ROUND_HALF_UP);
    }

    public static function present(int $userId, array $source): array
    {
        if (($source['user_id'] ?? null) !== $userId || !preg_match('/^[a-f0-9]{64}$/', (string) ($source['source_hash'] ?? ''))) {
            throw new DomainException('Bulk preview identity or source hash mismatch.');
        }
        $statement = $source['statement'] ?? null;
        if (!is_array($statement) || !empty($statement['provisional']) || !empty($statement['unknown_arrears'])) {
            throw new DomainException('Arrear evidence incomplete: preview amount remains UNKNOWN.');
        }
        $summary = $statement['summary'] ?? [];
        $current = $summary['current'] ?? [];
        $arrear = $summary['arrear'] ?? [];
        $total = $summary['total'] ?? [];
        $currentNet = self::money($current['net'] ?? null);
        $arrearNet = self::money($arrear['net'] ?? null);
        $totalNet = self::money($total['net'] ?? null);
        if (abs($totalNet - ($currentNet + $arrearNet)) > .009 ||
            abs($totalNet - self::money($source['net_payable'] ?? null)) > .009) {
            throw new DomainException('Single/Bulk net salary reconciliation mismatch.');
        }
        $rows = array_merge($source['regular_additions'] ?? [], $source['regular_deductions'] ?? []);
        $manual = false;
        foreach ($rows as $row) {
            if (($row['component_source'] ?? null) === 'employee_month_override' || isset($row['manual_adjustment_id'])) {
                $manual = true;
                break;
            }
        }
        return [
            'user_id'=>$userId, 'employee_code'=>(string) ($source['employee_code'] ?? ''),
            'name'=>(string) ($source['name'] ?? ''), 'grade'=>(string) ($source['grade'] ?? ''),
            'designation'=>(string) ($source['designation'] ?? ''),
            'basic'=>self::money($source['regular']['basic'] ?? null),
            'regular_gross'=>self::money($current['gross'] ?? null),
            'regular_deductions'=>self::money($current['deductions'] ?? null),
            'regular_net'=>$currentNet,
            'arrear_payable'=>self::money($arrear['gross'] ?? null),
            'arrear_deductions'=>self::money($arrear['deductions'] ?? null),
            'arrear_net'=>$arrearNet, 'net_payable'=>$totalNet,
            'source_hash'=>(string)$source['source_hash'],
            'calculation_version_id'=>$source['calculation_version_id'] ?? null,
            'manual_override'=>$manual,
            'calculation_status'=>$manual ? 'manual_override' : 'calculated',
            'status'=>'ready','warnings'=>[],
        ];
    }

    public static function failure(int $userId, string $message): array
    {
        $unknown = preg_match('/unknown|missing|unverified|unavailable|ambiguous|evidence|not verified|no approved|not published/i', $message) === 1;
        return [
            'user_id'=>$userId, 'status'=>$unknown ? 'missing_evidence' : 'blocked',
            'calculation_status'=>$unknown ? 'missing_evidence' : 'error',
            'reason'=>$message, 'warnings'=>[$message], 'source_hash'=>null,
            'net_payable'=>null, 'regular_net'=>null, 'arrear_net'=>null,
        ];
    }
}
