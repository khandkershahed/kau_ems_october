# KAU EMS Step 1 — QA এবং Source Reconciliation

## Evidence (attached local SQL snapshot)

- 2015 Govt scale ID: 1; 2026 Govt scale ID: 4 (এগুলো source snapshot-specific ID; code-এ ID hardcode করা হয়নি)।
- Govt 2015 grades: 20; Govt 2026 grades: 20 (name `Grade 1` ... `Grade 20`)।
- Govt 2015 Payroll Group: 81; 2026 target: 0 before patch application।
- Govt 2015 Payroll Components: 749; Special benefit type: 77; draft eligible: 672।
- 2026 `pay_scale_stages`: source prior audit 319।
- Pivot `pay_scale_payroll_group` 2015-এর source dump-এ empty; app uses direct `grade_id` relationship; command deliberately does not inject redundant pivot rows।

## Changed files

- Modified `app/Http/Controllers/PayrollComponentController.php`: two related dropdown filters for active or the targeted 2026 scale + target inactive groups.
- Modified `app/Http/Controllers/PayrollComponentTypeController.php`: filter includes the targeted 2026 scale.
- Added `app/Console/Commands/PreparePayScale2026.php`: 2015 structure to 2026 inactive draft, transactions and CSV.
- Added `tests/Unit/PayScale2026PreparationPolicyTest.php`.
- Added `tests/Feature/PayScale2026ConfigurationTest.php`.

## Executed checks

- PHP lint: 5 of 5 PHP files passed.
- Standalone PHP smoke: 4 special-benefit identification cases passed.
- Read-only SQL parser consistency: 20 matched old/new grades, 81 source groups, 749 component entries, 77 specials, 672 draft-eligible rows.
- Full Laravel execution: **not performed**; `vendor/` and a reachable MySQL staging database are unavailable in this runtime.
- Browser verification: **not performed**.
- No user's database or existing files were modified; patch is separate ZIP.

## Open sign-off

1. HR must approve 2026 component rules and rates; do not assume copied 2015 monetary amounts.
2. Stage assignments for `National Pay Scale 2015` Honorarium are out of scope; require official mapping.
3. Clarify effective date distinction: fixation phases July 2026/January 2027 vs regular active payroll July 2027.
4. Arrear House Rent, GPF rounding, Special Benefit recovery and Newton baseline remain Step 2–4 verification issues.
