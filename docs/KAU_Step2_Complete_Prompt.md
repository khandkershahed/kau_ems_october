# NEXT STEP PROMPT — KAU EMS Step 2: Fixation Reconciliation

Act as a senior Laravel 12/Vue 3/Inertia payroll engineer, database architect, QA engineer, and research-grade evidence auditor. Work in **Bangla for explanations**, keep source code and technical identifiers in English.

The current baseline is `app(6).zip` with `KAU_PayScale2026_Step1_Patch.zip` applied to a staging copy and the latest SQL dump. Read `KAU_Project_Handover_Memory_BN.md`, `KAU_Step1_Implementation_Guide_BN.md` and the results of Step 1 tests and mapping CSV. Do NOT overwrite or refactor existing business logic, Vue design, class names or route names; modify only files genuinely required.

**Step 2 Objective:** Reconcile all Salary Fixation 2026 records employee by employee against the original fixation PDF reports, the 2015 pay-scale salary history, grade stages, employee statuses and exception evidence; prepare clean, effective-dated, reviewable 2026 salary entitlements without changing October 2026's current 2015 regular payroll.

Tasks:
1. Inventory exact live SQL tables and Step 1 mapping; verify snapshot currency, migration status, duplicate employee user profiles, grade links, salary histories and the 2026 `pay_scale_stages` data.
2. Inspect current `SalaryFixationService`, `SalaryFixationController`, Vue pages, PDF export, `salary_fixations`, `salary_fixation_adjustments`, `salary_fixation_reference_reports`, approved pay schedules and all source reports. Diagnose discrepancies before writing code.
3. Define authoritative baseline per employee (normally June 2026; Newton's exception based on October 2025 only where evidenced). Never infer an absent historical payment. Explicitly report missing June, September or exception evidence.
4. Apply employee-specific increment eligibility: regular staff, education leave 28 withheld plus five exceptions marked `1 Increment`, 12 increment hold, verified AD HOC without increments, and any deceased/promotion cases supported by documentary dates. Do not block unrelated salary components solely due to increment hold.
5. Reconcile actual values in PDFs: original basic, fitted stage, July 2026 phase, January 2027 phase, July 2027 phase, GPF references, original grade/identity; distinguish 376 unique codes vs 377 documents and resolve duplicates explicitly. An employee without a source PDF must be flagged unverified, not silently approved.
6. Preserve separate dates: new-scale **regular payroll activation 2027-07-01** (current policy), 2026 retrospective entitlement phases if officially supported, and actual arrear payout months. Do not silently substitute one for another. Highlight any policy conflict.
7. Keep/manual expand adjustment model with title, amount, reason, evidence, effective period and reviewer; preserve original calculated and edited values separately. Support preview PDF, employee review and HR confirmation as needed, but do not add strict new role restrictions for this urgent testing version. Preserve existing protections against corruption/double-payment.
8. Produce targeted, ready-to-apply PATCH ZIP only changed/new files, not production SQL or `.env`. Include full working code, reliable idempotent migrations if genuinely needed, no destructive migrations, and exact staging installation commands.
9. Implement automated unit and feature tests for calculations, exception handling, duplicate codes, missing baselines, PDF-value parity and effective dates. Where full Laravel/MySQL/browser execution is unavailable, state precisely what was not verified.
10. Produce employee-level reconciliation CSV with `PASS`, `REVIEW`, `BLOCKED`, reason and evidence filename; final summary counts must add up to all employee profiles. Confirm that October 2026 regular salary generation remains unchanged by Step 2.

**Output in Bangla:** Exact diagnosis, patch ZIP, changes by file, SQL safety check, test results, reconciliation CSV, outstanding manual exceptions, acceptance criteria and an end-to-end single-code-block prompt for Step 3 (Effective-Dated Salary Engine). Do not claim production readiness without actual staging integration and HR sign-off.
