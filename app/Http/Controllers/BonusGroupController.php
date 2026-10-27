<?php

namespace App\Http\Controllers;

use App\Models\BonusGroup;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BonusGroupController extends Controller
{
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            if ($request->boolean('all')) {
                return response()->json(
                    BonusGroup::orderBy('id', 'desc')->get()
                );
            }

            $query = BonusGroup::query();

            if ($request->filled('search')) {
                $search = trim($request->search);
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            return response()->json(
                $query->orderBy('id', 'desc')->paginate(10)
            );
        }

        return Inertia::render('Payroll/BonusGroup/Index');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:1000',
            'bonusAllowanceObjects' => 'nullable|array',
            'bonusDeductionObjects' => 'nullable|array',
        ]);

        $bonusGroup = BonusGroup::create($validated);

        return response()->json([
            'message' => 'Bonus Group created successfully',
            'data' => $bonusGroup,
        ], 201);
    }

    public function update(Request $request, BonusGroup $bonusGroup)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:1000',
            'bonusAllowanceObjects' => 'nullable|array',
            'bonusDeductionObjects' => 'nullable|array',
        ]);

        $bonusGroup->update($validated);

        return response()->json([
            'message' => 'Bonus Group updated successfully',
            'data' => $bonusGroup,
        ]);
    }

    public function destroy(BonusGroup $bonusGroup)
    {
        $bonusGroup->delete();

        return response()->json([
            'message' => 'Bonus Group deleted successfully',
        ]);
    }
}
