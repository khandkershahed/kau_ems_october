<?php

namespace App\Http\Controllers;

use App\Models\PayScale;
use App\Models\Grade;
use App\Models\EmployeeInfo;
use App\Models\Promotion;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PayScaleController extends Controller
{
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $query = PayScale::query()->withCount(['grades', 'employeeInfos']);
            
            // Search by scale name or description
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('scale_name', 'like', "%{$search}%")
                      ->orWhere('scale_description', 'like', "%{$search}%");
                });
            }

            // Filter by active status
            if ($request->filled('isActive')) {
                $isActive = filter_var($request->input('isActive'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isActive !== null) {
                    $query->where('isActive', $isActive);
                }
            }

            // Filter by grades presence
            if ($request->filled('hasGrades')) {
                $hasGrades = filter_var($request->input('hasGrades'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($hasGrades !== null) {
                    if ($hasGrades) {
                        $query->has('grades');
                    } else {
                        $query->whereDoesntHave('grades');
                    }
                }
            }

            // Filter by employee assignment
            if ($request->filled('hasEmployees')) {
                $hasEmployees = filter_var($request->input('hasEmployees'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($hasEmployees !== null) {
                    if ($hasEmployees) {
                        $query->has('employeeInfos');
                    } else {
                        $query->whereDoesntHave('employeeInfos');
                    }
                }
            }

            $sortField = $request->input('sortField', 'id');
            $sortDirection = $request->input('sortDirection', 'desc');
            $allowedSortFields = ['id', 'scale_name', 'isActive', 'created_at', 'grades_count', 'employee_infos_count'];

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

        return Inertia::render('Payroll/Scale/Index');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'scale_name' => 'required|string|max:255',
            'scale_description' => 'nullable|string',
            'isActive' => 'boolean',
        ]);

        $payScale = PayScale::create($validated);

        return response()->json([
            'message' => 'Pay Scale created successfully',
            'data' => $payScale
        ], 201);
    }

    public function update(Request $request, PayScale $payScale)
    {
        $validated = $request->validate([
            'scale_name' => 'required|string|max:255',
            'scale_description' => 'nullable|string',
            'isActive' => 'boolean',
        ]);

        $payScale->update($validated);

        return response()->json([
            'message' => 'Pay Scale updated successfully',
            'data' => $payScale
        ]);
    }

    public function destroy(PayScale $payScale)
    {
        $reasons = [];

        $gradesCount = Grade::where('pay_scale_id', $payScale->id)->count();
        if ($gradesCount > 0) {
            $reasons[] = "{$gradesCount} " . ($gradesCount === 1 ? 'grade' : 'grades');
        }

        $employeeCount = EmployeeInfo::where('pay_scale_id', $payScale->id)->count();
        if ($employeeCount > 0) {
            $reasons[] = "{$employeeCount} employee " . ($employeeCount === 1 ? 'profile' : 'profiles');
        }

        $promotionsCount = Promotion::where('pay_scale_id', $payScale->id)->count();
        if ($promotionsCount > 0) {
            $reasons[] = "{$promotionsCount} promotion " . ($promotionsCount === 1 ? 'record' : 'records');
        }

        if (!empty($reasons)) {
            return response()->json([
                'message' => 'Cannot delete pay scale because it is currently linked to ' . implode(' and ', $reasons) . '. Please reassign or remove these records before deleting.'
            ], 422);
        }

        try {
            $payScale->delete();

            return response()->json([
                'message' => 'Pay Scale deleted successfully'
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Cannot delete pay scale because it is referenced by other records in the database.'
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete pay scale: ' . $e->getMessage()
            ], 500);
        }
    }
}
