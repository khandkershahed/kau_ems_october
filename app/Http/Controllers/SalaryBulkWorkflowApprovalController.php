<?php
namespace App\Http\Controllers;

use App\Services\SalaryWorkflowService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Two-stage bulk approval; one version & revision per employee; no posting. */
final class SalaryBulkWorkflowApprovalController extends Controller
{
    public function approve(Request $request, SalaryWorkflowService $workflow): JsonResponse
    {
        abort_unless($request->user(),401);
        $data = $request->validate([
            'year'=>'required|integer|between:2020,2100',
            'month'=>'required|integer|between:1,12',
            'action'=>'required|in:approve_calculation,approve_for_generation,return_for_revision',
            'reason'=>'required|string|min:3|max:2000',
            'items'=>'required|array|min:1|max:50',
            'items.*.user_id'=>'required|integer|min:1|distinct',
            'items.*.expected_revision'=>'required|integer|min:1',
            'items.*.calculation_version_id'=>'nullable|integer|min:1',
        ]);
        $seen=[];
        foreach ($data['items'] as $item) {
            if (isset($seen[$item['user_id']])) throw ValidationException::withMessages(['items'=>'Duplicate employee in bulk approval.']);
            $seen[$item['user_id']]=true;
        }
        $results=[];
        foreach ($data['items'] as $item) {
            try {
                $result=$workflow->transition((int)$item['user_id'],(int)$data['year'],(int)$data['month'],
                    $data['action'],(int)$request->user()->id,$data['reason'],
                    isset($item['calculation_version_id'])?(int)$item['calculation_version_id']:null,
                    null,null,(int)$item['expected_revision']);
                $results[]=['user_id'=>(int)$item['user_id'],'ok'=>true,
                    'status'=>$result->workflow_status,'revision'=>(int)$result->revision];
            } catch (DomainException $exception) {
                $results[]=['user_id'=>(int)$item['user_id'],'ok'=>false,
                    'error'=>$exception->getMessage()];
            }
        }
        return response()->json(['results'=>$results,'succeeded'=>count(array_filter($results,fn($r)=>$r['ok'])),
            'failed'=>count(array_filter($results,fn($r)=>!$r['ok']))]);
    }
}
