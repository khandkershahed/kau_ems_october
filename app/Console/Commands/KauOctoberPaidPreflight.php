<?php

namespace App\Console\Commands;

use App\Services\SalaryBillPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Read-only October 2026 paid-reference / actual posting preflight. */
final class KauOctoberPaidPreflight extends Command
{
    protected $signature = 'kau:october-paid-preflight {--output= : Local CSV report path}';
    protected $description = 'Read-only 413-employee October 2026 saved payment-versus-live posting audit';

    private static function paisa(mixed $value): int
    {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new \DomainException('Invalid monetary value.');
        }
        return (int) round((float) $value * 100, 0, PHP_ROUND_HALF_UP);
    }

    public function handle(SalaryBillPostingService $posting): int
    {
        $referenceFile = base_path('resources/kau_references/KAU_2026_10_FROZEN_413.csv');
        if (!is_file($referenceFile)) {
            $this->error('October frozen payment reference CSV is missing. No database writes performed.');
            return self::FAILURE;
        }
        $referenceHandle = fopen($referenceFile, 'rb');
        if ($referenceHandle === false) {
            $this->error('Cannot read frozen payment reference.');
            return self::FAILURE;
        }
        $header = fgetcsv($referenceHandle);
        if (!is_array($header)) {
            fclose($referenceHandle);
            $this->error('Frozen reference header unavailable.');
            return self::FAILURE;
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $reference = [];
        while (($values = fgetcsv($referenceHandle)) !== false) {
            if (count($values) !== count($header)) {
                fclose($referenceHandle);
                $this->error('Malformed frozen reference row.');
                return self::FAILURE;
            }
            $row = array_combine($header, $values);
            $code = trim((string) ($row['employee_code'] ?? ''));
            if ($code === '' || isset($reference[$code])) {
                fclose($referenceHandle);
                $this->error('Missing or duplicate frozen employee code: '.$code);
                return self::FAILURE;
            }
            $reference[$code] = $row;
        }
        fclose($referenceHandle);
        if (count($reference) !== 413) {
            $this->error('Frozen reference is not exactly 413 unique employees.');
            return self::FAILURE;
        }

        $roster = DB::table('employee_infos as e')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->where('u.isActive', 1)
            ->select('e.employeeCode', 'e.user_id')
            ->orderBy('e.employeeCode')->get();
        if ($roster->count() !== 413) {
            $this->error('Active employee profile count differs from 413. No salary was posted.');
            return self::FAILURE;
        }
        $output = trim((string) $this->option('output'));
        if ($output === '') {
            $output = storage_path('app/KAU_October_2026_paid_preflight.csv');
        }
        $directory = dirname($output);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->error('Cannot create local CSV output folder.');
            return self::FAILURE;
        }
        $out = fopen($output, 'wb');
        if ($out === false) {
            $this->error('Cannot open CSV report for writing.');
            return self::FAILURE;
        }
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'employee_code', 'user_id', 'saved_version_status', 'workflow_status',
            'reference_final_net', 'live_preview_net', 'source_hash', 'status', 'reason',
        ]);
        $ready = 0;
        $failed = 0;
        $seen = [];
        foreach ($roster as $person) {
            $code = (string) $person->employeeCode;
            $userId = (int) $person->user_id;
            $row = $reference[$code] ?? null;
            $savedStatus = (string) ($row['latest_status'] ?? '');
            $workflow = DB::table('salary_monthly_workflows')
                ->where('user_id', $userId)->where('salary_year', 2026)
                ->where('salary_month', 10)->value('workflow_status');
            $status = 'BLOCKED';
            $reason = '';
            $liveNet = '';
            $sourceHash = '';
            try {
                if (!$row || isset($seen[$code])) {
                    throw new \DomainException('Employee not uniquely present in frozen paid reference.');
                }
                if ((int) $row['user_id'] !== $userId) {
                    throw new \DomainException('Employee identity differs from frozen paid reference.');
                }
                $source = $posting->previewForPosting($userId, 2026, 10);
                $liveNet = number_format((float) $source['net_payable'], 2, '.', '');
                $sourceHash = (string) ($source['source_hash'] ?? '');
                $checks = [
                    'latest_calc_version_id' => $source['calculation_version_id'] ?? null,
                    'regular_basic' => $source['regular']['basic'] ?? null,
                    'regular_gross' => $source['regular']['total_allowance'] ?? null,
                    'regular_deduction' => $source['regular']['total_deduction'] ?? null,
                    'regular_gpf' => $source['regular']['gpf_deduction'] ?? null,
                    'regular_net' => $source['regular']['net_salary'] ?? null,
                    'arrear_payable' => $source['arrear_payable'] ?? null,
                    'arrear_deduction' => $source['arrear_deduction'] ?? null,
                    'total_gross_incl_arrear' => $source['gross'] ?? null,
                    'total_deductions_incl_arrear' => $source['total_deduction'] ?? null,
                    'final_net' => $source['net_payable'] ?? null,
                ];
                foreach ($checks as $key => $liveValue) {
                    if ($key === 'latest_calc_version_id') {
                        if ((int) $liveValue !== (int) ($row[$key] ?? 0)) {
                            throw new \DomainException('Frozen calculation version changed.');
                        }
                    } elseif (self::paisa($liveValue) !== self::paisa($row[$key] ?? null)) {
                        throw new \DomainException('Posting preview differs from paid reference: '.$key);
                    }
                }
                $arrears = [];
                foreach (array_merge($source['arrear_additions'], $source['arrear_deductions']) as $component) {
                    $key = (string) ($component['arrear_code'] ?? '');
                    if ($key === '' && self::paisa($component['calculated_amount'] ?? 0) === 0) {
                        continue;
                    }
                    if (!in_array($key, [
                        'BASIC_ARREAR_GROSS', 'BASIC_INCREMENT_ADJUSTMENT',
                        'SPECIAL_BENEFIT_RECOVERY', 'HOUSE_RENT_RECOVERY', 'ADDITIONAL_GPF',
                    ], true)) {
                        throw new \DomainException('Unexpected posting arrear component: '.$key);
                    }
                    $arrears[$key] = ($arrears[$key] ?? 0) + self::paisa($component['calculated_amount']);
                }
                foreach ([
                    'BASIC_ARREAR_GROSS', 'BASIC_INCREMENT_ADJUSTMENT',
                    'SPECIAL_BENEFIT_RECOVERY', 'HOUSE_RENT_RECOVERY', 'ADDITIONAL_GPF',
                ] as $key) {
                    if (($arrears[$key] ?? 0) !== self::paisa($row[$key] ?? null)) {
                        throw new \DomainException('Posting arrear differs from paid reference: '.$key);
                    }
                }
                // Also mirror the posting transaction's immutable July-September
                // entitlement uniqueness checks. previewForPosting() alone does
                // not detect a prior salary_bill_arrear_entitlements rule/month.
                $priorEntitlements = DB::table('salary_bill_arrear_entitlements')
                    ->where('user_id', $userId)
                    ->get(['rule_id', 'entitlement_month', 'entitlement_fingerprint']);
                foreach (array_merge($source['arrear_additions'], $source['arrear_deductions']) as $item) {
                    if (abs((float) ($item['calculated_amount'] ?? 0)) < 0.005) {
                        continue;
                    }
                    $periods = $item['arrear_entitlement_months']
                        ?: [sprintf('%04d-%02d', 2026, 10)];
                    foreach (array_unique($periods) as $period) {
                        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $period)) {
                            throw new \DomainException('Invalid arrear entitlement period: '.$period);
                        }
                        $fingerprint = hash('sha256', json_encode([
                            'user_id' => $userId,
                            'rule_id' => $item['arrear_rule_id'],
                            'title' => $item['name'],
                            'direction' => $item['component_type'],
                            'entitlement_period' => $period,
                            'evidence' => $item['arrear_source_reference'],
                        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                        foreach ($priorEntitlements as $posted) {
                            $sameFingerprint = (string) $posted->entitlement_fingerprint === $fingerprint;
                            $sameRuleAndPeriod = !empty($item['arrear_rule_id'])
                                && (int) $posted->rule_id === (int) $item['arrear_rule_id']
                                && (string) $posted->entitlement_month === (string) $period;
                            if ($sameFingerprint || $sameRuleAndPeriod) {
                                throw new \DomainException('Previously posted arrear entitlement overlaps '.$period
                                    .' (rule '.($item['arrear_rule_id'] ?? 'none').').');
                            }
                        }
                    }
                }
                $status = 'FINANCIALLY_MATCHED';
                $ready++;
            } catch (Throwable $exception) {
                $reason = substr($exception->getMessage(), 0, 1800);
                $failed++;
            }
            $seen[$code] = true;
            $values = [$code, $userId, $savedStatus, (string) ($workflow ?? 'WORKFLOW_MISSING'),
                $row['final_net'] ?? '', $liveNet, $sourceHash, $status, $reason];
            fputcsv($out, array_map(static function ($value) {
                if (!is_string($value)) return $value;
                return preg_match('/^\s*[=+@-]/u', $value) ? "'".$value : $value;
            }, $values));
        }
        fclose($out);
        $this->line('Active reference employees: '.count($reference));
        $this->line('Financially matched posting previews: '.$ready);
        $this->line('Failed posting previews or paid-reference mismatches: '.$failed);
        $this->line('Employee-specific CSV: '.$output);
        $this->line('No DB writes, approvals, bills, arrear entitlements or GPF entries were created.');
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
