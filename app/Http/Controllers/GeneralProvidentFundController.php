<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\DepartmentOffice;
use App\Models\GeneralProvidentFund;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\GeneralProvidentFundService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GeneralProvidentFundController extends Controller
{
    protected GeneralProvidentFundService $gpfService;

    public function __construct(GeneralProvidentFundService $gpfService)
    {
        $this->gpfService = $gpfService;
    }

    /**
     * Display a listing of GPF records with filters and summaries, or render the Inertia page.
     */
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $query = GeneralProvidentFund::with([
                'user.employeeInfo.departmentOffice',
                'user.employeeInfo.payrollGroup.payrollGroupType',
                'comments.user',
                'salaryGenerate',
            ]);

            // User filter
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->input('user_id'));
            }

            // Financial Year filter
            if ($request->filled('financialYear') && $request->input('financialYear') !== 'all') {
                $query->where('financialYear', $request->input('financialYear'));
            }

            // Year and Month filter
            if ($request->filled('year')) {
                $query->where('year', (int) $request->input('year'));
            }
            if ($request->filled('month')) {
                $query->where('month', (int) $request->input('month'));
            }

            // Settlement filter
            if ($request->filled('isSettled')) {
                $isSettled = filter_var($request->input('isSettled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isSettled !== null) {
                    $query->where('isSettled', $isSettled);
                }
            }

            // Type filter
            if ($request->filled('type') && $request->input('type') !== 'all') {
                $query->where('type', $request->input('type'));
            }

            // Employee search
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('employeeId', 'like', "%{$search}%")
                      ->orWhereHas('user', function ($uq) use ($search) {
                          $uq->where('name', 'like', "%{$search}%")
                             ->orWhere('email', 'like', "%{$search}%")
                             ->orWhereHas('employeeInfo', function ($eiq) use ($search) {
                                 $eiq->where('employeeCode', 'like', "%{$search}%")
                                     ->orWhereHas('departmentOffice', function ($doq) use ($search) {
                                         $doq->where('name', 'like', "%{$search}%");
                                     })
                                     ->orWhereHas('payrollGroup.payrollGroupType', function ($pgq) use ($search) {
                                         $pgq->where('group_name', 'like', "%{$search}%");
                                     });
                             });
                      });
                });
            }

            // Summary stats across matching records before pagination
            $baseQuery = clone $query;
            $totalRecords = $baseQuery->count();
            $totalContribution = (float) $baseQuery->sum('contributionAmount');
            $totalInterest = (float) $baseQuery->sum('interestAmount');
            $totalNetDeposit = (float) $baseQuery->sum('netDeposit');

            // Sorting
            $sortField = $request->input('sortField', 'id');
            $sortDirection = $request->input('sortDirection', 'desc');
            $allowedSort = ['id', 'year', 'month', 'contributionAmount', 'closingAmount', 'contributionDate'];

            if (in_array($sortField, $allowedSort)) {
                $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
            } else {
                $query->orderBy('year', 'desc')->orderBy('month', 'desc')->orderBy('id', 'desc');
            }

            $perPage = (int) $request->input('perPage', 15);
            $paginator = $query->paginate($perPage);

            return response()->json([
                'data' => $paginator->items(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'summary' => [
                    'total_records' => $totalRecords,
                    'total_contribution' => $totalContribution,
                    'total_interest' => $totalInterest,
                    'total_net_deposit' => $totalNetDeposit,
                ],
            ]);
        }

        // Return Inertia page with reference data
        $employees = User::whereHas('roles', function ($q) {
            $q->where('role_name', 'user');
        })->with(['employeeInfo.departmentOffice', 'employeeInfo.payrollGroup.payrollGroupType'])->get()->map(function ($u) {
            return [
                'id' => $u->id,
                'name' => (string) $u->name,
                'email' => (string) ($u->email ?? ''),
                'employee_code' => (string) ($u->employeeInfo?->employeeCode ?? 'N/A'),
                'designation' => (string) ($u->employeeInfo?->payrollGroup?->payrollGroupType?->group_name ?? 'N/A'),
                'department' => (string) ($u->employeeInfo?->departmentOffice?->name ?? 'N/A'),
            ];
        });

        // Distinct financial years present in DB plus default current ones
        $existingFys = GeneralProvidentFund::distinct()->pluck('financialYear')->filter()->values()->all();
        $currentYear = (int) date('Y');
        $defaultFys = [
            ($currentYear - 2) . '-' . ($currentYear - 1),
            ($currentYear - 1) . '-' . $currentYear,
            $currentYear . '-' . ($currentYear + 1),
            ($currentYear + 1) . '-' . ($currentYear + 2),
        ];
        $financialYears = array_values(array_unique(array_merge($defaultFys, $existingFys)));
        sort($financialYears);

        return Inertia::render('Payroll/GPF/Index', [
            'employees' => $employees,
            'financialYears' => $financialYears,
            'departments' => DepartmentOffice::where('isActive', true)->get(['id', 'name']),
            'currentFinancialYear' => $this->gpfService->getFinancialYear(),
            'currentMonth' => (int) date('n'),
            'currentYear' => (int) date('Y'),
        ]);
    }

    /**
     * Store a past / historical GPF contribution.
     */
    public function storePastContribution(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:1980|max:2099',
            'financialYear' => 'nullable|string|max:20',
            'contributionDate' => 'nullable|date',
            'openingBalance' => 'nullable|numeric|min:0',
            'contributionAmount' => 'required|numeric|min:0',
            'interestAmount' => 'nullable|numeric|min:0',
            'benefitPercentage' => 'nullable|numeric|min:0',
            'closingAmount' => 'nullable|numeric|min:0',
            'type' => 'nullable|string|max:30',
            'isSettled' => 'nullable|boolean',
            'remarks' => 'nullable|string|max:1000',
            'comment' => 'nullable|string|max:2000',
        ]);

        $gpf = $this->gpfService->storePastContribution($validated, auth()->id());

        return response()->json([
            'status' => 200,
            'message' => 'Historical GPF contribution record stored successfully.',
            'data' => $gpf->load(['user', 'comments']),
        ], 201);
    }

    /**
     * Store standard GPF record.
     */
    public function store(Request $request)
    {
        return $this->storePastContribution($request);
    }

    /**
     * Display a specific GPF record.
     */
    public function show(GeneralProvidentFund $generalProvidentFund)
    {
        $generalProvidentFund->load([
            'user.employeeInfo.departmentOffice',
            'user.employeeInfo.payrollGroup.payrollGroupType',
            'comments.user',
            'salaryGenerate',
        ]);

        return response()->json($generalProvidentFund);
    }

    /**
     * Update an existing GPF record.
     */
    public function update(Request $request, GeneralProvidentFund $generalProvidentFund)
    {
        $validated = $request->validate([
            'contributionAmount' => 'sometimes|numeric|min:0',
            'openingBalance' => 'sometimes|numeric|min:0',
            'interestAmount' => 'sometimes|numeric|min:0',
            'benefitPercentage' => 'sometimes|numeric|min:0',
            'closingAmount' => 'sometimes|numeric|min:0',
            'financialYear' => 'sometimes|string|max:20',
            'month' => 'sometimes|integer|min:1|max:12',
            'year' => 'sometimes|integer|min:1980|max:2099',
            'contributionDate' => 'sometimes|date',
            'remarks' => 'nullable|string|max:1000',
            'isSettled' => 'sometimes|boolean',
            'settlementDate' => 'nullable|date',
        ]);

        if (isset($validated['contributionAmount']) || isset($validated['interestAmount'])) {
            $contrib = (float) ($validated['contributionAmount'] ?? $generalProvidentFund->contributionAmount);
            $interest = (float) ($validated['interestAmount'] ?? $generalProvidentFund->interestAmount);
            $validated['netDeposit'] = round($contrib + $interest, 2);

            if (!isset($validated['closingAmount'])) {
                $opening = (float) ($validated['openingBalance'] ?? $generalProvidentFund->openingBalance);
                $validated['closingAmount'] = round($opening + $validated['netDeposit'], 2);
            }
        }

        $generalProvidentFund->update($validated);

        return response()->json([
            'status' => 200,
            'message' => 'GPF record updated successfully.',
            'data' => $generalProvidentFund->fresh(['user', 'comments']),
        ]);
    }

    /**
     * Remove a GPF record.
     */
    public function destroy(GeneralProvidentFund $generalProvidentFund)
    {
        $generalProvidentFund->delete();

        return response()->json([
            'status' => 200,
            'message' => 'GPF record deleted successfully.',
        ]);
    }

    /**
     * Get complete chronological GPF ledger / personal statement for an employee.
     */
    public function userLedger(User $user, Request $request)
    {
        $financialYear = $request->input('financialYear');
        $ledger = $this->gpfService->getUserLedger($user, $financialYear);

        return response()->json($ledger);
    }

    /**
     * Settle an employee's GPF account upon retirement or exit.
     */
    public function settle(Request $request, GeneralProvidentFund $generalProvidentFund)
    {
        $request->validate([
            'settlementDate' => 'nullable|date',
            'remarks' => 'nullable|string|max:1000',
            'comment' => 'nullable|string|max:2000',
        ]);

        $generalProvidentFund->update([
            'isSettled' => true,
            'settlementDate' => $request->input('settlementDate', now()->toDateString()),
            'remarks' => $request->input('remarks', $generalProvidentFund->remarks),
        ]);

        if ($request->filled('comment')) {
            $comment = Comment::create([
                'user_id' => auth()->id() ?? $generalProvidentFund->user_id,
                'purpose' => 'gpf_settlement',
                'comment' => $request->input('comment'),
            ]);
            $generalProvidentFund->comments()->syncWithoutDetaching([$comment->id]);
        }

        return response()->json([
            'status' => 200,
            'message' => 'GPF record marked as settled.',
            'data' => $generalProvidentFund->fresh(['user', 'comments']),
        ]);
    }
}
