#!/usr/bin/env python3
"""Read-only KAU payroll rule-matrix expansion. Produces review CSV, never touches MySQL.

Example: python scripts/kau_apply_approved_rate_matrix.py \
  database/seeders/data/KAU_payroll_component_rules_REVIEW.csv \
  database/seeders/data/KAU_payroll_component_rate_matrix_APPROVED.csv \
  /tmp/KAU_payroll_component_rules_EXPANDED.csv

Matrix wildcards: grade='*', designation='*'; pay_scale and component MUST match exactly.
Most-specific grade+designation rule wins; equally-specific conflicts fail.
Never mark PENDING candidates APPROVED without a matching evidence-backed matrix row.
"""
from __future__ import annotations
import csv
import sys
from pathlib import Path

FIELDS = [
    'pay_scale', 'grade', 'designation', 'component', 'direction',
    'calc_type', 'value', 'basis', 'frequency', 'effective_from',
    'effective_until', 'evidence_reference',
]

def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open('r', encoding='utf-8-sig', newline='') as handle:
        reader = csv.DictReader(handle)
        columns = list(reader.fieldnames or [])
        rows = list(reader)
    return columns, rows

def matched(rule: dict[str, str], item: dict[str, str]) -> bool:
    if rule['pay_scale'].strip().casefold() != item['pay_scale'].strip().casefold():
        return False
    if rule['component'].strip().casefold() != item['component'].strip().casefold():
        return False
    for field in ('grade', 'designation'):
        value = rule[field].strip()
        if value != '*' and value.casefold() != item[field].strip().casefold():
            return False
    return True

def specificity(rule: dict[str, str]) -> int:
    return sum(rule[field].strip() != '*' for field in ('grade', 'designation'))

def main() -> int:
    if len(sys.argv) != 4:
        print('Usage: kau_apply_approved_rate_matrix.py REVIEW.csv MATRIX.csv OUTPUT.csv', file=sys.stderr)
        return 2
    inventory, matrix_file, destination = map(Path, sys.argv[1:])
    if destination.resolve() in [inventory.resolve(), matrix_file.resolve()]:
        raise ValueError('OUTPUT must be a separate file: never overwrite the originals.')
    cols, items = read_csv(inventory)
    mcols, matrix = read_csv(matrix_file)
    if not set(FIELDS).issubset(mcols) or 'status' not in cols:
        raise ValueError('CSV columns do not match the supplied template.')
    for i, rule in enumerate(matrix, 2):
        if not rule['pay_scale'].strip() or rule['pay_scale'].strip() == '*':
            raise ValueError(f'Matrix row {i}: pay_scale wildcard is prohibited.')
        if not rule['component'].strip() or rule['component'].strip() == '*':
            raise ValueError(f'Matrix row {i}: component wildcard is prohibited.')
        if not rule['evidence_reference'].strip():
            raise ValueError(f'Matrix row {i}: evidence_reference is required.')
        if rule['calc_type'] not in ('fixed', 'percentage', 'calculated'):
            raise ValueError(f'Matrix row {i}: invalid calc_type.')
        if rule['calc_type'] != 'calculated' and not rule['value'].strip():
            raise ValueError(f'Matrix row {i}: missing approved value.')
        if rule['calc_type'] == 'calculated' and rule['value'].strip():
            raise ValueError(f'Matrix row {i}: calculated value must be blank.')
        if not rule['effective_from'].strip() or not rule['frequency'].strip():
            raise ValueError(f'Matrix row {i}: effective date and frequency are required.')
    applied = 0
    unused = set(range(len(matrix)))
    for item in items:
        if item['status'].strip().upper() != 'PENDING':
            continue
        matches = [(specificity(rule), index, rule) for index, rule in enumerate(matrix) if matched(rule, item)]
        if not matches:
            continue
        matches.sort(reverse=True, key=lambda v: v[0])
        best = [match for match in matches if match[0] == matches[0][0]]
        if len(best) > 1:
            label = ' / '.join(item[k] for k in ('pay_scale', 'grade', 'designation', 'component'))
            raise ValueError(f'Multiple equally specific approved rules for {label}. Resolve before expansion.')
        _, index, rule = best[0]
        if rule['direction'].strip().casefold() != item['direction'].strip().casefold():
            raise ValueError(f'Component direction conflict: {item["component"]}')
        for field in ('calc_type', 'value', 'basis', 'frequency', 'effective_from', 'effective_until', 'evidence_reference'):
            item[field] = rule[field].strip()
        item['status'] = 'APPROVED'
        applied += 1
        unused.discard(index)
    if unused:
        raise ValueError(f'{len(unused)} matrix rule(s) match no PENDING component; check grade/designation/source.')
    destination.parent.mkdir(parents=True, exist_ok=True)
    with destination.open('w', encoding='utf-8-sig', newline='') as handle:
        writer = csv.DictWriter(handle, fieldnames=cols, extrasaction='ignore')
        writer.writeheader()
        writer.writerows(items)
    pending = sum(r['status'].upper() == 'PENDING' for r in items)
    print(f'Expanded {applied} approved assignments; {pending} remain PENDING. Output: {destination}')
    print('Next: HR review values/dates; replace REVIEW.csv deliberately after independent approval.')
    return 0

if __name__ == '__main__':
    raise SystemExit(main())
