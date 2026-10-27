<?php

namespace App\Http\Controllers;

use App\Models\PayrollGroup;
use App\Models\PayrollComponent;
use App\Models\EmployeeInfo;
use App\Models\Promotion;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PayrollGroupController extends Controller
{
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $query = PayrollGroup::with(['grade.payScale', 'payrollGroupType']);
            
            if ($request->filled('grade_id')) {
                $query->where('grade_id', $request->grade_id);
            }
            if ($request->filled('payroll_group_type_id')) {
                $query->where('payroll_group_type_id', $request->payroll_group_type_id);
            }
            if ($request->filled('isActive')) {
                $query->where('isActive', $request->isActive === 'true' || $request->isActive === '1' ? 1 : 0);
            }

            if ($request->has('all')) {
                return response()->json($query->orderBy('id', 'desc')->get());
            }

            return response()->json(
                $query->orderBy('id', 'desc')->paginate(10)
            );
        }

        return Inertia::render('Payroll/Group/Index');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'grade_id' => 'required|exists:grades,id',
            'payroll_group_type_id' => [
                'required',
                'exists:payroll_group_types,id',
                \Illuminate\Validation\Rule::unique('payroll_groups')->where(function ($query) use ($request) {
                    return $query->where('grade_id', $request->grade_id);
                })
            ],
            'isActive' => 'boolean',
        ]);

        $payrollGroup = PayrollGroup::create($validated);

        return response()->json([
            'message' => 'Payroll Group created successfully',
            'data' => $payrollGroup->load(['grade.payScale', 'payrollGroupType'])
        ], 201);
    }

    public function update(Request $request, PayrollGroup $payrollGroup)
    {
        $validated = $request->validate([
            'grade_id' => 'required|exists:grades,id',
            'payroll_group_type_id' => [
                'required',
                'exists:payroll_group_types,id',
                \Illuminate\Validation\Rule::unique('payroll_groups')->where(function ($query) use ($request) {
                    return $query->where('grade_id', $request->grade_id);
                })->ignore($payrollGroup->id)
            ],
            'isActive' => 'boolean',
        ]);

        $payrollGroup->update($validated);

        return response()->json([
            'message' => 'Payroll Group updated successfully',
            'data' => $payrollGroup->load(['grade.payScale', 'payrollGroupType'])
        ]);
    }

    public function destroy(PayrollGroup $payrollGroup)
    {
        $reasons = [];

        $componentsCount = PayrollComponent::where('payroll_group_id', $payrollGroup->id)->count();
        if ($componentsCount > 0) {
            $reasons[] = "{$componentsCount} payroll " . ($componentsCount === 1 ? 'component' : 'components');
        }

        $employeeCount = EmployeeInfo::where('payroll_group_id', $payrollGroup->id)->count();
        if ($employeeCount > 0) {
            $reasons[] = "{$employeeCount} employee " . ($employeeCount === 1 ? 'profile' : 'profiles');
        }

        $promotionsCount = Promotion::where('payroll_group_id', $payrollGroup->id)->count();
        if ($promotionsCount > 0) {
            $reasons[] = "{$promotionsCount} promotion " . ($promotionsCount === 1 ? 'record' : 'records');
        }

        if (!empty($reasons)) {
            return response()->json([
                'message' => 'Cannot delete payroll group because it is currently linked to ' . implode(' and ', $reasons) . '. Please reassign or remove these records before deleting.'
            ], 422);
        }

        try {
            $payrollGroup->delete();

            return response()->json([
                'message' => 'Payroll Group deleted successfully'
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Cannot delete payroll group because it is referenced by other records in the database.'
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete payroll group: ' . $e->getMessage()
            ], 500);
        }
    }
}
