# KAU EMS — Project Handover / Working Memory

**As of 2026-10-24**. All future explanations and progress instructions **in Bangla**; filenames, class names, identifiers, tests and reusable prompts in English as necessary.

## Non-negotiable implementation rules

- User's original `app(6).zip` is authoritative current codebase for this step. Compare only with specifically supplied originals when modifying existing logic.
- Do not change unrelated existing controllers, routes, classes, IDs, layouts or salary-calculation patterns.
- Deliver targeted patch ZIP containing only modified/new files, plus complete instructions and QA evidence.
- Do not change or overwrite original 2015 employee salary setup and historical payroll records.
- HR needs manual titled additions/deductions and employee-specific override in each applicable financial calculation module.
- Strict new authorization/business blockers are not a priority for the urgent payroll testing milestone; do not weaken existing critical data safeguards.
- Future new pay scale regular activation policy currently specified as 2027-07-01.
- July–September 2026 arrear evidence and the fixation phases must remain independently reviewed; no assumption that the active date equals retroactive entitlement date.
- Fixation exceptions: increment holds, education leave no-increment plus five exceptions, AD HOC no increments, special historical baseline Newton October 2025; every case requires source validation.
- Special Benefit on new scale should be discontinued when new scale applies; its historical recovery should be evidence based and should not be double counted.
- GPF adjustments must propagate to GPF ledger, not only take-home salary.
- October 2026 payroll draft must not be treated as payment-cleared without HR reconciliation.

## Step roadmap

1. **2026 pay-scale configuration:** inactive 2026 group mapping and component templates; no 2015 amount copying; preview then apply.
2. **Fixation reconciliation:** verify source reports and exception eligibility; effective-dated schedules; employee review/HR sign-off.
3. **Effective-dated salary policy and 2026 component rules:** basic, allowances, Special Benefit, deductions, GPF; preserve 2015.
4. **Arrear and recovery:** monthwise July–September source-ledger reconciliation; exclude double deductions.
5. **Manual exception adjustments:** title, type, amount, month, reason, source record, trace.
6. **Salary generator integration:** current salary + approved arrears - approved recoveries.
7. **Reports / GPF / PDFs / bank advice and employee review**.
8. **Full payroll QA:** every employee preview, mismatch register, browser and integration tests, HR confirmation.

## Step 1 files and checkpoints

Patch adds `app/Console/Commands/PreparePayScale2026.php`; modifies `PayrollComponentController.php` and `PayrollComponentTypeController.php`; adds unit and feature tests. Default preview command `php artisan kau:prepare-pay-scale-2026`; staging apply `php artisan kau:prepare-pay-scale-2026 --apply`. Government 2015 source mapping: 81 groups, 749 components of which 77 Special Benefit and 672 eligible templates. Draft groups and template components inactive, monetary amount zero; existing 2026 rows not overwritten. Expected target `Govt. Pay Scale 2026` stays inactive.

User's SQL snapshot (`kau-app-new-local(1).sql`) dated 2026-10-24 00:34 is older than subsequent local `salary_manual_adjustments` migration; re-check current database before future modifications.

## Quality gate

Only syntax/source data checks and standalone policy smoke tests run in the artifact environment. User must execute Laravel unit/feature tests and staging DB command, review CSV, then browser UI and October salary regression checks. Do not claim production-certified correctness without these checks.
