<?php

namespace App\Http\Controllers;

use App\Models\EmployeeInfo;
use App\Models\SalaryFixationPaySchedule;
use App\Models\User;
use App\Services\ApprovedFixationResolver;
use App\Services\ApprovedFixationComponentProjector;
use App\Services\PayrollEffectivePolicy;
use App\Services\PayrollPreviewCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use DomainException;

/** Read-only, no SalaryGenerate insert, no GPF posting, no employee setup mutation. */
class SalaryPayrollPreviewController extends Controller
{
    public function index()
    {
        return Inertia::render('Salary/PayrollPreview/Index');
    }

    public function data(Request $request, ApprovedFixationResolver $resolver)
    {
        $period = $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|between:2020,2100',
            'arrear_period_id' => 'nullable|integer|exists:salary_arrear_periods,id',
        ]);
        $year = (int) $period['year'];
        $month = (int) $period['month'];
        $arrearPeriodId = (int) ($period['arrear_period_id'] ?? 0);
        $arrearPeriod = $arrearPeriodId && Schema::hasTable('salary_arrear_periods')
            ? DB::table('salary_arrear_periods')->where('id', $arrearPeriodId)->first() : null;
        $arrearPaymentMonthMatches = $arrearPeriod
            && substr((string) $arrearPeriod->planned_payment_month, 0, 7) === sprintf('%04d-%02d', $year, $month);
        $approvedArrears = $arrearPaymentMonthMatches
            ? DB::table('salary_arrear_snapshots')->where('period_id', $arrearPeriodId)
                ->where('status', 'approved')->get()->keyBy('user_id') : collect();
        $alreadyDisbursed = $arrearPaymentMonthMatches
            ? DB::table('salary_arrear_disbursements')->where('period_id', $arrearPeriodId)
                ->pluck('user_id')->flip() : collect();
        $cutoff = sprintf('%04d-%02d-01', $year, $month);
        $hasManual = Schema::hasTable('salary_manual_adjustments');
        $profiles = EmployeeInfo::with(['user.joiningDate', 'payScale', 'payrollGroup.grade', 'payrollGroup.payrollGroupType'])
            ->orderBy('employeeCode')->get();
        $profileCounts = $profiles->countBy('user_id');
        $existingBills = DB::table('salary_generates')
            ->where('monthId', $month)->where('salaryGenerationYear', $year)
            ->get()->keyBy('user_id');
        $historical = DB::table('salary_generates')
            ->where('salaryGenerationYear', 2026)
            ->whereIn('monthId', [6, 7, 8, 9])
            ->orderByDesc('id')->get()->groupBy('user_id');
        $manualByUser = $hasManual ? DB::table('salary_manual_adjustments')
            ->where('salary_month', $month)->where('salary_year', $year)->get()->groupBy('user_id') : collect();
        $effectiveFixations = $resolver->forUsers($profiles->pluck('user_id')->all(), $year, $month);

        $rows = [];
        foreach ($profiles as $profile) {
            $user = $profile->user;
            $errors = [];
            $warnings = [];
            if (!$user) $errors[] = 'USER_MISSING';
            elseif (!(bool) $user->isActive) $errors[] = 'USER_INACTIVE';
            $periodEnd = \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();
            if ($user?->joiningDate?->joining_date && $user->joiningDate->joining_date > $periodEnd) {
                $errors[] = 'EMPLOYEE_NOT_YET_JOINED_IN_SELECTED_PAYROLL_PERIOD';
            }
            if (($profileCounts[$profile->user_id] ?? 0) !== 1) $errors[] = 'DUPLICATE_USER_PROFILES';
            if (!isset($effectiveFixations[$profile->user_id])
                && (!$profile->payrollGroup?->grade || !$profile->payScale)) $errors[] = 'GRADE_OR_SCALE_MISSING';
            elseif (!isset($effectiveFixations[$profile->user_id])
                && (int) $profile->payrollGroup->grade->pay_scale_id !== (int) $profile->pay_scale_id) {
                $errors[] = 'PAY_SCALE_GRADE_MISMATCH';
            }
            if (isset($existingBills[$profile->user_id])) $warnings[] = 'BILL_ALREADY_EXISTS';
            $historyByMonth = $historical->get($profile->user_id, collect())->keyBy('monthId');
            // History is informational: a legitimate promotion/increment can change basic pay.
            // Missing September also means historical arrears cannot be reconciled here.
            if ($year === 2026 && $month === 10 && !isset($historyByMonth[9])) {
                $warnings[] = 'SEPTEMBER_2026_PAYROLL_HISTORY_MISSING';
            }
            $historicalBasics = [];
            foreach ([6, 7, 8, 9] as $historyMonth) {
                $record = $historyByMonth->get($historyMonth);
                if (!$record) continue;
                $items = json_decode((string) $record->salaryAllowanceCalcObject, true);
                if (!is_array($items)) continue;
                foreach ($items as $component) {
                    $name = strtolower((string) ($component['name'] ?? ''));
                    if (str_contains($name, 'basic')) {
                        $historicalBasics[$historyMonth] = (float) ($component['calculated_amount'] ?? $component['amount'] ?? 0);
                        break;
                    }
                }
            }
            $adds = is_array($profile->additionObjects) ? $profile->additionObjects : [];
            $deductions = is_array($profile->substractionObjects) ? $profile->substractionObjects : [];
            $scaleName = $profile->payScale?->scale_name ?? 'Unknown';
            $fixation = $effectiveFixations[$profile->user_id] ?? null;
            if (!$fixation && !str_contains($scaleName, '2015')) $errors[] = 'NON_2015_BASELINE';
            if ($fixation && ($profileCounts[$profile->user_id] ?? 0) === 1) {
                try {
                    $projected = ApprovedFixationComponentProjector::project($adds, $deductions, $fixation);
                    $adds = $projected['additions'];
                    $deductions = $projected['deductions'];
                    $scaleName = $fixation['pay_scale'];
                    $warnings[] = '2026_BASIC_WITH_CARRIED_FORWARD_CONFIGURED_FIXED_RATES';
                } catch (DomainException $e) {
                    $errors[] = '2026_COMPONENT_CONFIGURATION: '.$e->getMessage();
                }
            }
            $manualItems = $manualByUser->get($profile->user_id, collect());
            foreach ($manualItems as $manual) {
                if ($fixation && PayrollEffectivePolicy::isSpecialBenefit(['name' => $manual->title])) {
                    $errors[] = 'SPECIAL_BENEFIT_MANUAL_ITEM_NOT_ALLOWED';
                    continue;
                }
                $item = ['name' => $manual->title, 'amount' => (float) $manual->amount,
                    'calcType' => 'fixed', 'manual_adjustment_id' => $manual->id,
                    'manual_reason' => $manual->reason, 'manual_evidence_reference' => $manual->evidence_reference,
                    'component_type' => $manual->direction === 'addition' ? 'allowance' : 'deduction'];
                if ($manual->direction === 'addition') $adds[] = $item;
                else $deductions[] = $item;
            }
            $financial = null;
            if (!$errors) {
                try {
                    $financial = app(\App\Services\SalaryCalculationService::class)->calculate($adds, $deductions);
                    $approvedWorkspace = app(\App\Services\ApprovedSalaryCalculationVersionResolver::class)
                        ->forUser((int) $profile->user_id, $year, $month, $fixation);
                    if ($approvedWorkspace) {
                        $financial = $approvedWorkspace['financial'];
                        $warnings[] = 'APPROVED_SALARY_WORKSPACE_VERSION_'.$approvedWorkspace['version'];
                    }
                } catch (DomainException $e) {
                    $errors[] = 'CALCULATION: '.$e->getMessage();
                }
            }
            // An approved arrear is a separate, read-only reconciliation component.
            // Never add it to employee_infos or salary_generates in this endpoint.
            $arrearSnapshot = $approvedArrears->get($profile->user_id);
            $arrearEligible = $arrearSnapshot && !$alreadyDisbursed->has($profile->user_id)
                && ($profileCounts[$profile->user_id] ?? 0) === 1;
            $arrearAmount = $arrearEligible ? (float) $arrearSnapshot->net_arrear : null;
            $arrearLines = $arrearEligible
                ? DB::table('salary_arrear_lines')->where('snapshot_id', $arrearSnapshot->id)->get() : collect();
            $arrearAdd = $arrearLines->where('direction', 'addition')->sum('amount');
            $arrearDed = $arrearLines->where('direction', 'deduction')->sum('amount');
            $arrearGpf = $arrearLines->where('code', 'additional_gpf')->sum(fn ($item) =>
                $item->direction === 'deduction' ? (float) $item->amount : -(float) $item->amount);
            $combinedPreviewNet = $financial && $arrearAmount !== null
                ? round($financial['net_salary'] + $arrearAmount, 2) : null;
            $rows[] = [
                'user_id' => $profile->user_id,
                'employee_profile_id' => $profile->id,
                'employee_code' => (string) $profile->employeeCode,
                'employee_name' => $user?->name ?? 'Missing user',
                'pay_scale' => $scaleName,
                'grade' => $fixation['grade'] ?? ($profile->payrollGroup?->grade?->grade_name ?? ''),
                'fixation_id' => $fixation['salary_fixation_id'] ?? null,
                'fixation_effective_date' => $fixation['effective_date'] ?? null,
                'basic' => $financial['basic'] ?? null,
                'total_allowance' => $financial['total_allowance'] ?? null,
                'total_deduction' => $financial['total_deduction'] ?? null,
                'gpf' => $financial['gpf_deduction'] ?? null,
                'tax' => $financial['tax_deduction'] ?? null,
                'net' => $financial['net_salary'] ?? null,
                'additions' => $financial['allowances'] ?? [],
                'deductions' => $financial['deductions'] ?? [],
                'historical_paid_basics' => $historicalBasics,
                'manual_adjustments' => $manualItems->count(),
                'historical_arrear' => $arrearAmount,
                'arrear_additions' => $arrearEligible ? round($arrearAdd, 2) : null,
                'arrear_deductions' => $arrearEligible ? round($arrearDed, 2) : null,
                'arrear_additional_gpf' => $arrearEligible ? round($arrearGpf, 2) : null,
                'combined_read_only_net' => $combinedPreviewNet,
                'arrear_snapshot_id' => $arrearEligible ? $arrearSnapshot->id : null,
                'existing_bill_id' => $existingBills[$profile->user_id]->id ?? null,
                'status' => $errors ? 'BLOCKED' : ($warnings ? 'REVIEW' : 'PASS'),
                'reasons' => array_merge($errors, $warnings),
            ];
        }
        return response()->json([
            'year' => $year, 'month' => $month,
            'regular_scale_rule' => 'Employee-specific approved fixation from July 2026; others retain 2015 salary setup',
            'arrear_included' => $arrearPaymentMonthMatches && $approvedArrears->isNotEmpty(),
            'arrear_period_id' => $arrearPeriodId ?: null,
            'arrear_payment_month_matches' => (bool) $arrearPaymentMonthMatches,
            'payable' => false,
            'disclaimer' => 'READ-ONLY DRAFT; no salary bill generated or GPF ledger posted.',
            'counts' => [
                'total' => count($rows),
                'pass' => count(array_filter($rows, fn ($r) => $r['status'] === 'PASS')),
                'review' => count(array_filter($rows, fn ($r) => $r['status'] === 'REVIEW')),
                'blocked' => count(array_filter($rows, fn ($r) => $r['status'] === 'BLOCKED')),
            ],
            'employees' => $rows,
        ])->header('Cache-Control', 'private, no-store');
    }
}
