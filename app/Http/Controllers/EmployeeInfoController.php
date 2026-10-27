<?php

namespace App\Http\Controllers;

use App\Models\EmployeeInfo;
use App\Services\SalaryDefaultPreviewService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeeInfoController extends Controller
{
    /** Preview is the default. Replacing manually customised data takes explicit confirmation. */
    public function updateSalaryComponentsForUser(Request $request)
    {
        $input = $request->validate([
            'userId' => 'required|integer|exists:users,id',
            'mode' => 'nullable|in:preview,apply',
            'confirm_overwrite' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ]);
        $profiles = EmployeeInfo::where('user_id', $input['userId'])->limit(2)->get();
        if ($profiles->count() > 1) {
            return response()->json(['status' => 422, 'errors' => [
                'Duplicate employee profiles found. Choose one verified profile before changing salary defaults.']], 422);
        }
        $employee = $profiles->first();
        if (!$employee) {
            return response()->json(['status' => 422, 'errors' => ['Employee salary setup not found.']], 422);
        }
        $year = (int) ($request->input('salary_year') ?? now()->year);
        $month = (int) ($request->input('salary_month') ?? now()->month);
        $hasApproved = app(\App\Services\ApprovedFixationResolver::class)
            ->forUser((int) $input['userId'], $year, $month) !== null;
        if (($input['mode'] ?? 'preview') === 'apply' && $hasApproved) {
            return response()->json(['status' => 422, 'errors' => [
                'Approved 2026 salary must be calculated in the Employee Salary Calculator. Do not overwrite the historical 2015 employee setup.']], 422);
        }
        try {
            $defaults = SalaryDefaultPreviewService::preview($employee);
        } catch (DomainException $e) {
            return response()->json(['status' => 422, 'errors' => [$e->getMessage()]], 422);
        }
        if (($input['mode'] ?? 'preview') !== 'apply') {
            return response()->json([
                'status' => 200, 'preview_only' => true,
                'messages' => ['Preview only. Existing salary components were not changed.'],
                'defaults' => $defaults,
                'warnings' => $defaults['warnings'] ?? [],
                'current' => ['additions' => $employee->additionObjects ?? [],
                    'deductions' => $employee->substractionObjects ?? []],
            ])->header('Cache-Control', 'private, no-store');
        }
        if (empty($input['confirm_overwrite']) || trim((string) ($input['reason'] ?? '')) === '') {
            return response()->json(['status' => 422, 'errors' => [
                'Explicit overwrite confirmation and an audit reason are required.']], 422);
        }
        if (!Schema::hasTable('salary_setup_default_audit_events')) {
            return response()->json(['status' => 422, 'errors' => [
                'Run the Phase 2 audit migration before applying group defaults.']], 422);
        }
        DB::transaction(function () use ($employee, $defaults, $input, $request) {
            $locked = EmployeeInfo::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $before = ['additions' => $locked->additionObjects ?? [],
                'deductions' => $locked->substractionObjects ?? []];
            $after = ['additions' => $defaults['additions'], 'deductions' => $defaults['deductions']];
            DB::table('salary_setup_default_audit_events')->insert([
                'user_id' => $locked->user_id,
                'actor_id' => $request->user()?->id,
                'before_components' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'after_components' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'reason' => trim($input['reason']),
                'created_at' => now(),
            ]);
            $locked->update(['additionObjects' => $defaults['additions'],
                'substractionObjects' => $defaults['deductions']]);
        });
        return response()->json(['status' => 200,
            'messages' => ['Group defaults applied; prior values and reason are retained in audit history.']]);
    }
}
