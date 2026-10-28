<?php
namespace App\Http\Controllers;

use App\Models\EmployeeInfo;
use App\Models\PayrollComponentType;
use App\Services\ApprovedFixationResolver;
use App\Services\SalaryComponentCatalog;
use App\Services\SalaryComponentNormalizer;
use App\Services\SalaryEmployeeComponentOverrideService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Throwable;

/** Read-only multi-employee editor model; all financial writes use the original audited override endpoint. */
final class SalaryBulkComponentWorkspaceController extends Controller
{
    private const PENDING_CODES = ['1003','10046','10108','20029','20033','20038','20049','20057','20082','20083','30081','30098','30133','30254','40033','40140','40158','40274'];

    public function index(Request $request, ApprovedFixationResolver $fixations, SalaryComponentCatalog $catalog, SalaryEmployeeComponentOverrideService $overrides)
    {
        abort_unless($request->user(), 401);
        $filter = $request->validate(['year'=>'nullable|integer|between:2020,2100','month'=>'nullable|integer|between:1,12', 'codes'=>'nullable|string|max:1500']);
        $year = (int)($filter['year'] ?? 2026); $month = (int)($filter['month'] ?? 10);
        $codes = isset($filter['codes']) ? array_values(array_unique(array_filter(array_map('trim',explode(',', $filter['codes']))))) : self::PENDING_CODES;
        if (count($codes)>100 || array_filter($codes,fn($c)=>!preg_match('/^[a-zA-Z0-9_-]{1,32}$/',$c))) abort(422,'Invalid employee code list.');
        $masters = PayrollComponentType::query()->get(['id','name'])->keyBy('id');
        $result=[];
        foreach ($codes as $code) {
            $profiles = EmployeeInfo::query()->where('employeeCode',$code)->limit(3)->get();
            if ($profiles->count()!==1) { $result[]=['code'=>$code,'error'=>$profiles->isEmpty()?'Employee code not found':'Ambiguous employee code / duplicate profiles','components'=>[]]; continue; }
            $employee=$profiles->first(); $uid=(int)$employee->user_id;
            if (EmployeeInfo::where('user_id',$uid)->count()!==1) { $result[]=['code'=>$code,'error'=>'Multiple employee profiles for user','components'=>[]];continue; }
            $workflow=DB::table('salary_monthly_workflows')->where('user_id',$uid)->where('salary_year',$year)->where('salary_month',$month)->first();
            $billExists=DB::table('salary_generates')->where('user_id',$uid)->where('salaryGenerationYear',$year)->where('monthId',$month)->exists();
            $status=$workflow?->workflow_status ?? 'NOT_INITIALIZED';
            $row=['code'=>$code,'user_id'=>$uid,'name'=>DB::table('users')->where('id',$uid)->value('name'), 'status'=>$status,'revision'=>(int)($workflow?->revision ?? 0),'active_calculation_version_id'=>$workflow?->active_calculation_version_id,'ready_calculation_version_id'=>$workflow?->ready_calculation_version_id,'latest_approved_calculation_version_id'=>DB::table('salary_calculation_versions')->where('user_id',$uid)->where('salary_year',$year)->where('salary_month',$month)->where('status','approved')->orderByDesc('version')->value('id'),'error'=>null,'components'=>[], 'editable'=>!$billExists && in_array($status,['BLOCKED','CALCULATED'],true)];
            if (!$row['editable']) $row['error']=$billExists?'Existing posted bill — editing prohibited':'Initialize/revise the monthly workflow before editing';
            try {
                $fix=$fixations->forUser($uid,$year,$month);
                [$add,$ded]=$catalog->forEmployee($employee,SalaryComponentNormalizer::rows($employee->additionObjects),SalaryComponentNormalizer::rows($employee->substractionObjects),$year,$month,$fix);
                $saved=$overrides->rows($uid,$year,$month);
                foreach (['addition'=>$add,'deduction'=>$ded] as $direction=>$items) foreach ($items as $item) {
                    $typeId=(int)($item['payroll_component_type_id']??$item['payroll_component_type']['id']??0);
                    $title=trim((string)($item['name']??''));$master=$masters->get($typeId);
                    if (!$master || trim((string)$master->name)!==$title || SalaryEmployeeComponentOverrideService::basic($title)) continue;
                    $matches=array_values(array_filter($saved,fn($o)=>$o['direction']===$direction && (int)($o['payroll_component_type_id']??0)===$typeId));
                    $o=count($matches)===1?$matches[0]:null;
                    $row['components'][]=['direction'=>$direction,'title'=>$title,'type_id'=>$typeId,'calc_type'=>$o['calc_type']??strtolower((string)($item['calcType']??'fixed')),'value'=>$o['value']??($item['amount']??0),'mode'=>$o['mode']??'manual','basis'=>$o['basis']??(strtolower((string)($item['calcType']??'fixed'))==='percentage'?'payable_basic':'none'),'legacy_basic_reference'=>$o['legacy_basic_reference']??null,'expected_revision'=>(int)($o['revision']??0)];
                }
            } catch (Throwable $e) { $row['editable']=false;$row['error']=$e->getMessage(); }
            $result[]=$row;
        }
        return Inertia::render('Salary/Estimation/BulkComponents', ['employees'=>$result,'year'=>$year,'month'=>$month,'codes'=>implode(',',$codes)]);
    }
}
