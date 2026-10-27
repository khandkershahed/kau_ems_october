<?php

namespace App\Http\Controllers;

use App\Models\Grade;
use App\Models\PayrollGroup;
use App\Models\PayScale;
use App\Models\PayrollGroupType;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GradeController extends Controller
{
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $query = Grade::query()->with('payScale')->withCount('payrollGroups');
            
            // Search by grade name or description
            $searchTerm = $request->input('search', $request->input('grade_name'));
            if (!empty($searchTerm)) {
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('grade_name', 'like', "%{$searchTerm}%")
                      ->orWhere('grade_description', 'like', "%{$searchTerm}%");
                });
            }

            // Filter by pay scale
            if ($request->filled('pay_scale_id')) {
                $query->where('pay_scale_id', $request->input('pay_scale_id'));
            }

            // Filter by active status
            if ($request->filled('isActive')) {
                $isActive = filter_var($request->input('isActive'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isActive !== null) {
                    $query->where('isActive', $isActive);
                }
            }

            // Filter by salary range
            if ($request->filled('min')) {
                $query->where('min', '>=', $request->input('min'));
            }
            if ($request->filled('max')) {
                $query->where('max', '<=', $request->input('max'));
            }

            // Filter by payroll groups presence
            if ($request->filled('hasPayrollGroups')) {
                $hasGroups = filter_var($request->input('hasPayrollGroups'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($hasGroups !== null) {
                    if ($hasGroups) {
                        $query->has('payrollGroups');
                    } else {
                        $query->whereDoesntHave('payrollGroups');
                    }
                }
            }

            // Filter by linked payroll group type (designation group)
            if ($request->filled('payroll_group_type_id')) {
                $typeId = $request->input('payroll_group_type_id');
                $query->whereHas('payrollGroups', function ($q) use ($typeId) {
                    $q->where('payroll_group_type_id', $typeId);
                });
            }

            $sortField = $request->input('sortField', 'id');
            $sortDirection = $request->input('sortDirection', 'desc');
            $allowedSortFields = ['id', 'grade_name', 'min', 'max', 'isActive', 'created_at', 'pay_scale_id', 'payroll_groups_count'];

            if (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
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

        return Inertia::render('Payroll/Grade/Index', [
            'payScales' => PayScale::orderBy('scale_name')->get(),
            'payrollGroupTypes' => PayrollGroupType::orderBy('group_name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'pay_scale_id' => 'required|exists:pay_scales,id',
            'grade_name' => 'required|string|max:255',
            'grade_description' => 'nullable|string',
            'min' => 'required|numeric',
            'max' => 'required|numeric|gte:min',
            'isActive' => 'boolean',
        ]);

        $grade = Grade::create($validated);

        return response()->json([
            'message' => 'Grade created successfully',
            'data' => $grade
        ], 201);
    }

    public function update(Request $request, Grade $grade)
    {
        $validated = $request->validate([
            'pay_scale_id' => 'required|exists:pay_scales,id',
            'grade_name' => 'required|string|max:255',
            'grade_description' => 'nullable|string',
            'min' => 'required|numeric',
            'max' => 'required|numeric|gte:min',
            'isActive' => 'boolean',
        ]);

        $grade->update($validated);

        return response()->json([
            'message' => 'Grade updated successfully',
            'data' => $grade
        ]);
    }

    public function destroy(Grade $grade)
    {
        $groupsCount = PayrollGroup::where('grade_id', $grade->id)->count();
        if ($groupsCount > 0) {
            return response()->json([
                'message' => "Cannot delete grade because it is currently linked to {$groupsCount} payroll " . ($groupsCount === 1 ? 'group' : 'groups') . ". Please reassign or remove these groups first."
            ], 422);
        }

        try {
            $grade->delete();

            return response()->json([
                'message' => 'Grade deleted successfully'
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Cannot delete grade because it is referenced by other records in the database.'
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete grade: ' . $e->getMessage()
            ], 500);
        }
    }
}
