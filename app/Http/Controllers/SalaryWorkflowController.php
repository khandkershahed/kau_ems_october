<?php

namespace App\Http\Controllers;

use App\Services\SalaryWorkflowRosterService;
use App\Services\SalaryWorkflowService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Authenticated, JSON-only HR workflow API. No salary posting is performed here. */
final class SalaryWorkflowController extends Controller
{
    private function authorizeHr(Request $request): void
    {
        abort_unless($request->user(), 401); // PATCH 06: authenticated operator, no roles
    }

    public function reconcile(Request $request, SalaryWorkflowRosterService $roster): JsonResponse
    {
        $this->authorizeHr($request);
        $data = $request->validate([
            'year' => 'required|integer|between:2020,2100', 'month' => 'required|integer|between:1,12',
            'user_ids' => 'sometimes|array|max:1000', 'user_ids.*' => 'required|integer|min:1|distinct',
            'limit' => 'sometimes|integer|between:1,1000',
        ]);
        return response()->json($roster->reconcile((int)$data['year'], (int)$data['month'],
            isset($data['user_ids']) ? array_map('intval', $data['user_ids']) : null, (int)($data['limit'] ?? 500)));
    }

    public function show(Request $request, int $user, int $year, int $month): JsonResponse
    {
        $this->authorizeHr($request);
        abort_unless($year >= 2020 && $year <= 2100 && $month >= 1 && $month <= 12, 422);
        $row = DB::table('salary_monthly_workflows')->where('user_id', $user)
            ->where('salary_year', $year)->where('salary_month', $month)->first();
        abort_unless($row, 404);
        return response()->json(['workflow' => $row, 'events' => DB::table('salary_workflow_events')
            ->where('salary_monthly_workflow_id', $row->id)->orderByDesc('id')->limit(100)->get()]);
    }

    public function transition(Request $request, int $user, int $year, int $month, SalaryWorkflowService $workflow): JsonResponse
    {
        $this->authorizeHr($request);
        $data = $request->validate([
            'action' => 'required|in:save_draft,approve_calculation,approve_for_generation,return_for_revision',
            'reason' => 'required|string|min:3|max:2000',
            'expected_revision' => 'required|integer|min:1',
            'calculation_version_id' => 'nullable|integer|min:1',
        ]);
        try {
            $result = $workflow->transition($user, $year, $month, $data['action'],
                (int)$request->user()->id, $data['reason'],
                isset($data['calculation_version_id']) ? (int)$data['calculation_version_id'] : null,
                null, null, (int)$data['expected_revision']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }
        return response()->json(['workflow' => $result]);
    }
}
