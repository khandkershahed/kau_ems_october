<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\EmployeeInfo;
use App\Services\ApprovedFixationResolver;
use App\Services\SalaryComponentCatalog;
use App\Services\SalaryComponentNormalizer;
use App\Services\SalaryCalculationService;
use App\Services\SalaryEmployeeComponentOverrideService;
use App\Services\SalaryArrearRuleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Throwable;
use DomainException;

/** Local HR review editor. All writes delegate to the existing salary calculator's versioned save(). */
final class SalaryBulkSalaryEditorController extends Controller
{
    public function index(Request $request, ApprovedFixationResolver $fixations, SalaryComponentCatalog $catalog)
    {
        abort_unless($request->user(), 401);
        $data = $request->validate(['year'=>'nullable|integer|in:2026','month'=>'nullable|integer|in:10', 'page'=>'nullable|integer|min:1','search'=>'nullable|string|max:80']);
        $year=2026; $month=10; $page=(int)($data['page']??1); $search=trim((string)($data['search']??''));
        $query=DB::table('employee_infos as ei')->join('users as u','u.id','=','ei.user_id')
            ->select('ei.user_id','ei.employeeCode','u.name')->whereNotNull('ei.user_id');
        if ($search!=='') $query->where(function($q) use($search){$q->where('ei.employeeCode','like','%'.$search.'%')->orWhere('u.name','like','%'.$search.'%');});
        $total=(clone $query)->distinct('ei.user_id')->count('ei.user_id');
        $employees=$query->orderBy('ei.user_id')->offset(($page-1)*20)->limit(20)->get();
        $rows=[];
        foreach ($employees as $e) {
            $row=['user_id'=>(int)$e->user_id,'code'=>(string)$e->employeeCode,'name'=>(string)$e->name,'status'=>'NOT_SAVED', 'version'=>0, 'basic'=>null,'additions'=>[],'deductions'=>[],'arrears'=>[],'net'=>null,'error'=>null];
            try {
                if (DB::table('employee_infos')->where('user_id',$e->user_id)->count()!==1) throw new DomainException('Multiple profiles; identity review required');
                $latest=DB::table('salary_calculation_versions')->where('user_id',$e->user_id)->where('salary_year',$year)->where('salary_month',$month)->orderByDesc('version')->first();
                if($latest){
                    $row['status']=$latest->status; $row['version']=(int)$latest->version;
                    $row['additions']=json_decode((string)$latest->additions,true)?:[];
                    $row['deductions']=json_decode((string)$latest->deductions,true)?:[];
                    $ar=json_decode((string)$latest->arrear_snapshot,true)?:[];
                    $row['arrears']=$ar['rows']??[];
                    $calc=json_decode((string)$latest->calculated_result,true)?:[];
                    $row['net']=$calc['net_salary']??null;
                } else {
                    $employee=EmployeeInfo::findOrFail(DB::table('employee_infos')->where('user_id',$e->user_id)->value('id'));
                    $fix=$fixations->forUser((int)$e->user_id,$year,$month);
                    [$row['additions'],$row['deductions']]=$catalog->forEmployee($employee,
                        SalaryComponentNormalizer::rows($employee->additionObjects),SalaryComponentNormalizer::rows($employee->substractionObjects),$year,$month,$fix);
                    $rules=app(SalaryArrearRuleService::class)->preview((int)$e->user_id,$year,$month);
                    foreach($rules as $r) $row['arrears'][]=['rule_id'=>$r['rule_id']??null,'code'=>$r['code']??null,'title'=>$r['title'],'direction'=>$r['direction'],'mode'=>$r['mode']??'fixed','formula'=>$r['formula']??null,'calculated_amount'=>is_numeric($r['calculated_amount']??null)?(float)$r['calculated_amount']:null,'final_amount'=>is_numeric($r['calculated_amount']??null)?(float)$r['calculated_amount']:null,'months'=>$r['months']??[],'error'=>$r['error']??null];
                }
                foreach($row['additions'] as $c) if(preg_match('/\bbasic(?:\s+pay|\s+salary)?\b/i',(string)($c['name']??''))){$row['basic']=(float)($c['amount']??0);break;}
                $row['posted']=DB::table('salary_generates')->where('user_id',$e->user_id)->where('salaryGenerationYear',2026)->where('monthId',10)->exists();
            } catch(Throwable $ex){$row['error']=$ex->getMessage();}
            $rows[]=$row;
        }
        return Inertia::render('Salary/Estimation/BulkSalaryReview',['employees'=>$rows,'year'=>2026,'month'=>10,'page'=>$page,'pages'=>max(1,(int)ceil($total/20)),'total'=>$total,'search'=>$search]);
    }

    public function save(Request $request, SalaryCalculatorEntryController $controller, ApprovedFixationResolver $fixations, SalaryCalculationService $calculator)
    {
        abort_unless($request->user(), 401);
        $data=$request->validate([
            'year'=>'required|integer|in:2026','month'=>'required|integer|in:10',
            'items'=>'required|array|min:1|max:20',
            'items.*.user_id'=>'required|integer|min:1|distinct',
            'items.*.status'=>'required|in:draft,approved',
            'items.*.expected_version'=>'required|integer|min:0',
            'items.*.additions'=>'required|array|min:1|max:60',
            'items.*.deductions'=>'present|array|max:60',
            'items.*.arrear_rows'=>'present|array|max:100',
            'items.*.reason'=>'nullable|string|max:2000',
            'items.*.evidence_reference'=>'nullable|string|max:500'
        ]);
        $results=[];
        foreach($data['items'] as $item){
            $uid=(int)$item['user_id'];
            try{
                if(DB::table('salary_generates')->where('user_id',$uid)->where('salaryGenerationYear',2026)->where('monthId',10)->exists()) throw new DomainException('Existing generated bill; use a correction workflow');
                if(DB::table('employee_infos')->where('user_id',$uid)->count()!==1) throw new DomainException('Employee profile must be unique');
                $user=User::findOrFail($uid);
                $payload=['year'=>2026,'month'=>10,'status'=>$item['status'],'expected_version'=>(int)$item['expected_version'],
                  'component_override_hash'=>app(SalaryEmployeeComponentOverrideService::class)->signature($uid,2026,10),
                  'additions'=>$item['additions'],'deductions'=>$item['deductions'],'arrear_rows'=>$item['arrear_rows'],
                  'reason'=>$item['reason']??null,'evidence_reference'=>$item['evidence_reference']??null];
                $internal=Request::create('/dashboard/salary/calculator/'.$uid.'/versions','POST',$payload);
                $internal->setUserResolver(fn()=>$request->user());
                $response=$controller->save($internal,$user,$fixations,$calculator);
                $json=json_decode((string)$response->getContent(),true)?:[];
                if($response->getStatusCode()>=400||!isset($json['version'])) throw new DomainException($json['message']??'Save did not return a version');
                $results[]=['user_id'=>$uid,'ok'=>true,'version'=>$json['version'],'status'=>$json['status']??$item['status']];
            }catch(Throwable $e){$results[]=['user_id'=>$uid,'ok'=>false,'message'=>$e->getMessage()];}
        }
        return response()->json(['results'=>$results,'succeeded'=>count(array_filter($results,fn($r)=>$r['ok'])),'failed'=>count(array_filter($results,fn($r)=>!$r['ok']))]);
    }
}
