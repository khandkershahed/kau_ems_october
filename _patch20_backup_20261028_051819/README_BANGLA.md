# KAU EMS PATCH 19 — Phase 3 financial/report and common-edit improvements (PARTIAL)

This patch is a **staging candidate**, not completion of Phase 3. Baseline: user-uploaded `Kau-cline.zip`, 28 October 2026; requirements: Master Plan Sections 3–5, 7, 9, 11, 12.

## Implemented
1. `SalaryProvisionalBillController` now shows **only a persisted active, approved calculation version** scoped to employee and period in `CALCULATED` or `READY_FOR_GENERATION` status. It no longer calls `SalaryBillPostingService::preview()` to recompute amounts. No posting or GPF writes.
2. `salary/provisional-horizontal.blade.php` prints snapshot identity, saved regular results, configured regular components and arrear lines with unknown amount represented as **NOT VERIFIED**, not zero. Browser Print -> Save as PDF. No fabricated digital signature.
3. Estimation calculated list includes a version-linked Provisional Bill action. Main selected worklists remain Blocked and Calculated.
4. Compact Bulk Components UI has a **Common action for selected**: select mapped component, mode, Fixed/Percentage and value; stage rows locally; review per employee; save via existing audited employee component override endpoint. Legacy Basic percentage basis intentionally requires individual verified reference.
5. Feature tests for route and guest authentication (no MySQL-backed financial assertions).

## Important exclusions / Master Plan gaps
- Genuine resumable **saved** arrear-inclusive auto-calculation for all employees (current PATCH 05 runs are read-only previews); PATCH 12 bulk-save remains limited to 18 and can fail on missing evidence.
- Full per-employee spreadsheet grid with Basic, 5 canonical arrear columns and dedicated GPF source editing.
- Common-change automatic monetary impact preview / server-side batched atomic override operations.
- Complete cross-page selection, provisional aggregate multi-employee Horizontal Bill PDF.
- 18 individual actual financial comparisons, employee 20057 certified parity and fresh October 395/18 reconciliation.
- Stale source and posting idempotency full MariaDB integration.

## Financial warnings
DO NOT manually set missing GPF, Basic or arrears to zero. Review the approved version and source evidence independently. Do not use this patch to generate actual bills. No migration added; no salary/GPF ledger data modified by installation.

## Staging / rollback
Use included `PATCH19_INSTALL_VERIFY.ps1`, from your normal Windows PowerShell execution environment. It copies any existing overwritten files to a timestamped backup folder. Restore each overwritten file from the backup and remove newly created feature test to rollback. The installer never runs a database mutation. Existing saved calculation histories are not rewritten.

## Test results in creation environment
`php -l` on modified controller and added test: PASS. ZIP CRC check: PASS after packaging. Laravel/PHPUnit feature tests: **NOT EXECUTED** (no project `vendor/autoload.php` in uploaded ZIP). Vite build: **NOT EXECUTED** (no `node_modules` in uploaded ZIP). MariaDB financial tests: **NOT EXECUTED** (no isolated live DB). Staging installer will attempt Laravel tests and Vite build; its output is required before release.
