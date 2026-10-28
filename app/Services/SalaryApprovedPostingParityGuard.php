<?php
namespace App\Services;

use DomainException;
use Illuminate\Support\Facades\DB;

/** Approval-to-posting financial parity. Called INSIDE the employee-locked posting transaction. */
final class SalaryApprovedPostingParityGuard
{
    private static function cents(mixed $amount): int
    {
        if (!is_numeric($amount) || !is_finite((float)$amount)) {
            throw new DomainException('Approved payroll snapshot contains an invalid monetary amount.');
        }
        return (int)round((float)$amount * 100, 0, PHP_ROUND_HALF_UP);
    }

    /** Public pure assertion allows testing monetary parity without a database. */
    public static function assertAmounts(array $regular, array $arrear, array $composed): void
    {
        if (!array_key_exists('net_salary', $regular) || !array_key_exists('net_arrear', $arrear)
            || $arrear['net_arrear'] === null) {
            throw new DomainException('Fully resolved approved regular and arrear amounts are required.');
        }
        foreach (['basic', 'total_allowance', 'total_deduction', 'gpf_deduction', 'net_salary'] as $key) {
            if (!array_key_exists($key, $regular) || !array_key_exists($key, $composed['regular'] ?? [])) {
                throw new DomainException('Approved payroll calculation is missing required financial data: '.$key);
            }
            if (self::cents($regular[$key]) !== self::cents($composed['regular'][$key])) {
                throw new DomainException('Approved regular salary differs from current posting amount: '.$key);
            }
        }
        if (self::cents($arrear['net_arrear']) !==
            self::cents($composed['arrear_payable'] ?? null) - self::cents($composed['arrear_deduction'] ?? null)) {
            throw new DomainException('Approved arrear total differs from current posting amount.');
        }
        if (self::cents($regular['net_salary']) + self::cents($arrear['net_arrear']) !==
            self::cents($composed['net_payable'] ?? null)) {
            throw new DomainException('Approved net payable differs from current posting amount.');
        }
    }

    public function assertReadySource(object $workflow, array $composed, int $userId, int $year, int $month): void
    {
        $versionId = (int)($workflow->ready_calculation_version_id ?? 0);
        if (!$versionId || (int)($composed['calculation_version_id'] ?? 0) !== $versionId) {
            throw new DomainException('Posting composition is not linked to the currently approved calculation version.');
        }
        $version = DB::table('salary_calculation_versions')
            ->where('id', $versionId)->where('user_id', $userId)
            ->where('salary_year', $year)->where('salary_month', $month)
            ->lockForUpdate()->first();
        if (!$version || $version->status !== 'approved'
            || !hash_equals((string)$workflow->ready_snapshot_hash, (string)$version->snapshot_hash)) {
            throw new DomainException('The final approved salary calculation changed before posting.');
        }
        $regular = json_decode((string)$version->calculated_result, true, 512, JSON_THROW_ON_ERROR);
        $arrear = json_decode((string)$version->arrear_snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($regular) || !is_array($arrear)) {
            throw new DomainException('Approved calculation data is not a valid financial snapshot.');
        }
        self::assertAmounts($regular, $arrear, $composed);
    }
}
