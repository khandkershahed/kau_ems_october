# KAU EMS — Step 06 Source Audit (26 October 2026)

## Verified input
- Source: user-uploaded `app(5).zip` (extracted; file-level audit).
- Evidence: user-uploaded Windows Step 05 test log `Pasted text(20261026-034613).txt`.
- The current turn contained **no newer SQL dump**. Do not claim live DB read or current published values. Prior Step 01 snapshot reported 1,421 assignments, all pending. This is not a fresh DB verification.
- No database connection and no payroll posting were made while preparing this patch.

## Verified workflow before Step 06
- `SalaryGenerateController::bulkCandidates()` collected up to 1,000 unbilled IDs, but calculated only the first **25**.
- Existing frontend preview could select only the first 25, yet a different `Generate All Filtered` flow could create an unpreviewed run.
- `SalaryBulkRunController::start()` accepted just user IDs, no preview source hashes. `next()` posts 25 items per request using `SalaryBillPostingService::post()`.
- `SalaryGenerateController::generateSalaryForEmployee()` already uses `SalaryBillPostingService::post()`. A historical private `generateSalaryForEmployeeUnlocked()` method and legacy `generateBulk()` route still exist; the public single route is authoritative. This patch does not invoke or alter that private historical algorithm.
- `SalaryBillCompositionService::compose()` already calculates regular and arrear amounts and returns `SalaryStatementBreakdown`. The new bulk preview uses this exact source; there is **no second formula**.
- `SalaryBillPostingService::post()` already prevents duplicate bills, GPF ledger conflicts, back-dated ledger mutation, and legacy arrear overlap using MySQL transaction and source links.
- `salary_bulk_run_items.source_hash` already exists and can store a preview hash before posting, so **no migration** is required.
- Original `/salary/generate-bills` UI design, component classes, page navigation and other actions remain.

## Step 05 Windows log
- Step05 smoke 17 passed, Step05 unit 11 passed, Arrear overall 39 passed / 3 skipped / **1 failed** (missing PHP SQLite driver), Vite build passed for the previous version, not this patch.
- `kau:review-october-arrear-rules` said `BASIC_INCREMENT_ADJUSTMENT was edited independently; manual reconciliation required`. No rules committed; this condition remains unresolved.
- Earlier MySQL-dependent feature tests skipped, and this new patch has not run an isolated MySQL feature test.

## Step 06 changes
1. Full candidate preview in 25-employee read-only requests; server validates selected IDs against current filter and unbilled period.
2. `SalaryBulkPreviewPresenter` supplies Basic, current gross/deductions/net, arrear +/-/net, final net, rule source hash and a useful failure reason. Provisional/UNKNOWN financial amounts never become READY.
3. `SalaryBillPostingService::previewForPosting()` performs **read-only** early duplicate bill, GPF, back-dated contribution, and overlapping arrear checks. `post()` repeats authoritative checks under DB transaction.
4. Explicit selected employee IDs **and their matching financial source hashes** are saved into existing `salary_bulk_run_items.source_hash`.
5. In `post()` hash comparison happens **after server recomputation, inside the employee row lock and before bill/GPF writes**. A changed override, Basic, bank, arrear or saved calculation causes that item to become BLOCKED; the others proceed.
6. Existing resumable 25-per-call `next()` architecture retained, alongside result CSV and old run token resume support.
7. `Generate All Filtered` now opens full preview instead of posting unpreviewed staff. `Edit` links to the existing calculator with `?year=...&month=...`; `Recalculate` refreshes one row. The administrator can select/deselect and export preview/exceptions CSV.
8. Existing `auth` middleware retained; **no role-based blockades or new approval workflow introduced**.

## Limitations
- The Step 01 data are not published; no fabricated rates are introduced. Employees with incomplete arrear evidence or financial sources show missing-evidence/blocked status and need correction. There is no compulsory multistage HR approval.
- Old `salaryBulkRun` records with null source hashes are still resumable for backward compatibility; they do not receive the new hash comparison. They still use all existing posting checks.
- Single/Bulk PDF redesign is explicitly Step 07. This patch leaves the existing Salary Reports module accessible and does not change report templates.
- Status counts are from preview at a point in time; generation recomputes under locks and may block employees if financial inputs change.
