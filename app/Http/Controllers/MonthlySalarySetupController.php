<?php

namespace App\Http\Controllers;

use App\Exceptions\HistoricalSalaryImmutableException;
use App\Models\EmployeeInfo;
use App\Models\PayrollComponentType;
use App\Models\User;
use App\Services\MonthlySalarySetupService;
use App\Services\SalaryGenerationGuards;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authenticated JSON endpoints for the 19-component Monthly Salary Setup modal.
 * Month-scoped edits persist only to salary_monthly_component_adjustments;
 * recurring defaults only change when saveRecurring() is explicitly invoked.
 */
final class MonthlySalarySetupController extends Controller
{
    public function __construct(
        private readonly MonthlySalarySetupService $setupService
    ) {
    }

    /**
     * GET /salary-setup/{user}?year=2026&month=11
     */
    public function show(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user(), 401);

        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'between:2020,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        $year = (int) ($validated['year'] ?? now()->year);
        $month = (int) ($validated['month'] ?? now()->month);

        try {
            $setup = $this->setupService->getSetupForEmployee($user, $year, $month);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->enrichPayload($user, $setup));
    }

    /**
     * POST /salary-setup/{user}/month
     */
    public function saveMonth(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user(), 401);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'between:2020,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.payroll_component_type_id' => ['required', 'integer', 'min:1'],
            'components.*.arrear_amount' => ['nullable', 'numeric'],
            'components.*.rule_override' => ['nullable', 'boolean'],
            'components.*.override_current_amount' => ['nullable', 'numeric', 'min:0'],
            'components.*.current_amount' => ['nullable', 'numeric', 'min:0'],
            'components.*.comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $year = (int) $validated['year'];
        $month = (int) $validated['month'];

        if (SalaryGenerationGuards::isMonthLocked($year, $month)) {
            return response()->json([
                'message' => sprintf(
                    'Historical salary month %04d-%02d is finalized and locked. Adjustments are blocked.',
                    $year,
                    $month
                ),
                'is_locked' => true,
            ], 422);
        }

        // Explicit controller-level guard: Basic Pay manual rule_override requires a non-empty comment.
        $typeNames = PayrollComponentType::query()->pluck('name', 'id')->all();
        foreach ($validated['components'] as $index => $item) {
            $typeId = (int) ($item['payroll_component_type_id'] ?? 0);
            $typeName = (string) ($typeNames[$typeId] ?? '');
            $isOverride = (bool) ($item['rule_override'] ?? false);
            $comment = trim((string) ($item['comment'] ?? ''));

            if ($typeName !== '' && MonthlySalarySetupService::isBasicPay($typeName) && $isOverride && $comment === '') {
                return response()->json([
                    'message' => 'A non-empty comment is required when manually overriding Basic Pay.',
                    'errors' => [
                        "components.{$index}.comment" => ['A non-empty comment is required when manually overriding Basic Pay.'],
                    ],
                ], 422);
            }
        }

        try {
            $setup = $this->setupService->saveForMonth(
                $user,
                $year,
                $month,
                $validated['components'],
                $request->user()?->id
            );
        } catch (HistoricalSalaryImmutableException|DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'is_locked' => SalaryGenerationGuards::isMonthLocked($year, $month),
            ], 422);
        }

        return response()->json(array_merge(
            $this->enrichPayload($user, $setup),
            ['message' => sprintf('Saved monthly salary setup for %04d-%02d.', $year, $month)]
        ));
    }

    /**
     * POST /salary-setup/{user}/recurring
     */
    public function saveRecurring(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user(), 401);

        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'between:2020,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.payroll_component_type_id' => ['required', 'integer', 'min:1'],
            'components.*.calcType' => ['nullable', 'string', 'in:fixed,percentage'],
            'components.*.amount' => ['nullable', 'numeric', 'min:0'],
            'components.*.current_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Strip any month-only fields before passing to recurring save.
        $cleanComponents = array_map(static function (array $item): array {
            $payload = [
                'payroll_component_type_id' => (int) $item['payroll_component_type_id'],
                'calcType' => strtolower((string) ($item['calcType'] ?? 'fixed')),
            ];
            if (array_key_exists('amount', $item) && $item['amount'] !== null) {
                $payload['amount'] = (float) $item['amount'];
            } elseif (array_key_exists('current_amount', $item) && $item['current_amount'] !== null) {
                $payload['current_amount'] = (float) $item['current_amount'];
            } else {
                $payload['amount'] = 0.0;
            }

            return $payload;
        }, $validated['components']);

        try {
            $this->setupService->saveAsRecurringDefault($user, $cleanComponents);

            $year = (int) ($validated['year'] ?? now()->year);
            $month = (int) ($validated['month'] ?? now()->month);
            $setup = $this->setupService->getSetupForEmployee($user, $year, $month);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(array_merge(
            $this->enrichPayload($user, $setup),
            ['message' => 'Saved recurring profile defaults (arrears and monthly overrides were not copied).']
        ));
    }

    /**
     * Attach lock state and employee header metadata to the service setup payload.
     *
     * @param array<string, mixed> $setup
     * @return array<string, mixed>
     */
    private function enrichPayload(User $user, array $setup): array
    {
        $year = (int) $setup['year'];
        $month = (int) $setup['month'];
        $isLocked = SalaryGenerationGuards::isMonthLocked($year, $month);

        $relations = [];
        if (Schema::hasTable('payroll_groups') && Schema::hasTable('payroll_group_types')) {
            $relations[] = 'payrollGroup.payrollGroupType';
        }
        if (Schema::hasTable('department_offices')) {
            $relations[] = 'departmentOffice';
        }

        $profileQuery = EmployeeInfo::query()->where('user_id', $user->id);
        if ($relations !== []) {
            $profileQuery->with($relations);
        }
        $profile = $profileQuery->first();

        $jobStatus = (string) ($profile?->jobStatus ?? '');
        $normalizedStatus = strtolower(str_replace(['-', ' ', '_'], '', $jobStatus));
        $isAdHoc = str_contains($normalizedStatus, 'adhoc');
        $isOnLeave = str_contains($normalizedStatus, 'leave');

        if (!$isOnLeave && Schema::hasTable('leave_statuses')) {
            $isOnLeave = DB::table('leave_statuses')
                ->where('user_id', $user->id)
                ->where(function ($q): void {
                    if (Schema::hasColumn('leave_statuses', 'status')) {
                        $q->where('status', 'active')->orWhere('status', 'approved');
                    } elseif (Schema::hasColumn('leave_statuses', 'is_active')) {
                        $q->where('is_active', true);
                    }
                })
                ->exists();
        }

        $holdIncrement = (bool) ($profile?->holdIncrement ?? false);
        $payScale = (string) ($setup['pay_scale'] ?? '');
        $payScaleYear = str_contains($payScale, '2026')
            ? '2026'
            : (str_contains($payScale, '2015') ? '2015' : null);

        $badgeItems = [];
        if ($isOnLeave) {
            $badgeItems[] = 'Leave';
        }
        if ($isAdHoc) {
            $badgeItems[] = 'Ad-Hoc';
        }
        if ($holdIncrement) {
            $badgeItems[] = 'Increment Hold';
        }

        return array_merge($setup, [
            'is_locked' => $isLocked,
            'employee' => [
                'id' => (int) $user->id,
                'code' => (string) ($profile?->employeeCode ?? ''),
                'name' => (string) $user->name,
                'designation' => (string) ($profile?->payrollGroup?->payrollGroupType?->group_name ?? ''),
                'department' => (string) ($profile?->departmentOffice?->name ?? ''),
                'grade' => $setup['grade'],
                'pay_scale' => $setup['pay_scale'],
                'pay_scale_year' => $payScaleYear,
                'job_status' => $jobStatus,
                'hold_increment' => $holdIncrement,
                'badges' => [
                    'on_leave' => $isOnLeave,
                    'ad_hoc' => $isAdHoc,
                    'increment_hold' => $holdIncrement,
                    'items' => $badgeItems,
                ],
            ],
        ]);
    }
}
