# KAU EMS — Step 9 Employee Exception Root-Cause Audit

**Data status:** October 24, 2026. **Safety:** READ-ONLY preflight patch. **Production:** NO-GO.

## Evidence and boundaries

- Current user-provided, locally generated CSV: `kau_payroll_release_audit_2026_10.csv` (423 employee profiles). `KAU_Step9_BLOCKED_Employees.csv` (34), `KAU_Step9_REVIEW_Employees.csv` (54), `KAU_Step9_REGULAR_DRAFT_Employees.csv` (335).
- Historical contextual SQL: `kau-app-new-local.sql` (export on 24 October 2026 around 04:50, **not** necessarily after latest local Step 8 actions). Its `employee_infos`, `users`, `grades`, `pay_scales`, `payroll_groups`, `bank_accounts` and approved `salary_generates` were parsed for reference.
- Code baseline: `app(1).zip` uploaded in the previous turn, containing Step 8 resolver and audits.
- Do not infer that a saved approved salary bill was actually disbursed. No employee edits or disbursement instructions are included.

## Live audit classification (from the user-provided CSV)

| Category | Profiles |
|---|---:|
| REGULAR_DRAFT | 335 |
| REVIEW | 54 |
| BLOCKED | 34 |
| TOTAL | 423 |

Separate root cause counts for the 34 blocked profiles (overlapping categories):

- `GRADE_SCALE_RELATION_MISMATCH`: **25**. All 25 historical SQL profile pay scales are National Pay Scale 2015 (ID 2) while their payroll group grade refers to Govt. Pay Scale 2015 (ID 1). This requires an HR-approved identity/scale mapping, not a blanket SQL update.
- `USER_INACTIVE`: **9**. Requires employment status/effective date review. Do **not** auto-activate. Some have previous approved salary bills but not necessarily payable October entitlement.
- `SALARY_SETUP: Basic Pay...`: **5**. Historical SQL profile addition configurations are zero-value/empty in terms of valid Basic; prior approved bills contain historical Basic. Review and restore the **full salary component setup** only after source verification; do not auto-copy Basic.
- `DUPLICATE_USER_PROFILES`: **4 profile rows / 2 distinct user IDs**: user 230 (employee codes 30098 and 800000), user 246 (employee codes 30254 and 3200). The source of truth must come from HR identity documentation. Never delete or merge automatically.
- Duplicate salary-account flag warning: **63** across all 423 profiles, including **54** REVIEW-only and **9** among BLOCKED. Current salary arithmetic is calculable for all 54 REVIEW-only profiles. Even if the explicit bank link is valid, payment-account flags require HR verification. No bank account numbers are included in the exported worklist.

## Five historical Basic Pay references, NOT authorized corrections

| Employee code | Last approved historical bill month | Historical Basic (BDT) |
|---|---|---:|
| 10046 | August 2026 | 37,280 |
| 10108 | August 2026 | 29,510 |
| 20029 | August 2026 | 25,480 |
| 40033 | August 2026 | 11,650 |
| 40158 | August 2026 | 10,870 |

The **current** salary setup may also lack Medical, House Rent, GPF and other components. The last approved month does not prove that October employment/increment/amount is unchanged. Reconcile every item against employee source documents and HR orders.

## Other payment and release limitations

- The audit CSV shows `missing_september_history=yes` for all 423 profiles, `arrear_status=NOT_APPROVED` for all 423, `approved_arrear_snapshot_count=0`, `posted_arrear_count=0`, and no selected-month saved bills. Zero posted-arrear anomalies proves only that no posted arrears were found to inspect.
- The 335 `REGULAR_DRAFT` profiles are not an HR-payable certificate. November/October bank advice, HR signatures, and GPF ledger are not fully reconciled.
- October 2026 regular payroll should remain on the applicable **2015** scale. Pay Scale 2026 regular activation is fixed to **2027-07-01** by project policy. Retrospective entitlements require separate HR authorization.

## Step 9 patch functionality

The patch consists of **only new files**:

1. `app/Services/PayrollExceptionReviewClassifier.php` — deterministic HR action labels and historical salary Basic extraction with double-JSON decoding; **never** applies a correction.
2. `app/Console/Commands/PreparePayrollExceptionReview.php` — read-only live database lookup of current profile-scale-group-grade, account flag IDs, latest approved Basic reference. Reads the existing payroll audit CSV and refuses a changed/mismatched profile identity. Outputs a local CSV, no DB mutations.
3. `tests/Unit/PayrollExceptionReviewClassifierTest.php` — checks independent exception actions and safe historical Basic reference extraction.
4. `docs/KAU_Step9_Implementation_QA_BN.md` — this document.

### Windows commands

Before patch: take a project and staging DB backup. Extract the ZIP over the project root. It contains no migration and no frontend files.

```powershell
php artisan optimize:clear
php artisan test --filter=PayrollExceptionReviewClassifierTest
php artisan test --filter=SalaryBankAccountResolverTest
php artisan test --filter=SalaryBillReconciliationTest
php artisan kau:audit-payroll-release --year=2026 --month=10
php artisan kau:prepare-payroll-exception-review --year=2026 --month=10
```

Expected new file: `storage/app/kau_step9_hr_exception_worklist_2026_10.csv`. Export the file to HR, fill only `hr_decision` and `hr_evidence_reference` after review. Use the **application's existing employee/profile maintenance workflows** to apply authorized corrections; do not import this CSV as a direct database patch.

**No `php artisan migrate` required. No `npm run build` required** for this PHP-only patch. Run both if you have other outstanding migrations/frontend modifications, not as Step 9 prerequisites.

## QA actually run in this authoring environment

- Three PHP files `php -l` **PASS**.
- Standalone PHP tests of classifier actions and double-JSON historical Basic extraction **PASS**.
- SQL source parser: 423 employee profiles, 423 users, 41 grades, 163 payroll groups, 485 bank accounts, 5,658 historical salary bills.
- Uploaded audit CSV: exact match 335/54/34 and its split CSVs; 88 exception rows exported.
- ZIP CRC and SHA-256 checks are included in external file manifest.

**Not executed:** Laravel PHPUnit (application `vendor/` directory is not available in this container); live MySQL integration, real HR evidence confirmation, bank advice, browser and production salary generation. The corresponding HR/data work cannot be certified from an older SQL snapshot.

## HR resolution gates

1. HR establishes which salary scale is legally appropriate for the 25 mismatches (especially Honorarium/AD HOC cases). Changes require an approval reference and month-specific impact analysis.
2. HR confirms current employment and payable dates for the 9 inactive profiles; do not reactivate an account as a shortcut to payroll.
3. HR reconciles all components for 5 missing Basic setups against historic bills, increment orders, GPF policy and approved allowances.
4. HR resolves 2 duplicate-user identities covering 4 profile rows, preserving every historical salary and employee code reference.
5. HR verifies the explicit account linked for each of 63 salary-bank flag anomalies; never publish raw account numbers in review CSV.
6. Re-run Step 8 audit. An employee can only graduate from `BLOCKED`/`REVIEW` after the approved source correction is in staging.
7. Conduct isolated **single and bulk payroll generation**, GPF closing balance, arrear idempotency, PDF and Bank Advice testing in Step 10 before release.

**Release decision: NO-GO** pending HR sign-off and MySQL end-to-end checks.
