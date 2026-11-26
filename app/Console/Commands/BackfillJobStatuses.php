<?php

namespace App\Console\Commands;

use App\Models\PayrollComponentType;
use App\Models\SalaryGenerate;
use App\Services\MonthlySalarySetupService;
use App\Services\SalaryCalculationService;
use App\Services\SalaryComponentNormalizer;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BackfillJobStatuses extends Command
{
    private const FALLBACK_CODES = [
        '10046', '10108', '20026', '20028', '20029', '20033', '20034', '20035',
        '20037', '20062', '20069', '30098', '30133', '30254', '40033', '40158',
    ];

    private const GPF_PLACEHOLDER_CODES = [
        '20028', '20029', '20033', '20034', '20035', '20037', '30133', '40033', '40158',
    ];

    private const OTHERS_DEDUCTION_CODES = ['30036', '30043', '30073', '30075'];

    protected $signature = 'kau:backfill-job-statuses
        {--apply : Persist profile changes (default is dry-run)}';

    protected $description = 'Backfill job statuses, payroll flags, and recurring salary components';

    public function handle(): int
    {
        if (!Schema::hasColumn('employee_infos', 'gpf_exempt')) {
            $this->error('Run the employee_infos.gpf_exempt migration before using this command.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        try {
            [$scanned, $changed, $counts] = DB::transaction(fn () => $this->backfill($apply));
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s | Profiles scanned: %d | %s profile updates: %d',
            $apply ? 'APPLY' : 'DRY-RUN',
            $scanned,
            $apply ? 'Applied' : 'Planned',
            $changed
        ));
        $this->table(['Field', 'Profiles with changes'], collect($counts)->map(fn ($count, $field) => [$field, $count])->values()->all());

        return self::SUCCESS;
    }

    private function backfill(bool $apply): array
    {
        $eligibility = config('kau_fixation_eligibility', []);
        $holdCodes = ($eligibility['increment_hold'] ?? []) + ['20083' => true];
        $paidLeaveUsers = Schema::hasTable('leave_statuses')
            ? DB::table('leave_statuses')->where('onLeave', 1)->where('isActive', 1)->where('isPaid', 1)
                ->pluck('user_id')->flip()
            : collect();
        $profiles = DB::table('employee_infos')->orderBy('id')->when($apply, fn ($query) => $query->lockForUpdate())->get([
            'id', 'user_id', 'employeeCode', 'jobStatus', 'holdIncrement', 'gpf_exempt',
            'additionObjects', 'substractionObjects',
        ]);
        $fallbackUserIds = $profiles->filter(fn ($profile) => in_array(trim((string) $profile->employeeCode), self::FALLBACK_CODES, true))
            ->pluck('user_id');
        $octoberBills = $fallbackUserIds->isEmpty() ? collect() : SalaryGenerate::query()
            ->whereIn('user_id', $fallbackUserIds)
            ->where('salaryGenerationYear', 2026)
            ->where('monthId', 10)
            ->get()
            ->groupBy('user_id');
        $types = $fallbackUserIds->isEmpty() ? collect() : PayrollComponentType::query()->orderBy('id')->get();

        $counts = array_fill_keys(['jobStatus', 'holdIncrement', 'gpf_exempt', 'additionObjects', 'substractionObjects'], 0);
        $changed = 0;
        foreach ($profiles as $profile) {
            $code = trim((string) $profile->employeeCode);
            $current = strtolower(trim((string) $profile->jobStatus));
            $status = $this->targetJobStatus($current, $code, $eligibility, $paidLeaveUsers->has($profile->user_id));
            $updates = [];
            if ($status !== $current) {
                $updates['jobStatus'] = $status;
            }
            if (isset($holdCodes[$code]) && !(bool) $profile->holdIncrement) {
                $updates['holdIncrement'] = true;
            }
            if (!(bool) $profile->gpf_exempt && ($status === 'ad_hoc' || in_array($code, ['20082', '20137', '20083'], true))) {
                $updates['gpf_exempt'] = true;
            }

            if (in_array($code, self::FALLBACK_CODES, true)) {
                $bills = $octoberBills->get($profile->user_id, collect());
                if ($bills->count() !== 1) {
                    throw new DomainException("Expected one October 2026 bill for employee {$code}.");
                }
                $updates += $this->recurringUpdates($profile, $code, $bills->first(), $types);
            }
            if (in_array($code, self::OTHERS_DEDUCTION_CODES, true)) {
                $rows = SalaryComponentNormalizer::rows($profile->substractionObjects);
                $original = $rows;
                foreach ($rows as &$row) {
                    if (strcasecmp($row['name'], 'Others Deduction') === 0 && $row['calcType'] === 'fixed' && $row['amount'] === 1270.50) {
                        $row['amount'] = 0.0;
                    }
                }
                unset($row);
                if ($rows !== $original) {
                    $updates['substractionObjects'] = json_encode($rows, JSON_THROW_ON_ERROR);
                }
            }

            if ($updates !== []) {
                $changed++;
                foreach (array_keys($updates) as $field) {
                    $counts[$field]++;
                }
                if ($apply) {
                    DB::table('employee_infos')->where('id', $profile->id)->update($updates);
                }
            }
        }

        return [$profiles->count(), $changed, $counts];
    }

    private function targetJobStatus(string $current, string $code, array $eligibility, bool $hasPaidLeave): string
    {
        $isConfiguredAdHoc = isset($eligibility['adhoc'][$code]);
        if ($current === 'temporary' && $isConfiguredAdHoc) {
            return 'ad_hoc';
        }
        if ($current !== 'permanent') {
            return $current;
        }
        if (isset($eligibility['study_leave'][$code])) {
            $allowsIncrement = ($eligibility['study_leave'][$code]['first_increment_allowed'] ?? false) === true;

            return $allowsIncrement ? 'study_leave_with_pay_with_increment' : 'study_leave_with_pay';
        }
        if ($isConfiguredAdHoc) {
            return 'ad_hoc';
        }

        return $hasPaidLeave ? 'study_leave_with_pay' : $current;
    }

    private function recurringUpdates(object $profile, string $code, SalaryGenerate $bill, Collection $types): array
    {
        $updates = [];
        foreach ([
            'additionObjects' => ['allowance', $bill->salaryAllowanceCalcObject],
            'substractionObjects' => ['deduction', $bill->salaryDeductionCalcObject],
        ] as $field => [$direction, $octoberPayload]) {
            $rows = SalaryComponentNormalizer::rows($profile->{$field});
            $original = $rows;
            $octoberRows = MonthlySalarySetupService::octoberRegularRows($octoberPayload);
            foreach ($types as $type) {
                $name = $type->name;
                if (MonthlySalarySetupService::classifyDirection($name) !== $direction
                    || MonthlySalarySetupService::isBasicPay($name)
                    || MonthlySalarySetupService::isRevenueStamp($name)
                    || MonthlySalarySetupService::isSpecialBenefit($name)) {
                    continue;
                }

                $current = MonthlySalarySetupService::matchProfileRow($rows, $type->id, $name);
                $calcType = 'fixed';
                if (SalaryCalculationService::isGpf(['name' => $name])) {
                    $replacePlaceholder = $current !== null && $current['calcType'] === 'fixed'
                        && abs($current['amount']) < 0.005 && in_array($code, self::GPF_PLACEHOLDER_CODES, true);
                    if ($current !== null && !$replacePlaceholder) {
                        continue;
                    }
                    $calcType = 'percentage';
                    $amount = 10.0;
                } else {
                    if ($current !== null && $current['amount'] !== 0.0) {
                        continue;
                    }
                    $october = MonthlySalarySetupService::matchProfileRow($octoberRows, $type->id, $name);
                    $amount = $october['amount'] ?? 0.0;
                    if ($amount <= 0.0) {
                        continue;
                    }
                }

                $index = $current === null ? count($rows) : array_search($current, $rows, true);
                $rows[$index] = [
                    'payroll_component_type_id' => $type->id,
                    'name' => $name,
                    'calcType' => $calcType,
                    'amount' => $amount,
                ];
            }
            if ($rows !== $original) {
                $updates[$field] = json_encode($rows, JSON_THROW_ON_ERROR);
            }
        }

        return $updates;
    }
}
