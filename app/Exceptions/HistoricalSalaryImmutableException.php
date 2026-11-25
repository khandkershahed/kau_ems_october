<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Thrown when code tries to change finalized salary for a locked historical month. */
final class HistoricalSalaryImmutableException extends DomainException
{
    public static function forMonth(int $year, int $month, string $action): self
    {
        return new self(sprintf(
            'HISTORICAL_SALARY_IMMUTABLE: salary for %04d-%02d is finalized and cannot be %s. '
            .'Settle corrections in the November 2026 (or later) payroll.',
            $year, $month, $action
        ));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['status' => 422, 'errors' => [$this->getMessage()],
            'message' => $this->getMessage()], 422);
    }
}
