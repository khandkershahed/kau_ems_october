<?php

namespace App\Http\Controllers;

use App\Services\SalaryBillPostingService;
use App\Services\SalaryWorkflowRosterService;
use App\Services\SalaryFinancialSourceFingerprint;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

/** PATCH 05: resumable, read-only estimation. NEVER creates bill or approves payroll. */
final class SalaryEstimationWorkspaceController extends Controller
{
    private function hr(Request $request): void
    {
        abort_unless($request->user(), 401); // PATCH 06: role-free authenticated operator
    }

    /** PATCH 17: employee-directory-backed roster. A GET never initializes a workflow. */
    public function index(Request $request)
    {
        $this->hr($request);
        $data = $request->validate([
            'year' => 'nullable|integer|between:2020,2100',
            'month' => 'nullable|integer|between:1,12',
            'status' => 'nullable|in:NOT_INITIALIZED,BLOCKED,CALCULATED,READY_FOR_GENERATION,GENERATED,WORK_BLOCKED',
            'search' => 'nullable|string|max:100',
            'department_id' => 'nullable|integer|min:1',
            'category_id' => 'nullable|integer|min:1',
            'payroll_group_id' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:25,50,100,200',
        ]);
        $year = (int) ($data['year'] ?? 2026);
        $month = (int) ($data['month'] ?? 10);

        // Only use employee_infos as the directory. Duplicate profiles are visible
        // for reconciliation; never silently select one for a financial mutation.
        $profiles = DB::table('employee_infos')
            ->select('user_id')
            ->selectRaw('MIN(employeeCode) as employee_code, COUNT(*) as profile_count')
            ->groupBy('user_id');
        $base = DB::query()->fromSub($profiles, 'ep')
            ->join('users as u', 'u.id', '=', 'ep.user_id')
            ->leftJoin('salary_monthly_workflows as w', function ($join) use ($year, $month) {
                $join->on('w.user_id', '=', 'ep.user_id')
                    ->where('w.salary_year', '=', $year)
                    ->where('w.salary_month', '=', $month);
            });

        if (!empty($data['department_id'])) {
            $base->whereExists(function ($q) use ($data) {
                $q->selectRaw('1')->from('employee_infos as ef')
                    ->whereColumn('ef.user_id', 'ep.user_id')
                    ->where('ef.department_office_id', $data['department_id']);
            });
        }
        if (!empty($data['payroll_group_id'])) {
            $base->whereExists(function ($q) use ($data) {
                $q->selectRaw('1')->from('employee_infos as ef')
                    ->whereColumn('ef.user_id', 'ep.user_id')
                    ->where('ef.payroll_group_id', $data['payroll_group_id']);
            });
        }
        if (!empty($data['category_id'])) {
            $base->whereExists(function ($q) use ($data) {
                $q->selectRaw('1')->from('user_user_categories as uc')
                    ->whereColumn('uc.user_id', 'ep.user_id')
                    ->where('uc.user_category_id', $data['category_id']);
            });
        }
        $filterOptions = [
            'departments' => DB::table('department_offices')->orderBy('name')->get(['id','name']),
            'payroll_groups' => DB::table('payroll_groups as pg')->join('payroll_group_types as pgt', 'pgt.id', '=', 'pg.payroll_group_type_id')->orderBy('pgt.group_name')->get(['pg.id', 'pgt.group_name']),
            'categories' => DB::table('user_categories')->orderBy('name')->get(['id','name']),
        ];
        $allCounts = (clone $base)->selectRaw("COALESCE(w.workflow_status, 'NOT_INITIALIZED') as status, COUNT(*) as total")
            ->groupByRaw("COALESCE(w.workflow_status, 'NOT_INITIALIZED')")
            ->pluck('total', 'status');
        if (!empty($data['status'])) {
            if ($data['status'] === 'WORK_BLOCKED') $base->where(function ($q) { $q->whereNull('w.id')->orWhere('w.workflow_status', 'BLOCKED'); });
            elseif ($data['status'] === 'NOT_INITIALIZED') $base->whereNull('w.id');
            else $base->where('w.workflow_status', $data['status']);
        }
        if (!empty($data['search'])) {
            $term = '%' . addcslashes($data['search'], '%_\\') . '%';
            $base->where(function ($q) use ($term) {
                $q->where('u.name', 'like', $term)->orWhere('ep.employee_code', 'like', $term);
            });
        }
        $rows = $base->select(
                'ep.user_id', 'ep.employee_code', 'ep.profile_count', 'u.name',
                'w.id as workflow_id', 'w.workflow_status', 'w.revision',
                'w.blocking_reasons', 'w.posted_salary_generate_id', 'w.active_calculation_version_id'
            )
            ->selectRaw("COALESCE(w.workflow_status, 'NOT_INITIALIZED') as display_status")
            ->addSelect(['latest_approved_calculation_version_id' => DB::table('salary_calculation_versions as v')
                ->select('v.id')->whereColumn('v.user_id', 'ep.user_id')
                ->where('v.salary_year', $year)->where('v.salary_month', $month)
                ->where('v.status', 'approved')->orderByDesc('v.version')->limit(1)])
            ->orderBy('ep.employee_code')->orderBy('ep.user_id')
            ->paginate((int)($data['per_page'] ?? 25))->withQueryString();
        $counts = $allCounts->toArray();
        // Master Plan 5.2: only two editing worklists, with uninitialized employees included in blocked attention.
        $counts['WORK_BLOCKED'] = (int) ($counts['BLOCKED'] ?? 0) + (int) ($counts['NOT_INITIALIZED'] ?? 0);
        $runs = Schema::hasTable('salary_estimation_runs')
            ? DB::table('salary_estimation_runs')->where('salary_year', $year)->where('salary_month', $month)
                ->orderByDesc('id')->limit(8)->get() : [];
        return Inertia::render('Salary/Estimation/Workspace', compact('rows', 'counts', 'runs', 'year', 'month', 'filterOptions'));
    }

