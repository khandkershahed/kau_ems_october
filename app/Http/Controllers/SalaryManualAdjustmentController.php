<?php

namespace App\Http\Controllers;

use App\Models\SalaryGenerate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalaryManualAdjustmentController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'salary_month' => 'required|integer|between:1,12',
            'salary_year' => 'required|integer|between:2020,2100',
        ]);
        if (!Schema::hasTable('salary_manual_adjustments')) {
            return response()->json(['message' => 'Run pending migrations first.'], 503);
        }
        return response()->json(DB::table('salary_manual_adjustments')
            ->where('user_id', $data['user_id'])
            ->where('salary_month', $data['salary_month'])
            ->where('salary_year', $data['salary_year'])
            ->orderBy('id')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'salary_month' => 'required|integer|between:1,12',
            'salary_year' => 'required|integer|between:2020,2100',
            'direction' => 'required|in:addition,deduction',
            'title' => 'required|string|min:3|max:120',
            'amount' => 'required|numeric|gt:0|max:1000000',
            'reason' => 'required|string|min:5|max:2000',
            'evidence_reference' => 'required|string|min:3|max:500',
        ]);
        if (!Schema::hasTable('salary_manual_adjustments')) {
            return response()->json(['message' => 'Run pending migrations first.'], 503);
        }
        if (app(\App\Services\ApprovedFixationResolver::class)->forUser(
            (int) $data['user_id'], (int) $data['salary_year'], (int) $data['salary_month'])
            && \App\Services\PayrollEffectivePolicy::isSpecialBenefit(['name' => $data['title']])) {
            return response()->json(['message' => 'Special Benefit is not payable under the activated revised scale.'], 422);
        }
        if (SalaryGenerate::where('user_id', $data['user_id'])
            ->where('monthId', $data['salary_month'])
            ->where('salaryGenerationYear', $data['salary_year'])->exists()) {
            return response()->json(['message' => 'Salary bill already exists. Do not change the adjustment without reconciling the existing bill.'], 409);
        }
        $data['created_by'] = $request->user()?->id;
        $data['created_at'] = now();
        $data['updated_at'] = now();
        $id = DB::table('salary_manual_adjustments')->insertGetId($data);
        return response()->json(['id' => $id, 'message' => 'Manual component saved for the selected payroll month.'], 201);
    }

    public function destroy(Request $request, int $id)
    {
        if (!Schema::hasTable('salary_manual_adjustments')) {
            return response()->json(['message' => 'Run pending migrations first.'], 503);
        }
        $row = DB::table('salary_manual_adjustments')->where('id', $id)->first();
        abort_unless($row, 404);
        if (SalaryGenerate::where('user_id', $row->user_id)
            ->where('monthId', $row->salary_month)
            ->where('salaryGenerationYear', $row->salary_year)->exists()) {
            return response()->json(['message' => 'Cannot delete an adjustment after its salary bill has been generated.'], 409);
        }
        DB::table('salary_manual_adjustments')->where('id', $id)->delete();
        return response()->json(['message' => 'Manual adjustment deleted.']);
    }
}
