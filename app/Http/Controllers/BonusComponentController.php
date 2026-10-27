<?php

namespace App\Http\Controllers;

use App\Models\BonusComponent;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BonusComponentController extends Controller
{
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            if ($request->boolean('all')) {
                return response()->json(
                    BonusComponent::orderBy('id', 'desc')->get()
                );
            }

            $query = BonusComponent::query();

            if ($request->filled('search')) {
                $search = trim($request->search);
                $query->where(function ($q) use ($search) {
                    if (is_numeric($search)) {
                        $q->where('id', $search)
                          ->orWhere('amount', 'like', "%{$search}%");
                    } else {
                        $q->where('componentType', 'like', "%{$search}%")
                          ->orWhere('calcType', 'like', "%{$search}%");
                    }
                });
            }

            if ($request->filled('componentType')) {
                $query->where('componentType', $request->componentType);
            }

            if ($request->filled('calcType')) {
                $query->where('calcType', $request->calcType);
            }

            if ($request->filled('fromBasic') && $request->fromBasic !== '') {
                $fromBasic = filter_var($request->fromBasic, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($fromBasic !== null) {
                    $query->where('fromBasic', $fromBasic);
                }
            }

            if ($request->filled('isTaxable') && $request->isTaxable !== '') {
                $isTaxable = filter_var($request->isTaxable, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isTaxable !== null) {
                    $query->where('isTaxable', $isTaxable);
                }
            }

            return response()->json(
                $query->orderBy('id', 'desc')->paginate(10)
            );
        }

        return Inertia::render('Payroll/BonusComponent/Index');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'fromBasic' => 'required|boolean',
            'isTaxable' => 'required|boolean',
            'componentType' => 'required|in:allowance,deduction',
            'calcType' => 'required|in:fixed,percentage',
            'amount' => 'required|numeric|min:0',
        ]);

        $component = BonusComponent::create($validated);

        return response()->json([
            'message' => 'Bonus Component created successfully',
            'data' => $component,
        ], 201);
    }

    public function update(Request $request, BonusComponent $bonusComponent)
    {
        $validated = $request->validate([
            'fromBasic' => 'required|boolean',
            'isTaxable' => 'required|boolean',
            'componentType' => 'required|in:allowance,deduction',
            'calcType' => 'required|in:fixed,percentage',
            'amount' => 'required|numeric|min:0',
        ]);

        $bonusComponent->update($validated);

        return response()->json([
            'message' => 'Bonus Component updated successfully',
            'data' => $bonusComponent,
        ]);
    }

    public function destroy(BonusComponent $bonusComponent)
    {
        $bonusComponent->delete();

        return response()->json([
            'message' => 'Bonus Component deleted successfully',
        ]);
    }
}
