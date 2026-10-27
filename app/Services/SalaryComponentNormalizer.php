<?php

namespace App\Services;

use DomainException;

/** Accepts legacy JSON, including the double-encoded rows found in older KAU imports. Read-only. */
final class SalaryComponentNormalizer
{
    public static function rows(mixed $value): array
    {
        for ($attempt = 0; $attempt < 4 && is_string($value); $attempt++) {
            $value = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new DomainException('Invalid historical salary component JSON.');
        }
        if ($value === null || $value === '') return [];
        if (!is_array($value) || !array_is_list($value)) throw new DomainException('Salary components must be a list.');
        $out = [];
        foreach ($value as $row) {
            if (!is_array($row)) throw new DomainException('Damaged salary component row.');
            $name = trim((string) ($row['name'] ?? $row['payroll_component_type']['name'] ?? ''));
            $type = strtolower((string) ($row['calcType'] ?? 'fixed'));
            $amount = $row['amount'] ?? null;
            if ($name === '' || !in_array($type, ['fixed', 'percentage'], true) || !is_numeric($amount)) {
                throw new DomainException('Invalid component title, type, or rate in saved employee setup.');
            }
            $row['name'] = $name;
            $row['calcType'] = $type;
            $row['amount'] = (float) $amount;
            $out[] = $row;
        }
        return $out;
    }
}
