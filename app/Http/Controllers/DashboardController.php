<?php

namespace App\Http\Controllers;

use App\Models\BonusGenerate;
use App\Models\DepartmentOffice;
use App\Models\EmployeeInfo;
use App\Models\GeneralProvidentFund;
use App\Models\SalaryGenerate;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\GeneralProvidentFundService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    protected GeneralProvidentFundService $gpfService;

    public function __construct(GeneralProvidentFundService $gpfService)
    {
        $this->gpfService = $gpfService;
    }

    /**
     * Display the Executive Analytics Dashboard.
     */
    public function index(Request $request)
    {
        $currentMonth = (int) date('n');
        $currentYear = (int) date('Y');
        $financialYear = $this->gpfService->getFinancialYear();

        // 1. Employee Workforce Metrics
        $totalEmployees = User::whereHas('roles', function ($q) {
            $q->where('role_name', 'user');
        })->count();

        $activeEmployees = User::whereHas('roles', function ($q) {
            $q->where('role_name', 'user');
        })->where('isActive', true)->count();

        $inactiveEmployees = $totalEmployees - $activeEmployees;

        $genderBreakdown = User::whereHas('roles', function ($q) {
            $q->where('role_name', 'user');
        })
            ->select('gender', DB::raw('count(*) as count'))
            ->groupBy('gender')
            ->pluck('count', 'gender')
            ->all();

        $jobStatusBreakdown = EmployeeInfo::select('jobStatus', DB::raw('count(*) as count'))
            ->groupBy('jobStatus')
            ->pluck('count', 'jobStatus')
            ->all();

        // Category breakdown (Teacher, Officer, Staff, Class 3/4)
        $userCategories = UserCategory::withCount('users')->get()->map(function ($cat) {
            return [
                'id' => $cat->id,
                'name' => $cat->name,
                'count' => $cat->users_count,
            ];
        });

        // 2. Current Month Payroll Status
        $currentMonthSalaryQuery = SalaryGenerate::where('monthId', $currentMonth)
            ->where('salaryGenerationYear', $currentYear);

        $currentMonthGeneratedBills = (clone $currentMonthSalaryQuery)->count();
        $pendingBillsCount = max(0, $activeEmployees - $currentMonthGeneratedBills);

        $currentMonthSalaryTotal = (float) (clone $currentMonthSalaryQuery)->sum('salaryTotal');
        $currentMonthTotalAllowance = (float) (clone $currentMonthSalaryQuery)->sum('totalAllowance');
        $currentMonthTotalDeduction = (float) (clone $currentMonthSalaryQuery)->sum('totalDeduction');
        $currentMonthTaxDeduction = (float) (clone $currentMonthSalaryQuery)->sum('taxDeduction');
        $currentMonthGpfDeduction = (float) (clone $currentMonthSalaryQuery)->sum('gpfDeduction');

        $salaryReviewStatusBreakdown = (clone $currentMonthSalaryQuery)
            ->select('reviewStatus', DB::raw('count(*) as count'))
            ->groupBy('reviewStatus')
            ->pluck('count', 'reviewStatus')
            ->all();

        // 3. Last 6 Months Payroll Trend
        $monthlyTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $targetDate = Carbon::now()->subMonths($i);
            $tMonth = (int) $targetDate->format('n');
            $tYear = (int) $targetDate->format('Y');
            $label = $targetDate->format('M Y');

            $monthQuery = SalaryGenerate::where('monthId', $tMonth)->where('salaryGenerationYear', $tYear);
            $disbursed = (float) $monthQuery->sum('salaryTotal');
            $billCount = $monthQuery->count();

            $monthlyTrend[] = [
                'month' => $tMonth,
                'year' => $tYear,
                'label' => $label,
                'disbursed' => $disbursed,
                'billCount' => $billCount,
            ];
        }

        // 4. GPF (General Provident Fund) Overview
        $activeGpfUsersCount = GeneralProvidentFund::distinct('user_id')->count('user_id');
        
        // Latest accumulated closing balance across all users
        $totalGpfBalance = (float) DB::table('general_provident_funds as g1')
            ->join(DB::raw('(SELECT user_id, MAX(id) as max_id FROM general_provident_funds GROUP BY user_id) as g2'), function ($join) {
                $join->on('g1.user_id', '=', 'g2.user_id')
                     ->on('g1.id', '=', 'g2.max_id');
            })
            ->sum('g1.closingAmount');

        $currentMonthGpfContributions = (float) GeneralProvidentFund::where('month', $currentMonth)
            ->where('year', $currentYear)
            ->sum('contributionAmount');

        // 5. Bonus Statistics
        $latestBonus = BonusGenerate::orderBy('id', 'desc')->first();
        $latestBonusTitle = $latestBonus ? $latestBonus->bonus_title : 'No Recent Bonus';
        $latestBonusTotal = $latestBonus 
            ? (float) BonusGenerate::where('bonus_title', $latestBonus->bonus_title)
                ->where('bonusGenerationYear', $latestBonus->bonusGenerationYear)
                ->sum('netBonus')
            : 0.00;
        $latestBonusCount = $latestBonus
            ? BonusGenerate::where('bonus_title', $latestBonus->bonus_title)
                ->where('bonusGenerationYear', $latestBonus->bonusGenerationYear)
                ->count()
            : 0;

        // 6. Departmental Distribution (Top 6)
        $departments = DepartmentOffice::withCount('users')
            ->orderBy('users_count', 'desc')
            ->take(6)
            ->get()
            ->map(function ($dept) use ($currentMonth, $currentYear) {
                // Calculate current month salary for department
                $deptSalary = (float) SalaryGenerate::whereHas('user.departmentOffices', function ($q) use ($dept) {
                    $q->where('department_offices.id', $dept->id);
                })
                    ->where('monthId', $currentMonth)
                    ->where('salaryGenerationYear', $currentYear)
                    ->sum('salaryTotal');

                return [
                    'id' => $dept->id,
                    'name' => $dept->name,
                    'type' => $dept->type,
                    'staff_count' => $dept->users_count,
                    'monthly_disbursed' => $deptSalary,
                ];
            });

        // 7. Recent Salary Bills (Last 6 bills)
        $recentBills = SalaryGenerate::with(['user.employeeInfo.departmentOffice', 'user.employeeInfo.payrollGroup.payrollGroupType'])
            ->orderBy('id', 'desc')
            ->take(6)
            ->get()
            ->map(function ($bill) {
                $info = $bill->user?->employeeInfo;
                return [
                    'id' => $bill->id,
                    'user_id' => $bill->user_id,
                    'name' => $bill->name ?? $bill->user?->name ?? 'N/A',
                    'employeeCode' => $bill->employeeCode ?? $info?->employeeCode ?? 'N/A',
                    'designation' => $bill->designation ?? $info?->payrollGroup?->payrollGroupType?->group_name ?? 'N/A',
                    'department' => $info?->departmentOffice?->name ?? 'General Administration',
                    'month' => $bill->monthId,
                    'year' => $bill->salaryGenerationYear,
                    'salaryTotal' => (float) $bill->salaryTotal,
                    'reviewStatus' => $bill->reviewStatus ?? 'pending',
                    'generatedDate' => $bill->salaryGenerationDate ? Carbon::parse($bill->salaryGenerationDate)->format('M d, Y') : $bill->created_at->format('M d, Y'),
                ];
            });

        // 8. Administrative Notices / Alerts
        $heldIncrementsCount = EmployeeInfo::where('holdIncrement', true)->count();

        return Inertia::render('Dashboard', [
            'metrics' => [
                'currentMonth' => $currentMonth,
                'currentYear' => $currentYear,
                'financialYear' => $financialYear,
                'totalEmployees' => $totalEmployees,
                'activeEmployees' => $activeEmployees,
                'inactiveEmployees' => $inactiveEmployees,
                'genderBreakdown' => $genderBreakdown,
                'jobStatusBreakdown' => $jobStatusBreakdown,
                'userCategories' => $userCategories,
                'currentMonthSalary' => [
                    'generatedBills' => $currentMonthGeneratedBills,
                    'pendingBills' => $pendingBillsCount,
                    'totalDisbursed' => $currentMonthSalaryTotal,
                    'totalAllowance' => $currentMonthTotalAllowance,
                    'totalDeduction' => $currentMonthTotalDeduction,
                    'taxDeduction' => $currentMonthTaxDeduction,
                    'gpfDeduction' => $currentMonthGpfDeduction,
                    'reviewStatus' => [
                        'approved' => $salaryReviewStatusBreakdown['approved'] ?? 0,
                        'review' => $salaryReviewStatusBreakdown['review'] ?? 0,
                        'pending' => $salaryReviewStatusBreakdown['pending'] ?? 0,
                    ],
                ],
                'monthlyTrend' => $monthlyTrend,
                'gpf' => [
                    'activeSubscribers' => $activeGpfUsersCount,
                    'totalBalance' => $totalGpfBalance,
                    'currentMonthContribution' => $currentMonthGpfContributions,
                ],
                'bonus' => [
                    'title' => $latestBonusTitle,
                    'totalDisbursed' => $latestBonusTotal,
                    'beneficiaryCount' => $latestBonusCount,
                ],
                'departments' => $departments,
                'recentBills' => $recentBills,
                'alerts' => [
                    'heldIncrements' => $heldIncrementsCount,
                    'pendingSalaryBills' => $pendingBillsCount,
                ],
            ],
        ]);
    }
}
