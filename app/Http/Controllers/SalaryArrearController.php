<?php

namespace App\Http\Controllers;

use App\Services\SalaryArrearCalculator;
use App\Services\SalaryStructuredArrearService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Step 4: preview and audited drafts only; never posts a salary bill or GPF contribution. */
class SalaryArrearController extends Controller
{
    public function index()
    {
        return Inertia::render('Salary/Arrear/Index');
    }

    public function employeeInbox(Request $request)
    {
        $snapshots = DB::table('salary_arrear_snapshots as s')
            ->join('salary_arrear_periods as p', 'p.id', '=', 's.period_id')
            ->where('s.user_id', $request->user()->id)
            ->whereIn('s.status', ['sent', 'employee_confirmed', 'employee_disputed', 'approved'])
            ->select('s.id', 's.version', 's.status', 's.net_arrear', 's.calculation', 'p.name as period_name')
            ->orderByDesc('s.id')->get();
        return Inertia::render('Salary/Arrear/EmployeeInbox', [
            'snapshots' => $snapshots,
        ]);
    }

    public function periods()
    {
        return response()->json(DB::table('salary_arrear_periods')->orderByDesc('id')->get());
    }

    public function storePeriod(Request $request)
    {
        $d = $request->validate([
            'name' => 'required|string|min:3|max:120',
            'entitlement_start' => 'required|date_format:Y-m-d',
            'entitlement_end' => 'required|date_format:Y-m-d|after_or_equal:entitlement_start',
            'notes' => 'nullable|string|max:3000',
        ]);
        $start = Carbon::parse($d['entitlement_start']);
        $end = Carbon::parse($d['entitlement_end']);
        if ($start->day !== 1 || $end->day !== $end->copy()->endOfMonth()->day
            || $start->diffInMonths($end) > 24) {
            throw ValidationException::withMessages(['entitlement_start' => 'Use whole months (first-day start, last-day end) and at most 24 months.']);
        }
        $id = DB::table('salary_arrear_periods')->insertGetId([
            'name' => $d['name'], 'entitlement_start' => $d['entitlement_start'],
            'entitlement_end' => $d['entitlement_end'], 'regular_scale_activation' => '2027-07-01',
            'notes' => $d['notes'] ?? null, 'created_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'message' => 'Policy period draft created; no entitlement approved.'], 201);
    }

    public function updatePolicy(Request $request, int $periodId)
    {
        $d = $request->validate([
            'special_benefit_policy' => 'required|in:recover_paid,retain',
            'house_rent_policy' => 'required|in:reconcile,none',
            'gpf_policy' => 'required|in:reconcile,none',
            'gpf_rounding' => 'required|in:truncate_taka,round_taka,paisa',
            'gpf_rate_percent' => 'nullable|numeric|between:0,100',
            'planned_payment_month' => 'nullable|date_format:Y-m-d',
            'policy_evidence' => 'required|string|min:5|max:500',
            'notes' => 'nullable|string|max:3000',
            'policy_status' => 'required|in:draft,confirmed',
        ]);
        $period = DB::table('salary_arrear_periods')->where('id', $periodId)->first();
        abort_unless($period, 404);
        if (!empty($d['planned_payment_month']) && substr($d['planned_payment_month'], 8, 2) !== '01') {
            throw ValidationException::withMessages(['planned_payment_month' => 'Planned payment date must be the first day of a month.']);
        }
        if (!empty($d['planned_payment_month'])
            && substr($d['planned_payment_month'], 0, 7) < substr($period->entitlement_end, 0, 7)) {
            throw ValidationException::withMessages(['planned_payment_month' => 'Planned arrear payment cannot precede the entitlement period end.']);
        }
        if (DB::table('salary_arrear_snapshots')->where('period_id', $periodId)->where('status', 'approved')->exists()) {
            return response()->json(['message' => 'Approved snapshots exist. Create a new policy period/version.'], 409);
        }
        $d['updated_at'] = now();
        DB::table('salary_arrear_periods')->where('id', $periodId)->update($d);
        return response()->json(['message' => 'Policy saved. Approval of calculation remains separate.']);
    }

    public function preview(Request $request, int $periodId, SalaryArrearCalculator $calculator)
    {
        $period = DB::table('salary_arrear_periods')->where('id', $periodId)->first();
        abort_unless($period, 404);
        $d = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'search' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|between:1,100',
        ]);
        $q = DB::table('employee_infos as e')->join('users as u', 'u.id', '=', 'e.user_id')
            ->select('e.id as profile_id', 'e.employeeCode as employee_code', 'e.user_id', 'u.name')
            ->orderBy('e.employeeCode')->orderBy('e.id');
        if (isset($d['user_id'])) $q->where('e.user_id', $d['user_id']);
        if (!empty($d['search'])) $q->where(function ($q) use ($d) {
            $q->where('u.name', 'like', '%'.$d['search'].'%')
                ->orWhere('e.employeeCode', 'like', '%'.$d['search'].'%');
        });
        $page = $q->paginate($d['per_page'] ?? 25, ['*'], 'page', $d['page'] ?? 1);
        $rows = [];
        foreach ($page->items() as $profile) {
            $calculation = $calculator->preview($period, (int) $profile->user_id);
            $rows[] = [
                'profile_id' => $profile->profile_id,
                'employee_code' => $profile->employee_code,
                'name' => $profile->name,
                'user_id' => $profile->user_id,
                'status' => $calculation['status'],
                'net_arrear' => $calculation['net_arrear'],
                'errors' => $calculation['errors'],
                'warnings' => $calculation['warnings'],
            ];
        }
        return response()->json([
            'period' => $period,
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'total_profiles' => $page->total(), 'employees' => $rows,
            'disclaimer' => 'PROVISIONAL READ-ONLY. Missing historical payroll is not treated as zero; no bill or GPF post.',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function statement(Request $request, int $periodId, int $userId, SalaryArrearCalculator $calculator)
    {
        $period = DB::table('salary_arrear_periods')->where('id', $periodId)->first();
        abort_unless($period, 404);
        abort_unless(DB::table('users')->where('id', $userId)->exists(), 404);
        $result = $calculator->preview($period, $userId);
        return response()->json(['period' => $period, 'statement' => $result,
            'payable' => false, 'gpf_posted' => false])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Phase 3C: canonical five-component preview via SalaryStructuredArrearService.
     * Read-only draft preview. Never posts a bill, never touches the legacy
     * statement response above. All amounts come from the backend service;
     * Vue renders only and does not calculate arrears independently.
     */
    public function structuredPreview(Request $request, int $periodId, int $userId)
    {
        $period = DB::table('salary_arrear_periods')->where('id', $periodId)->first();
        abort_unless($period, 404);
        $user = DB::table('users as u')
            ->leftJoin('employee_infos as e', 'e.user_id', '=', 'u.id')
            ->where('u.id', $userId)
            ->selectRaw('u.*, e.employeeCode as employee_code')
            ->first();
        abort_unless($user !== null, 404);

        $service = app(SalaryStructuredArrearService::class);
        $rules = SalaryStructuredArrearService::canonicalRuleSet(Schema::hasTable('salary_arrear_rules')
            ? DB::table('salary_arrear_rules')->whereIn('code', SalaryStructuredArrearService::CODES)
                ->where('published', 1)->orderBy('display_order')->get()->all()
            : []);
        $components = $service->preview($userId, $rules);
        $evidence = $service->evidenceSummary($userId);
        $conflict = (bool) ($evidence['source_conflicts'] ?? false);

        $provisional = ['payable' => 0.0, 'deduction' => 0.0];
        foreach ($components as $component) {
            if ($component['calculated_amount'] === null) continue;
            if ($component['direction'] === 'payable') $provisional['payable'] += $component['calculated_amount'];
            else $provisional['deduction'] += $component['calculated_amount'];
        }

        return response()->json([
            'period_id' => $periodId,
            'user_id' => $userId,
            'user_name' => $user->name ?? '',
            'employee_code' => $user->employee_code ?? '',
            'verified' => ! $conflict,
            'conflict' => $conflict,
            'calculation_method' => 'STRUCTURED_PAY_SCALE_2026',
            'components' => $components,
            'provisional_totals' => $provisional,
            'evidence' => [
                'june_basic' => $evidence['june_basic'] ?? null,
                'old_increment' => $evidence['old_increment'] ?? null,
                'increment_status' => $evidence['increment_status'] ?? 'UNKNOWN',
                'revised_basics' => $evidence['revised_basics'] ?? [],
                'revised_sources' => $evidence['revised_sources'] ?? [],
                'paid_months' => $evidence['paid_months'] ?? [],
                'financial_months' => $evidence['financial_months'] ?? [],
            ],
            'disclaimer' => 'Draft preview only. No salary bill, GPF ledger, journal, or disbursement is created. Missing evidence is shown as 0.00 with "Unverified - Source required".',
        ])->header('Cache-Control', 'private, no-store');
    }
    /** HR attests that an approved bill was actually disbursed; no bank data is guessed. */
    public function certifyPayment(Request $request, int $periodId, int $userId)
    {
        $period = $this->mutablePeriod($periodId, $userId);
        $d = $request->validate([
            'year' => 'required|integer|between:2020,2100',
            'month' => 'required|integer|between:1,12',
            'salary_generate_id' => 'required|integer|exists:salary_generates,id',
            'evidence_reference' => 'required|string|min:5|max:500',
            'reason' => 'required|string|min:8|max:3000',
        ]);
        $this->checkMonth($period, (int) $d['year'], (int) $d['month']);
        $bill = DB::table('salary_generates')->where('id', $d['salary_generate_id'])
            ->where('user_id', $userId)->where('salaryGenerationYear', $d['year'])
            ->where('monthId', $d['month'])->where('reviewStatus', 'approved')->first();
        abort_unless($bill, 422, 'No matching approved historical salary bill for the employee/month.');
        $key = ['period_id' => $periodId, 'user_id' => $userId,
            'year' => $d['year'], 'month' => $d['month']];
        $existing = DB::table('salary_arrear_payment_confirmations')->where($key)->first();
        if ($existing) {
            abort_unless((int) $existing->salary_generate_id === (int) $d['salary_generate_id']
                && $existing->evidence_reference === $d['evidence_reference'],
                409, 'Existing bank-payment certification differs; use formal reconciliation rather than overwrite.');
            return response()->json(['message' => 'Historical payment already certified with matching evidence.']);
        }
        DB::table('salary_arrear_payment_confirmations')->insert(array_merge($key, [
            'salary_generate_id' => $d['salary_generate_id'],
            'evidence_reference' => $d['evidence_reference'], 'reason' => $d['reason'],
            'certified_by' => $request->user()->id,
            'updated_at' => now(), 'created_at' => now(),
        ]));
        return response()->json(['message' => 'Disbursement evidence certified for the historical salary month.']);
    }

    public function sourceOverride(Request $request, int $periodId, int $userId)
    {
        $period = $this->mutablePeriod($periodId, $userId);
        $d = $request->validate([
            'year' => 'required|integer|between:2020,2100', 'month' => 'required|integer|between:1,12',
            'field' => 'required|in:paid_basic,paid_special_benefit,paid_house_rent,paid_gpf,revised_house_rent,revised_gpf,gpf_rate_percent',
            'amount' => 'required|numeric|min:0|max:10000000',
            'certified' => 'required|boolean',
            'reason' => 'required|string|min:8|max:3000',
            'evidence_reference' => 'required|string|min:5|max:500',
        ]);
        $this->checkMonth($period, (int) $d['year'], (int) $d['month']);
        // A pending salary draft is NOT proof that money was paid. Permit the
        // existing audited source-override workflow to record independently
        // certified payment evidence for one pending draft; never permit a
        // silent override of an approved bill or ambiguous duplicate bills.
        if (str_starts_with($d['field'], 'paid_')) {
            $existingBills = DB::table('salary_generates')
                ->where('user_id', $userId)->where('salaryGenerationYear', $d['year'])
                ->where('monthId', $d['month'])->limit(2)->get(['id', 'reviewStatus']);
            if ($existingBills->count() > 1 || $existingBills->contains(
                static fn ($bill): bool => (string) $bill->reviewStatus === 'approved'
            )) {
                return response()->json(['message' => 'Approved or duplicate payroll source exists. Financial reconciliation is required; no silent override.'], 409);
            }
        }
        $key = ['period_id' => $periodId, 'user_id' => $userId,
            'year' => $d['year'], 'month' => $d['month'], 'field' => $d['field']];
        DB::transaction(function () use ($key, $d, $request, $periodId, $userId) {
            $old = DB::table('salary_arrear_source_overrides')->where($key)->lockForUpdate()->first();
            $newValues = [
                'amount' => $d['amount'], 'certified' => $d['certified'],
                'reason' => $d['reason'], 'evidence_reference' => $d['evidence_reference'],
                'created_by' => $request->user()->id, 'updated_at' => now(),
            ];
            if ($old) DB::table('salary_arrear_source_overrides')->where('id', $old->id)->update($newValues);
            else DB::table('salary_arrear_source_overrides')->insert(array_merge($key, $newValues, ['created_at' => now()]));
            DB::table('salary_arrear_override_audits')->insert([
                'period_id' => $periodId, 'user_id' => $userId,
                'year' => $d['year'], 'month' => $d['month'], 'field' => $d['field'],
                'before_value' => $old ? json_encode($old) : null,
                'after_value' => json_encode($d), 'actor_user_id' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        return response()->json(['message' => 'Evidence-based source/entitlement override saved; snapshot will require recalculation.']);
    }

    public function manualItem(Request $request, int $periodId, int $userId)
    {
        $period = $this->mutablePeriod($periodId, $userId);
        $d = $request->validate([
            'year' => 'required|integer|between:2020,2100', 'month' => 'required|integer|between:1,12',
            'title' => 'required|string|min:3|max:120',
            'direction' => 'required|in:addition,deduction',
            'amount' => 'required|numeric|gt:0|max:1000000',
            'reason' => 'required|string|min:8|max:3000',
            'evidence_reference' => 'required|string|min:5|max:500',
        ]);
        $this->checkMonth($period, (int) $d['year'], (int) $d['month']);
        $reservedTitles = ['gross basic arrear', 'old-scale increment adjustment',
            'special benefit recovery', 'additional gpf contribution', 'house rent difference'];
        if (in_array(strtolower(trim($d['title'])), $reservedTitles, true)) {
            return response()->json(['message' => 'This is an automatic calculation line. Correct its evidence/source rather than adding the same deduction twice.'], 422);
        }
        if (DB::table('salary_arrear_manual_items')->where('period_id', $periodId)->where('user_id', $userId)
            ->where('year', $d['year'])->where('month', $d['month'])->where('title', $d['title'])
            ->where('direction', $d['direction'])->where('amount', $d['amount'])->exists()) {
            return response()->json(['message' => 'This manual adjustment is already recorded.'], 409);
        }
        $id = DB::table('salary_arrear_manual_items')->insertGetId(array_merge($d, [
            'period_id' => $periodId, 'user_id' => $userId,
            'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Titled, audited adjustment created. Existing entries are immutable.'], 201);
    }

    public function snapshot(Request $request, int $periodId, int $userId, SalaryArrearCalculator $calculator)
    {
        $period = $this->mutablePeriod($periodId, $userId);
        $calculation = $calculator->preview($period, $userId);
        if ($calculation['status'] === 'BLOCKED') {
            return response()->json(['message' => 'Cannot snapshot blocked arrear.', 'errors' => $calculation['errors']], 422);
        }
        $hash = $this->sourceHash($period, $userId, $calculation);
        $snapshot = DB::transaction(function () use ($periodId, $userId, $request, $calculation, $hash) {
            // Lock period to serialize snapshot versions even on databases without a unique period/user lock row.
            DB::table('salary_arrear_periods')->where('id', $periodId)->lockForUpdate()->first();
            if (DB::table('salary_arrear_snapshots')->where('period_id', $periodId)
                ->where('user_id', $userId)->where('status', 'approved')->exists()) {
                abort(409, 'An approved arrear snapshot already exists. A formal revision is required.');
            }
            $version = (int) DB::table('salary_arrear_snapshots')->where('period_id', $periodId)
                ->where('user_id', $userId)->max('version') + 1;
            $id = DB::table('salary_arrear_snapshots')->insertGetId([
                'period_id' => $periodId, 'user_id' => $userId, 'version' => $version,
                'status' => 'draft', 'source_hash' => $hash,
                'calculation' => json_encode($calculation, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'net_arrear' => $calculation['net_arrear'],
                'prepared_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($calculation['months'] as $month) {
                DB::table('salary_arrear_monthly_breakdowns')->insert([
                    'snapshot_id' => $id, 'year' => $month['year'], 'month' => $month['month'],
                    'source_data' => json_encode($month['paid'], JSON_UNESCAPED_UNICODE),
                    'calculated_data' => json_encode($month['calculated'], JSON_UNESCAPED_UNICODE),
                    'net_difference' => $month['calculated']['net_arrear'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach ($calculation['lines'] as $line) DB::table('salary_arrear_lines')->insert(array_merge($line, [
                'snapshot_id' => $id, 'created_at' => now(), 'updated_at' => now(),
            ]));
            DB::table('salary_arrear_reviews')->insert(['snapshot_id' => $id, 'actor_user_id' => $request->user()->id,
                'action' => 'draft_saved', 'created_at' => now(), 'updated_at' => now()]);
            return $id;
        });
        return response()->json(['snapshot_id' => $snapshot, 'status' => 'draft'], 201);
    }

    public function review(Request $request, int $snapshotId)
    {
        $d = $request->validate(['action' => 'required|in:send,confirm,dispute', 'note' => 'nullable|string|max:3000']);
        return DB::transaction(function () use ($snapshotId, $request, $d) {
            $snapshot = DB::table('salary_arrear_snapshots')->where('id', $snapshotId)->lockForUpdate()->first();
            abort_unless($snapshot, 404);
            if ($d['action'] === 'send') {
                abort_unless($snapshot->status === 'draft', 409);
                $status = 'sent';
            } else {
                abort_unless($snapshot->status === 'sent', 409);
                // Employee responses cannot be submitted on behalf of another person.
                abort_unless((int) $snapshot->user_id === (int) $request->user()->id, 403);
                $status = $d['action'] === 'confirm' ? 'employee_confirmed' : 'employee_disputed';
            }
            DB::table('salary_arrear_snapshots')->where('id', $snapshotId)
                ->update(['status' => $status, 'updated_at' => now()]);
            DB::table('salary_arrear_reviews')->insert([
                'snapshot_id' => $snapshotId, 'actor_user_id' => $request->user()->id,
                'action' => $d['action'], 'note' => $d['note'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return response()->json(['status' => $status]);
        });
    }

    public function approve(Request $request, int $snapshotId, SalaryArrearCalculator $calculator)
    {
        $d = $request->validate(['approval_note' => 'required|string|min:10|max:3000']);
        return DB::transaction(function () use ($snapshotId, $request, $d, $calculator) {
            $snapshot = DB::table('salary_arrear_snapshots')->where('id', $snapshotId)->lockForUpdate()->first();
            abort_unless($snapshot, 404);
            abort_unless(in_array($snapshot->status, ['draft','sent','employee_confirmed'], true), 409);
            abort_if(DB::table('salary_arrear_disbursements')->where('period_id', $snapshot->period_id)
                ->where('user_id', $snapshot->user_id)->exists(), 409, 'A disbursement record already exists.');
            abort_if(DB::table('salary_arrear_snapshots')->where('period_id', $snapshot->period_id)
                ->where('user_id', $snapshot->user_id)->where('status', 'approved')->exists(), 409);
            $period = DB::table('salary_arrear_periods')->where('id', $snapshot->period_id)->first();
            $current = $calculator->preview($period, (int) $snapshot->user_id);
            abort_unless($current['status'] === 'PASS' || ($current['status'] === 'REVIEW'
                && count(array_filter($current['warnings'], fn ($w) => !str_contains($w, 'CERTIFIED_MANUAL_PAYMENT_SOURCE'))) === 0),
                409, 'Arrear contains missing evidence or an unresolved calculation/policy issue.');
            abort_unless(hash_equals($snapshot->source_hash, $this->sourceHash($period, (int) $snapshot->user_id, $current)),
                409, 'Source data or calculation changed. Create and review a new snapshot.');
            DB::table('salary_arrear_snapshots')->where('id', $snapshotId)->update([
                'status' => 'approved', 'approved_by' => $request->user()->id,
                'approved_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('salary_arrear_reviews')->insert([
                'snapshot_id' => $snapshotId, 'actor_user_id' => $request->user()->id,
                'action' => 'hr_approved', 'note' => $d['approval_note'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return response()->json(['status' => 'approved', 'disbursed' => false, 'gpf_posted' => false]);
        });
    }

    private function mutablePeriod(int $periodId, int $userId): object
    {
        $period = DB::table('salary_arrear_periods')->where('id', $periodId)->first();
        abort_unless($period && DB::table('users')->where('id', $userId)->exists(), 404);
        abort_if(DB::table('salary_arrear_snapshots')->where('period_id', $periodId)
            ->where('user_id', $userId)->where('status', 'approved')->exists(),
            409, 'Approved arrear cannot be silently modified; create a formal revision period.');
        return $period;
    }

    private function checkMonth(object $period, int $year, int $month): void
    {
        $date = sprintf('%04d-%02d-01', $year, $month);
        abort_unless($date >= substr($period->entitlement_start, 0, 7).'-01'
            && $date <= substr($period->entitlement_end, 0, 7).'-01', 422, 'Month outside arrear period.');
    }

    private function sourceHash(object $period, int $userId, array $calculation): string
    {
        return hash('sha256', json_encode([
            'period' => $period,
            'calculation' => $calculation,
            'manual' => DB::table('salary_arrear_manual_items')->where('period_id', $period->id)
                ->where('user_id', $userId)->orderBy('id')->get(),
            'overrides' => DB::table('salary_arrear_source_overrides')->where('period_id', $period->id)
                ->where('user_id', $userId)->orderBy('id')->get(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}