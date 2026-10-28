<?php

namespace App\Http\Controllers;

use App\Models\EmployeeInfo;
use App\Models\BankAccount;
use App\Services\SalaryBankAccountResolver;
use App\Models\User;
use App\Models\PayrollComponentType;
use App\Services\ApprovedFixationResolver;
use App\Services\SalaryCalculationService;
use App\Services\SalaryStatementBreakdown;
use App\Services\SalaryComponentNormalizer;
use App\Services\ApprovedFixationComponentProjector;
use App\Services\SalaryArrearRuleService;
use App\Services\SalaryComponentCatalog;
use App\Services\SalaryEmployeeComponentOverrideService;
use App\Support\SalaryArrearRuleView;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Employee/month calculation workspace. No SalaryGenerate or GPF ledger writes. */
final class SalaryCalculatorEntryController extends Controller
{
    private function profiles(User $user): EmployeeInfo
    {
        $rows = EmployeeInfo::with(['payScale', 'payrollGroup.grade', 'payrollGroup.payrollGroupType'])
            ->where('user_id', $user->id)->limit(2)->get();
        if ($rows->count() !== 1) {
            throw new DomainException('Exactly one employee salary profile is required; found '.$rows->count().'.');
        }
        return $rows->first();
    }

    private function period(Request $request): array
    {
        $input = $request->validate([
            'year' => 'required|integer|between:2020,2100',
            'month' => 'required|integer|between:1,12',
        ]);
        return [(int) $input['year'], (int) $input['month']];
    }

    private function latest(int $userId, int $year, int $month): ?object
    {
        if (!Schema::hasTable('salary_calculation_versions')) return null;
        return DB::table('salary_calculation_versions')->where('user_id', $userId)
            ->where('salary_year', $year)->where('salary_month', $month)
            ->orderByDesc('version')->first();
    }

    /** @return array{0: array, 1: array} */
    private function components(EmployeeInfo $employee, int $year, int $month, ?array $fixation): array
    {
        $adds = SalaryComponentNormalizer::rows($employee->additionObjects);
        $ded = SalaryComponentNormalizer::rows($employee->substractionObjects);
        if (Schema::hasTable('salary_manual_adjustments')) {
            foreach (DB::table('salary_manual_adjustments')->where('user_id', $employee->user_id)
                ->where('salary_year', $year)->where('salary_month', $month)->orderBy('id')->get() as $row) {
                $item = ['name' => $row->title, 'calcType' => 'fixed', 'amount' => (float) $row->amount,
                    'manual_adjustment_id' => $row->id, 'reason' => $row->reason,
                    'evidence_reference' => $row->evidence_reference];
                if ($row->direction === 'addition') $adds[] = $item;
                elseif ($row->direction === 'deduction') $ded[] = $item;
            }
        }
        return app(SalaryComponentCatalog::class)->forEmployee($employee, $adds, $ded, $year, $month, $fixation);
    }

    /** June 2026 historical rent is the October workspace regular-rent reference.
     * Read-only; never alter an employee's source bill or posted salary.
     */
    private function juneRentReference(int $userId): ?float
    {
        $bills = DB::table('salary_generates')->where('user_id', $userId)
            ->where('salaryGenerationYear', 2026)->where('monthId', 6)->limit(2)->get();
        if ($bills->count() !== 1) return null;
        try {
            $source = \App\Services\SalaryArrearCalculator::paidFromPayroll($bills->first());
            $rent = $source['paid_house_rent'] ?? null;
            return is_numeric($rent) && (float)$rent >= 0 ? (float)$rent : null;
        } catch (DomainException $e) {
            return null;
        }
    }

    private function setHistoricalJuneRent(array $additions, float $rent): array
    {
        foreach ($additions as &$row) {
            $name = strtolower(trim((string)($row['name'] ?? '')));
            if (str_contains($name, 'house rent') && !str_contains($name, 'difference')
                && !str_contains($name, 'arrear')) {
                $row['amount'] = $rent;
                $row['calcType'] = 'fixed';
                $row['historical_source'] = 'approved_june_2026_salary';
            }
        }
        unset($row);
        return $additions;
    }

