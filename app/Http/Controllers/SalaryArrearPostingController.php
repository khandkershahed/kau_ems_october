<?php

namespace App\Http\Controllers;

use App\Services\SalaryArrearPostingService;
use Illuminate\Http\Request;
use DomainException;

class SalaryArrearPostingController extends Controller
{
    public function post(Request $request, SalaryArrearPostingService $service)
    {
        $data = $request->validate([
            'snapshot_id' => 'required|integer|min:1',
            'salary_generate_id' => 'required|integer|min:1',
        ]);
        try {
            return response()->json($service->post(
                (int) $data['snapshot_id'], (int) $data['salary_generate_id'], (int) $request->user()->id
            ));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }
}
