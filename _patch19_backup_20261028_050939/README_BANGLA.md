# KAU EMS — PATCH 18: Master Plan §5 UI Alignment (PARTIAL)

**Source baseline:** `Kau-cline(5).zip` (uploaded 2026-10-28). **Master authority:** `KAU_EMS_HR_PAYROLL_WORKFLOW_MASTER_PLAN(2).md`.

## Changed files
- `app/Http/Controllers/SalaryEstimationWorkspaceController.php` — `WORK_BLOCKED` filter includes `BLOCKED` and not-yet-initialized employee-periods, without silently writing on GET. Adds consolidated blocked count.
- `resources/js/Pages/Salary/Estimation/Workspace.vue` — two primary worklist tabs, current-page selection, per-employee links, initialization, preview and bulk save/approval actions. No posting action.
- `tests/Feature/SalaryPatch18MasterPlanScreenTest.php` — auth smoke / structural UI contract; **not** financial tests.

## Master Plan mapping
- §5.2: Blocked and Calculated list tabs — UI wired.
- §5.3: uses existing estimation run (`start`/`next`) — **preview only**, does not persist a finalized calculation version.
- §5.4: links to existing component bulk editor. **Full per-employee spreadsheet grid and common-change mode not implemented in this patch.**
- §5.5: existing calculation-save and bulk approval services reused; no added Provisional Horizontal Bill download.
- §5.6: no posting call added in Estimation. Existing legacy routes require separate end-to-end audit.
- §2: state machine unchanged.

## Safety/release
No changes to pay-scale, salary math, arrear arithmetic, posting service, GPF ledger or historical bills. No migrations. MySQL integration, per-person monetary reconciliation and Vite build **not executed** in this container. Do **NOT** deploy to production. In staging, run the bundled PowerShell script, then test 18 employees and approve only verified snapshots.

## Rollback
Copy the saved copies of each overwritten file from the script's timestamped backup. Remove the new test file if it did not exist before. Do not rollback salary/GPF data or delete calculation versions to reverse a UI patch.
