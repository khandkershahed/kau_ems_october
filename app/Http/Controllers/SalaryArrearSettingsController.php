<?php

namespace App\Http\Controllers;

use App\Models\PayrollComponentType;
use App\Support\SalaryArrearRuleView;
use App\Services\SalaryArrearFormulaEngine;
use App\Services\SalaryArrearRuleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Reusable global rule catalog; all writes append an immutable before/after event. */
final class SalaryArrearSettingsController extends Controller
{
    public function index()
    {
        return Inertia::render('Salary/Arrear/Settings', [
            'rules' => Schema::hasTable('salary_arrear_rules')
                ? DB::table('salary_arrear_rules')->where('archived', false)->orderBy('display_order')->orderBy('id')->get()->map(fn ($row) => SalaryArrearRuleView::normalize($row))->all() : [],
            'componentTypes' => PayrollComponentType::orderBy('name')->get(['id', 'name']),
            'publications' => Schema::hasTable('salary_component_publications')
                ? DB::table('salary_component_publications')->get() : [],
        ]);
    }

    public function saveRule(Request $request, SalaryArrearFormulaEngine $engine)
    {
        $data = $request->validate([
            'id' => 'nullable|integer|min:1',
            'expected_revision' => 'nullable|integer|min:1',
            'code' => 'nullable|string|max:60',
            'title' => 'required|string|max:160',
            'direction' => 'required|in:payable,deduction',
            'mode' => 'required|in:fixed,percentage,formula',
            'amount' => 'nullable|numeric|min:0|max:1000000000',
            'formula' => 'nullable|string|max:400',
            'start_month' => 'nullable|date_format:Y-m', 'end_month' => 'nullable|date_format:Y-m',
            'selected_months' => 'nullable|array|max:60',
            'selected_months.*' => ['string','regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'recurring' => 'required|boolean',
            'scope' => 'required|in:all,specific',
            'employee_ids' => 'nullable|array|max:1000',
            'employee_ids.*' => 'integer|min:1',
            'published' => 'required|boolean',
            'display_order' => 'required|integer|min:0|max:100000',
        ]);
        if (!Schema::hasTable('salary_arrear_rules')) return response()->json(['message' => 'Run Phase 4 migration first.'], 422);
        if ($data['mode'] !== 'fixed' && !trim((string)($data['formula'] ?? ''))) {
            return response()->json(['message' => 'Formula/basis is required for percentage and formula rules.'], 422);
        }
        if (isset($data['start_month'],$data['end_month']) && $data['start_month'] > $data['end_month']) {
            return response()->json(['message' => 'Start month must not exceed end month.'], 422);
        }
        if ($data['mode'] === 'fixed' && !isset($data['amount'])) {
            return response()->json(['message' => 'Fixed amount required.'], 422);
        }
        if ($data['mode'] !== 'fixed') {
            try { $engine->validate((string)$data['formula']); }
            catch (\DomainException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        }
        $id = DB::transaction(function () use ($data, $request) {
            $id = (int)($data['id'] ?? 0);
            $old = $id ? DB::table('salary_arrear_rules')->where('id', $id)->lockForUpdate()->first() : null;
            if ($id && !$old) abort(404);
            if ($old && (int)$old->revision !== (int)($data['expected_revision'] ?? -1)) {
                throw ValidationException::withMessages(['revision'=>'Rule has changed since it was opened. Reload before editing.']);
            }
            $values = [
                'code' => $data['code'] ?? null, 'title'=>$data['title'], 'direction'=>$data['direction'],
                'mode'=>$data['mode'], 'amount'=>$data['amount'] ?? null, 'formula'=>$data['formula'] ?? null,
                'start_month'=> isset($data['start_month']) ? $data['start_month'].'-01' : null,
                'end_month'=> isset($data['end_month']) ? $data['end_month'].'-01' : null,
                'selected_months'=>json_encode($data['selected_months'] ?? []),
                'recurring'=>$data['recurring'], 'scope'=>$data['scope'],
                'employee_ids'=>json_encode($data['employee_ids'] ?? []),
                'published'=>$data['published'], 'display_order'=>$data['display_order'],
                'revision'=>(int)($old->revision ?? 0)+1,
                'updated_by'=>$request->user()->id, 'updated_at'=>now(),
            ];
            if ($old) DB::table('salary_arrear_rules')->where('id', $id)->update($values);
            else $id=DB::table('salary_arrear_rules')->insertGetId($values+['created_at'=>now()]);
            DB::table('salary_rule_events')->insert(['rule_kind'=>'arrear','rule_id'=>$id,
                'action'=>$old?'revision':'created','before_value'=>$old?json_encode($old):null,
                'after_value'=>json_encode($values), 'actor_user_id'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now()]);
            return $id;
        });
        $saved = DB::table('salary_arrear_rules')->where('id', $id)->first();
        return response()->json([
            'message'=>'Rule saved; existing salary bills/snapshots unchanged.',
            'id'=>$id,
            'revision'=>(int) $saved->revision,
            'published'=>(bool) $saved->published,
            'rule'=>SalaryArrearRuleView::normalize($saved),
        ]);
    }

    public function archive(Request $request, int $id)
    {
        DB::transaction(function () use ($id,$request) {
            $old=DB::table('salary_arrear_rules')->where('id',$id)->lockForUpdate()->first();
            if (!$old) abort(404);
            DB::table('salary_arrear_rules')->where('id',$id)->update(['archived'=>true,'published'=>false,'revision'=>$old->revision+1,'updated_at'=>now()]);
            DB::table('salary_rule_events')->insert(['rule_kind'=>'arrear','rule_id'=>$id,'action'=>'archived',
                'before_value'=>json_encode($old),'after_value'=>json_encode(['archived'=>true,'published'=>false]),
                'actor_user_id'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);
        });
        return response()->json(['message'=>'Rule archived. Historical snapshots preserved.']);
    }

    public function preview(Request $request, SalaryArrearRuleService $service)
    {
        $data=$request->validate(['user_id'=>'required|integer|min:1','year'=>'required|integer|between:2020,2100',
            'month'=>'required|integer|between:1,12','rule_id'=>'nullable|integer|min:1']);
        $rules=isset($data['rule_id']) ? DB::table('salary_arrear_rules')->where('id',$data['rule_id'])->get()->all() : null;
        return response()->json(['rows'=>$service->preview((int)$data['user_id'],(int)$data['year'],(int)$data['month'],$rules)]);
    }

    public function saveComponent(Request $request)
    {
        $data=$request->validate([
            'payroll_component_type_id'=>'required|integer|exists:payroll_component_types,id',
            'direction'=>'required|in:addition,deduction', 'published'=>'required|boolean',
            'default_calc_type'=>'required|in:fixed,percentage',
            'default_amount'=>'nullable|numeric|min:0|max:1000000000',
            'expected_revision'=>'nullable|integer|min:1',
        ]);
        DB::transaction(function () use ($data, $request) {
            $id=(int)$data['payroll_component_type_id'];
            $old=DB::table('salary_component_publications')->where('payroll_component_type_id',$id)->lockForUpdate()->first();
            if ($old && (int)$old->revision !== (int)($data['expected_revision'] ?? -1)) {
                throw ValidationException::withMessages(['revision'=>'Component publication has changed. Reload.']);
            }
            $v=['direction'=>$data['direction'], 'published'=>$data['published'],
                'default_calc_type'=>$data['default_calc_type'], 'default_amount'=>$data['default_amount'] ?? null,
                'revision'=>(int)($old->revision ?? 0)+1,'updated_by'=>$request->user()->id,'updated_at'=>now()];
            if ($old) DB::table('salary_component_publications')->where('id',$old->id)->update($v);
            else {
                DB::table('salary_component_publications')->insert($v+['payroll_component_type_id'=>$id,'created_at'=>now()]);
            }
            DB::table('salary_rule_events')->insert(['rule_kind'=>'regular_component','rule_id'=>$id,
                'action'=>$old?'revision':'created','before_value'=>$old?json_encode($old):null,'after_value'=>json_encode($v),
                'actor_user_id'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);
        });
        return response()->json(['message'=>'Component publish status updated. Historical employee setups unchanged.']);
    }
}
