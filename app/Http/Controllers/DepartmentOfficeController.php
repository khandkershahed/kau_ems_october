<?php

namespace App\Http\Controllers;

use App\Models\DepartmentOffice;
use App\Models\EmployeeInfo;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DepartmentOfficeController extends Controller
{
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $query = DepartmentOffice::query()->withCount('users');
            
            // Search by name or description
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Filter by type (department, office, others)
            if ($request->filled('type')) {
                $query->where('type', $request->input('type'));
            }

            // Filter by isActive
            if ($request->filled('isActive')) {
                $isActive = filter_var($request->input('isActive'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isActive !== null) {
                    $query->where('isActive', $isActive);
                }
            }

            // Filter by staff assignment (has assigned employees / staff)
            if ($request->filled('hasEmployees')) {
                $hasEmployees = filter_var($request->input('hasEmployees'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($hasEmployees !== null) {
                    if ($hasEmployees) {
                        $query->where(function ($q) {
                            $q->has('users')->orHas('employeeInfos');
                        });
                    } else {
                        $query->whereDoesntHave('users')->whereDoesntHave('employeeInfos');
                    }
                }
            }

            // Filter by Employee Group / User Category of assigned employees
            if ($request->filled('user_category_id')) {
                $catId = $request->input('user_category_id');
                $query->whereHas('users.userCategories', function ($q) use ($catId) {
                    $q->where('user_categories.id', $catId);
                });
            }

            $sortField = $request->input('sortField', 'id');
            $sortDirection = $request->input('sortDirection', 'desc');
            $allowedSortFields = ['id', 'name', 'type', 'isActive', 'created_at', 'users_count'];

            if (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
            } else {
                $query->orderBy('id', 'desc');
            }

            return response()->json(
                $query->paginate(10)
            );
        }

        return Inertia::render('Office/DepartmentOffice/Index', [
            'userCategories' => \App\Models\UserCategory::all(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'type' => 'required|string',
            'isActive' => 'boolean',
        ]);

        $departmentOffice = DepartmentOffice::create($validated);

        return response()->json([
            'message' => 'Record created successfully',
            'data' => $departmentOffice
        ], 201);
    }

    public function update(Request $request, DepartmentOffice $departmentOffice)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'type' => 'required|string',
            'isActive' => 'boolean',
        ]);

        $departmentOffice->update($validated);

        return response()->json([
            'message' => 'Record updated successfully',
            'data' => $departmentOffice
        ]);
    }

    public function destroy(DepartmentOffice $departmentOffice)
    {
        $linkedUserIds = EmployeeInfo::where('department_office_id', $departmentOffice->id)->pluck('user_id')
            ->merge(DB::table('department_office_users')->where('department_office_id', $departmentOffice->id)->pluck('user_id'))
            ->unique()
            ->filter();
        $totalLinked = $linkedUserIds->count();

        if ($totalLinked > 0) {
            $typeLabel = strtolower($departmentOffice->type ?? 'department/office');
            return response()->json([
                'message' => "Cannot delete this {$typeLabel} because it is currently assigned to {$totalLinked} " . ($totalLinked === 1 ? 'employee' : 'employees') . ". Please reassign or remove these employees before deleting."
            ], 422);
        }

        try {
            $departmentOffice->delete();

            return response()->json([
                'message' => 'Record deleted successfully'
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Cannot delete record because it is referenced by other records in the database.'
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete record: ' . $e->getMessage()
            ], 500);
        }
    }
}
