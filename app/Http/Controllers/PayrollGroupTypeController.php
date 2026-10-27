<?php

namespace App\Http\Controllers;

use App\Models\PayrollGroupType;
use App\Models\PayrollGroup;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PayrollGroupTypeController extends Controller
{
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $query = \App\Models\PayrollGroupType::query();

            if ($request->has('unassigned_for_grade_id')) {
                $gradeId = $request->input('unassigned_for_grade_id');
                $includeTypeId = $request->input('include_type_id');

                $query->whereDoesntHave('payrollGroups', function ($q) use ($gradeId) {
                    $q->where('grade_id', $gradeId);
                });

                if ($includeTypeId) {
                    $query->orWhere('id', $includeTypeId);
                }
            }

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function($q) use ($search) {
                    $q->where('group_name', 'like', "%{$search}%")
                      ->orWhere('group_description', 'like', "%{$search}%");
                });
            }

            if ($request->filled('grade_id')) {
                $gradeId = $request->input('grade_id');
                $query->whereHas('payrollGroups', function ($q) use ($gradeId) {
                    $q->where('grade_id', $gradeId);
                });
            }

            if ($request->has('all')) {
                return response()->json($query->orderBy('group_name')->get());
            }

            return response()->json(
                $query->orderBy('id', 'desc')->paginate(10)
            );
        }
        
        return Inertia::render('Payroll/GroupType/Index');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'group_name' => 'required|string|max:255|unique:payroll_group_types',
            'group_description' => 'nullable|string',
        ]);

        $payrollGroupType = \App\Models\PayrollGroupType::create($validated);

        return response()->json([
            'message' => 'Payroll Group Type created successfully',
            'data' => $payrollGroupType
        ], 201);
    }

    public function update(Request $request, \App\Models\PayrollGroupType $payrollGroupType)
    {
        $validated = $request->validate([
            'group_name' => 'required|string|max:255|unique:payroll_group_types,group_name,' . $payrollGroupType->id,
            'group_description' => 'nullable|string',
        ]);

        $payrollGroupType->update($validated);

        return response()->json([
            'message' => 'Payroll Group Type updated successfully',
            'data' => $payrollGroupType
        ]);
    }

    public function destroy(PayrollGroupType $payrollGroupType)
    {
        $groupsCount = PayrollGroup::where('payroll_group_type_id', $payrollGroupType->id)->count();
        if ($groupsCount > 0) {
            return response()->json([
                'message' => "Cannot delete group type because it is currently assigned to {$groupsCount} payroll " . ($groupsCount === 1 ? 'group' : 'groups') . ". Please reassign or remove these payroll groups first."
            ], 422);
        }

        try {
            $payrollGroupType->delete();

            return response()->json([
                'message' => 'Payroll Group Type deleted successfully'
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Cannot delete group type because it is referenced by other records in the database.'
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete group type: ' . $e->getMessage()
            ], 500);
        }
    }
}
