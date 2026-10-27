# KAU EMS Step 07 — Actual Source Audit (26 October 2026)

## Source inspected
- Uploaded `app(6).zip` (the newest application in the current turn).
- Latest user-provided `Pasted text(20261026-040734).txt` Windows migration/test/build log.
- Prior uploaded `kau-app-local(2).sql` snapshot was available in project session; **not a live database** and not an updated SQL export accompanying this Step 07 message.
- Existing `SalaryGenerateController`, `SalaryReportController`, `SalaryBillCompositionService`, `SalaryBillPostingService`, `SalaryStatementBreakdown`, `SalaryPayScaleArrearEngine`, `SalaryBulkRunController`, `SalaryBill.vue`, `SalaryReports/Index.vue`, routes and pay-fixation HTML.

## Source defects
1. `resources/js/Pages/Salary/GenerateBills/Index.vue` line 1498 had an invalid ternary/assignment expression in the `:href`. Prior Step 06 user test log proves this broke Vite production build. Fixed narrowly to `?salary_year=...&salary_month=...`.
2. `SalaryReportController::transformUsers()` calculates a posted historical Basic initially from current employee profile, and falls back to current grade minimum when a saved bill Basic is absent. The patch makes posted Basic derive only from saved bill component amount (`calculated_amount` preferred) and restricts minimum-grade fallback to unposted legacy previews.
3. Legacy `SalaryBill.vue` presents saved allowances/deductions without server-side line-to-saved-total integrity checks, so mismatches can look normal.
4. Existing Report Factory uses jsPDF `autoTable` / html2canvas for reporting. Step 07 provides a separate canonical server HTML/print path specifically for Salary Bill and Arrear Statements, without changing the unrelated Fixation PDF.
5. For Phase 5 posted bills, `salary_bill_source_links.source_snapshot` and source hash exist. The new read-only service verifies snapshot ID, hash, and exact posted gross/deduction/net before presenting a bill. Legacy saved bills have no source link and are labelled `LEGACY_POSTED_BILL`.
6. Existing Step 05 published rule conflict is unresolved: `BASIC_INCREMENT_ADJUSTMENT` was independently modified and `kau:review-october-arrear-rules` did not commit. Step 07 does not overwrite it.
7. The 1,421 Step 01 component assignments remained 0 approved / 1,421 pending in the last confirmed seeder preflight. Step 07 neither seeds nor activates them.

## Changes made
- `SalaryOfficialReportSource.php` — read-only posted salary and frozen source projection, amounts reconciliation, UNKNOWN preservation, no current-rule regeneration.
- `SalaryOfficialReportController.php` — authenticated GET report endpoint, selected employee validation, canonical HTML and optional configured PDF, JSON, CSV, and A4 bulk/single/arrear modes.
- `resources/views/salary/official-report.blade.php` — inline CSS A4, Arial/sans-serif Bengali fallback, one employee per portrait page; landscape bulk 10/15/custom up to 25, repeated headers, page/grand totals, signature lines; stored arrear aggregate without invented monthly split.
- Report Factory — canonical print/PDF buttons for Salary Bill and Arrear, Bulk Overview and CSV/Excel from same server saved rows, and embedded official preview for generated employees. Unrelated report types retain legacy actions.
- New opt-in `config/kau_official_reports.php` reads `KAU_REPORT_CHROMIUM_BINARY`; if missing, use browser Print/Save PDF. No Namecheap renderer assumed.
- No migration, no seeder, no role-based HR blocker, no financial DB writes.

## Known coverage limitations
- The source snapshot does not always contain **monthly allocated** July/August/September paid/revised Basic/House Rent/Special Benefit/GPF. The official arrear report prints `NOT ALLOCATED` for monthly values when the frozen source provides only a 3-month aggregate. It does not fabricate source data.
- Existing legacy posted percentage rows without stored calculated amount are `UNKNOWN` in itemized details; frozen saved totals remain official. These records need financial data reconciliation if a fully itemized certified report is required.
- For old posted bills, `department` is current informational data because it was not always frozen on `SalaryGenerate`; source marks it as such.
- Direct automatic PDF download is conditional on a configured/verified Chromium installation; the print-ready HTML works without it.
- Real MySQL, Namecheap host, logged-in browser and all live financial reports **not tested here**. Do not deploy without staging acceptance.
