<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SalaryGenerationReadyController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'year'=>'required|integer|between:2020,2100',
            'month'=>'required|integer|between:1,12',
            'status'=>'sometimes|in:READY_FOR_GENERATION,GENERATED',
        ]);
        $year=(int)$filters['year'];$month=(int)$filters['month'];
        $status=$filters['status'] ?? 'READY_FOR_GENERATION';
        $query=DB::table('salary_monthly_workflows as w')
            ->join('users as u','u.id','=','w.user_id')
            ->where('w.salary_year',$year)->where('w.salary_month',$month)
            ->where('w.workflow_status',$status)
            ->select('w.user_id','u.name','w.salary_year','w.salary_month','w.workflow_status',
                'w.revision','w.ready_calculation_version_id','w.posted_salary_generate_id','w.posting_error');
        return response()->json($query->orderBy('w.user_id')->paginate(50));
    }
}