    public function start(Request $request, SalaryWorkflowRosterService $roster): JsonResponse
    {
        $this->hr($request);
        $data=$request->validate(['year'=>'required|integer|between:2020,2100',
            'month'=>'required|integer|between:1,12','user_ids'=>'sometimes|array|min:1|max:300',
            'user_ids.*'=>'required|integer|min:1|distinct',
            'employee_codes'=>'sometimes|array|min:1|max:100','employee_codes.*'=>'required|string|max:32|distinct']);
        $year=(int)$data['year'];$month=(int)$data['month'];
        if (!Schema::hasTable('salary_estimation_runs')) abort(409,'PATCH 05 migration missing');
        if (isset($data['user_ids']) && isset($data['employee_codes'])) throw ValidationException::withMessages(['employee_codes'=>'Choose either employee codes or user IDs, not both.']);
        if (isset($data['employee_codes'])) {
            $codes = $data['employee_codes'];
            $profiles = DB::table('employee_infos')->whereIn('employeeCode',$codes)->get(['employeeCode','user_id']);
            if ($profiles->count() !== count($codes) || $profiles->pluck('employeeCode')->unique()->count() !== count($codes)
                || $profiles->pluck('user_id')->unique()->count() !== count($codes)) {
                throw ValidationException::withMessages(['employee_codes'=>'Employee code mapping is missing or ambiguous.']);
            }
            $ids = $profiles->pluck('user_id')->map(fn($id)=>(int)$id)->all();
            // Explicit POST run creation may initialize missing workflow rows without posting money.
            $roster->reconcile($year, $month, $ids, count($ids));
            $available = DB::table('salary_monthly_workflows')->where('salary_year',$year)->where('salary_month',$month)
                ->whereIn('user_id',$ids)->count();
            if ($available !== count($ids)) throw ValidationException::withMessages(['employee_codes'=>'Reconcile all selected employee-period workflows first.']);
        } elseif (isset($data['user_ids'])) {
            $ids=array_map('intval',$data['user_ids']);
            $roster->reconcile($year, $month, $ids, count($ids));
            $available=DB::table('salary_monthly_workflows')->where('salary_year',$year)
                ->where('salary_month',$month)->whereIn('user_id',$ids)->count();
            if ($available!==count($ids)) throw ValidationException::withMessages(['user_ids'=>'All selected employees must exist in the reconciled roster.']);
        } else {
            $ids=DB::table('salary_monthly_workflows')->where('salary_year',$year)
                ->where('salary_month',$month)->orderBy('user_id')->pluck('user_id')->map(fn($x)=>(int)$x)->all();
        }
        // Immutable selection stored explicitly: subsequent roster changes cannot alter run membership.
        $runId=DB::transaction(function () use ($year,$month,$ids,$request) {
            $id=DB::table('salary_estimation_runs')->insertGetId([
                'salary_year'=>$year,'salary_month'=>$month,'created_by'=>$request->user()->id,
                'status'=>'running','last_user_id'=>0,'created_at'=>now(),'updated_at'=>now()]);
            foreach (array_chunk($ids,200) as $chunk) {
                DB::table('salary_estimation_run_items')->insert(array_map(fn($uid)=>[
                    'salary_estimation_run_id'=>$id,'user_id'=>$uid,'result'=>'pending','created_at'=>now()],$chunk));
            }
            return $id;
        });
        return response()->json(['run_id'=>$runId,'selected'=>count($ids),'status'=>'running'],201);
    }

