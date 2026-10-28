<?php
namespace App\Http\Controllers;

use App\Services\SalaryBillPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use DomainException;

/** Read-only printable, clearly provisional report. Never creates bill/GPF ledger. */
final class SalaryProvisionalBillController extends Controller
{
    public function show(Request $request, int $user, SalaryBillPostingService $posting)
    {
        abort_unless($request->user(), 401);
        $data = $request->validate([
            'year' => 'required|integer|between:2020,2100',
            'month' => 'required|integer|between:1,12',
        ]);
        $year = (int) $data['year']; $month = (int) $data['month'];
        $workflow = DB::table('salary_monthly_workflows')->where('user_id',$user)
            ->where('salary_year',$year)->where('salary_month',$month)->first();
        abort_unless($workflow, 404);
        if (DB::table('employee_infos')->where('user_id',$user)->count() !== 1) {
            abort(409,'Ambiguous employee profile.');
        }
        $employee = DB::table('users')->where('id',$user)->first(['id','name']);
        abort_unless($employee,404);
        try {
            $data = $posting->preview($user,$year,$month);
        } catch (DomainException $exception) {
            abort(409,$exception->getMessage());
        }
        return response()->view('salary.provisional-horizontal', [
            'employee'=>$employee, 'year'=>$year,'month'=>$month,
            'workflow'=>$workflow,'bill'=>$data,
        ])->header('Cache-Control','private, no-store, max-age=0');
    }
}
