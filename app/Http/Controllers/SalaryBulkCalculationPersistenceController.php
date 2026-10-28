<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ApprovedFixationResolver;
use App\Services\SalaryCalculationService;
use App\Services\SalaryWorkflowRosterService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Strict October 2026 bulk calculator persistence; no bill, GPF or arrear journal posting. */
final class SalaryBulkCalculationPersistenceController extends Controller
{
    public function save(Request $request, SalaryCalculatorEntryController $calculatorController,
        ApprovedFixationResolver $fixations, SalaryCalculationService $calculator,
        SalaryWorkflowRosterService $roster)
    {
        abort_unless($request->user(), 401);
        $data = $request->validate([
            'year'=>'required|integer|in:2026', 'month'=>'required|integer|in:10',
            'employee_codes'=>'required|array|min:1|max:50',
            'employee_codes.*'=>'required|string|max:32|distinct',
            'reason'=>'required|string|min:3|max:2000',
            'save_status'=>'nullable|in:draft,approved',
        ]);
        $year=2026; $month=10; $results=[];
        $saveStatus = $data['save_status'] ?? 'approved';
        // Exactly one employee at a time: failures do not roll back verified successes.
        foreach ($data['employee_codes'] as $code) {
            try {
                $profiles=DB::table('employee_infos')->where('employeeCode',$code)->limit(3)->get(['user_id']);
                if ($profiles->count()!==1) throw new DomainException('Employee code not unique or not found.');
                $userId=(int)$profiles->first()->user_id;
                if (DB::table('employee_infos')->where('user_id',$userId)->count()!==1) {
                    throw new DomainException('Multiple profiles for this user require reconciliation.');
                }
                $user=User::findOrFail($userId);
                // Roster initialization only; NEVER modifies source salary/ledger.
                $roster->reconcile($year,$month,[$userId],1);
                $workflow=DB::table('salary_monthly_workflows')->where('user_id',$userId)
                    ->where('salary_year',$year)->where('salary_month',$month)->first();
                if (!$workflow || !in_array($workflow->workflow_status, ['BLOCKED', 'CALCULATED'], true)) {
                    throw new DomainException('Salary workflow not editable: '.($workflow->workflow_status ?? 'missing'));
                }
                if (DB::table('salary_generates')->where('user_id',$userId)
                    ->where('salaryGenerationYear',$year)->where('monthId',$month)->exists()) {
                    throw new DomainException('A posted bill already exists.');
                }
                $payload=$calculatorController->verifiedBulkInput($user,$year,$month,$data['reason'],$fixations,$calculator,$saveStatus);
                $internal=Request::create('/dashboard/salary/calculator/'.$userId.'/versions','POST',$payload);
                $internal->setUserResolver(fn ()=>$request->user());
                $response=$calculatorController->save($internal,$user,$fixations,$calculator);
                $body=json_decode((string)$response->getContent(),true) ?: [];
                if ($response->getStatusCode()>=400) {
                    throw new DomainException($body['message'] ?? 'Calculator rejected the version.');
                }
                $saved=DB::table('salary_calculation_versions')->where('user_id',$userId)
                    ->where('salary_year',$year)->where('salary_month',$month)->orderByDesc('version')->first();
                if (!$saved || $saved->status!==$saveStatus) throw new DomainException('No requested snapshot persisted.');
                $results[]=['code'=>$code,'user_id'=>$userId,'ok'=>true,'version_id'=>$saved->id,
                    'version'=>(int)$saved->version,'snapshot_hash'=>$saved->snapshot_hash,
                    'message'=>$body['message']??'Approved calculation version saved.'];
            } catch (Throwable $e) {
                $results[]=['code'=>$code,'ok'=>false,'error'=>$e->getMessage()];
            }
        }
        return response()->json(['results'=>$results,
            'succeeded'=>count(array_filter($results,fn($x)=>$x['ok'])),
            'failed'=>count(array_filter($results,fn($x)=>!$x['ok']))]);
    }
}