    public function next(Request $request, int $run, SalaryBillPostingService $posting,
        SalaryFinancialSourceFingerprint $fingerprint): JsonResponse
    {
        $this->hr($request);
        $row=DB::table('salary_estimation_runs')->where('id',$run)->first();
        abort_unless($row,404);
        if ($row->status==='completed') return response()->json(['run'=>$row,'processed'=>0]);
        $items=DB::table('salary_estimation_run_items')->where('salary_estimation_run_id',$run)
            ->where('result','pending')->orderBy('user_id')->limit(10)->get();
        $done=0;
        foreach ($items as $item) {
            // Each step is idempotent and uses a separate lock. Concurrent calls cannot double count.
            DB::transaction(function () use ($item,$row,$posting,$fingerprint,&$done) {
                $state=DB::table('salary_estimation_run_items')->where('id',$item->id)->lockForUpdate()->first();
                if (!$state || $state->result!=='pending') return;
                $result='blocked';$error=null;$hash=null;$preview=null;
                try {
                    $workflow=DB::table('salary_monthly_workflows')->where('user_id',$item->user_id)
                        ->where('salary_year',$row->salary_year)->where('salary_month',$row->salary_month)->first();
                    if (!$workflow || $workflow->workflow_status==='GENERATED') {
                        throw new \DomainException('Missing roster or already generated; no estimation performed.');
                    }
                    if (DB::table('employee_infos')->where('user_id',$item->user_id)->count()!==1) {
                        throw new \DomainException('Ambiguous employee profile.');
                    }
                    if (DB::table('salary_generates')->where('user_id',$item->user_id)
                        ->where('salaryGenerationYear',$row->salary_year)->where('monthId',$row->salary_month)->exists()) {
                        throw new \DomainException('Posted bill exists; estimation skipped.');
                    }
                    $hash=$fingerprint->forEmployeePeriod((int)$item->user_id,(int)$row->salary_year,(int)$row->salary_month);
                    $preview=$posting->preview((int)$item->user_id,(int)$row->salary_year,(int)$row->salary_month);
                    if (!is_array($preview)) throw new \DomainException('Preview service returned no calculation.');
                    $result='estimated';
                } catch (Throwable $exception) {
                    $error=substr($exception->getMessage(),0,1800);
                }
                DB::table('salary_estimation_run_items')->where('id',$item->id)->update([
                    'result'=>$result,'error_message'=>$error,'source_hash'=>$hash,
                    'preview_result'=>$preview===null?null:json_encode($preview,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
                DB::table('salary_estimation_runs')->where('id',$row->id)->increment('examined');
                DB::table('salary_estimation_runs')->where('id',$row->id)->increment($result==='estimated'?'estimated':'blocked');
                $done++;
            },3);
        }
        if (!DB::table('salary_estimation_run_items')->where('salary_estimation_run_id',$run)->where('result','pending')->exists()) {
            DB::table('salary_estimation_runs')->where('id',$run)->update(['status'=>'completed','updated_at'=>now()]);
        }
        return response()->json(['run'=>DB::table('salary_estimation_runs')->where('id',$run)->first(),'processed'=>$done]);
    }

    public function errors(Request $request,int $run): JsonResponse
    {
        $this->hr($request);
        abort_unless(DB::table('salary_estimation_runs')->where('id',$run)->exists(),404);
        return response()->json(DB::table('salary_estimation_run_items')->where('salary_estimation_run_id',$run)
            ->where('result','blocked')->orderBy('user_id')->paginate(50));
    }
}
