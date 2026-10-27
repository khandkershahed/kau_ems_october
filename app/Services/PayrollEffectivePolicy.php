<?php

namespace App\Services;

/** Effective-dated payroll selection. Keeps statutory phase previews separate from payable payroll. */
final class PayrollEffectivePolicy
{
    public const PAY_SCALE_2026_ACTIVATION_DATE = '2027-07-01';

    public static function uses2026PayScale(int $year, int $month): bool
    {
        return $year >= 1 && $month >= 1 && $month <= 12
            && sprintf('%04d-%02d-01', $year, $month) >= self::PAY_SCALE_2026_ACTIVATION_DATE;
    }

    public static function isSpecialBenefit(mixed $component): bool
    {
        if (!is_array($component)) {
            return false;
        }
        $name = strtolower(trim((string) ($component['name'] ?? $component['payroll_component_type']['name'] ?? '')));
        return str_contains($name, 'special benefit') || str_contains($name, 'special bonus');
    }
}
