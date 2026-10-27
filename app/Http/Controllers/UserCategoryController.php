<?php

namespace App\Http\Controllers;

use App\Models\UserCategory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class UserCategoryController extends Controller
{
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $query = UserCategory::query();
            
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
            }

            return response()->json(
                $query->orderBy('id', 'desc')->paginate(10)
            );
        }

        return Inertia::render('Employee/UserCategory/Index');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        $userCategory = UserCategory::create($validated);

        return response()->json([
            'message' => 'Record created successfully',
            'data' => $userCategory
        ], 201);
    }

    public function update(Request $request, UserCategory $userCategory)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        $userCategory->update($validated);

        return response()->json([
            'message' => 'Record updated successfully',
            'data' => $userCategory
        ]);
    }

    public function destroy(UserCategory $userCategory)
    {
        $userCount = DB::table('user_user_categories')->where('user_category_id', $userCategory->id)->count();
        if ($userCount > 0) {
            return response()->json([
                'message' => "Cannot delete employee group because it is currently assigned to {$userCount} " . ($userCount === 1 ? 'employee' : 'employees') . ". Please reassign or remove these employees before deleting."
            ], 422);
        }

        try {
            $userCategory->delete();

            return response()->json([
                'message' => 'Employee group deleted successfully'
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Cannot delete employee group because it is referenced by other records in the database.'
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete employee group: ' . $e->getMessage()
            ], 500);
        }
    }
}
