<?php

namespace App\Http\Controllers;

use App\Exceptions\HistoricalSalaryImmutableException;
use App\Models\SalaryGenerate;
use App\Models\User;
use App\Services\MonthlySalarySetupService;
use App\Services\SalaryBillPostingService;
use App\Services\SalaryCalculationService;
use App\Services\SalaryGenerationGuards;
use App\Services\SalaryWorkflowRosterService;
use App\Services\SalaryFinancialSourceFingerprint;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

/** PATCH 05: resumable, read-only estimation. NEVER creates bill or approves payroll. */
final class SalaryEstimationWorkspaceController extends Controller
{
    private const NON_PAYABLE_EMPLOYEE_TYPES = [
        'Inactive',
        'Suspend',
        'Study Leave Without Pay',
    ];

    private function hr(Request $request): void
    {
        abort_unless($request->user(), 401); // PATCH 06: role-free authenticated operator
    }

    /** Read-only employee directory query. A GET never initializes a workflow or saves salary. */
    private function directoryRequest(Request $request): array
    {
        return $request->validate([
            'year' => 'nullable|integer|between:2020,2100',
            'month' => 'nullable|integer|between:1,12',
            'status' => 'nullable|in:ALL,PENDING,GENERATED,NOT_INITIALIZED,BLOCKED,CALCULATED,READY_FOR_GENERATION,WORK_BLOCKED',
            'search' => 'nullable|string|max:100',
            'department_id' => 'nullable|integer|min:1',
            'category_id' => 'nullable|integer|min:1',
            'payroll_group_id' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:10,25,50,100,200',
            'employee_code' => 'nullable|string|max:40',
            'name_email' => 'nullable|string|max:100',
            'gender' => 'nullable|string|max:30',
            'profile_status' => 'nullable|in:active,inactive',
            'pay_scale_id' => 'nullable|integer|min:1',
            'grade_id' => 'nullable|integer|min:1',
            'designation' => 'nullable|string|max:100',
            'religion' => 'nullable|string|max:60',
            'joining_date' => 'nullable|date_format:Y-m-d',
            'increment_status' => 'nullable|in:available,hold,adhoc,study_with,study_without,other',
            'component_type_id' => 'nullable|integer|min:1',
            'fixation_status' => 'nullable|in:approved,not_approved,missing',
            'employee_types' => 'nullable|array',
            'employee_types.*' => 'string|max:60',
        ]);
    }

    private function mapJobStatusToLabel(?string $jobStatus): ?string
    {
        $raw = trim((string) ($jobStatus ?? ''));
        if ($raw === '') {
            return null;
        }
        $norm = strtolower(str_replace(['-', '_', ' '], '', $raw));
        return match ($norm) {
            'permanent', 'active', 'regular' => 'Active',
            'studyleavewithpay', 'leavewithpay' => 'Study Leave With Pay',
            'studyleavewithoutpay', 'leavewithoutpay' => 'Study Leave Without Pay',
            'adhoc' => 'Ad Hoc',
            'contract', 'contractual' => 'Contractual',
            'deputation' => 'Deputation',
            'lean', 'lien' => 'Lean',
            'prl' => 'PRL',
            'suspend', 'suspended' => 'Suspend',
            'inactive' => 'Inactive',
            'pensionhalf' => 'Pension Half',
            'pensionfull' => 'Pension Full',
            'temporary' => 'Temporary',
            'others' => 'Others',
            default => $raw,
        };
    }

    /**
     * Resolve canonical Employee Type (Employment Status) label for workspace filtering and badges.
     *
     * @param array<string, bool|null> $studyLeaveIncrements
     * @param array<string, string> $adhocCodes
     */
    private function resolveEmployeeType(object $row, array $studyLeaveIncrements, array $adhocCodes, ?bool $leavePaid): string
    {
        if (!(bool) $row->is_active) {
            return 'Inactive';
        }

        $mapped = $this->mapJobStatusToLabel($row->job_status);
        if (in_array($mapped, self::NON_PAYABLE_EMPLOYEE_TYPES, true)) {
            return $mapped;
        }

        if ($leavePaid === false) {
            return 'Study Leave Without Pay';
        }

        $code = trim((string) $row->employee_code);
        $firstIncrementAllowed = $studyLeaveIncrements[$code] ?? null;
        if ($firstIncrementAllowed === false
            || ($firstIncrementAllowed !== true && ($leavePaid === true || $mapped === 'Study Leave With Pay'))) {
            return 'Study Leave With Pay';
        }
        if (isset($adhocCodes[$code])) {
            return 'Ad Hoc';
        }

        return $mapped === 'Study Leave With Pay' ? 'Active' : ($mapped ?? 'Active');
    }

    private function eligibilityCodes(): array
    {
        $config = config('kau_fixation_eligibility', []);
        $studyWith = [];
        $studyWithout = [];
        foreach (($config['study_leave'] ?? []) as $code => $row) {
            if (($row['first_increment_allowed'] ?? false) === true) $studyWith[] = (string)$code;
            else $studyWithout[] = (string)$code;
        }
        return [
            'hold' => array_map('strval', array_keys($config['increment_hold'] ?? [])),
            'adhoc' => array_map('strval', array_keys($config['adhoc'] ?? [])),
            'study_with' => $studyWith,
            'study_without' => $studyWithout,
        ];
    }

