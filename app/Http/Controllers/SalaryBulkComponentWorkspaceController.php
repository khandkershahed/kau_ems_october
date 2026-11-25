<?php

namespace App\Http\Controllers;

use App\Models\DepartmentOffice;
use App\Models\EmployeeInfo;
use App\Models\PayrollComponentType;
use App\Models\UserCategory;
use App\Services\ApprovedFixationResolver;
use App\Services\SalaryComponentCatalog;
use App\Services\SalaryComponentNormalizer;
use App\Services\SalaryEmployeeComponentOverrideService;
use App\Services\SalaryGenerationGuards;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Throwable;

/**
 * Dynamic DB-driven multi-employee bulk workspace.
 * Replaces the legacy hardcoded 18-employee list with active employee filtering
 * by Category, Department, and Search while keeping the existing bulk workflow intact.
 */
final class SalaryBulkComponentWorkspaceController extends Controller
{
    public function index(
        Request $request,
        ApprovedFixationResolver $fixations,
        SalaryComponentCatalog $catalog,
        SalaryEmployeeComponentOverrideService $overrides
    ) {
        abort_unless($request->user(), 401);

        $filter = $request->validate([
            'year' => 'nullable|integer|between:2020,2100',
            'month' => 'nullable|integer|between:1,12',
            'codes' => 'nullable|string|max:1500',
            'search' => 'nullable|string|max:100',
            'user_category_id' => 'nullable|integer|min:1',
            'department_office_id' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|between:1,100',
        ]);

        $year = (int) ($filter['year'] ?? 2026);
        $month = (int) ($filter['month'] ?? 11);
        $search = trim((string) ($filter['search'] ?? ''));
        $categoryId = isset($filter['user_category_id']) ? (int) $filter['user_category_id'] : null;
        $departmentId = isset($filter['department_office_id']) ? (int) $filter['department_office_id'] : null;
        $limit = (int) ($filter['limit'] ?? 50);
        $isLocked = SalaryGenerationGuards::isMonthLocked($year, $month);

        $explicitCodes = [];
        if (isset($filter['codes']) && trim((string) $filter['codes']) !== '') {
            $explicitCodes = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $filter['codes'])))));
            if (count($explicitCodes) > 100 || array_filter($explicitCodes, fn ($c) => !preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $c))) {
                abort(422, 'Invalid employee code list.');
            }
        }

        // Dynamic DB-driven active employee query
        $employeeQuery = DB::table('employee_infos as ei')
            ->join('users as u', 'u.id', '=', 'ei.user_id')
            ->whereNotNull('ei.user_id')
            ->where('u.isActive', true)
            ->select('ei.employeeCode');

        if ($explicitCodes !== []) {
            $employeeQuery->whereIn('ei.employeeCode', $explicitCodes);
        }

        if ($search !== '') {
            $employeeQuery->where(function ($q) use ($search): void {
                $q->where('ei.employeeCode', 'like', '%' . $search . '%')
                    ->orWhere('u.name', 'like', '%' . $search . '%');
            });
        }

        if ($categoryId !== null && Schema::hasTable('user_user_categories')) {
            $employeeQuery->whereExists(function ($q) use ($categoryId): void {
                $q->select(DB::raw(1))
                    ->from('user_user_categories as uuc')
                    ->whereColumn('uuc.user_id', 'u.id')
                    ->where('uuc.user_category_id', $categoryId);
            });
        }

        if ($departmentId !== null) {
            $employeeQuery->where(function ($q) use ($departmentId): void {
                $q->where('ei.department_office_id', $departmentId);
                if (Schema::hasTable('department_office_users')) {
                    $q->orWhereExists(function ($sub) use ($departmentId): void {
                        $sub->select(DB::raw(1))
                            ->from('department_office_users as dou')
                            ->whereColumn('dou.user_id', 'u.id')
                            ->where('dou.department_office_id', $departmentId);
                    });
                }
            });
        }

        $totalMatched = (clone $employeeQuery)->distinct('ei.employeeCode')->count('ei.employeeCode');
        $codes = $explicitCodes !== []
            ? $explicitCodes
            : $employeeQuery->orderBy('ei.employeeCode')->limit($limit)->pluck('ei.employeeCode')->unique()->values()->all();

        $masters = PayrollComponentType::query()->get(['id', 'name'])->keyBy('id');
        $result = [];

        foreach ($codes as $code) {
            $profiles = EmployeeInfo::query()->where('employeeCode', $code)->limit(3)->get();
            if ($profiles->count() !== 1) {
                $result[] = [
                    'code' => $code,
                    'error' => $profiles->isEmpty() ? 'Employee code not found' : 'Ambiguous employee code / duplicate profiles',
                    'components' => [],
                ];
                continue;
            }

            $employee = $profiles->first();
            $uid = (int) $employee->user_id;
            if (EmployeeInfo::where('user_id', $uid)->count() !== 1) {
                $result[] = ['code' => $code, 'error' => 'Multiple employee profiles for user', 'components' => []];
                continue;
            }

            $workflow = Schema::hasTable('salary_monthly_workflows')
                ? DB::table('salary_monthly_workflows')->where('user_id', $uid)->where('salary_year', $year)->where('salary_month', $month)->first()
                : null;
            $billExists = Schema::hasTable('salary_generates')
                ? DB::table('salary_generates')->where('user_id', $uid)->where('salaryGenerationYear', $year)->where('monthId', $month)->exists()
                : false;
            $status = $workflow?->workflow_status ?? 'NOT_INITIALIZED';

            $row = [
                'code' => $code,
                'user_id' => $uid,
                'name' => DB::table('users')->where('id', $uid)->value('name'),
                'status' => $status,
                'revision' => (int) ($workflow?->revision ?? 0),
                'active_calculation_version_id' => $workflow?->active_calculation_version_id,
                'ready_calculation_version_id' => $workflow?->ready_calculation_version_id,
                'latest_approved_calculation_version_id' => Schema::hasTable('salary_calculation_versions')
                    ? DB::table('salary_calculation_versions')
                        ->where('user_id', $uid)
                        ->where('salary_year', $year)
                        ->where('salary_month', $month)
                        ->where('status', 'approved')
                        ->orderByDesc('version')
                        ->value('id')
                    : null,
                'error' => null,
                'components' => [],
                'editable' => !$isLocked && !$billExists && in_array($status, ['BLOCKED', 'CALCULATED'], true),
            ];

            if (!$row['editable']) {
                $row['error'] = $isLocked
                    ? sprintf('Historical month %04d-%02d is locked (read-only)', $year, $month)
                    : ($billExists ? 'Existing posted bill — editing prohibited' : 'Initialize/revise the monthly workflow before editing');
            }

            try {
                $fix = $fixations->forUser($uid, $year, $month);
                [$add, $ded] = $catalog->forEmployee(
                    $employee,
                    SalaryComponentNormalizer::rows($employee->additionObjects),
                    SalaryComponentNormalizer::rows($employee->substractionObjects),
                    $year,
                    $month,
                    $fix
                );
                $saved = $overrides->rows($uid, $year, $month);
                foreach (['addition' => $add, 'deduction' => $ded] as $direction => $items) {
                    foreach ($items as $item) {
                        $typeId = (int) ($item['payroll_component_type_id'] ?? $item['payroll_component_type']['id'] ?? 0);
                        $title = trim((string) ($item['name'] ?? ''));
                        $master = $masters->get($typeId);
                        if (!$master || trim((string) $master->name) !== $title || SalaryEmployeeComponentOverrideService::basic($title)) {
                            continue;
                        }
                        $matches = array_values(array_filter(
                            $saved,
                            fn ($o) => $o['direction'] === $direction && (int) ($o['payroll_component_type_id'] ?? 0) === $typeId
                        ));
                        $o = count($matches) === 1 ? $matches[0] : null;
                        $row['components'][] = [
                            'direction' => $direction,
                            'title' => $title,
                            'type_id' => $typeId,
                            'calc_type' => $o['calc_type'] ?? strtolower((string) ($item['calcType'] ?? 'fixed')),
                            'value' => $o['value'] ?? ($item['amount'] ?? 0),
                            'mode' => $o['mode'] ?? 'manual',
                            'basis' => $o['basis'] ?? (strtolower((string) ($item['calcType'] ?? 'fixed')) === 'percentage' ? 'payable_basic' : 'none'),
                            'legacy_basic_reference' => $o['legacy_basic_reference'] ?? null,
                            'expected_revision' => (int) ($o['revision'] ?? 0),
                        ];
                    }
                }
            } catch (Throwable $e) {
                $row['editable'] = false;
                $row['error'] = $e->getMessage();
            }

            $result[] = $row;
        }

        $userCategories = Schema::hasTable('user_categories')
            ? UserCategory::query()->orderBy('name')->get(['id', 'name'])
            : [];
        $departmentOffices = Schema::hasTable('department_offices')
            ? DepartmentOffice::query()->orderBy('name')->get(['id', 'name'])
            : [];

        return Inertia::render('Salary/Estimation/BulkComponents', [
            'employees' => $result,
            'year' => $year,
            'month' => $month,
            'isLocked' => $isLocked,
            'codes' => implode(',', $explicitCodes),
            'totalMatched' => $totalMatched,
            'userCategories' => $userCategories,
            'departmentOffices' => $departmentOffices,
            'filters' => [
                'search' => $search,
                'user_category_id' => $categoryId ? (string) $categoryId : '',
                'department_office_id' => $departmentId ? (string) $departmentId : '',
                'limit' => $limit,
            ],
        ]);
    }
}