    public function show(Request $request, User $user, ApprovedFixationResolver $resolver, SalaryCalculationService $calculator)
    {
        [$year, $month] = $this->period($request);
        $error = null;
        $employee = null;
        $fixation = $resolver->forUser($user->id, $year, $month);
        $source = $fixation ? 'approved_fixation' : 'legacy_employee_setup';
        $latest = $this->latest($user->id, $year, $month);
        $componentService = app(SalaryEmployeeComponentOverrideService::class);
        $componentSignature = $componentService->signature($user->id, $year, $month);
        $overrideRows = $componentService->rows($user->id, $year, $month);
        $savedOverrideSignature = $latest->component_override_hash ?? null;
        $overrideChanged = $latest && ($overrideRows || $savedOverrideSignature !== null) && $savedOverrideSignature !== $componentSignature;
        $financial = null;
        $baseline = null;
        $adds = [];
        $ded = [];
        $gpfOverride = null;
        $arrearRows = [];
        $publishedRulesChanged = false;
        try {
            $employee = $this->profiles($user);
            [$adds, $ded] = $this->components($employee, $year, $month, $fixation);
            $juneRent = $year === 2026 && $month === 10
                ? $this->juneRentReference($user->id) : null;
            if ($juneRent !== null) {
                // Use June's actual saved House Rent, not July's projected rate.
                $adds = $this->setHistoricalJuneRent($adds, $juneRent);
            }
            // GPF is an optional published/employee-configured component, not a
            // silently inserted deduction for an empty Payroll Component list.
            $gpfOverride = collect($ded)->contains(fn ($r) => SalaryCalculationService::isGpf($r))
                ? null : ['type'=>'disabled','amount'=>0];
            $baseline = $calculator->calculate($adds, $ded, $fixation, $gpfOverride);
            $arrearRows = app(SalaryArrearRuleService::class)->preview($user->id, $year, $month);
            if ($overrideChanged) {
                $error = 'Employee component overrides changed since the saved calculation. Current automatic components are displayed. Save a new version before bill generation.';
                $financial = $baseline;
                $adds = $baseline['allowances'];
                $ded = $baseline['deductions'];
            } elseif ($latest) {
                $saved = json_decode($latest->calculated_result, true);
                $savedAdds = json_decode($latest->additions, true);
                $savedDed = json_decode($latest->deductions, true);
                $savedOverride = $latest->gpf_override ? json_decode($latest->gpf_override, true) : $gpfOverride;
                if (!is_array($savedAdds) || !is_array($savedDed)) throw new DomainException('Saved calculation snapshot is damaged.');
                // Recalculate the saved version from its explicit approved-era Basic.
                // If a newer approved fixation replaced the old one, do not silently
                // change the historical draft. Flag the discrepancy for reconciliation.
                $savedSource = (int) ($latest->fixation_id ?? 0);
                $currentSource = (int) ($fixation['salary_fixation_id'] ?? 0);
                if ($savedSource !== $currentSource) {
                    // Show the current approved fixation rather than a stale
                    // previously saved Basic. Preserve the previous version in DB.
                    $error = 'The approved fixation changed. This page shows the current fixation; save a new calculation version to record the revision.';
                    $adds = $baseline['allowances'];
                    $ded = $baseline['deductions'];
                    $gpfOverride = $gpfOverride;
                    $financial = $baseline;
                } else {
                    $adds = $savedAdds;
                    $ded = $savedDed;
                    $gpfOverride = $savedOverride;
                    if ($juneRent !== null) {
                        $correctedAdds = $this->setHistoricalJuneRent($adds, $juneRent);
                        if (json_encode($correctedAdds) !== json_encode($adds)) {
                            $adds = $correctedAdds;
                            $error = trim(($error ? $error.' ' : '')
                                .'October House Rent now uses the approved June 2026 salary. Review and save a NEW calculation version before generating a bill.');
                        }
                    }
                    $financial = $calculator->calculate($adds, $ded, null, $gpfOverride);
                    if (abs((float) ($saved['net_salary'] ?? -1) - $financial['net_salary']) > 0.009) {
                        $error = 'Saved calculation totals do not match its component snapshot. Review before using it.';
                    }
                }
            } else {
                $financial = $baseline;
                $adds = $baseline['allowances'];
                $ded = $baseline['deductions'];
            }
            // A newly published/unpublished/edited arrear rule cannot be hidden
            // behind a previously saved draft. Preserve the old snapshot in DB,
            // but display the live source and require a new audited version.
            if ($latest && $latest->arrear_snapshot) {
                $prior = json_decode((string) $latest->arrear_snapshot, true);
                $priorRows = is_array($prior) ? ($prior['rows'] ?? []) : [];
                $publishedRulesChanged = SalaryArrearRuleView::hasPublishedChanges($priorRows, $arrearRows);
                if ($publishedRulesChanged) {
                    $oldManual = array_values(array_filter($priorRows, static fn ($r) => empty($r['rule_id'])));
                    $arrearRows = array_merge($arrearRows, $oldManual);
                    $error = trim(($error ? $error.' ' : '')
                        .'Published arrear rules or verified payment sources changed since the saved calculation. '
                        .'Current published rules are displayed; review and save a new calculation version before posting.');
                }
            }
            if ($gpfOverride === null && ($financial['gpf_rule']['source'] ?? '') === 'existing_employee_subscription') {
                $gpfOverride = ['type' => $financial['gpf_rule']['type'],
                    'amount' => $financial['gpf_rule']['amount']];
            }
        } catch (DomainException $e) {
            $error = $e->getMessage();
        }
        // Offer only this employee's effective, direction-specific regular components.
        // The labels are read from the existing component master, never hardcoded.
        // Legacy rows without a stable master ID remain editable through their
        // existing key, but are deliberately not silently mapped by similar names.
        $componentOptions = ['addition' => [], 'deduction' => []];
        $typeIds = collect(array_merge($adds, $ded))
            ->map(static fn ($r) => (int) ($r['payroll_component_type_id'] ?? $r['payroll_component_type']['id'] ?? 0))
            ->filter(static fn ($id) => $id > 0)->unique()->values();
        // Include unambiguous master mappings for historical name-only components,
        // but ONLY when the employee's payroll group has an active assignment
        // in the same direction. Ambiguous/unmapped legacy rows remain untouched.
        $masters = PayrollComponentType::with(['payrollComponents' => function ($q) use ($employee) {
            $q->where('payroll_group_id', $employee?->payroll_group_id)->where('isActive', true);
        }])->get();
        $masterNames = $masters->pluck('name', 'id');
        foreach (['addition' => $adds, 'deduction' => $ded] as $direction => $items) {
            $seen = [];
            foreach ($items as $item) {
                if (isset($item['manual_adjustment_id'])) continue;
                $id = (int) ($item['payroll_component_type_id'] ?? $item['payroll_component_type']['id'] ?? 0);
                $sourceName = trim((string) ($item['name'] ?? ''));
                if ($id <= 0) {
                    $candidates = $masters->filter(static fn ($master) =>
                        strcasecmp(trim((string) $master->name), $sourceName) === 0 &&
                        $master->payrollComponents->contains(static fn ($component) =>
                            in_array(strtolower((string) $component->component_type),
                                $direction === 'addition' ? ['allowance','addition'] : ['deduction'], true)));
                    if ($candidates->count() !== 1) continue;
                    $id = (int) $candidates->first()->id;
                }
                if (!isset($masterNames[$id]) || isset($seen[$id])) continue;
                if (SalaryEmployeeComponentOverrideService::basic((string) $masterNames[$id])) continue;
                $seen[$id] = true;
                $componentOptions[$direction][] = ['id' => $id, 'name' => (string) $masterNames[$id],
                    'source_name' => $sourceName];
            }
            // Offer assigned active master components that were deleted from the
            // current draft as well, so they can be added back without a modal.
            foreach ($masters as $master) {
                $id = (int) $master->id;
                if (isset($seen[$id]) || SalaryEmployeeComponentOverrideService::basic((string) $master->name)) continue;
                $assigned = $master->payrollComponents->contains(static fn ($component) =>
                    in_array(strtolower((string) $component->component_type),
                        $direction === 'addition' ? ['allowance','addition'] : ['deduction'], true));
                if (!$assigned) continue;
                $seen[$id] = true;
                $assignment = $master->payrollComponents->first(static fn ($component) =>
                    in_array(strtolower((string) $component->component_type),
                        $direction === 'addition' ? ['allowance','addition'] : ['deduction'], true));
                $componentOptions[$direction][] = ['id' => $id, 'name' => (string) $master->name,
                    'source_name' => (string) $master->name,
                    'calcType' => strtolower((string) ($assignment?->calcType ?? 'fixed')) === 'percentage' ? 'percentage' : 'fixed',
                    'amount' => (float) ($assignment?->amount ?? 0)];
            }
            usort($componentOptions[$direction], static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        }
        $accounts = BankAccount::where('user_id', $user->id)->get();
        $selectedBank = SalaryBankAccountResolver::resolve($user->id, $employee?->bank_account_id, $accounts->all());
        $bankRecord = $selectedBank['selected_id'] ? $accounts->firstWhere('id', $selectedBank['selected_id']) : null;
        return Inertia::render('Salary/Calculator/Entry', [
            'verifiedBank' => $selectedBank['errors'] ? null : ($bankRecord?->account_no ?? null),
            'bankWarnings' => $selectedBank['errors'] ?: $selectedBank['warnings'],
            'employee' => ['id' => $user->id, 'name' => $user->name,
                'code' => $employee?->employeeCode,
                'designation' => $employee?->payrollGroup?->payrollGroupType?->group_name],
            'period' => ['year' => $year, 'month' => $month],
            'effective' => ['pay_scale' => $fixation['pay_scale'] ?? ($employee?->payScale?->scale_name ?? 'Not configured'),
                'grade' => $fixation['grade'] ?? ($employee?->payrollGroup?->grade?->grade_name ?? 'Not configured'),
                'basic' => $fixation['basic'] ?? ($baseline['basic'] ?? null),
                'fixation_id' => $fixation['salary_fixation_id'] ?? null,
                'effective_date' => $fixation['effective_date'] ?? null,
                'source' => $source],
            'financial' => $financial,
            'statement' => $financial ? SalaryStatementBreakdown::build($financial,
                $latest && !$overrideChanged && $latest->arrear_snapshot && !$publishedRulesChanged
                    ? (json_decode((string) $latest->arrear_snapshot, true)['rows'] ?? $arrearRows) : $arrearRows) : null,
            'baseline' => $baseline,
            'additions' => $adds, 'deductions' => $ded,
            'gpfOverride' => $gpfOverride,
            'componentOverrides' => $overrideRows,
            'componentOptions' => $componentOptions,
            'componentOverrideHash' => $componentSignature,
            'arrearRows' => $latest && !$overrideChanged && $latest->arrear_snapshot && !$publishedRulesChanged ? (json_decode($latest->arrear_snapshot, true)['rows'] ?? $arrearRows) : $arrearRows,
            'unpublishedArrearTemplates' => app(SalaryArrearRuleService::class)->unpublishedTemplates($user->id),
            'saved' => $latest ? ['id' => $latest->id, 'version' => (int) $latest->version,
                'status' => $latest->status, 'fixation_id' => $latest->fixation_id,
                'snapshot_hash' => $latest->snapshot_hash, 'reason' => $latest->reason,
                'evidence_reference' => $latest->evidence_reference] : null,
            'error' => $error,
        ]);
    }

    /** Never convert missing evidence to zero. Unresolved lines stay null in a draft. */
    private function validateArrearSnapshot(array $rows, string $status): array
    {
        $seen = []; $seenTitles = []; $items = []; $unknown = [];
        foreach ($rows as $index => $row) {
            $key = $row['rule_id'] ?? null;
            if ($key && isset($seen[$key])) throw new DomainException('Duplicate published arrear rule #'.$key);
            if ($key) $seen[$key] = true;
            $titleKey = strtolower(trim(preg_replace('/\s+/u', ' ', (string) ($row['title'] ?? ''))));
            $titleIdentity = ($row['direction'] ?? '').':'.$titleKey;
            if ($titleKey === '' || isset($seenTitles[$titleIdentity])) {
                throw new DomainException('Duplicate or empty arrear title and direction; reconcile repeated entries before saving.');
            }
            $seenTitles[$titleIdentity] = true;
            $automatic = $row['calculated_amount'] ?? null;
            $final = $row['final_amount'] ?? null;
            $overridden = ($automatic === null && $final !== null)
                || ($automatic !== null && $final !== null && abs((float)$final-(float)$automatic) > 0.009);
            if ($status === 'approved' && $overridden && (!trim((string)($row['reason'] ?? '')) || !trim((string)($row['evidence_reference'] ?? '')))) {
                throw new DomainException('Manual arrear override needs reason and evidence reference for '.$row['title']);
            }
            if ($final === null) $unknown[] = $row['title'];
            $items[] = [
                'rule_id'=>$key, 'code'=>$row['code'] ?? null, 'title'=>$row['title'], 'direction'=>$row['direction'],
                'mode'=>$row['mode'], 'formula'=>$row['formula'] ?? null,
                'calculated_amount'=>$automatic, 'final_amount'=>$final,
                'overridden'=>$overridden, 'months'=>$row['months'] ?? [],
                'reason'=>$row['reason'] ?? null, 'evidence_reference'=>$row['evidence_reference'] ?? null,
                'error'=>$row['error'] ?? null,
            ];
        }
        if ($status === 'approved' && $unknown) {
            throw new DomainException('Arrear amount remains UNKNOWN for: '.implode(', ', $unknown).'. Supply documented override or unpublish the rule.');
        }
        $pay = $ded = 0;
        foreach ($items as $row) {
            if ($row['final_amount'] === null) continue;
            if ($row['direction'] === 'payable') $pay += (float)$row['final_amount'];
            else $ded += (float)$row['final_amount'];
        }
        return ['rows'=>$items,'known_payable'=>round($pay,2), 'known_deduction'=>round($ded,2),
            'net_arrear'=> $unknown ? null : round($pay-$ded,2), 'unknown_lines'=>$unknown];
    }

    public function previewArrear(Request $request, User $user, SalaryArrearRuleService $service)
    {
        $data = $request->validate([
            'year'=>'required|integer|between:2020,2100','month'=>'required|integer|between:1,12',
            'rows'=>'required|array|max:100', 'rows.*.title'=>'required|string|max:160',
            'rows.*.direction'=>'required|in:payable,deduction',
            'rows.*.code'=>'nullable|string|max:60',
            'rows.*.mode'=>'required|in:fixed,percentage,formula',
            'rows.*.amount'=>'nullable|numeric|min:0', 'rows.*.formula'=>'nullable|string|max:400',
            'rows.*.start_month'=>'nullable|date_format:Y-m','rows.*.end_month'=>'nullable|date_format:Y-m',
            'rows.*.selected_months'=>'nullable|array|max:60',
        ]);
        $input = array_map(fn ($r) => (object) array_merge([
            'id'=>null,'code'=>null,'revision'=>1,'start_month'=>null,
            'end_month'=>null,'selected_months'=>[], 'recurring'=>false,
        ], $r), $data['rows']);
        return response()->json(['rows'=>$service->preview($user->id,(int)$data['year'],(int)$data['month'],$input)]);
    }

    /** PATCH 12: authoritative calculator input for bulk persistence, never browser totals. */
    public function verifiedBulkInput(User $user, int $year, int $month, string $reason,
        ApprovedFixationResolver $resolver, SalaryCalculationService $calculator): array
    {
        if ($year !== 2026 || $month !== 10) {
            throw new DomainException('This verified bulk workflow is limited to October 2026.');
        }
        $employee = $this->profiles($user);
        $fixation = $resolver->forUser($user->id, $year, $month);
        [$adds, $ded] = $this->components($employee, $year, $month, $fixation);
        $juneRent = $this->juneRentReference($user->id);
        if ($juneRent !== null) $adds = $this->setHistoricalJuneRent($adds, $juneRent);
        $gpf = collect($ded)->contains(fn ($r) => SalaryCalculationService::isGpf($r))
            ? null : ['type' => 'disabled', 'amount' => 0];
        // Identical original calculation service and approval constraints.
        $calculator->calculate($adds, $ded, $fixation, $gpf);
        $rules = app(SalaryArrearRuleService::class)->preview($user->id, $year, $month);
        if (!$rules) throw new DomainException('No published arrear rules; verify October arrear configuration.');
        $arrears = [];
        foreach ($rules as $rule) {
            if (!empty($rule['error']) || !isset($rule['calculated_amount'])
                || !is_numeric($rule['calculated_amount']) || (float)$rule['calculated_amount'] < 0) {
                throw new DomainException('Arrear source not verified for '.$rule['title'].': '.($rule['error'] ?? 'unknown amount'));
            }
            $arrears[] = [
                'rule_id' => $rule['rule_id'], 'code' => $rule['code'],
                'title' => $rule['title'], 'direction' => $rule['direction'],
                'mode' => $rule['mode'], 'formula' => $rule['formula'] ?? null,
                'calculated_amount' => $rule['calculated_amount'],
                'final_amount' => $rule['calculated_amount'], 'months' => $rule['months'] ?? [],
            ];
        }
        $latest = $this->latest($user->id, $year, $month);
        // Previously entered manual arrears must be reviewed, not silently discarded.
        if ($latest && $latest->arrear_snapshot) {
            $prior = json_decode((string)$latest->arrear_snapshot, true);
            foreach (($prior['rows'] ?? []) as $row) {
                if (empty($row['rule_id']) || !empty($row['overridden'])) {
                    throw new DomainException('Existing custom/manual arrear lines require individual calculator review.');
                }
            }
        }
        return [
            'year'=>$year, 'month'=>$month, 'status'=>'approved',
            'expected_version'=>(int)($latest->version ?? 0),
            'component_override_hash'=>app(SalaryEmployeeComponentOverrideService::class)->signature($user->id,$year,$month),
            'additions'=>$adds, 'deductions'=>$ded, 'gpf_override'=>$gpf,
            'arrear_rows'=>$arrears, 'reason'=>$reason,
        ];
    }

    public function save(Request $request, User $user, ApprovedFixationResolver $resolver, SalaryCalculationService $calculator)
    {
        $input = $request->validate([
            'year' => 'required|integer|between:2020,2100',
            'month' => 'required|integer|between:1,12',
            'status' => 'required|in:draft,approved',
            'expected_version' => 'required|integer|min:0',
            'component_override_hash' => 'nullable|string|size:64',
            'additions' => 'required|array|min:1|max:60',
            'additions.*.name' => 'required|string|max:160',
            'additions.*.calcType' => 'required|in:fixed,percentage',
            'additions.*.amount' => 'required|numeric|min:0|max:1000000000',
            'deductions' => 'present|array|max:60',
            'deductions.*.name' => 'required|string|max:160',
            'deductions.*.calcType' => 'required|in:fixed,percentage',
            'deductions.*.amount' => 'required|numeric|min:0|max:1000000000',
            'gpf_override' => 'nullable|array',
            'gpf_override.type' => 'required_with:gpf_override|in:fixed,percentage,disabled',
            'gpf_override.amount' => 'required_with:gpf_override|numeric|min:0|max:1000000000',
            'reason' => 'nullable|string|max:2000',
            'evidence_reference' => 'nullable|string|max:500',
            'arrear_rows' => 'sometimes|array|max:100',
            'arrear_rows.*.rule_id' => 'nullable|integer|min:1',
            'arrear_rows.*.code' => 'nullable|string|max:60',
            'arrear_rows.*.title' => 'required|string|max:160',
            'arrear_rows.*.direction' => 'required|in:payable,deduction',
            'arrear_rows.*.mode' => 'required|in:fixed,percentage,formula',
            'arrear_rows.*.formula' => 'nullable|string|max:400',
            'arrear_rows.*.calculated_amount' => 'nullable|numeric|min:0',
            'arrear_rows.*.final_amount' => 'nullable|numeric|min:0|max:1000000000',
            'arrear_rows.*.reason' => 'nullable|string|max:2000',
            'arrear_rows.*.evidence_reference' => 'nullable|string|max:500',
            'arrear_rows.*.months' => 'nullable|array|max:60',
            'arrear_rows.*.error' => 'nullable|string|max:1000',
        ]);
        if (!Schema::hasTable('salary_calculation_versions')) {
            return response()->json(['message' => 'Phase 3 migration has not been applied.'], 422);
        }
        try {
            $employee = $this->profiles($user);
            $year = (int) $input['year']; $month = (int) $input['month'];
            $fixation = $resolver->forUser($user->id, $year, $month);
            $sourceId = (int) ($fixation['salary_fixation_id'] ?? 0);
            $componentSignature = app(SalaryEmployeeComponentOverrideService::class)->signature($user->id,$year,$month);
            if (($input['component_override_hash'] ?? null) !== null
                ? !hash_equals($componentSignature, $input['component_override_hash'])
                : count(app(SalaryEmployeeComponentOverrideService::class)->rows($user->id,$year,$month)) > 0) {
                return response()->json(['message'=>'Employee component overrides changed; reload this calculator before saving.'],409);
            }
            $adds = $input['additions'];
            $ded = $input['deductions'];
            // The fixation itself remains immutable. A monthly HR Basic override
            // is stored within the versioned salary components, with attribution.
            $calculationFixation = $fixation;
            $basicOverrides = array_values(array_filter($adds, static fn ($row) =>
                preg_match('/\bbasic(?:\s+pay|\s+salary)?\b/i', (string) ($row['name'] ?? '')) === 1));
            if (count($basicOverrides) !== 1) {
                throw new DomainException('Exactly one Basic Pay component is required.');
            }
            $enteredBasic = (float) $basicOverrides[0]['amount'];
            if ($enteredBasic <= 0 || ($basicOverrides[0]['calcType'] ?? '') !== 'fixed') {
                throw new DomainException('HR Basic must be a positive fixed amount.');
            }
            if ($fixation && abs($enteredBasic - (float) $fixation['basic']) > .009) {
                if ($input['status'] === 'approved' &&
                    (!trim((string) ($input['reason'] ?? '')) || !trim((string) ($input['evidence_reference'] ?? '')))) {
                    throw new DomainException('HR Basic override requires a revision reason and evidence/reference before approval.');
                }
                $calculationFixation['basic'] = $enteredBasic;
                $calculationFixation['hr_basic_override'] = true;
                $calculationFixation['approved_fixation_basic'] = (float) $fixation['basic'];
            }
            $gpfInput = $input['gpf_override'] ?? null;
            if ($gpfInput === null && !collect($ded)->contains(fn ($r) => SalaryCalculationService::isGpf($r))) {
                $gpfInput = ['type'=>'disabled','amount'=>0];
            }
            // Drafts may deliberately omit an obsolete monthly override component.
            // Retain the strict snapshot check for approved calculations and posting.
            if ($input['status'] === 'approved') {
                SalaryEmployeeComponentOverrideService::assertSnapshotMatches(
                    $adds, $ded, app(SalaryEmployeeComponentOverrideService::class)->rows($user->id,$year,$month));
            }
            foreach (app(SalaryEmployeeComponentOverrideService::class)->rows($user->id,$year,$month) as $row) {
                if ($row['direction']==='deduction' && SalaryCalculationService::isGpf(['name'=>$row['component_title']])) {
                    if ($row['mode']==='manual' && $gpfInput!==null && $gpfInput['type']!=='disabled') {
                        throw new DomainException('GPF has a saved employee override; use the component override editor rather than a second GPF override.');
                    }
                    if ($row['mode']==='disabled' && $gpfInput!==null && ($gpfInput['type']??'')!=='disabled') {
                        throw new DomainException('Disabled GPF cannot be re-enabled by the calculator snapshot.');
                    }
                }
            }
            $calculated = $calculator->calculate($adds, $ded, $calculationFixation, $gpfInput);
            $submittedArrear = $input['arrear_rows'] ?? [];
            // Re-evaluate every published rule from authoritative historical records.
            // Never trust browser-provided automatic results or payment evidence.
            $liveRules = app(SalaryArrearRuleService::class)->preview($user->id, $year, $month);
            $byId = collect($liveRules)->filter(fn ($r) => $r['rule_id'] !== null)
                ->keyBy('rule_id');
            foreach ($submittedArrear as &$ar) {
                if (empty($ar['rule_id'])) {
                    // A custom display title cannot assert one of the five canonical codes.
                    if (!empty($ar['code'])) throw new DomainException('Custom arrear cannot claim a canonical code.');
                    $ar['code'] = null;
                    $ar['calculated_amount'] = null;
                    continue;
                }
                $correct = $byId->get((int)$ar['rule_id']);
                if (!$correct) throw new DomainException('An arrear rule is unpublished/unknown. Reload its current template.');
                if ($ar['direction'] !== $correct['direction']) {
                    throw new DomainException('An arrear rule direction was changed. Use a separate manual row with evidence.');
                }
                // Canonical financial identity is resolved from the server-side rule ID.
                $ar['code'] = $correct['code'];
                $ar['calculated_amount'] = $correct['calculated_amount'];
                $ar['error'] = $correct['error'];
                $ar['months'] = $correct['months'] ?? [];
                $ar['formula'] = $correct['formula'];
            }
            unset($ar);
            $arrear = $this->validateArrearSnapshot($submittedArrear, $input['status']);
            if ($input['status'] === 'approved' && $arrear['net_arrear'] !== null
                && (float)$calculated['net_salary'] + (float)$arrear['net_arrear'] < -0.009) {
                throw new DomainException('Combined deductions exceed salary and payable arrears; review the adjustment.');
            }
            // Persist normalized components; do not create a second GPF row.
            $normalizedAdds = $calculated['allowances'];
            $normalizedDed = $calculated['deductions'];
            $hash = hash('sha256', json_encode([
                'user_id' => $user->id, 'year' => $year, 'month' => $month,
                'fixation_id' => $sourceId, 'additions' => $normalizedAdds,
                'deductions' => $normalizedDed, 'result' => $calculated, 'arrear' => $arrear,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $response = DB::transaction(function () use ($request, $user, $employee, $input, $year, $month,
                $calculated, $normalizedAdds, $normalizedDed, $sourceId, $hash, $calculator, $arrear, $gpfInput, $componentSignature) {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $liveSignature = app(SalaryEmployeeComponentOverrideService::class)->signature($user->id,$year,$month);
                if (!hash_equals($componentSignature, $liveSignature)) {
                    return response()->json(['message'=>'Component overrides changed during calculation. Reload and retry.'],409);
                }
                $latest = $this->latest($user->id, $year, $month);
                $currentVersion = (int) ($latest->version ?? 0);
                if ($currentVersion !== (int) $input['expected_version']) {
                    return response()->json(['message' => 'Another calculation version was saved. Reload this employee before saving changes.'], 409);
                }
                if ($latest && $latest->snapshot_hash === $hash && $latest->status === $input['status']) {
                    return response()->json(['message' => 'Identical snapshot already saved.',
                        'version' => $currentVersion, 'status' => $latest->status]);
                }
                $baselineAdds = SalaryComponentNormalizer::rows($employee->additionObjects);
                $baselineDed = SalaryComponentNormalizer::rows($employee->substractionObjects);
                $baseline = null;
                try {
                    $baseline = $calculator->calculate($baselineAdds, $baselineDed, null);
                } catch (DomainException $e) {
                    $baseline = ['unavailable_reason' => $e->getMessage()];
                }
                $now = now();
                $id = DB::table('salary_calculation_versions')->insertGetId([
                    'user_id' => $user->id, 'employee_info_id' => $employee->id,
                    'salary_year' => $year, 'salary_month' => $month,
                    'version' => $currentVersion + 1, 'status' => $input['status'],
                    'fixation_id' => $sourceId ?: null,
                    'component_override_hash'=>$componentSignature,
                    'source' => $sourceId ? 'approved_fixation' : 'legacy_employee_setup',
                    'additions' => json_encode($normalizedAdds, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'deductions' => json_encode($normalizedDed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'gpf_override' => $gpfInput ? json_encode($gpfInput, JSON_THROW_ON_ERROR) : null,
                    'calculated_result' => json_encode($calculated, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'baseline_result' => json_encode($baseline, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'arrear_snapshot' => json_encode($arrear, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'arrear_source_hash' => hash('sha256', json_encode($arrear, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                    'reason' => trim((string) ($input['reason'] ?? '')) ?: null,
                    'evidence_reference' => trim((string) ($input['evidence_reference'] ?? '')) ?: null,
                    'snapshot_hash' => $hash, 'created_by' => $request->user()->id,
                    'approved_at' => $input['status'] === 'approved' ? $now : null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($arrear['rows'] as $position => $row) {
                    if (!$row['overridden']) continue;
                    DB::table('salary_arrear_employee_overrides')->insert([
                        'salary_calculation_version_id'=>$id,
                        'user_id'=>$user->id, 'salary_year'=>$year, 'salary_month'=>$month,
                        'rule_id'=>$row['rule_id'], 'row_number'=>$position,
                        'title'=>$row['title'], 'direction'=>$row['direction'],
                        'automatic_amount'=>$row['calculated_amount'], 'final_amount'=>$row['final_amount'],
                        'formula'=>$row['formula'], 'reason'=>$row['reason'],
                        'evidence_reference'=>$row['evidence_reference'],
                        'source_hash'=>hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                        'created_by'=>$request->user()->id,'created_at'=>$now,'updated_at'=>$now,
                    ]);
                }
                return response()->json(['message' => ucfirst($input['status']).' version saved. No bill or GPF ledger entry created.',
                    'id' => $id, 'version' => $currentVersion + 1, 'status' => $input['status'],
                    'financial' => $calculated, 'arrear'=>$arrear]);
            });
            return $response;
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
    /** Safe regular-only salary preview; no approval, bill, GPF or journal write. */
    public function previewRegularOnly(Request $request, User $user, \App\Services\SalaryBillPostingService $service)
    {
        [$year, $month] = $this->period($request);
        return response()->json($service->previewRegularOnly($user->id, $year, $month));
    }

    /** Direct bill generation: browser never supplies salary or arrear amounts. */
    public function generateBill(Request $request, User $user, \App\Services\SalaryBillPostingService $service)
    {
        $data=$request->validate([
            'year'=>'required|integer|between:2020,2100',
            'month'=>'required|integer|between:1,12',
            'expected_version'=>'required|integer|min:0',
        ]);
        try {
            $result=$service->post($user->id,(int)$data['year'],(int)$data['month'],$request->user()?->id,(int)$data['expected_version']);
            return response()->json($result);
        } catch (DomainException $e) {
            return response()->json(['message'=>$e->getMessage()],422);
        }
    }

}
