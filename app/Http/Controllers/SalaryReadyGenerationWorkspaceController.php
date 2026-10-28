<?php

namespace App\Http\Controllers;

use App\Services\SalaryBillPostingService;
use App\Services\SalaryPostingApprovalGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use DomainException;

/** Authenticated-only Generate Salary worklist. Does not post a bill directly. */
final class SalaryReadyGenerationWorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'year' => 'nullable|integer|between:2020,2100',
            'month' => 'nullable|integer|between:1,12',
            'status' => 'nullable|in:READY_FOR_GENERATION,GENERATED',
        ]);
        $year = (int) ($data['year'] ?? now()->year);
        $month = (int) ($data['month'] ?? now()->month);
        $status = $data['status'] ?? 'READY_FOR_GENERATION';
        $rows = DB::table('salary_monthly_workflows as w')
            ->join('users as u', 'u.id', '=', 'w.user_id')
            ->where('w.salary_year', $year)->where('w.salary_month', $month)
            ->where('w.workflow_status', $status)
            ->select('w.user_id', 'u.name', 'w.workflow_status', 'w.revision',
                'w.posted_salary_generate_id', 'w.posting_error')
            ->orderBy('w.user_id')->paginate(50)->withQueryString();
        $counts = DB::table('salary_monthly_workflows')
            ->where('salary_year', $year)->where('salary_month', $month)
            ->whereIn('workflow_status', ['READY_FOR_GENERATION', 'GENERATED'])
            ->selectRaw('workflow_status, COUNT(*) as total')->groupBy('workflow_status')
            ->pluck('total', 'workflow_status');
        return Inertia::render('Salary/Generation/Workspace', compact('rows', 'counts', 'year', 'month', 'status'));
    }

    /** Queue only READY records with a CURRENT server-computed full financial preview. */
    public function start(Request $request, SalaryBillPostingService $posting,
        SalaryPostingApprovalGuard $guard)
    {
        $data = $request->validate([
            'year' => 'required|integer|between:2020,2100',
            'month' => 'required|integer|between:1,12',
            'user_ids' => 'sometimes|array|min:1|max:50',
            'user_ids.*' => 'required|integer|min:1|distinct',
        ]);
        if (!Schema::hasTable('salary_bulk_runs') || !Schema::hasTable('salary_monthly_workflows')) {
            abort(409, 'Salary posting/run migrations are required.');
        }
        $year = (int) $data['year']; $month = (int) $data['month'];
        $ids = isset($data['user_ids'])
            ? array_map('intval', $data['user_ids'])
            : DB::table('salary_monthly_workflows')->where('salary_year', $year)
                ->where('salary_month', $month)->where('workflow_status', 'READY_FOR_GENERATION')
                ->orderBy('user_id')->limit(50)->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        if (!$ids || count($ids) > 50) {
            throw ValidationException::withMessages(['user_ids' => 'Select between 1 and 50 READY employees.']);
        }
        // Acquire user locks in stable order, then verify each approval and hash.
        // No unapproved employee may be silently removed from a selected batch.
        sort($ids, SORT_NUMERIC);
        try {
            $token = DB::transaction(function () use ($ids, $year, $month, $request, $guard, $posting) {
                $hashes = [];
                foreach ($ids as $userId) {
                    $employee = DB::table('users')->where('id', $userId)->lockForUpdate()->first(['id']);
                    if (!$employee) throw new DomainException('Employee not found: '.$userId);
                    $guard->assertReady($userId, $year, $month);
                    // Full arrear-inclusive preview and posting-feasibility checks.
                    $preview = $posting->previewForPosting($userId, $year, $month);
                    $hash = $preview['source_hash'] ?? null;
                    if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/i', $hash)) {
                        throw new DomainException('Full financial preview hash unavailable for employee '.$userId);
                    }
                    $hashes[$userId] = $hash;
                }
                $token = (string) Str::uuid();
                $runId = DB::table('salary_bulk_runs')->insertGetId([
                    'run_token' => $token, 'salary_year' => $year, 'salary_month' => $month,
                    'status' => 'prepared', 'created_by' => $request->user()->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach (array_chunk($ids, 200) as $chunk) {
                    DB::table('salary_bulk_run_items')->insert(array_map(fn ($uid) => [
                        'salary_bulk_run_id' => $runId, 'user_id' => $uid,
                        'status' => 'queued', 'source_hash' => $hashes[$uid],
                        'created_at' => now(), 'updated_at' => now(),
                    ], $chunk));
                }
                return $token;
            }, 3);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
        return response()->json([
            'run_token' => $token, 'total' => count($ids), 'batch_size' => 25,
            'next_url' => route('salary-bulk-runs.next', ['token' => $token]),
            'summary_url' => route('salary-bulk-runs.summary', ['token' => $token]),
        ], 201);
    }
}
