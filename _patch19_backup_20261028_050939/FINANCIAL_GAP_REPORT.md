# PATCH 18 — Financial Integrity and Master Plan Gap Audit

**Production verdict:** NOT APPROVED.

## Evidence
- Approved Master Plan defines 413 roster / 395 generated / 18 pending as **historical context, not live verified counts** (§0, §9).
- The current `SalaryEstimationWorkspaceController::next()` uses `SalaryBillPostingService::preview()` and persists preview in `salary_estimation_run_items`, **not** the authoritative `salary_calculation_versions` snapshot; separate `SalaryBulkCalculationPersistenceController::save()` attempts version persistence for selected October codes.
- The current roster service creates BLOCKED records for unposted employees; duplicates do not become silently resolved.
- Latest uploaded SQL snapshots need independent isolated database restore/parity comparison. The user has not provided a live October 2026 18-person successful calculation/export in this turn.

## Acceptance gates still OPEN
- Genuine arrear-inclusive per-employee monetary reconciliation against authoritative sources, especially employee 20057 and 40274.
- GPF source evidence adequacy and approved October GPF dependency resolution for each employee.
- Confirm actual 18 employee identities, past paid bills, June/July/September baselines and five canonical arrear components.
- Per-employee bulk editor spreadsheet-style distinct values and common action mode (§5.4).
- Real MySQL tests for snapshot version idempotency, stale source approval rejection, posted bill + GPF + arrear ledger consistency and concurrency.
- Provisional Horizontal Bill from persisted snapshots, paper review reference, and approved snapshot/report parity.
- Bank Advice from actual saved `salary_generates`; seven-item sidebar compliance; full project-wide authentication-only permission audit.

## New PATCH 18 scope
This is a **UI/worklist contract correction**, not a declaration that 18 October salaries have been calculated or the entire Master Plan fulfilled.
