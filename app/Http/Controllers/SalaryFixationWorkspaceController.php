<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EmployeeInfo;
use App\Models\PayrollComponentType;
use App\Models\SalaryFixation;
use App\Models\User;
use App\Services\ApprovedFixationResolver;
use App\Services\SalaryComponentCatalog;
use App\Services\SalaryComponentNormalizer;
use App\Services\SalaryEmployeeComponentOverrideService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Read-only unified HR projection; edits delegate to the existing override endpoint. */
final class SalaryFixationWorkspaceController extends Controller
{
    public function show(Request $request, User $user, ApprovedFixationResolver $resolver, SalaryComponentCatalog $catalog, SalaryEmployeeComponentOverrideService $overrides)
    {
        // abort_unless($request->user() && $request->user()->roles()->where('is_admin', 1)->where('isActive', 1)->exists(), 403);
        $data = $request->validate(['year' => 'required|integer|between:2020,2100', 'month' => 'required|integer|between:1,12']);
        $year = (int) $data['year'];
        $month = (int) $data['month'];
        $profiles = EmployeeInfo::where('user_id', $user->id)->limit(2)->get();
        if ($profiles->count() !== 1) {
            return Inertia::render('Salary/Fixation/Workspace', [
                'employee' => ['id' => $user->id, 'name' => $user->name, 'code' => null],
                'year' => $year, 'month' => $month, 'error' => 'Exactly one employee profile is required before financial configuration.',
                'fixation' => null, 'components' => [], 'overrides' => [], 'workflow' => null,
            ]);
        }
        $profile = $profiles->first();
        $fixation = $resolver->forUser($user->id, $year, $month);
        $workflow = DB::table('salary_monthly_workflows')->where('user_id', $user->id)
            ->where('salary_year', $year)->where('salary_month', $month)->first();
        $error = null;
        $components = [];
        try {
            [$add, $ded] = $catalog->forEmployee(
                $profile, SalaryComponentNormalizer::rows($profile->additionObjects),
                SalaryComponentNormalizer::rows($profile->substractionObjects), $year, $month, $fixation
            );
            $masters = PayrollComponentType::query()->get(['id', 'name'])->keyBy('id');
            foreach (['addition' => $add, 'deduction' => $ded] as $direction => $rows) {
                foreach ($rows as $row) {
                    $id = (int)($row['payroll_component_type_id'] ?? $row['payroll_component_type']['id'] ?? 0);
                    $name = trim((string)($row['name'] ?? ''));
                    if ($name === '') continue;
                    $master = $id ? $masters->get($id) : null;
                    // Only an exact master match can be edited here. Unmapped legacy rows remain visible.
                    $editable = $master && trim((string)$master->name) === $name
                        && !SalaryEmployeeComponentOverrideService::basic($name);
                    $components[] = [
                        'direction' => $direction, 'name' => $name, 'type_id' => $editable ? $id : null,
                        'calc_type' => strtolower((string)($row['calcType'] ?? 'fixed')),
                        'value' => (float)($row['amount'] ?? 0),
                        'source' => (string)($row['component_source'] ?? 'configured'),
                        'editable' => (bool)$editable,
                    ];
                }
            }
        } catch (DomainException $exception) {
            $error = $exception->getMessage();
        }
        $saved = $overrides->rows($user->id, $year, $month);
        $latestFixation = SalaryFixation::where('user_id', $user->id)->latest('id')->first();
        return Inertia::render('Salary/Fixation/Workspace', [
            'employee' => ['id' => $user->id, 'name' => $user->name, 'code' => $profile->employeeCode],
            'year' => $year, 'month' => $month, 'error' => $error,
            'fixation' => [
                'status' => $latestFixation?->status,
                'id' => $latestFixation?->id,
                'effective_basic' => $fixation['basic'] ?? $fixation['new_basic'] ?? null,
                'effective_date' => $fixation['effective_date'] ?? null,
                'reference' => $fixation['pay_scale_reference'] ?? null,
                'current_profile_basic' => $profile->basic ?? null,
            ],
            'components' => $components, 'overrides' => $saved,
            'workflow' => $workflow ? [
                'status' => $workflow->workflow_status, 'revision' => (int)$workflow->revision,
                'calculation_version_id' => $workflow->active_calculation_version_id,
            ] : null,
        ]);
    }
}
