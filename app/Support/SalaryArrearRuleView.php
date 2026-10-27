<?php

namespace App\Support;

/** Pure presentation and source-drift helper; never mutates financial sources. */
final class SalaryArrearRuleView
{
    public static function normalize(object|array $rule): array
    {
        $row = (array) $rule;
        foreach (['published', 'recurring', 'archived'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = (bool) (int) $row[$field];
            }
        }
        if (array_key_exists('revision', $row)) {
            $row['revision'] = (int) $row['revision'];
        }
        return $row;
    }

    /** Detect when an existing calculation snapshot no longer matches live published rules. */
    public static function hasPublishedChanges(array $savedRows, array $liveRows): bool
    {
        $identity = static function (array $rows): array {
            $out = [];
            foreach ($rows as $row) {
                $row = (array) $row;
                $id = (int) ($row['rule_id'] ?? 0);
                if ($id <= 0) continue; // Employee-added manual components are independent.
                if (array_key_exists($id, $out)) return ['duplicate' => true];
                $periods = array_values((array) ($row['months'] ?? []));
                $amount = $row['calculated_amount'] ?? null;
                $out[$id] = [
                    // Display title is editable; never use it as the rule's financial identity.
                    'direction' => (string) ($row['direction'] ?? ''),
                    'mode' => (string) ($row['mode'] ?? ''),
                    'formula' => (string) ($row['formula'] ?? ''),
                    'months' => $periods,
                    'calculated_amount' => $amount === null ? null : number_format((float) $amount, 2, '.', ''),
                    'error' => (string) ($row['error'] ?? ''),
                ];
            }
            ksort($out);
            return $out;
        };
        return $identity($savedRows) !== $identity($liveRows);
    }
}
