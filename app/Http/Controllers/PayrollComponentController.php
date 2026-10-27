<?php

namespace App\Http\Controllers;

use App\Models\PayrollComponent;
use App\Models\PayrollComponentType;
use App\Models\PayrollGroup;
use App\Models\PayrollGroupType;
use App\Models\PayScale;
use App\Models\Grade;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PayrollComponentController extends Controller
{
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $query = PayrollComponent::with([
                'payrollGroup.grade.payScale',
                'payrollGroup.payrollGroupType',
                'payrollComponentType'
            ]);

            // Universal search
            if ($request->filled('search')) {
                $search = trim($request->search);
                $query->where(function ($q) use ($search) {
                    $q->whereHas('payrollComponentType', function ($typeQuery) use ($search) {
                        $typeQuery->where('name', 'like', "%{$search}%")
                                  ->orWhere('component_description', 'like', "%{$search}%");
                    })->orWhereHas('payrollGroup.grade', function ($gradeQuery) use ($search) {
                        $gradeQuery->where('grade_name', 'like', "%{$search}%")
                                   ->orWhereHas('payScale', function ($scaleQuery) use ($search) {
                                       $scaleQuery->where('scale_name', 'like', "%{$search}%");
                                   });
                    })->orWhereHas('payrollGroup.payrollGroupType', function ($groupTypeQuery) use ($search) {
                        $groupTypeQuery->where('group_name', 'like', "%{$search}%");
                    });

                    if (is_numeric($search)) {
                        $q->orWhere('amount', $search)
                          ->orWhere('id', $search);
                    }
                });
            }

            // Relationship Filters
            if ($request->filled('pay_scale_id')) {
                $query->whereHas('payrollGroup.grade', function ($q) use ($request) {
                    $q->where('pay_scale_id', $request->pay_scale_id);
                });
            }

            if ($request->filled('grade_id')) {
                $query->whereHas('payrollGroup', function ($q) use ($request) {
                    $q->where('grade_id', $request->grade_id);
                });
            }

            if ($request->filled('payroll_group_type_id')) {
                $query->whereHas('payrollGroup', function ($q) use ($request) {
                    $q->where('payroll_group_type_id', $request->payroll_group_type_id);
                });
            }

            if ($request->filled('payroll_group_id')) {
                $query->where('payroll_group_id', $request->payroll_group_id);
            }

            if ($request->filled('payroll_component_type_id')) {
                $query->where('payroll_component_type_id', $request->payroll_component_type_id);
            }

            // Direct Model Filters
            if ($request->filled('component_type')) {
                $query->where('component_type', $request->component_type);
            }

            if ($request->filled('calcType')) {
                $query->where('calcType', $request->calcType);
            }

            if ($request->filled('min_amount')) {
                $query->where('amount', '>=', (float)$request->min_amount);
            }

            if ($request->filled('max_amount')) {
                $query->where('amount', '<=', (float)$request->max_amount);
            }

            if ($request->filled('is_taxable') && $request->is_taxable !== '') {
                $query->where('is_taxable', $request->is_taxable === 'true' || $request->is_taxable === '1' || $request->is_taxable === true ? 1 : 0);
            }

            if ($request->filled('isActive') && $request->isActive !== '') {
                $query->where('isActive', $request->isActive === 'true' || $request->isActive === '1' || $request->isActive === true ? 1 : 0);
            }

            // Sorting
            $sortField = $request->input('sort_field', 'id');
            $sortDirection = strtolower($request->input('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

            $allowedSortFields = [
                'id',
                'amount',
                'component_type',
                'calcType',
                'is_taxable',
                'isActive',
                'created_at',
            ];

            if (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortDirection);
            } else {
                $query->orderBy('id', 'desc');
            }

            if ($request->boolean('all')) {
                return response()->json($query->get());
            }

            return response()->json(
                $query->paginate(10)
            );
        }

        return Inertia::render('Payroll/Component/Index', [
            'payScales' => PayScale::select('id', 'scale_name')->where(function ($query) {
                $query->where('isActive', true)->orWhere('scale_name', 'Govt. Pay Scale 2026');
            })->orderBy('scale_name')->get(),
            'grades' => Grade::select('id', 'grade_name', 'pay_scale_id')->where(function ($query) {
                $query->where('isActive', true)->orWhereHas('payScale', function ($scaleQuery) {
                    $scaleQuery->where('scale_name', 'Govt. Pay Scale 2026');
                });
            })->orderBy('grade_name')->get(),
            'payrollGroupTypes' => PayrollGroupType::select('id', 'group_name')->orderBy('group_name')->get(),
            'allPayrollComponentTypes' => PayrollComponentType::select('id', 'name')->orderBy('name')->get(),
            'payrollGroups' => PayrollGroup::with(['grade.payScale', 'payrollGroupType'])->where(function ($query) {
                $query->where('isActive', true)->orWhereHas('grade.payScale', function ($scaleQuery) {
                    $scaleQuery->where('scale_name', 'Govt. Pay Scale 2026');
                });
            })->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'payroll_group_id' => 'required|exists:payroll_groups,id',
            'payroll_component_type_id' => [
                'required',
                'exists:payroll_component_types,id',
                Rule::unique('payroll_components')->where('payroll_group_id', $request->payroll_group_id)
            ],
            'component_type' => 'required|string|max:255',
            'calcType' => 'required|string|max:255',
            'is_taxable' => 'boolean',
            'isActive' => 'boolean',
            'amount' => 'required|numeric|min:0',
        ]);

        $component = PayrollComponent::create($validated);

        return response()->json([
            'message' => 'Payroll Component created successfully',
            'data' => $component
        ], 201);
    }

    public function update(Request $request, PayrollComponent $payrollComponent)
    {
        $validated = $request->validate([
            'payroll_group_id' => 'required|exists:payroll_groups,id',
            'payroll_component_type_id' => [
                'required',
                'exists:payroll_component_types,id',
                Rule::unique('payroll_components')->where('payroll_group_id', $request->payroll_group_id)->ignore($payrollComponent->id)
            ],
            'component_type' => 'required|string|max:255',
            'calcType' => 'required|string|max:255',
            'is_taxable' => 'boolean',
            'isActive' => 'boolean',
            'amount' => 'required|numeric|min:0',
        ]);

        $payrollComponent->update($validated);

        return response()->json([
            'message' => 'Payroll Component updated successfully',
            'data' => $payrollComponent
        ]);
    }

    public function destroy(PayrollComponent $payrollComponent)
    {
        try {
            $payrollComponent->delete();

            return response()->json([
                'message' => 'Payroll Component deleted successfully'
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Cannot delete payroll component because it is referenced by other records in the database.'
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete payroll component: ' . $e->getMessage()
            ], 500);
        }
    }
}
