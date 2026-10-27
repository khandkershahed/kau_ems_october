<?php

namespace App\Support;

/**
 * A 2015 pay-scale reference only, NOT evidence that a July increment was
 * earned or paid. Never use this result directly for payable arrear posting.
 */
final class SalaryOldScaleIncrementReference
{
    public static function nextStageDifference(array $stages, float $juneBasic): ?array
    {
        $normalized = array_values(array_filter($stages, 'is_numeric'));
        foreach ($normalized as $index => $raw) {
            $current = (float)$raw;
            if (abs($current - $juneBasic) > 0.009) continue;
            if (!isset($normalized[$index + 1])) return null;
            $next = (float)$normalized[$index + 1];
            if ($next <= $current) return null;
            return [
                'june_basic' => round($current, 2),
                'next_2015_stage' => round($next, 2),
                'candidate_increment' => round($next - $current, 2),
                'status' => 'REFERENCE_ONLY_NOT_PROOF_OF_JULY_PAYMENT',
            ];
        }
        return null;
    }
}
