<?php
namespace App\Http\Controllers;

use App\Services\ApprovedFixationResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use DomainException;

/** Manual *verified* source entry; cannot silently derive October GPF from percentage. */
final class SalaryOctoberGpfEvidenceController extends Controller
{
    public function store(Request $request, ApprovedFixationResolver $fixations)
    {
        abort_unless($request->user(), 401);
        $data=$request->validate([
            'employee_code'=>'required|string|max:32',
            'verified_gpf_amount'=>'required|numeric|min:0.01|max:1000000000',
            'evidence_reference'=>'required|string|min:5|max:500',
            'verification_note'=>'required|string|min:10|max:2000',
        ]);
        if (!Schema::hasTable('salary_october_gpf_evidence')) {
            return response()->json(['message'=>'Apply October GPF evidence migration first.'],422);
        }
        try {
            return DB::transaction(function () use ($data,$request,$fixations) {
                $profiles=DB::table('employee_infos')->where('employeeCode',$data['employee_code'])->limit(3)->get();
                if ($profiles->count()!==1) throw new DomainException('Employee code missing/duplicated.');
                $userId=(int)$profiles->first()->user_id;
                if (DB::table('employee_infos')->where('user_id',$userId)->count()!==1) throw new DomainException('User has duplicate employee profiles.');
                DB::table('users')->where('id',$userId)->lockForUpdate()->first();
                $posted=DB::table('salary_generates')->where('user_id',$userId)->where('salaryGenerationYear',2026)->where('monthId',10)->exists();
                if ($posted) throw new DomainException('October salary already posted; evidence is immutable.');
                $workflow=DB::table('salary_monthly_workflows')->where('user_id',$userId)->where('salary_year',2026)->where('salary_month',10)->first();
                if ($workflow && in_array($workflow->workflow_status,['READY_FOR_GENERATION','GENERATED'],true)) throw new DomainException('Return approved workflow for revision before changing evidence.');
                if (DB::table('salary_calculation_versions')->where('user_id',$userId)->where('salary_year',2026)->where('salary_month',10)->where('status','approved')->exists()) throw new DomainException('Approved October calculation exists; review the existing version, do not overwrite evidence.');
                $fix=$fixations->forUser($userId,2026,10);
                $fixId=(int)($fix['salary_fixation_id']??0);
                if (!$fixId || !is_numeric($fix['basic']??null)) throw new DomainException('Approved October fixation absent.');
                $already=DB::table('salary_october_gpf_evidence')->where('user_id',$userId)->where('salary_year',2026)->where('salary_month',10)->first();
                if ($already) throw new DomainException('Evidence already recorded. Existing evidence cannot be overwritten.');
                $id=DB::table('salary_october_gpf_evidence')->insertGetId([
                    'user_id'=>$userId,'fixation_id'=>$fixId,'salary_year'=>2026,'salary_month'=>10,
                    'verified_gpf_amount'=>round((float)$data['verified_gpf_amount'],2),
                    'evidence_reference'=>trim($data['evidence_reference']),
                    'verification_note'=>trim($data['verification_note']),
                    'verified_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
                ]);
                return response()->json(['evidence_id'=>$id,'employee_code'=>$data['employee_code'],'source'=>'documented_manual_verification','message'=>'Recorded, not posted.'],201);
            },3);
        } catch (DomainException $e) {return response()->json(['message'=>$e->getMessage()],422);}
    }
}
