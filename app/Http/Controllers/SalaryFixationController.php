<?php

namespace App\Http\Controllers;

use App\Models\SalaryFixation;
use App\Models\SalaryFixationAdjustment;
use App\Models\DepartmentOffice;
use App\Models\PayrollGroup;
use App\Models\UserCategory;
use App\Models\SalaryFixationPaySchedule;
use App\Mail\SalaryFixationReviewMail;
use App\Models\User;
use App\Services\SalaryFixationService;
use App\Services\SalaryFixationWorkflowService;
use App\Services\SalaryFixationApprovalPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;

class SalaryFixationController extends Controller
{

    private function ensureAuthorized(Request $request): void
    {
        // Authentication is the sole access prerequisite for the approved
        // single-operator KAU EMS workflow; no separate HR role gate.
        abort_unless($request->user(), 401);
    }

    public function index(Request $request, SalaryFixationService $service)
    {
        $this->ensureAuthorized($request);
        $validated = $request->validate([
            'search' => 'nullable|string|max:120',
            'increment_status' => 'nullable|in:all,increment_available,increment_hold,adhoc,study_leave_with,study_leave_without,other_no_increment',
            'fixation_status' => 'nullable|in:all,needs_review,blocked,provisional,approved',
            'per_page' => 'nullable|integer|in:10,15,25,50,100,500,1000,1500',
            'department_office_id' => 'nullable|integer|exists:department_offices,id',
            'user_category_id' => 'nullable|integer|exists:user_categories,id',
            'payroll_group_id' => 'nullable|integer|exists:payroll_groups,id',
        ]);
        $search = trim($validated['search'] ?? '');
        $incrementStatus = $validated['increment_status'] ?? 'all';
        $fixationStatus = $validated['fixation_status'] ?? 'all';
        $perPage = (int)($validated['per_page'] ?? 10);
        $cachedPrepared = [];
        $query = User::query()->whereHas('employeeInfo')->where('isActive', true);
        // Relational filters reuse the same employee-directory semantics as the
        // salary generation page (see SalaryGenerateController::buildFilteredEmployeeQuery).
        if (!empty($validated['department_office_id'])) {
            $deptId = (int) $validated['department_office_id'];
            $query->where(function ($q) use ($deptId) {
                $q->whereHas('departmentOffices', function ($sub) use ($deptId) {
                    $sub->where('department_offices.id', $deptId);
                })->orWhereHas('employeeInfo', function ($sub) use ($deptId) {
                    $sub->where('department_office_id', $deptId);
                });
            });
        }
        if (!empty($validated['user_category_id'])) {
            $catId = (int) $validated['user_category_id'];
            $query->whereHas('userCategories', function ($q) use ($catId) {
                $q->where('user_categories.id', $catId);
            });
        }
        if (!empty($validated['payroll_group_id'])) {
            $groupId = (int) $validated['payroll_group_id'];
            $query->whereHas('employeeInfo', function ($q) use ($groupId) {
                $q->where('payroll_group_id', $groupId);
            });
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhereHas('employeeInfo', function ($sq) use ($search) {
                        $sq->where('employeeCode', 'like', '%' . $search . '%');
                    });
            });
        }
        // Filter actual prepared eligibility and the latest saved workflow status
        // BEFORE pagination, not merely the currently visible 10 employees.
        // Profile duplicates remain flagged by prepare() rather than silently combined.
        if ($incrementStatus === 'all' && $fixationStatus === 'all') {
            $page = $query->orderBy('name')->paginate($perPage)->withQueryString();
            $saved = SalaryFixation::query()
                ->whereIn('user_id', $page->getCollection()->pluck('id'))
                ->latest('id')->get()->unique('user_id')->keyBy('user_id');
        } else {
            $candidateUsers = $query->orderBy('name')->get();
            $saved = SalaryFixation::query()
                ->whereIn('user_id', $candidateUsers->pluck('id'))
                ->latest('id')->get()->unique('user_id')->keyBy('user_id');
            $matching = $candidateUsers->filter(function (User $user) use (
                $service,
                $incrementStatus,
                $fixationStatus,
                $saved,
                &$cachedPrepared
            ) {
                $prepared = $cachedPrepared[$user->id] = $service->prepare($user);
                $category = $prepared['special_eligibility_category'] ?? 'ordinary';
                $increment = SalaryFixationWorkflowService::incrementDisplay($prepared);
                $available = is_numeric($increment['amount'] ?? null) && (float)$increment['amount'] > 0;
                $eligibilityMatches = match ($incrementStatus) {
                    'all' => true,
                    'increment_available' => $available,
                    'increment_hold' => $category === 'increment_hold' || ($category === 'ordinary' && !empty($prepared['hold_increment'])),
                    'adhoc' => $category === 'adhoc',
                    'study_leave_with' => $category === 'study_leave_exception',
                    'study_leave_without' => $category === 'study_leave_restricted',
                    'other_no_increment' => !$available && !in_array(
                        $category,
                        ['increment_hold', 'adhoc', 'study_leave_restricted', 'study_leave_exception'],
                        true
                    ),
                    default => false,
                };
                $current = $saved->get($user->id);
                $status = $current?->status;
                $blocked = !SalaryFixationApprovalPolicy::isCalculable($prepared)
                    || !empty($prepared['blockers']);
                $needsReview = $blocked || !empty($prepared['warnings'])
                    || !$current || in_array($status, ['employee_disputed', 'rejected'], true);
                $statusMatches = match ($fixationStatus) {
                    'all' => true,
                    'approved' => $status === 'approved',
                    'blocked' => $blocked,
                    'needs_review' => $needsReview,
                    'provisional' => $status !== 'approved' && !$blocked,
                    default => false,
                };
                return $eligibilityMatches && $statusMatches;
            })->values();
            $currentPage = LengthAwarePaginator::resolveCurrentPage();
            $page = new LengthAwarePaginator(
                $matching->forPage($currentPage, $perPage)->values(),
                $matching->count(),
                $perPage,
                $currentPage,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        }
        $page->getCollection()->transform(function (User $user) use ($service, $saved, &$cachedPrepared) {
            $data = $cachedPrepared[$user->id] ?? $service->prepare($user);
            return [
                'id' => $user->id,
                'employee_code' => $data['employee_code'] ?? '—',
                'name' => $user->name,
                'grade' => $data['grade'] ?? null,
                'current_basic' => $data['basic'] ?? null,
                'source_type' => $data['source_type'] ?? '',
                'baseline_period' => $data['baseline_period'] ?? '2026-06',
                'employee_profile_count' => $data['employee_profile_count'] ?? null,
                'phase_2026_07' => $data['calculation']['phase_2026_07'] ?? null,
                'phase_2027_01' => $data['calculation']['phase_2027_01'] ?? null,
                'phase_2027_07' => $data['calculation']['phase_2027_07'] ?? null,
                'increment' => SalaryFixationWorkflowService::incrementDisplay($data),
                'can_calculate' => SalaryFixationApprovalPolicy::isCalculable($data),
                'ready' => $data['ready'] ?? false,
                'blockers' => $data['blockers'] ?? [],
                'warning_count' => count($data['warnings'] ?? []),
                'warnings' => $data['warnings'] ?? [],
                'saved_id' => $saved->get($user->id)?->id,
                'saved_status' => $saved->get($user->id)?->status,
                'manual_evidence' => $data['manual_evidence'] ?? null,
                'adjustment' => SalaryFixationAdjustment::where('user_id', $user->id)->first(),
                'special_category' => $data['special_eligibility_category'] ?? 'ordinary',
                'reference_match_status' => $data['reference_match_status'] ?? 'missing',
            ];
        });
        return Inertia::render('Salary/Fixation/Index', [
            'employees' => $page,
            'filters' => [
                'search' => $search,
                'increment_status' => $incrementStatus,
                'fixation_status' => $fixationStatus,
                'per_page' => $perPage,
                'department_office_id' => (int)($validated['department_office_id'] ?? 0),
                'user_category_id' => (int)($validated['user_category_id'] ?? 0),
                'payroll_group_id' => (int)($validated['payroll_group_id'] ?? 0),
            ],
            'departmentOffices' => DepartmentOffice::select('id', 'name')->where('isActive', true)->orderBy('name')->get(),
            'userCategories' => UserCategory::select('id', 'name')->orderBy('name')->get(),
            'payrollGroups' => PayrollGroup::with(['grade:id,grade_name', 'payrollGroupType:id,group_name'])
                ->where('isActive', true)->orderBy('id')->get(),
            'effectiveDate' => '2026-07-01',
            'ruleReference' => 'Report reference: 17 Sep 2026, SRO 348/2026 (verify against HR order)',
        ]);
    }

    public function preview(Request $request, User $user, SalaryFixationService $service)
    {
        $this->ensureAuthorized($request);
        $data = $service->prepare($user);
        // Reporting is read-only. Missing financial evidence does not prevent a
        // provisional document; the renderer blanks unverified money values.
        return $this->renderFixationDocument($data, false);
    }

    /** Print the exact, immutable snapshot reviewed by employee and HR. */
    public function adminPrintSnapshot(Request $request, SalaryFixation $salaryFixation)
    {
        $this->ensureAuthorized($request);
        $data = $salaryFixation->prepared_data;
        if (!is_array($data)) {
            $data = app(SalaryFixationService::class)->prepare($salaryFixation->employee);
            $data['warnings'][] = 'Saved snapshot has no prepared data; current read-only calculation is shown.';
            $data['ready'] = false;
        }
        return $this->renderFixationDocument(
            $data,
            $salaryFixation->status === 'approved' && $salaryFixation->approved_at !== null && is_array($salaryFixation->prepared_data)
        );
    }

    public function employeePrint(Request $request, SalaryFixation $salaryFixation)
    {
        abort_unless($request->user() && $request->user()->isActive &&
            (int) $salaryFixation->user_id === (int) $request->user()->id, 403);
        abort_unless(in_array($salaryFixation->status, ['sent', 'employee_confirmed', 'employee_disputed', 'approved']), 404);
        abort_unless(is_array($salaryFixation->prepared_data), 409, 'No frozen prepared report available.');
        return $this->renderFixationDocument($salaryFixation->prepared_data, $salaryFixation->approved_at !== null);
    }

    private function renderFixationDocument(array $data, bool $approved)
    {
        $template = file_get_contents(resource_path('fixation/kaufixation.html'));
        $runtime = file_get_contents(resource_path('fixation/runtime.js'));
        if ($template === false || $runtime === false) {
            abort(500, 'Fixation template is missing.');
        }
        // Keep the original reference form text intact; any Gazette citation dispute is a separate HR review.
        // The supplied standalone page loaded executable Tailwind JS from a third party.
        // Use the app's own compiled stylesheet instead so employee data remains private.
        $manifestPath = public_path('build/manifest.json');
        if (!is_file($manifestPath)) {
            abort(503, 'Frontend assets missing. Run npm install and npm run build.');
        }
        $manifest = json_decode(file_get_contents($manifestPath), true);
        $cssAsset = $manifest['resources/js/app.js']['css'][0] ?? null;
        if (!$cssAsset) {
            abort(503, 'Built application stylesheet is missing.');
        }
        $template = str_replace('https://administration.kau.ac.bd/storage/editor/dweRPWcCOvjgIahj1Paj.webp', asset('logo.png'), $template);
        $stylesheetTag = '<link rel="stylesheet" href="' . asset('build/' . $cssAsset) . '">';
        $template = str_replace('<script src="https://cdn.tailwindcss.com"></script>', $stylesheetTag, $template);
        $pdfJs = $manifest['resources/js/fixation-pdf.js']['file'] ?? null;
        if (!$pdfJs) {
            abort(503, 'Fixation PDF asset missing from build manifest. Run npm run build.');
        }
        $pdfTag = '<script type="module" src="' . asset('build/' . $pdfJs) . '"></script>';
        $data['approved_print'] = $approved;
        // The reference is a single A4 sheet; remove clipped scrolling containers in print.
        $printFix = <<<'CSS'
<style id="kau-fixation-print-rules">
/* Embedded in Report Factory: show only the same official form, without
   the calculator sidebar. The ordinary standalone URL remains unchanged. */
@media screen {
  .kau-embed-mode body > header, .kau-embed-mode body > footer, .kau-embed-mode #copyToast { display:none !important; }
  .kau-embed-mode #mainContentWrapper { max-width:none !important; width:100% !important; padding:0 !important; margin:0 !important; }
  .kau-embed-mode #mainContentWrapper > .grid { display:block !important; }
  .kau-embed-mode #mainContentWrapper > .grid > div:first-child { display:none !important; }
  .kau-embed-mode #mainContentWrapper > .grid > div:nth-child(2) { width:100% !important; max-width:none !important; }
  .kau-embed-mode #view-official { padding:0 !important; border:0 !important; box-shadow:none !important; }
  .kau-embed-mode #view-official > .no-print { display:none !important; }
  .kau-embed-mode #officialFormContainer { width:100% !important; margin:0 !important; }
  .kau-embed-mode #mainContentWrapper .no-print { display:none !important; }
  .kau-embed-mode body > .no-print { display:none !important; }
}
/* Original Bengali official form, with legible print sizes and real signature space. */
.official-preview-table { table-layout: fixed; width: 100%; border-collapse: collapse; font-size:11.5px !important; }
.official-preview-table th, .official-preview-table td {
  padding: 5.7px 7px; line-height: 1.32; vertical-align: middle;
  white-space: normal; overflow-wrap: anywhere; word-break: normal;
}
.kau-employee-signature { min-height: 82px !important; padding-top: 65px !important; align-items: flex-end; }
.kau-finance-signatures { padding-top: 72px !important; align-items: flex-end; }
.kau-signature-block { break-inside: avoid; page-break-inside: avoid; }
@page { size: A4 portrait; margin: 0; }
@media print {
  html, body, main, #mainContentWrapper, #officialFormContainer, .official-sheet,
  #view-official, .overflow-x-auto { height: auto !important; max-height: none !important;
    overflow: visible !important; }
  #mainContentWrapper { width:100% !important; max-width:none !important; margin:0 !important; padding:0 !important; }
  #mainContentWrapper > .grid { display:block !important; }
  #officialFormContainer { width:194mm !important; max-width:194mm !important; margin:0 auto !important; padding:6mm !important; }
  .official-sheet { page-break-inside:avoid !important; break-inside:avoid !important;
    width:100% !important; font-size:9px !important; line-height:1.18 !important; }
  .official-preview-table { font-size:9px !important; }
  .official-preview-table th, .official-preview-table td {
    padding:2px 3px !important; line-height:1.18 !important; vertical-align:middle !important;
    overflow-wrap:anywhere !important; white-space:normal !important; }
  .official-preview-table tr { break-inside:avoid !important; page-break-inside:avoid !important; }
  .kau-employee-signature { min-height:18mm !important; padding-top:15mm !important; }
  .kau-finance-signatures { padding-top:5mm !important; }
  .kau-signature-block { break-inside:avoid !important; page-break-inside:avoid !important; }
  .official-sheet img { max-height:48px !important; }
  .kau-provisional-mark { display:block!important; font-size:9px!important; }
  .no-print, header, footer, #copyToast, #kau-approval-status { display:none !important; }
  html, body { -webkit-print-color-adjust: exact; print-color-adjust:exact; }
}
</style>
CSS;
        $template = str_replace('</head>', $printFix . "\n</head>", $template);
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $injection = "\n<script>if(new URLSearchParams(location.search).get('embed')==='1') document.documentElement.classList.add('kau-embed-mode');</script>";
        $injection .= "\n<script>window.KAU_DB_FIXATION = " . $json . ";</script>\n<script>\n" . $runtime . "\n</script>\n" . $pdfTag;
        $template = str_replace('</body>', $injection . '</body>', $template);
        return response($template, 200)->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data: https://administration.kau.ac.bd https://placehold.co; connect-src 'self'; frame-ancestors 'self'");
    }

    public function store(Request $request, User $user, SalaryFixationWorkflowService $workflow)
    {
        $this->ensureAuthorized($request);
        $record = $workflow->createDraft($user, (int) $request->user()->id);
        return back()->with('success', 'Fixation draft #' . $record->id . ' saved. No regular salary bill changed.');
    }

    /** Approval does not depend on employee review or separately configured HR roles. */
    public function approveNow(Request $request, User $user, SalaryFixationWorkflowService $workflow)
    {
        $this->ensureAuthorized($request);
        $record = $workflow->approveNow($user, (int) $request->user()->id);
        return back()->with('success', 'Fixation #' . $record->id . ' approved. Current pay schedule updated; old salary bills untouched.');
    }

    /** Reject latest version without deleting historical snapshots or paid salary. */
    public function reject(Request $request, User $user, SalaryFixationWorkflowService $workflow)
    {
        $this->ensureAuthorized($request);
        $request->validate(['reason' => 'nullable|string|max:2000']);
        $record = $workflow->rejectLatest($user, (int) $request->user()->id, $request->input('reason'));
        return back()->with('success', 'Fixation #' . $record->id . ' rejected; historical bills remain unchanged.');
    }

    public function bulkDecision(Request $request, SalaryFixationWorkflowService $workflow)
    {
        $this->ensureAuthorized($request);
        $validated = $request->validate([
            'decision' => 'required|in:approve,reject',
            'user_ids' => 'required|array|min:1|max:100',
            'user_ids.*' => 'required|integer|distinct|exists:users,id',
            'reason' => 'nullable|string|max:2000',
        ]);
        $result = ['approved' => 0, 'rejected' => 0, 'failed' => 0];
        $errors = [];
        foreach ($validated['user_ids'] as $id) {
            try {
                $employee = User::findOrFail($id);
                if ($validated['decision'] === 'approve') {
                    $workflow->approveNow($employee, (int) $request->user()->id);
                    $result['approved']++;
                } else {
                    $workflow->rejectLatest($employee, (int) $request->user()->id, $validated['reason'] ?? null);
                    $result['rejected']++;
                }
            } catch (\Illuminate\Validation\ValidationException $e) {
                $result['failed']++;
                $errors[] = '#' . $id . ': ' . implode('; ', $e->validator->errors()->all());
            } catch (\Throwable $e) {
                $result['failed']++;
                report($e);
                $errors[] = '#' . $id . ': could not process this employee; check Laravel log.';
            }
        }
        return back()->with('success', 'Bulk complete — approved ' . $result['approved']
            . ', rejected ' . $result['rejected'] . ', needs input ' . $result['failed'] . '.')
            ->with('fixation_bulk_errors', array_slice($errors, 0, 30));
    }

    /**
     * Bulk approve already-saved fixations. Each saved snapshot is approved
     * through the exact same approveSaved workflow used by the single-record
     * button: no recalculation, only the existing locked snapshot is published.
     */
    public function bulkApproveSaved(Request $request, SalaryFixationWorkflowService $workflow)
    {
        $this->ensureAuthorized($request);
        $validated = $request->validate([
            'fixation_ids' => 'required|array|min:1|max:100',
            'fixation_ids.*' => 'required|integer|distinct|exists:salary_fixations,id',
        ]);
        $approved = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];
        $processed = 0;
        foreach ($validated['fixation_ids'] as $fixationId) {
            $record = SalaryFixation::find($fixationId);
            if (!$record) {
                $skipped++;
                continue;
            }
            if ($record->status === 'approved') {
                $skipped++; // Already approved; approved fixation values are never modified.
                continue;
            }
            try {
                $workflow->approveSaved($record, (int) $request->user()->id);
                $approved++;
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                $processed++;
                report($e);
                $errors[] = '#' . $fixationId . ': could not approve this fixation; check Laravel log.';
            }
        }
        return back()->with('success', 'Bulk saved-fixation approval complete — approved ' . $approved
            . ', already approved/skipped ' . $skipped . ', needs input ' . $failed . '.')
            ->with('fixation_bulk_errors', array_slice($errors, 0, 30));
    }

    /** Audited, employee-specific adjustment; current approved schedule remains until reapproval. */
    public function saveAdjustment(Request $request, User $user, SalaryFixationWorkflowService $workflow)
    {
        $this->ensureAuthorized($request);
        $validated = $request->validate([
            'increment_2026' => 'required|in:automatic,allow,hold',
            'increment_2027' => 'required|in:automatic,allow,hold',
            'grade_override' => 'nullable|integer|between:1,20',
            'old_basic_override' => 'nullable|numeric|min:0.01|max:9999999',
            'personal_pay_override' => 'nullable|numeric|min:0|max:9999999',
            'baseline_period' => ['nullable', 'date_format:Y-m', 'after_or_equal:2015-01', 'before_or_equal:2026-06'],
            'additional_fields' => 'nullable|array|max:30',
            'additional_fields.*.title' => 'required|string|max:120',
            'additional_fields.*.direction' => 'required|in:addition,deduction',
            'additional_fields.*.value' => 'required|numeric|min:0|max:9999999',
            'evidence_reference' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:2000',
        ]);
        DB::transaction(function () use ($user, $request, $validated, $workflow) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $old = SalaryFixationAdjustment::where('user_id', $user->id)->first();
            $before = $old?->toArray() ?? [];
            $attributes = $validated + [
                'evidence_reference' => null,
                'reason' => null,
                'additional_fields' => [],
                'grade_override' => null,
                'old_basic_override' => null,
                'personal_pay_override' => null,
            ];
            $attributes['updated_by'] = $request->user()->id;
            $new = SalaryFixationAdjustment::updateOrCreate(['user_id' => $user->id], $attributes);
            $workflow->recordAdjustment($user, (int) $request->user()->id, $before, $new->toArray());
            SalaryFixation::where('user_id', $user->id)
                ->whereIn('status', ['pending_review', 'sent', 'employee_confirmed', 'employee_disputed'])
                ->update(['status' => 'superseded']);
        });
        return back()->with('success', 'Adjustment saved and logged. Approve Now to publish recalculated fixation.');
    }

    /** Send the individual employee a link to their own locked snapshot. */
    public function sendReview(Request $request, SalaryFixation $salaryFixation, SalaryFixationService $service)
    {
        $this->ensureAuthorized($request);
        abort_unless($salaryFixation->status === 'pending_review', 409, 'Only a new snapshot can be sent.');
        $employee = $salaryFixation->employee;
        abort_unless(
            $employee && $employee->isActive && filter_var($employee->email, FILTER_VALIDATE_EMAIL),
            422,
            'Active employee with a valid email is required.'
        );
        $current = $service->prepare($employee);
        abort_unless(($current['ready'] ?? false) && hash_equals($salaryFixation->source_sha256, $current['source_sha256']),
            409,
            'Fixation inputs changed or are no longer valid; regenerate the snapshot.'
        );
        Mail::to($employee->email)->send(new SalaryFixationReviewMail($salaryFixation));
        $salaryFixation->update(['status' => 'sent', 'sent_at' => now()]);
        return back()->with('success', 'Individual fixation review link sent to the employee.');
    }

    /** Employees can respond only to their own specific revision. */
    public function employeeReview(Request $request, SalaryFixation $salaryFixation)
    {
        abort_unless($request->user() && $request->user()->isActive &&
            (int) $salaryFixation->user_id === (int) $request->user()->id, 403);
        abort_unless(in_array($salaryFixation->status, ['sent', 'employee_confirmed', 'employee_disputed', 'approved']), 404);
        return Inertia::render('Salary/Fixation/EmployeeReview', [
            'fixation' => [
                'id' => $salaryFixation->id,
                'status' => $salaryFixation->status,
                'grade_number' => $salaryFixation->grade_number,
                'old_basic' => $salaryFixation->old_basic,
                'calculation' => $salaryFixation->calculation,
                'employee_note' => $salaryFixation->employee_note,
                'effective_date' => $salaryFixation->effective_date?->format('Y-m-d'),
            ],
        ]);
    }

    public function employeeRespond(Request $request, SalaryFixation $salaryFixation)
    {
        abort_unless($request->user() && $request->user()->isActive &&
            (int) $salaryFixation->user_id === (int) $request->user()->id, 403);
        $validated = $request->validate([
            'decision' => 'required|in:confirm,dispute',
            'note' => 'required_if:decision,dispute|nullable|string|max:2000',
        ]);
        DB::transaction(function () use ($salaryFixation, $validated, $request) {
            $locked = SalaryFixation::whereKey($salaryFixation->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'sent', 409, 'This fixation review is not awaiting your response.');
            $locked->update([
                'status' => $validated['decision'] === 'confirm' ? 'employee_confirmed' : 'employee_disputed',
                'employee_note' => $validated['note'] ?? null,
                'employee_reviewed_by' => $request->user()->id,
                'employee_responded_at' => now(),
            ]);
        });
        return back()->with('success', 'Your response has been recorded for HR review.');
    }

    /** Approve a previously saved version, even without employee confirmation. */
    public function approve(Request $request, SalaryFixation $salaryFixation, SalaryFixationWorkflowService $workflow)
    {
        $this->ensureAuthorized($request);
        $record = $workflow->approveSaved($salaryFixation, (int) $request->user()->id);
        return back()->with('success', 'Saved fixation #' . $record->id . ' approved. Old salary bills unchanged.');
    }
}
