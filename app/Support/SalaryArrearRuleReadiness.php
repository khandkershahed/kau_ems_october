<?php

namespace App\Support;

/** Read-only published-rule audit. This class never edits financial records. */
final class SalaryArrearRuleReadiness
{
    private const PERIOD = ['2026-07','2026-08','2026-09'];

    public static function inspect(object|array $rule): array
    {
        $r = (array)$rule;
        $code = (string)($r['code'] ?? '');
        $formula = trim((string)($r['formula'] ?? ''));
        $selected = $r['selected_months'] ?? [];
        if (is_string($selected)) {
            $selected = json_decode($selected, true);
        }
        $issues = [];
        $expected = array_column(SalaryArrearTemplateCatalog::definitions(), 'formula', 'code');
        if (!array_key_exists($code, $expected)) {
            $issues[] = 'CUSTOM_RULE_REQUIRES_INDEPENDENT_VALIDATION';
        } else {
            if ($selected !== self::PERIOD) $issues[] = 'WRONG_ARREAR_PERIOD';
            if (preg_match('/\bSUM_MONTHS\s*\(/i', $formula)
                && preg_match('/\)\s*\*\s*3(?:\.0+)?\s*$/', $formula)) {
                $issues[] = 'DOUBLE_MONTH_MULTIPLIER';
            }
            if ($formula !== $expected[$code]) $issues[] = 'FORMULA_DIFFERS_FROM_REFERENCE';
            if (($r['mode'] ?? null) !== 'formula') $issues[] = 'UNEXPECTED_CALCULATION_MODE';
        }
        if (empty($r['published']) || !empty($r['archived'])) $issues[] = 'NOT_ACTIVE_PUBLISHED_RULE';
        return ['code'=>$code, 'status'=>$issues ? 'REVIEW_REQUIRED' : 'MATCHES_REFERENCE_TEMPLATE',
            'issues'=>$issues, 'current_formula'=>$formula, 'reference_formula'=>$expected[$code] ?? null,
            'selected_months'=>is_array($selected) ? $selected : null];
    }
}
