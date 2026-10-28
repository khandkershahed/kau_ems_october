<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Prints an immutable persisted calculation version; never recomputes/post financial data. */
final class SalaryProvisionalBillController extends Controller
{
    public function show(Request $request, int $user)
    {
        abort_unless($request->user(), 401);
        $parameters = $request->validate([
            'year' => 'required|integer|between:2020,2100',
            'month' => 'required|integer|between:1,12',
            'version_id' => 'nullable|integer|min:1',
        ]);
        $year=(int)$parameters['year']; $month=(int)$parameters['month'];
        abort_if(DB::table('employee_infos')->where('user_id',$user)->count() !== 1, 409, 'Ambiguous employee profile.');
        $workflow=DB::table('salary_monthly_workflows')->where('user_id',$user)
            ->where('salary_year',$year)->where('salary_month',$month)->first();
        abort_unless($workflow,404);
        abort_unless(in_array($workflow->workflow_status,['CALCULATED','READY_FOR_GENERATION'],true),409,
            'Only reviewed calculations can be printed as provisional bills.');
        $id=$parameters['version_id']??$workflow->active_calculation_version_id;
        abort_unless($id,409,'No active approved calculation snapshot.');
        $version=DB::table('salary_calculation_versions')->where('id',$id)
            ->where('user_id',$user)->where('salary_year',$year)->where('salary_month',$month)
            ->where('status','approved')->first();
        abort_unless($version,409,'Approved calculation version missing or mismatched.');
        abort_unless((int)$workflow->active_calculation_version_id === (int)$version->id,409,
            'Selected calculation version is no longer active; re-review before printing.');
        $employee=DB::table('users')->where('id',$user)->first(['id','name']);
        abort_unless($employee,404);
        $decode=static function ($json, $name): array {
            $data=json_decode((string)$json,true);
            if (!is_array($data)) throw new HttpException(409,'Stored '.$name.' is invalid.');
            return $data;
        };
        return response()->view('salary.provisional-horizontal',[
            'employee'=>$employee,'year'=>$year,'month'=>$month,'workflow'=>$workflow,
            'version'=>$version,
            'regular'=>$decode($version->calculated_result,'calculation'),
            'additions'=>$decode($version->additions,'additions'),
            'deductions'=>$decode($version->deductions,'deductions'),
            'arrear'=>$decode($version->arrear_snapshot,'arrear'),
        ])->header('Cache-Control','private, no-store, max-age=0');
    }
}
