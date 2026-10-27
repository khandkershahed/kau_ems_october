<?php

namespace App\Http\Controllers;

use App\Models\PayrollComponentType;
use App\Models\PayrollComponent;
use App\Models\PayScale;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PayrollComponentTypeController extends Controller
{
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $query = PayrollComponentType::withCount('payrollComponents');

            if ($request->has('unassigned_for_group_id')) {
                $groupId = $request->input('unassigned_for_group_id');
                $includeTypeId = $request->input('include_type_id');

                $query->whereDoesntHave('payrollComponents', function ($q) use ($groupId) {
                    $q->where('payroll_group_id', $groupId);
                });

                if ($includeTypeId) {
                    $query->orWhere('id', $includeTypeId);
                }
            }

            // Universal search
            if ($request->filled('search')) {
                $search = trim($request->input('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('component_description', 'like', "%{$search}%");
                    if (is_numeric($search)) {
                        $q->orWhere('id', $search);
                    }
                });
            }

            // Filter by usage (assigned vs unassigned)
            if ($request->filled('usage')) {
                if ($request->usage === 'assigned') {
                    $query->has('payrollComponents');
                } elseif ($request->usage === 'unassigned') {
                    $query->doesntHave('payrollComponents');
                }
            }

            // Filter by component type (allowance, deduction, tax)
            if ($request->filled('component_type')) {
                $compType = $request->input('component_type');
                $query->whereHas('payrollComponents', function ($q) use ($compType) {
                    $q->where('component_type', $compType);
                });
            }

            // Filter by pay scale
            if ($request->filled('pay_scale_id')) {
                $scaleId = $request->input('pay_scale_id');
                $query->whereHas('payrollComponents.payrollGroup.grade', function ($q) use ($scaleId) {
                    $q->where('pay_scale_id', $scaleId);
                });
            }

            // Sorting
            $sortField = $request->input('sort_field', 'id');
            $sortDirection = strtolower($request->input('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

            $allowedSortFields = [
                'id',
                'name',
                'payroll_components_count',
                'created_at',
            ];

            if (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortDirection);
            } else {
                $query->orderBy('id', 'desc');
            }

            if ($request->has('all')) {
                return response()->json($query->get());
            }

            return response()->json(
                $query->paginate(10)
            );
        }

        return Inertia::render('Payroll/ComponentType/Index', [
            'payScales' => PayScale::select('id', 'scale_name')->where(function ($query) {
                $query->where('isActive', true)->orWhere('scale_name', 'Govt. Pay Scale 2026');
            })->orderBy('scale_name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'component_description' => 'nullable|string',
        ]);

        $type = PayrollComponentType::create($validated);

        return response()->json([
            'message' => 'Payroll Component Type created successfully',
            'data' => $type
        ], 201);
    }

    public function update(Request $request, PayrollComponentType $payrollComponentType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'component_description' => 'nullable|string',
        ]);

        $payrollComponentType->update($validated);

        return response()->json([
            'message' => 'Payroll Component Type updated successfully',
            'data' => $payrollComponentType
        ]);
    }

    public function destroy(PayrollComponentType $payrollComponentType)
    {
        $componentsCount = PayrollComponent::where('payroll_component_type_id', $payrollComponentType->id)->count();
        if ($componentsCount > 0) {
            return response()->json([
                'message' => "Cannot delete payroll component type because it is currently assigned to {$componentsCount} payroll " . ($componentsCount === 1 ? 'component' : 'components') . " across designation groups. Please reassign or remove these components first."
            ], 422);
        }

        try {
            $payrollComponentType->delete();

            return response()->json([
                'message' => 'Payroll Component Type deleted successfully'
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Cannot delete payroll component type because it is referenced by other records in the database.'
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete payroll component type: ' . $e->getMessage()
            ], 500);
        }
    }
}
