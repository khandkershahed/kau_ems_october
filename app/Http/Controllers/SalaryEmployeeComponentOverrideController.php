<?php

namespace App\Http\Controllers;

use App\Models\EmployeeInfo;
use App\Models\PayrollComponentType;
use App\Models\User;
use App\Services\ApprovedFixationResolver;
use App\Services\SalaryComponentCatalog;
use App\Services\SalaryComponentNormalizer;
use App\Services\SalaryEmployeeComponentOverrideService as Overrides;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Single-component, month-scoped change; never posts a bill or touches GPF ledger. */
final class SalaryEmployeeComponentOverrideController extends Controller
{
    public function save(Request $request, User $user, Overrides $service)
    {
        abort_unless($request->user(), 401); // PATCH 06: role-free authenticated operator
        $data = $request->validate([
            'year'=>'required|integer|between:2020,2100',
            'month'=>'required|integer|between:1,12',
            'direction'=>'required|in:addition,deduction',
            'component_name'=>'required|string|max:160',
            'payroll_component_type_id'=>'nullable|integer|min:1|exists:payroll_component_types,id',
            'custom'=>'required|boolean',
            'mode'=>'required|in:manual,disabled,automatic',
            'calc_type'=>'nullable|in:fixed,percentage',
            'value'=>'nullable|numeric|min:0|max:1000000000',
            'basis'=>'nullable|in:none,payable_basic,legacy_basic',
            'legacy_basic_reference'=>'nullable|numeric|gt:0|max:1000000000',
            'reason'=>'nullable|string|max:2000',
            'evidence_reference'=>'nullable|string|max:500',
            'expected_revision'=>'required|integer|min:0',
        ]);
        if (!Schema::hasTable('salary_component_employee_overrides')) {
            return response()->json(['message'=>'Run Step 03 migration before saving overrides.'], 422);
        }
        try {
            $year=(int) $data['year']; $month=(int) $data['month'];
            $custom=(bool) $data['custom'];
            $name=trim($data['component_name']);
            $master = !empty($data['payroll_component_type_id'])
                ? PayrollComponentType::find((int) $data['payroll_component_type_id']) : null;
            if (!empty($data['payroll_component_type_id']) && !$master) {
                throw new DomainException('Selected payroll component no longer exists.');
            }
            if ($master && $name !== trim((string) $master->name)) {
                throw new DomainException('Component title must match the selected database master record.');
            }
            if (Overrides::basic($name)) throw new DomainException('Basic Pay cannot be changed through component overrides.');
            // Historical custom overrides are editable/resettable, but a NEW
            // regular component must be chosen from the configured master list.
            if ($custom && (int) $data['expected_revision'] === 0) {
                throw new DomainException('Choose an existing database component for a new regular override.');
            }
            if ($custom && !empty($data['payroll_component_type_id'])) {
                throw new DomainException('A custom component must not claim an existing component type ID.');
            }
            $typeId=$custom?null:($data['payroll_component_type_id'] ?? null);
            $key=Overrides::key($typeId, $name, $custom);
            $profile=EmployeeInfo::with(['payrollGroup.grade', 'payrollGroup.payrollGroupType'])
                ->where('user_id', $user->id)->limit(2)->get();
            if ($profile->count() !== 1) throw new DomainException('Exactly one salary profile is required.');
            $employee=$profile->first();
            $fixation=app(ApprovedFixationResolver::class)->forUser($user->id, $year, $month);
            [$add,$ded]=app(SalaryComponentCatalog::class)->forEmployee(
                $employee, SalaryComponentNormalizer::rows($employee->additionObjects),
                SalaryComponentNormalizer::rows($employee->substractionObjects), $year,$month,$fixation);
            $source=$data['direction']==='addition'?$add:$ded;
            if ($custom) {
                foreach ($source as $r) {
                    if (strcasecmp(trim((string)($r['name']??'')), $name)===0) {
                        throw new DomainException('Custom component has the same name as an existing salary component.');
                    }
                }
            }
            if (!$custom) {
                $matches=array_values(array_filter($source, static function ($row) use ($key, $data, $name) {
                    if (isset($row['manual_adjustment_id'])) return false;
                    $id=(int) ($row['payroll_component_type_id'] ?? $row['payroll_component_type']['id'] ?? 0);
                    return Overrides::key($id?:null, (string) ($row['name']??'')) === $key
                        || ($id === 0 && !empty($data['payroll_component_type_id'])
                            && strcasecmp(trim((string) ($row['name'] ?? '')), $name) === 0);
                }));
                if (count($matches)!==1) throw new DomainException('Component must match exactly one configured entry for this employee and direction.');
                // A legacy title-only record may already override this same
                // component. Never create a parallel ID-based override for it.
                if ($typeId) {
                    $legacyDuplicates = DB::table('salary_component_employee_overrides')
                        ->where('user_id', $user->id)->where('salary_year', $year)
                        ->where('salary_month', $month)->where('direction', $data['direction'])
                        ->whereNull('payroll_component_type_id')->where('component_title', $name)
                        ->where('component_key', '!=', $key)->exists();
                    if ($legacyDuplicates) {
                        throw new DomainException('A legacy title-only override already covers this component. Reconcile it before creating a master-ID override.');
                    }
                }
                if ($typeId && (int) ($matches[0]['payroll_component_type_id'] ?? $matches[0]['payroll_component_type']['id'] ?? 0) === 0) {
                    $targetDirection = $data['direction'] === 'addition' ? ['allowance','addition'] : ['deduction'];
                    $hasAssignment = $master->payrollComponents()
                        ->where('payroll_group_id', $employee->payroll_group_id)
                        ->where('isActive', true)->whereIn('component_type', $targetDirection)->exists();
                    if (!$hasAssignment) throw new DomainException('Legacy component cannot be mapped without an active same-direction assignment.');
                }
                if (Overrides::basic((string) ($matches[0]['name']??''))) throw new DomainException('Basic Pay cannot be overridden.');
            }
            $mode=$data['mode'];
            $calcType=$mode==='manual'?($data['calc_type']??null):null;
            $value=$mode==='manual'?($data['value']??null):null;
            $basis=$mode==='manual' ? ($calcType==='fixed'?'none':($data['basis']??null)) : null;
            $legacy=$mode==='manual' && $basis==='legacy_basic' ? ($data['legacy_basic_reference']??null) : null;
            if ($mode==='manual') {
                if (!in_array($calcType,['fixed','percentage'],true) || !is_numeric($value)
                    || ($calcType==='percentage' && ((float) $value > 100
                        || !in_array($basis,['payable_basic','legacy_basic'],true)))
                    || ($basis==='legacy_basic' && (!is_numeric($legacy) || (float)$legacy<=0))) {
                    throw ValidationException::withMessages(['value'=>'Select a valid Fixed/Percentage rule and calculation basis.']);
                }
            }
            if ($custom && $mode==='disabled' && (int) $data['expected_revision']===0) {
                throw new DomainException('A new custom component must have a manual value.');
            }
            // Preview of proposed resolution BEFORE any database write.
            if ($mode !== 'automatic') {
                $existingState = [
                    'id'=>0,'component_key'=>$key,'payroll_component_type_id'=>$typeId,
                    'component_title'=>$name,'direction'=>$data['direction'],'mode'=>$mode,
                    'calc_type'=>$calcType,'value'=>$value,'basis'=>$basis,
                    'legacy_basic_reference'=>$legacy,
                    'reason'=>$data['reason']??null,'evidence_reference'=>$data['evidence_reference']??null,
                ];
                Overrides::apply($add,$ded,[$existingState]);
            }
            return DB::transaction(function () use ($request,$user,$year,$month,$key,$data,$typeId,$name,$mode,$calcType,$value,$basis,$legacy) {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                // Lock workflow before changing financially approved inputs.
                $workflow = DB::table('salary_monthly_workflows')
                    ->where('user_id', $user->id)->where('salary_year', $year)
                    ->where('salary_month', $month)->lockForUpdate()->first();
                if (!$workflow) {
                    // V1 usability: initialize a missing employee-period record on first edit.
                    // The existing canonical reconciler preserves posted bills and records ambiguities.
                    app(\App\Services\SalaryWorkflowRosterService::class)->reconcile($year, $month, [(int) $user->id], 1);
                    $workflow = DB::table('salary_monthly_workflows')
                        ->where('user_id', $user->id)->where('salary_year', $year)
                        ->where('salary_month', $month)->lockForUpdate()->first();
                    if (!$workflow) {
                        throw new DomainException('Unable to initialize employee-period workflow.');
                    }
                }
                if (in_array($workflow->workflow_status, ['READY_FOR_GENERATION', 'GENERATED'], true)) {
                    return response()->json(['message'=>'Return the workflow for revision before changing approved components; generated salary is immutable.'], 409);
                }
                if (DB::table('salary_generates')->where('user_id', $user->id)
                    ->where('salaryGenerationYear', $year)->where('monthId', $month)->exists()) {
                    return response()->json(['message'=>'An actual posted bill exists. Historical payroll inputs cannot be edited.'], 409);
                }

                $query=DB::table('salary_component_employee_overrides')
                    ->where('user_id',$user->id)->where('salary_year',$year)->where('salary_month',$month)
                    ->where('direction',$data['direction'])->where('component_key',$key);
                $before=$query->lockForUpdate()->first();
                $revision=(int)($before->revision??0);
                if ($revision !== (int)$data['expected_revision']) {
                    return response()->json(['message'=>'The override changed in another session. Reload and retry.'],409);
                }
                $after=null;
                if ($mode==='automatic') {
                    if ($before) $query->delete();
                } else {
                    $fields=[
                        'user_id'=>$user->id,'salary_year'=>$year,'salary_month'=>$month,
                        'direction'=>$data['direction'],'component_key'=>$key,
                        'payroll_component_type_id'=>$typeId,'component_title'=>$name,
                        'mode'=>$mode,'calc_type'=>$calcType,'value'=>$value,'basis'=>$basis,
                        'legacy_basic_reference'=>$legacy, 'reason'=>$data['reason']??null,
                        'evidence_reference'=>$data['evidence_reference']??null,
                        'changed_by'=>$request->user()->id, 'revision'=>$revision+1,
                        'updated_at'=>now(),
                    ];
                    if ($before) {
                        $query->update($fields);
                        $overrideId=$before->id;
                    } else {
                        $overrideId=DB::table('salary_component_employee_overrides')->insertGetId($fields+['created_at'=>now()]);
                    }
                    $after=(array)DB::table('salary_component_employee_overrides')->where('id',$overrideId)->first();
                }
                DB::table('salary_component_override_events')->insert([
                    'override_id'=>$before->id??($overrideId??null),
                    'user_id'=>$user->id,'salary_year'=>$year,'salary_month'=>$month,
                    'direction'=>$data['direction'],'component_key'=>$key,
                    'action'=>$mode==='automatic'?'reset':'save',
                    'before_state'=>$before?json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null,
                    'after_state'=>$after?json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null,
                    'actor_id'=>$request->user()->id,'created_at'=>now(),
                ]);
                // Existing CALCULATED approval is invalid after any effective component edit.
                // A new calculation version and fresh first/second approval are mandatory.
                if ($workflow->workflow_status === 'CALCULATED' && ($mode !== 'automatic' || $before)) {
                    DB::table('salary_monthly_workflows')->where('id', $workflow->id)->update([
                        'workflow_status' => 'BLOCKED', 'revision' => (int)$workflow->revision + 1,
                        'active_calculation_version_id' => null,
                        'ready_calculation_version_id' => null, 'ready_snapshot_hash' => null,
                        'calculation_approved_by' => null, 'calculation_approved_at' => null,
                        'ready_approved_by' => null, 'ready_approved_at' => null,
                        'blocking_reasons' => json_encode(['Components changed; recalculate and obtain new approvals.']),
                        'updated_at' => now(),
                    ]);
                    DB::table('salary_workflow_events')->insert([
                        'salary_monthly_workflow_id' => $workflow->id,
                        'user_id' => $user->id, 'salary_year' => $year, 'salary_month' => $month,
                        'action' => 'financial_input_changed', 'from_status' => 'CALCULATED',
                        'to_status' => 'BLOCKED', 'actor_id' => $request->user()->id,
                        'reason' => trim((string)($data['reason'] ?? 'Component configuration changed')),
                        'before_hash' => $workflow->ready_snapshot_hash, 'after_hash' => null,
                        'revision' => (int)$workflow->revision + 1, 'created_at' => now(),
                    ]);
                }
                return response()->json(['message'=>'Employee/month override saved. Refresh to recalculate and save a new salary version.',
                    'revision'=>(int)($after['revision']??0),'mode'=>$mode]);
            });
        } catch (DomainException $e) {
            return response()->json(['message'=>$e->getMessage()],422);
        }
    }
    /** Edit/reset one EXISTING record by ID, preserving audit and approval invalidation. */
    public function manageExisting(Request $request, User $user, int $overrideId)
    {
        abort_unless($request->user(), 401);
        $data = $request->validate([
            'year'=>'required|integer|between:2020,2100',
            'month'=>'required|integer|between:1,12',
            'action'=>'required|in:manual,disabled,reset',
            'expected_revision'=>'required|integer|min:1',
            'calc_type'=>'nullable|in:fixed,percentage',
            'value'=>'nullable|numeric|min:0|max:1000000000',
            'basis'=>'nullable|in:none,payable_basic,legacy_basic',
            'legacy_basic_reference'=>'nullable|numeric|gt:0',
            'reason'=>'required|string|min:3|max:2000',
            'evidence_reference'=>'nullable|string|max:500',
        ]);
        try {
            return DB::transaction(function () use ($request, $user, $overrideId, $data) {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $year=(int)$data['year'];$month=(int)$data['month'];
                $record=DB::table('salary_component_employee_overrides')
                    ->where('id',$overrideId)->where('user_id',$user->id)
                    ->where('salary_year',$year)->where('salary_month',$month)
                    ->lockForUpdate()->first();
                if (!$record) return response()->json(['message'=>'Override not found. Reload.'],404);
                if ((int)$record->revision !== (int)$data['expected_revision'])
                    return response()->json(['message'=>'Override changed elsewhere. Reload.'],409);
                if (DB::table('salary_generates')->where('user_id',$user->id)
                    ->where('salaryGenerationYear',$year)->where('monthId',$month)->exists())
                    return response()->json(['message'=>'Posted bill exists: reversal workflow required.'],409);
                $wf=DB::table('salary_monthly_workflows')->where('user_id',$user->id)
                    ->where('salary_year',$year)->where('salary_month',$month)->lockForUpdate()->first();
                if ($wf && in_array($wf->workflow_status,['READY_FOR_GENERATION','GENERATED'],true))
                    return response()->json(['message'=>'Return workflow for revision first.'],409);
                $action=$data['action'];$before=(array)$record;$after=null;
                if ($action==='reset') {
                    DB::table('salary_component_employee_overrides')->where('id',$record->id)->delete();
                } else {
                    $type=$action==='manual'?($data['calc_type']??null):null;
                    $value=$action==='manual'?($data['value']??null):null;
                    $basis=$action==='manual'?($type==='fixed'?'none':($data['basis']??null)):null;
                    $legacy=$action==='manual' && $basis==='legacy_basic'?($data['legacy_basic_reference']??null):null;
                    if ($action==='manual' && (!in_array($type,['fixed','percentage'],true) || !is_numeric($value)
                        || ($type==='percentage' && ((float)$value>100 || !in_array($basis,['payable_basic','legacy_basic'],true)))
                        || ($basis==='legacy_basic' && (!is_numeric($legacy) || (float)$legacy<=0))))
                        return response()->json(['message'=>'Invalid manual override amount or basis.'],422);
                    DB::table('salary_component_employee_overrides')->where('id',$record->id)->update([
                        'mode'=>$action,'calc_type'=>$type,'value'=>$value,'basis'=>$basis,
                        'legacy_basic_reference'=>$legacy,'reason'=>$data['reason'],
                        'evidence_reference'=>$data['evidence_reference']??null,
                        'revision'=>(int)$record->revision+1,'changed_by'=>$request->user()->id,
                        'updated_at'=>now(),
                    ]);
                    $after=(array)DB::table('salary_component_employee_overrides')->where('id',$record->id)->first();
                }
                DB::table('salary_component_override_events')->insert([
                    'override_id'=>$record->id,'user_id'=>$user->id,'salary_year'=>$year,'salary_month'=>$month,
                    'direction'=>$record->direction,'component_key'=>$record->component_key,
                    'action'=>$action==='reset'?'reset':'save',
                    'before_state'=>json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                    'after_state'=>$after?json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null,
                    'actor_id'=>$request->user()->id,'created_at'=>now(),
                ]);
                if ($wf && $wf->workflow_status==='CALCULATED') {
                    DB::table('salary_monthly_workflows')->where('id',$wf->id)->update([
                        'workflow_status'=>'BLOCKED','revision'=>(int)$wf->revision+1,
                        'active_calculation_version_id'=>null,'ready_calculation_version_id'=>null,
                        'ready_snapshot_hash'=>null,'calculation_approved_by'=>null,
                        'calculation_approved_at'=>null,'ready_approved_by'=>null,'ready_approved_at'=>null,
                        'blocking_reasons'=>json_encode(['Monthly override changed; recalculate and approve.']),
                        'updated_at'=>now(),
                    ]);
                    DB::table('salary_workflow_events')->insert([
                        'salary_monthly_workflow_id'=>$wf->id,'user_id'=>$user->id,
                        'salary_year'=>$year,'salary_month'=>$month,
                        'action'=>'financial_input_changed','from_status'=>'CALCULATED',
                        'to_status'=>'BLOCKED','actor_id'=>$request->user()->id,
                        'reason'=>$data['reason'],'before_hash'=>$wf->ready_snapshot_hash,
                        'after_hash'=>null,'revision'=>(int)$wf->revision+1,'created_at'=>now(),
                    ]);
                }
                return response()->json(['message'=>'Override updated. Recalculate and reapprove before posting.']);
            });
        } catch (DomainException $e) {
            return response()->json(['message'=>$e->getMessage()],422);
        }
    }

}