    /** A single query builder is shared by the table, counts and cursor selection. */
    private function directoryQuery(array $data, int $year, int $month, bool $withStatus = true)
    {
        // Duplicate profiles stay visible and are never silently used for payroll actions.
        $profiles = DB::table('employee_infos')
            ->select('user_id')
            ->selectRaw('MIN(employeeCode) as employee_code, MIN(id) as profile_id, COUNT(*) as profile_count')
            ->groupBy('user_id');
        $query = DB::query()->fromSub($profiles, 'ep')
            ->join('users as u', 'u.id', '=', 'ep.user_id')
            ->where('u.isActive', 1)
            ->leftJoin('employee_infos as ei', 'ei.id', '=', 'ep.profile_id')
            ->leftJoin('department_offices as dep', 'dep.id', '=', 'ei.department_office_id')
            ->leftJoin('pay_scales as ps', 'ps.id', '=', 'ei.pay_scale_id')
            ->leftJoin('payroll_groups as pg_info', 'pg_info.id', '=', 'ei.payroll_group_id')
            ->leftJoin('grades as gr', 'gr.id', '=', 'pg_info.grade_id')
            ->leftJoin('payroll_group_types as pgt_info', 'pgt_info.id', '=', 'pg_info.payroll_group_type_id')
            ->leftJoin('joining_dates as jd', 'jd.id', '=', 'ei.joining_date_id')
            ->leftJoin('salary_monthly_workflows as w', function ($join) use ($year, $month) {
                $join->on('w.user_id', '=', 'ep.user_id')
                    ->where('w.salary_year', '=', $year)->where('w.salary_month', '=', $month);
            })
            ->leftJoin('salary_fixations as sf', function ($join) {
                $join->on('sf.user_id', '=', 'ep.user_id')
                    ->whereRaw('sf.id = (SELECT MAX(sfi.id) FROM salary_fixations sfi WHERE sfi.user_id = ep.user_id)');
            })
            ->leftJoin('salary_fixation_adjustments as fa', 'fa.user_id', '=', 'ep.user_id');

        if (!empty($data['department_id'])) $query->where('ei.department_office_id', $data['department_id']);
        if (!empty($data['payroll_group_id'])) $query->where('ei.payroll_group_id', $data['payroll_group_id']);
        if (!empty($data['category_id'])) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('user_user_categories as uc')
                ->whereColumn('uc.user_id', 'ep.user_id')->where('uc.user_category_id', $data['category_id']));
        }
        if (!empty($data['employee_code'])) {
            $query->where('ep.employee_code', 'like', '%' . addcslashes($data['employee_code'], '%_\\') . '%');
        }
        if (!empty($data['name_email'])) {
            $term = '%' . addcslashes($data['name_email'], '%_\\') . '%';
            $query->where(fn ($q) => $q->where('u.name', 'like', $term)->orWhere('u.email', 'like', $term));
        }
        if (!empty($data['search'])) {
            $term = '%' . addcslashes($data['search'], '%_\\') . '%';
            $query->where(fn ($q) => $q->where('u.name', 'like', $term)
                ->orWhere('u.email', 'like', $term)->orWhere('ep.employee_code', 'like', $term));
        }
        if (!empty($data['gender'])) $query->where('u.gender', $data['gender']);
        if (!empty($data['profile_status'])) $query->where('u.isActive', $data['profile_status'] === 'active' ? 1 : 0);
        if (!empty($data['pay_scale_id'])) $query->where('ei.pay_scale_id', $data['pay_scale_id']);
        if (!empty($data['grade_id'])) $query->where('pg_info.grade_id', $data['grade_id']);
        if (!empty($data['designation'])) $query->where('pgt_info.group_name', $data['designation']);
        if (!empty($data['religion'])) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('religions as rel')
                ->whereColumn('rel.user_id', 'u.id')->where('rel.religion', $data['religion']));
        }
        if (!empty($data['joining_date'])) $query->whereDate('jd.joining_date', $data['joining_date']);
        if (!empty($data['component_type_id'])) {
            // Actual group component assignment OR employee-specific component override.
            $query->where(function ($q) use ($data, $year, $month) {
                $q->whereExists(fn ($sub) => $sub->selectRaw('1')->from('payroll_components as pc')
                    ->whereColumn('pc.payroll_group_id', 'ei.payroll_group_id')
                    ->where('pc.payroll_component_type_id', $data['component_type_id']))
                  ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('salary_component_employee_overrides as ov')
                    ->whereColumn('ov.user_id', 'ep.user_id')
                    ->where('ov.salary_year', $year)->where('ov.salary_month', $month)
                    ->where('ov.payroll_component_type_id', $data['component_type_id']));
            });
        }
        $codes = $this->eligibilityCodes();
        $special = array_values(array_unique(array_merge(...array_values($codes))));
        if (!empty($data['increment_status'])) {
            switch ($data['increment_status']) {
                case 'adhoc': $query->whereIn('ep.employee_code', $codes['adhoc']); break;
                case 'study_with': $query->whereIn('ep.employee_code', $codes['study_with']); break;
                case 'study_without': $query->whereIn('ep.employee_code', $codes['study_without']); break;
                case 'hold':
                    $query->where(function ($q) use ($codes, $special) {
                        $q->whereIn('ep.employee_code', $codes['hold'])
                            ->orWhere(fn ($s) => $s->whereNotIn('ep.employee_code', $special)
                                ->where(fn ($h) => $h->where('ei.holdIncrement', 1)
                                    ->orWhere('fa.increment_2026', 'hold')));
                    });
                    break;
                case 'available':
                    $query->whereNotIn('ep.employee_code', array_unique(array_merge($codes['hold'], $codes['adhoc'], $codes['study_without'])))
                        ->where(fn ($q) => $q->whereNull('sf.id')
                            ->orWhereRaw("JSON_VALID(sf.calculation) AND CAST(JSON_UNQUOTE(JSON_EXTRACT(sf.calculation, '$.increment_2026')) AS DECIMAL(12,2)) > 0") )
                        ->where(fn ($q) => $q->whereIn('ep.employee_code', $codes['study_with'])
                            ->orWhere(fn ($s) => $s->where('ei.holdIncrement', 0)
                                ->where('ei.jobStatus', '!=', 'others')
                                ->where(fn ($h) => $h->whereNull('fa.increment_2026')->orWhere('fa.increment_2026', '!=', 'hold'))));
                    break;
                case 'other':
                    $query->whereNotIn('ep.employee_code', $special)
                        ->where(fn ($q) => $q->whereNull('ei.holdIncrement')->orWhere('ei.jobStatus', 'others')
                            ->orWhereRaw("JSON_VALID(sf.calculation) AND CAST(JSON_UNQUOTE(JSON_EXTRACT(sf.calculation, '$.increment_2026')) AS DECIMAL(12,2)) <= 0"));
                    break;
            }
        }
        if (!empty($data['fixation_status'])) {
            if ($data['fixation_status'] === 'missing') $query->whereNull('sf.id');
            elseif ($data['fixation_status'] === 'approved') $query->where('sf.status', 'approved');
            else $query->whereNotNull('sf.id')->where('sf.status', '!=', 'approved');
        }
        if ($withStatus && !empty($data['status']) && $data['status'] !== 'ALL') {
            if ($data['status'] === 'WORK_BLOCKED') {
                $query->where(fn ($q) => $q->whereNull('w.id')->orWhere('w.workflow_status', 'BLOCKED'));
            } elseif ($data['status'] === 'NOT_INITIALIZED') $query->whereNull('w.id');
            else $query->where('w.workflow_status', $data['status']);
        }
        return $query;
    }

    public function index(Request $request, MonthlySalarySetupService $setupService)
    {
        $this->hr($request);
        $data = $this->directoryRequest($request);
        $year = (int) ($data['year'] ?? 2026);
        $month = (int) ($data['month'] ?? 11);
        $isLocked = SalaryGenerationGuards::isMonthLocked($year, $month);

        $rawStatus = strtoupper((string) ($data['status'] ?? 'ALL'));
        $status = in_array($rawStatus, ['ALL', 'PENDING', 'GENERATED'], true) ? $rawStatus : 'ALL';
        $search = trim((string) ($data['search'] ?? ''));
        $categoryId = !empty($data['category_id']) ? (int) $data['category_id'] : null;
        $departmentId = !empty($data['department_id']) ? (int) $data['department_id'] : null;
        $designation = trim((string) ($data['designation'] ?? ''));
        $perPage = (int) ($data['per_page'] ?? 50);

        $profiles = DB::table('employee_infos')
            ->select('user_id')
            ->selectRaw('MIN(employeeCode) as employee_code, MIN(id) as profile_id, COUNT(*) as profile_count')
            ->groupBy('user_id');

        $roster = DB::query()->fromSub($profiles, 'ep')
            ->join('users as u', 'u.id', '=', 'ep.user_id')
            ->leftJoin('employee_infos as ei', 'ei.id', '=', 'ep.profile_id');

        $eligibility = config('kau_fixation_eligibility', []);
        $studyLeaveIncrements = array_map(fn ($entry) => $entry['first_increment_allowed'] ?? null, $eligibility['study_leave'] ?? []);
        $adhocCodes = $eligibility['adhoc'] ?? [];
        $leavePaidByUser = Schema::hasTable('leave_statuses')
            ? DB::table('leave_statuses')->where('onLeave', 1)->where('isActive', 1)
                ->groupBy('user_id')->selectRaw('user_id, MIN(isPaid) as is_paid')->pluck('is_paid', 'user_id')
            : collect();

        $employeeTypesByUser = [];
        $employeeTypeCounts = [];
        foreach ((clone $roster)->get(['ep.user_id', 'ep.employee_code', 'u.isActive as is_active', 'ei.jobStatus as job_status']) as $row) {
            $leavePaid = $leavePaidByUser->get($row->user_id);
            $type = $this->resolveEmployeeType($row, $studyLeaveIncrements, $adhocCodes, $leavePaid === null ? null : (bool) $leavePaid);
            $employeeTypesByUser[$row->user_id] = $type;
            $employeeTypeCounts[$type] = ($employeeTypeCounts[$type] ?? 0) + 1;
        }
        ksort($employeeTypeCounts, SORT_NATURAL | SORT_FLAG_CASE);

        $defaultEmployeeTypes = array_values(array_diff(array_keys($employeeTypeCounts), self::NON_PAYABLE_EMPLOYEE_TYPES));
        $selectedEmployeeTypes = $request->has('employee_types')
            ? array_values(array_unique(array_filter(array_map('trim', (array) ($data['employee_types'] ?? [])))))
            : $defaultEmployeeTypes;
        $selectedTypeSet = array_fill_keys($selectedEmployeeTypes, true);
        $allActiveTypesSelected = $selectedEmployeeTypes === [] && $request->has('employee_types');
        $selectedUserIds = array_keys(array_filter(
            $employeeTypesByUser,
            fn ($type) => $allActiveTypesSelected ? $type !== 'Inactive' : isset($selectedTypeSet[$type])
        ));

        $query = $roster->whereIntegerInRaw('ep.user_id', $selectedUserIds)
            ->leftJoin('department_offices as dep', 'dep.id', '=', 'ei.department_office_id')
            ->leftJoin('pay_scales as ps', 'ps.id', '=', 'ei.pay_scale_id')
            ->leftJoin('payroll_groups as pg_info', 'pg_info.id', '=', 'ei.payroll_group_id')
            ->leftJoin('grades as gr', 'gr.id', '=', 'pg_info.grade_id')
            ->leftJoin('payroll_group_types as pgt_info', 'pgt_info.id', '=', 'pg_info.payroll_group_type_id');

        if ($departmentId !== null) {
            $query->where('ei.department_office_id', $departmentId);
        }

        if ($designation !== '') {
            $query->where('pgt_info.group_name', $designation);
        }

        if ($categoryId !== null && Schema::hasTable('user_user_categories')) {
            $query->whereExists(function ($q) use ($categoryId) {
                $q->selectRaw('1')
                    ->from('user_user_categories as uc')
                    ->whereColumn('uc.user_id', 'ep.user_id')
                    ->where('uc.user_category_id', $categoryId);
            });
        }

        if ($search !== '') {
            $term = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('u.name', 'like', $term)
                    ->orWhere('u.email', 'like', $term)
                    ->orWhere('ep.employee_code', 'like', $term);
            });
        }

        $baseRows = $query->select(
            'ep.user_id',
            'ep.employee_code',
            'ep.profile_count',
            'u.name',
            'u.email',
            'u.isActive as is_active',
            'ei.department_office_id',
            'ei.pay_scale_id',
            'ei.payroll_group_id',
            'ei.jobStatus as job_status',
            'ei.holdIncrement as hold_increment',
            'dep.name as department_name',
            'ps.scale_name as pay_scale_name',
            'gr.grade_name',
            'pgt_info.group_name as designation_name'
        )
            ->orderBy('ep.employee_code')
            ->orderBy('ep.user_id')
            ->get();

        $allUserIds = $baseRows->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $categoryMap = [];
        $primaryCategoryIdMap = [];
        if ($allUserIds !== [] && Schema::hasTable('user_user_categories') && Schema::hasTable('user_categories')) {
            $catRows = DB::table('user_user_categories as uc')
                ->join('user_categories as cat', 'cat.id', '=', 'uc.user_category_id')
                ->whereIn('uc.user_id', $allUserIds)
                ->orderBy('cat.name')
                ->get(['uc.user_id', 'cat.id', 'cat.name']);
            foreach ($catRows as $cr) {
                $uid = (int) $cr->user_id;
                $categoryMap[$uid][] = (string) $cr->name;
                if (!isset($primaryCategoryIdMap[$uid])) {
                    $primaryCategoryIdMap[$uid] = (int) $cr->id;
                }
            }
        }

        $bankMap = [];
        if ($allUserIds !== [] && Schema::hasTable('bank_accounts')) {
            $bankRows = DB::table('bank_accounts')
                ->whereIn('user_id', $allUserIds)
                ->orderByDesc('isSalaryAccount')
                ->orderBy('id')
                ->get(['user_id', 'account_no', 'address']);
            foreach ($bankRows as $br) {
                $uid = (int) $br->user_id;
                if (!isset($bankMap[$uid])) {
                    $bankMap[$uid] = [
                        'account_no' => (string) ($br->account_no ?? ''),
                        'address' => (string) ($br->address ?? ''),
                    ];
                }
            }
        }

        $generatedIds = [];
        if ($allUserIds !== [] && Schema::hasTable('salary_generates')) {
            $generatedIds = DB::table('salary_generates')
                ->whereIn('user_id', $allUserIds)
                ->where('salaryGenerationYear', $year)
                ->where('monthId', $month)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->flip()
                ->all();
        }

        $summariesByUser = $setupService->getSummariesForUsers($allUserIds, $year, $month);

        $regularNetCents = 0;
        $netArrearCents = 0;
        $netPayableCents = 0;
        $generatedCount = 0;
        $pendingCount = 0;

        $enriched = [];
        $eligiblePending = [];

        foreach ($baseRows as $row) {
            $uid = (int) $row->user_id;
            $fin = $summariesByUser[$uid] ?? null;
            $isGenerated = isset($generatedIds[$uid]);

            $basicPay = (float) ($fin['basic_pay'] ?? 0.0);
            $regularNet = (float) ($fin['regular_net'] ?? 0.0);
            $netArrear = (float) ($fin['net_arrear'] ?? 0.0);
            $netPayable = (float) ($fin['net_payable'] ?? 0.0);

            $regularNetCents += (int) round($regularNet * 100);
            $netArrearCents += (int) round($netArrear * 100);
            $netPayableCents += (int) round($netPayable * 100);

            if ($isGenerated) {
                $generatedCount++;
            } else {
                $pendingCount++;
            }

            $item = [
                'user_id' => $uid,
                'employee_code' => (string) $row->employee_code,
                'profile_count' => (int) $row->profile_count,
                'name' => (string) $row->name,
                'email' => $row->email,
                'is_active' => (bool) $row->is_active,
                'job_status' => $row->job_status,
                'employee_type' => $employeeTypesByUser[$uid],
                'hold_increment' => (bool) ($row->hold_increment ?? false),
                'department_office_id' => $row->department_office_id ? (int) $row->department_office_id : null,
                'pay_scale_id' => $row->pay_scale_id ? (int) $row->pay_scale_id : null,
                'payroll_group_id' => $row->payroll_group_id ? (int) $row->payroll_group_id : null,
                'category_id' => $primaryCategoryIdMap[$uid] ?? null,
                'department_name' => $row->department_name,
                'designation_name' => $row->designation_name,
                'pay_scale_name' => $fin['pay_scale'] ?? $row->pay_scale_name,
                'grade_name' => $fin['grade'] ?? $row->grade_name,
                'category_names' => isset($categoryMap[$uid]) ? implode(', ', array_unique($categoryMap[$uid])) : null,
                'bank_account_no' => $bankMap[$uid]['account_no'] ?? null,
                'bank_address' => $bankMap[$uid]['address'] ?? null,
                'basic_pay' => $basicPay,
                'regular_allowances' => (float) ($fin['regular_allowances'] ?? 0.0),
                'regular_deductions' => (float) ($fin['regular_deductions'] ?? 0.0),
                'regular_net' => $regularNet,
                'arrear_allowances' => (float) ($fin['arrear_allowances'] ?? 0.0),
                'arrear_deductions' => (float) ($fin['arrear_deductions'] ?? 0.0),
                'net_arrear' => $netArrear,
                'net_payable' => $netPayable,
                'is_generated' => $isGenerated,
                'status' => $isGenerated ? 'GENERATED' : 'PENDING',
            ];

            if ($status === 'PENDING' && $isGenerated) {
                continue;
            }
            if ($status === 'GENERATED' && !$isGenerated) {
                continue;
            }

            $enriched[] = $item;

            if (!$isGenerated && (bool) $row->is_active && (int) $row->profile_count === 1) {
                $eligiblePending[] = [
                    'user_id' => $uid,
                    'employee_code' => (string) $row->employee_code,
                    'name' => (string) $row->name,
                    'regular_net' => $regularNet,
                    'net_arrear' => $netArrear,
                    'net_payable' => $netPayable,
                ];
            }
        }

        $page = max(1, (int) $request->integer('page', 1));
        $totalFiltered = count($enriched);
        $slice = array_slice($enriched, ($page - 1) * $perPage, $perPage);
        $rows = (new LengthAwarePaginator(
            $slice,
            $totalFiltered,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        ));

        $summary = [
            'active_eligible' => count($baseRows),
            'generated_count' => $generatedCount,
            'pending_count' => $pendingCount,
            'regular_net_total' => $regularNetCents / 100,
            'net_arrear_total' => $netArrearCents / 100,
            'net_payable_total' => $netPayableCents / 100,
        ];

        $payrollGroupOptions = [];
        if (Schema::hasTable('payroll_groups') && Schema::hasTable('payroll_group_types')) {
            $pgQuery = DB::table('payroll_groups as pg')
                ->leftJoin('payroll_group_types as pgt', 'pgt.id', '=', 'pg.payroll_group_type_id');
            if (Schema::hasTable('grades')) {
                $pgQuery->leftJoin('grades as g', 'g.id', '=', 'pg.grade_id')
                    ->select('pg.id', 'pgt.group_name', 'g.grade_name');
            } else {
                $pgQuery->select('pg.id', 'pgt.group_name');
            }
            $payrollGroupOptions = $pgQuery->orderBy('pgt.group_name')->get()->map(function ($pg) {
                $gName = property_exists($pg, 'grade_name') && $pg->grade_name ? " ({$pg->grade_name})" : '';
                return [
                    'id' => (int) $pg->id,
                    'label' => trim((string) ($pg->group_name ?? "Group #{$pg->id}") . $gName),
                ];
            })->all();
        }

        $filterOptions = [
            'employee_types' => collect($employeeTypeCounts)
                ->map(fn ($count, $type) => ['value' => $type, 'count' => $count])
                ->values()->all(),
            'default_employee_types' => $defaultEmployeeTypes,
            'categories' => Schema::hasTable('user_categories')
                ? DB::table('user_categories')->orderBy('name')->get(['id', 'name'])
                : [],
            'departments' => Schema::hasTable('department_offices')
                ? DB::table('department_offices')->orderBy('name')->get(['id', 'name'])
                : [],
            'designations' => Schema::hasTable('payroll_group_types')
                ? DB::table('payroll_group_types')->whereNotNull('group_name')->where('group_name', '!=', '')->distinct()->orderBy('group_name')->pluck('group_name')->all()
                : [],
            'pay_scales' => Schema::hasTable('pay_scales')
                ? DB::table('pay_scales')->orderBy('scale_name')->get(['id', 'scale_name'])
                : [],
            'payroll_groups' => $payrollGroupOptions,
        ];

        $filters = [
            'search' => $search,
            'category_id' => $categoryId ? (string) $categoryId : '',
            'department_id' => $departmentId ? (string) $departmentId : '',
            'designation' => $designation,
            'employee_types' => $selectedEmployeeTypes,
            'status' => $status,
            'per_page' => (string) $perPage,
        ];

        return Inertia::render('Salary/Estimation/Workspace', [
            'rows' => $rows,
            'exportRows' => $enriched,
            'summary' => $summary,
            'eligiblePending' => $eligiblePending,
            'year' => $year,
            'month' => $month,
            'isLocked' => $isLocked,
            'filters' => $filters,
            'filterOptions' => $filterOptions,
        ]);
    }

    /**
     * Verified Bulk Calculate & Generate Salary for mutable periods (>= 2026-11).
     * Persists authoritative MonthlySalarySetupService breakdowns into salary_generates.
     * Strictly blocks <= 2026-10 via SalaryGenerationGuards::assertMonthMutable.
     */
    public function generateBulk(Request $request, MonthlySalarySetupService $setupService): JsonResponse
    {
        $this->hr($request);
        $validated = $request->validate([
            'year' => 'required|integer|between:2020,2100',
            'month' => 'required|integer|between:1,12',
            'user_ids' => 'required|array|min:1|max:50',
            'user_ids.*' => 'required|integer|min:1|distinct',
        ]);

        $year = (int) $validated['year'];
        $month = (int) $validated['month'];
        $userIds = array_values(array_unique(array_map('intval', $validated['user_ids'])));

        try {
            SalaryGenerationGuards::assertMonthMutable($year, $month, 'generated');
        } catch (HistoricalSalaryImmutableException|DomainException $e) {
            return response()->json([
                'status' => 422,
                'is_locked' => true,
                'message' => $e->getMessage(),
                'errors' => [$e->getMessage()],
            ], 422);
        }

        if (!Schema::hasTable('salary_generates')) {
            return response()->json([
                'status' => 422,
                'message' => 'salary_generates table is missing.',
                'errors' => ['salary_generates table is missing.'],
            ], 422);
        }

        $summaries = $setupService->getSummariesForUsers($userIds, $year, $month);
        $salaryDate = CarbonImmutable::create($year, $month, 1)->endOfMonth()->toDateString();

        $successCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($userIds as $uid) {
            try {
                DB::transaction(function () use (
                    $uid,
                    $year,
                    $month,
                    $salaryDate,
                    $summaries,
                    &$successCount,
                    &$skippedCount
                ): void {
                    $existing = SalaryGenerate::query()
                        ->where('user_id', $uid)
                        ->where('salaryGenerationYear', $year)
                        ->where('monthId', $month)
                        ->exists();
                    if ($existing) {
                        $skippedCount++;
                        return;
                    }

                    $user = User::query()->whereKey($uid)->first();
                    if (!$user || !(bool) $user->isActive) {
                        throw new DomainException('Employee account is inactive or missing.');
                    }

                    $profiles = DB::table('employee_infos')->where('user_id', $uid)->get();
                    if ($profiles->count() !== 1) {
                        throw new DomainException('Employee must have exactly one payroll profile.');
                    }
                    $profile = $profiles->first();

                    if (!isset($summaries[$uid])) {
                        throw new DomainException('Unable to compute monthly salary breakdown.');
                    }
                    $fin = $summaries[$uid];

                    $designation = Schema::hasTable('payroll_groups') && Schema::hasTable('payroll_group_types') && $profile->payroll_group_id
                        ? (string) (DB::table('payroll_groups as pg')
                            ->join('payroll_group_types as pgt', 'pgt.id', '=', 'pg.payroll_group_type_id')
                            ->where('pg.id', $profile->payroll_group_id)
                            ->value('pgt.group_name') ?? 'N/A')
                        : 'N/A';

                    $bankRow = Schema::hasTable('bank_accounts')
                        ? DB::table('bank_accounts')->where('user_id', $uid)->orderByDesc('isSalaryAccount')->orderBy('id')->first()
                        : null;
                    $phoneNo = Schema::hasTable('phone_numbers') && Schema::hasTable('phone_number_users')
                        ? (string) ($user->phoneNumbers()->orderBy('phone_numbers.id')->value('phoneNumber') ?? 'N/A')
                        : 'N/A';
                    $ageVal = Schema::hasTable('ages')
                        ? (string) (DB::table('ages')->where('user_id', $uid)->value('age') ?? 'N/A')
                        : 'N/A';
                    $joiningDate = Schema::hasTable('joining_dates')
                        ? (string) (DB::table('joining_dates')->where('user_id', $uid)->value('joining_date') ?? $salaryDate)
                        : $salaryDate;

                    $allowancesObj = [];
                    foreach ($fin['allowances'] as $row) {
                        if (abs((float) $row['total_amount']) < 0.005 && abs((float) $row['current_amount']) < 0.005) {
                            continue;
                        }
                        $allowancesObj[] = [
                            'payroll_component_type_id' => (int) $row['payroll_component_type_id'],
                            'name' => (string) $row['name'],
                            'calcType' => 'fixed',
                            'amount' => (float) $row['current_amount'],
                            'current_amount' => (float) $row['current_amount'],
                            'arrear_amount' => (float) $row['arrear_amount'],
                            'calculated_amount' => (float) $row['total_amount'],
                            'component_type' => 'allowance',
                        ];
                    }

                    $deductionsObj = [];
                    $taxDeduction = 0.0;
                    $gpfDeduction = 0.0;
                    foreach ($fin['deductions'] as $row) {
                        $totalAmt = (float) $row['total_amount'];
                        $lowerName = strtolower((string) $row['name']);
                        if (str_contains($lowerName, 'income tax')) {
                            $taxDeduction += $totalAmt;
                        }
                        if (SalaryCalculationService::isGpf(['name' => $row['name']])) {
                            $gpfDeduction += $totalAmt;
                        }
                        if (abs($totalAmt) < 0.005 && abs((float) $row['current_amount']) < 0.005) {
                            continue;
                        }
                        $deductionsObj[] = [
                            'payroll_component_type_id' => (int) $row['payroll_component_type_id'],
                            'name' => (string) $row['name'],
                            'calcType' => 'fixed',
                            'amount' => (float) $row['current_amount'],
                            'current_amount' => (float) $row['current_amount'],
                            'arrear_amount' => (float) $row['arrear_amount'],
                            'calculated_amount' => $totalAmt,
                            'component_type' => 'deduction',
                        ];
                    }

                    SalaryGenerate::create([
                        'user_id' => $uid,
                        'reviewStatus' => 'pending',
                        'name' => (string) $user->name,
                        'age' => $ageVal,
                        'gender' => (string) ($user->gender ?? 'N/A'),
                        'payScale' => (string) ($fin['pay_scale'] ?? 'Govt. Pay Scale 2015'),
                        'grade' => (string) ($fin['grade'] ?? 'N/A'),
                        'designation' => $designation,
                        'employeeCode' => (string) $profile->employeeCode,
                        'bankAccountNo' => (string) ($bankRow->account_no ?? 'N/A'),
                        'bankAddress' => (string) ($bankRow->address ?? 'N/A'),
                        'employeePhoneNumber' => $phoneNo,
                        'joiningDate' => $joiningDate ?: $salaryDate,
                        'salaryGenerationDate' => $salaryDate,
                        'monthId' => $month,
                        'salaryGenerationYear' => $year,
                        'salaryAllowanceCalcObject' => $allowancesObj,
                        'salaryDeductionCalcObject' => $deductionsObj,
                        'totalAllowance' => round((float) $fin['total_allowances'], 2),
                        'totalDeduction' => round((float) $fin['total_deductions'], 2),
                        'taxDeduction' => round($taxDeduction, 2),
                        'gpfDeduction' => round($gpfDeduction, 2),
                        'salaryTotal' => round((float) $fin['net_payable'], 2),
                        'bonusAllowanceCalcObject' => [],
                        'bonusDeductionCalcObject' => [],
                        'bonusAllowance' => 0,
                        'bonusDeduction' => 0,
                        'bonusTaxDeduction' => 0,
                        'bonusTotal' => 0,
                        'loanInstallmentDeduction' => 0,
                        'loanInstallmentCount' => null,
                        'loanInstallmentRemaining' => null,
                    ]);

                    $successCount++;
                });
            } catch (Throwable $e) {
                $failedCount++;
                $userName = User::query()->whereKey($uid)->value('name') ?? "User #{$uid}";
                $errors[] = "{$userName}: {$e->getMessage()}";
            }
        }

        return response()->json([
            'status' => 200,
            'success_count' => $successCount,
            'skipped_count' => $skippedCount,
            'failed_count' => $failedCount,
            'errors' => $errors,
            'message' => sprintf(
                'Generated %d salary bill(s)%s%s.',
                $successCount,
                $skippedCount > 0 ? " ({$skippedCount} already existed)" : '',
                $failedCount > 0 ? " ({$failedCount} failed)" : ''
            ),
        ]);
    }

    /** Cursor batches return only identity/revision metadata; never update financial records. */
    public function selection(Request $request): JsonResponse
    {
        $this->hr($request);
        $data = $this->directoryRequest($request);
        $data['status'] = $data['status'] ?? 'ALL';
        $paging = $request->validate(['cursor' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|between:1,25']);
        $cursor = (int)($paging['cursor'] ?? 0);
        $limit = (int)($paging['limit'] ?? 25);
        $year = (int)($data['year'] ?? 2026);
        $month = (int)($data['month'] ?? 11);
        $query = $this->directoryQuery($data, $year, $month)
            ->where('ep.profile_count', 1)
            ->where(fn ($q) => $q->whereNull('w.id')->orWhereIn('w.workflow_status', ['BLOCKED', 'CALCULATED']));
        $items = (clone $query)->where('ep.user_id', '>', $cursor)
            ->select('ep.user_id', 'ep.employee_code', 'w.workflow_status', 'w.revision',
                'w.active_calculation_version_id')
            ->orderBy('ep.user_id')->limit($limit)->get();
        $nextCursor = $items->count() ? (int)$items->last()->user_id : $cursor;
        $hasMore = (clone $query)->where('ep.user_id', '>', $nextCursor)->exists();
        return response()->json(['items' => $items, 'cursor' => $nextCursor, 'has_more' => $hasMore]);
    }

    /** Only report identities matching the applied directory filters; no payroll writes. */
    public function horizontalReportIds(Request $request): array
    {
        $this->hr($request);
        $data = $this->directoryRequest($request);
        $data['status'] = $data['status'] ?? 'ALL';
        $year = (int) ($data['year'] ?? 2026);
        $month = (int) ($data['month'] ?? 11);
        $ids = $this->directoryQuery($data, $year, $month)
            ->select('ep.user_id')->distinct()->orderBy('ep.user_id')
            ->limit(501)->pluck('ep.user_id')->map(fn ($id) => (int) $id)->all();
        if (count($ids) > 500) {
            throw ValidationException::withMessages([
                'filters' => 'More than 500 employees match. Refine the filters before exporting.',
            ]);
        }
        return $ids;
    }

    public function start(Request $request, SalaryWorkflowRosterService $roster): JsonResponse
    {
        $this->hr($request);
        $data = $request->validate([
            'year' => 'required|integer|between:2020,2100',
            'month' => 'required|integer|between:1,12',
            'user_ids' => 'sometimes|array|min:1|max:300',
            'user_ids.*' => 'required|integer|min:1|distinct',
            'employee_codes' => 'sometimes|array|min:1|max:100',
            'employee_codes.*' => 'required|string|max:32|distinct'
        ]);
        $year = (int)$data['year'];
        $month = (int)$data['month'];
        if (!Schema::hasTable('salary_estimation_runs')) abort(409, 'PATCH 05 migration missing');
        if (isset($data['user_ids']) && isset($data['employee_codes'])) throw ValidationException::withMessages(['employee_codes' => 'Choose either employee codes or user IDs, not both.']);
        if (isset($data['employee_codes'])) {
            $codes = $data['employee_codes'];
            $profiles = DB::table('employee_infos')->whereIn('employeeCode', $codes)->get(['employeeCode', 'user_id']);
            if (
                $profiles->count() !== count($codes) || $profiles->pluck('employeeCode')->unique()->count() !== count($codes)
                || $profiles->pluck('user_id')->unique()->count() !== count($codes)
            ) {
                throw ValidationException::withMessages(['employee_codes' => 'Employee code mapping is missing or ambiguous.']);
            }
            $ids = $profiles->pluck('user_id')->map(fn($id) => (int)$id)->all();
            // Explicit POST run creation may initialize missing workflow rows without posting money.
            $roster->reconcile($year, $month, $ids, count($ids));
            $available = DB::table('salary_monthly_workflows')->where('salary_year', $year)->where('salary_month', $month)
                ->whereIn('user_id', $ids)->count();
            if ($available !== count($ids)) throw ValidationException::withMessages(['employee_codes' => 'Reconcile all selected employee-period workflows first.']);
        } elseif (isset($data['user_ids'])) {
            $ids = array_map('intval', $data['user_ids']);
            $roster->reconcile($year, $month, $ids, count($ids));
            $available = DB::table('salary_monthly_workflows')->where('salary_year', $year)
                ->where('salary_month', $month)->whereIn('user_id', $ids)->count();
            if ($available !== count($ids)) throw ValidationException::withMessages(['user_ids' => 'All selected employees must exist in the reconciled roster.']);
        } else {
            $ids = DB::table('salary_monthly_workflows')->where('salary_year', $year)
                ->where('salary_month', $month)->orderBy('user_id')->pluck('user_id')->map(fn($x) => (int)$x)->all();
        }
        // Immutable selection stored explicitly: subsequent roster changes cannot alter run membership.
        $runId = DB::transaction(function () use ($year, $month, $ids, $request) {
            $id = DB::table('salary_estimation_runs')->insertGetId([
                'salary_year' => $year,
                'salary_month' => $month,
                'created_by' => $request->user()->id,
                'status' => 'running',
                'last_user_id' => 0,
                'created_at' => now(),
                'updated_at' => now()
            ]);
            foreach (array_chunk($ids, 200) as $chunk) {
                DB::table('salary_estimation_run_items')->insert(array_map(fn($uid) => [
                    'salary_estimation_run_id' => $id,
                    'user_id' => $uid,
                    'result' => 'pending',
                    'created_at' => now()
                ], $chunk));
            }
            return $id;
        });
        return response()->json(['run_id' => $runId, 'selected' => count($ids), 'status' => 'running'], 201);
    }

    public function next(
        Request $request,
        int $run,
        SalaryBillPostingService $posting,
        SalaryFinancialSourceFingerprint $fingerprint
    ): JsonResponse {
        $this->hr($request);
        $row = DB::table('salary_estimation_runs')->where('id', $run)->first();
        abort_unless($row, 404);
        if ($row->status === 'completed') return response()->json(['run' => $row, 'processed' => 0]);
        $items = DB::table('salary_estimation_run_items')->where('salary_estimation_run_id', $run)
            ->where('result', 'pending')->orderBy('user_id')->limit(10)->get();
        $done = 0;
        foreach ($items as $item) {
            // Each step is idempotent and uses a separate lock. Concurrent calls cannot double count.
            DB::transaction(function () use ($item, $row, $posting, $fingerprint, &$done) {
                $state = DB::table('salary_estimation_run_items')->where('id', $item->id)->lockForUpdate()->first();
                if (!$state || $state->result !== 'pending') return;
                $result = 'blocked';
                $error = null;
                $hash = null;
                $preview = null;
                try {
                    $workflow = DB::table('salary_monthly_workflows')->where('user_id', $item->user_id)
                        ->where('salary_year', $row->salary_year)->where('salary_month', $row->salary_month)->first();
                    if (!$workflow || $workflow->workflow_status === 'GENERATED') {
                        throw new \DomainException('Missing roster or already generated; no estimation performed.');
                    }
                    if (DB::table('employee_infos')->where('user_id', $item->user_id)->count() !== 1) {
                        throw new \DomainException('Ambiguous employee profile.');
                    }
                    if (DB::table('salary_generates')->where('user_id', $item->user_id)
                        ->where('salaryGenerationYear', $row->salary_year)->where('monthId', $row->salary_month)->exists()
                    ) {
                        throw new \DomainException('Posted bill exists; estimation skipped.');
                    }
                    $hash = $fingerprint->forEmployeePeriod((int)$item->user_id, (int)$row->salary_year, (int)$row->salary_month);
                    $preview = $posting->preview((int)$item->user_id, (int)$row->salary_year, (int)$row->salary_month);
                    if (!is_array($preview)) throw new \DomainException('Preview service returned no calculation.');
                    $result = 'estimated';
                } catch (Throwable $exception) {
                    $error = substr($exception->getMessage(), 0, 1800);
                }
                DB::table('salary_estimation_run_items')->where('id', $item->id)->update([
                    'result' => $result,
                    'error_message' => $error,
                    'source_hash' => $hash,
                    'preview_result' => $preview === null ? null : json_encode($preview, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                ]);
                DB::table('salary_estimation_runs')->where('id', $row->id)->increment('examined');
                DB::table('salary_estimation_runs')->where('id', $row->id)->increment($result === 'estimated' ? 'estimated' : 'blocked');
                $done++;
            }, 3);
        }
        if (!DB::table('salary_estimation_run_items')->where('salary_estimation_run_id', $run)->where('result', 'pending')->exists()) {
            DB::table('salary_estimation_runs')->where('id', $run)->update(['status' => 'completed', 'updated_at' => now()]);
        }
        return response()->json(['run' => DB::table('salary_estimation_runs')->where('id', $run)->first(), 'processed' => $done]);
    }

    public function errors(Request $request, int $run): JsonResponse
    {
        $this->hr($request);
        abort_unless(DB::table('salary_estimation_runs')->where('id', $run)->exists(), 404);
        return response()->json(DB::table('salary_estimation_run_items')->where('salary_estimation_run_id', $run)
            ->where('result', 'blocked')->orderBy('user_id')->paginate(50));
    }
}
