# KAU EMS PATCH 20 — Phase 3: selected Horizontal Bill (PARTIAL)

## Delivered
- Multi-employee **Provisional Horizontal Bill** for selected Calculated employees on current page.
- Saved active approved salary_calculation_versions only; no recomputation or financial posting.
- Shows five canonical arrear columns, version/hash, exact exclusions, and `NOT RECORDED` for missing numbers. Totals deliberately `INCOMPLETE` if an amount is missing.
- Browser print / Save PDF. Does not claim a verified payable final net when underlying saved data is incomplete.
- No migration or modification to salary, arrear, or GPF tables.

## NOT delivered — Master Plan Phase 3 remains incomplete
- Genuine 413-employee resumable persisted Calculate All: existing estimation run is preview-only.
- Full spreadsheet-style Basic, GPF, and 5-component per-employee/month bulk override editor.
- Financial GPF circular dependency and independent evidence completeness for all 18.
- Full October 18-employee reconciliation and 20057 monetary parity.
- MySQL-backed integration tests, single/bulk monetary parity, full two-stage approval and concurrent retry verification.

## Staging installation
1. Back up the complete source and staging database separately. 
2. Copy the ZIP into `D:\Projects\KAU EMS\new patch`.
3. Run the bundled PowerShell installer on an isolated staging clone.
4. From Salary Estimation > Calculated Employee List, select employees then `Print selected Horizontal Bill`.
5. Reconcile report version/hashes, status, and persisted values before any real use.

## Rollback
Restore the files listed in the staged source backup directory, and delete the newly added report controller, Blade view, and test where they did not previously exist. No new migrations.

## Testing evidence
PHP lint for controller, route and PHPUnit test: PASS. ZIP integrity: PASS. Laravel PHPUnit: NOT RUN (vendor absent). Vite build: NOT RUN (node_modules absent). MariaDB: NOT RUN (no isolated database credentials). These are release blockers.
