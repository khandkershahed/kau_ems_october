# KAU 2026 Pay Fixation — Production SQL Compatibility Audit

## Basis and handling

- Input: user-supplied `kau-app.sql` (MySQL 8.4 dump). Data analyzed offline; **not imported or modified**.
- Compared against the user's original `app.zip` and the review-only 2026 salary-fixation integration.
- This report includes **aggregate statistics only**. The production SQL, identifiable employee data, emails, account numbers, national identification numbers, and passwords are not bundled into either deliverable.
- Government payroll legal interpretation, official iBAS++ equivalence, and final entitlement decisions remain unverified.

## Schema/data inventory

| Item | Observed rows |
|---|---:|
| User records | 423 |
| Employee profiles (`employee_infos`) | 423 |
| Monthly salary records (`salary_generates`) | 5,658 |
| **Approved June 2026 salary records** | **404** |
| Grade entries (2015 scales and other) | 21 |
| Pay scales | 2 |
| Payroll groups | 82 |
| Payroll component records | 749 |
| Explicit promotion-history rows | 0 |

Employee scale assignments: 398 on `Govt. Pay Scale 2015` (ID 1), 25 on `National Pay Scale 2015` (ID 2). All 25 profiles assigned to the second scale have payroll-group grade rows belonging to scale ID 1; this is a **scale-reference mismatch**, not proof that these employees are ineligible for pay fixation. It must be investigated in the existing staff data.

## Automatic input readiness

| Result / reason | Profiles |
|---|---:|
| Profiles present | 423 |
| Passed basic consistency gates for **provisional formula preview** | **385** |
| Blocked from an ordinary automated fixation preview pending source reconciliation | **38** |
| Without a June 2026 salary snapshot | 18 |
| Employee-scale vs payroll-group grade-scale mismatch | 25 |
| June 2026 salary grade differs from current payroll-group grade | 3 |
| June 2026 recorded basic is not an exact 2015 scale stage for its assigned grade | 8 |
| Of the 8 nonstandard basics: outside assigned grade's salary range | 5 |

**Reason counts overlap**. The 38 blocked employee profiles are the union of the reasons, not the sum. The 385 are data-consistency candidates only; none is automatically certified, legally approved, or posted to salary generation.

Additional caution points:
- `employee_infos.holdIncrement` = `0` for all 423 profiles. This shows how the flag is currently populated, **not** independent confirmation of individual increment entitlement.
- `employee_infos.incrementDate` = `2026-09-20` for 416 profiles and `2026-07-01` for 7. The large shared date should be checked against appointment/service records before using it as an official DNI.
- Six `additionObjects` entries in the current salary setup are JSON strings containing JSON instead of normal JSON arrays; two are NULL. These cannot replace a verified June 2026 payroll record.
- June 2026 salary records are all marked `approved` in this dump; their generation date is `2026-06-30`.
- No explicit promotion rows exist despite grade differences between historical payroll and current assignments. The empty promotion table does **not** establish that no employee was promoted or reassigned.
- Personal pay (PP), fixed/honorarium employment arrangements, sanctioned special pay, delayed increments, and personnel orders need independent checks before final approval.

## Fixes applied to review-only module

1. Compare `employee_infos.pay_scale_id` with the grade's own `pay_scale_id`; mismatches block automatic fixation preview/snapshot saving.
2. Require an approved June 2026 payroll snapshot generated on 30 June 2026 for an ordinary automatic preview. A current salary setup may still be displayed as an **unverified** data fallback but cannot authorize calculation.
3. Verify that the historically paid June grade maps to the same grade as the current employee payroll group; conflicts block automated fixation.
4. Require a single fixed Basic Pay component from the verified source, matching a published 2015 pay stage for the selected grade.
5. Block missing joining dates, post-cutoff joiners, inactive staff accounts, ambiguous personal pay, percentage-based basic, and fitments beyond grade ceiling.
6. Handle legacy double-encoded JSON safely for fallback display, without promoting it to historical evidence.
7. Display explicit blocking reasons in the staff fixation list. Keep read-only previews, provisional audit snapshots, and original payroll generation isolated.

## Operational restrictions

**Not production payroll-ready.** The patch never changes `employee_infos`, `salary_generates`, existing salary configuration, or historical bills. It creates a separate `salary_fixations` table if migrated. A 2026 pay scale seeder remains optional and registers rows as inactive.

Deploy to a staging copy first. Run PHP lint, automated calculation tests, Laravel migration/feature tests, Vite build, role/access checks, and manually reconcile multiple employee cases with official orders and the gazette. Only then consider a separate approved activation workflow with effective dates and rollback safeguards.
