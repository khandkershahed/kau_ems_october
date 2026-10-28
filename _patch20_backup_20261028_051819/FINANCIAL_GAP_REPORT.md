# PATCH 19 — Financial reconciliation and acceptance gap report

## Grounded source audit
- Original `SalaryEstimationWorkspaceController::next()` persists a preview JSON into `salary_estimation_run_items`; it does **not** persist a `salary_calculation_versions` record or promote workflow status. A passed preview is NOT a saved approved payroll calculation.
- `SalaryBulkCalculationPersistenceController::save()` writes October 2026 selected employee versions via existing `SalaryCalculatorEntryController::save()` and returns per-employee errors. It is not a generic paginated calculate-all/version-save service.
- Original `SalaryProvisionalBillController` recomposed a fresh `SalaryBillPostingService::preview()`; fixed to select the current approved saved version as required by Master Plan §5.5/§10.
- Original `BulkComponents.vue` supported distinct selected component edits, but no convenient common-component staging action. Added client-side staging; existing audited row-wise save path remains.

## Historical database snapshots
Two user-provided SQL dumps are available (`trektrwu_kau2_39(4).sql` and `kau_step16_test(2).sql`), but they represent different points in time. They are NOT authoritative evidence of the current staging DB after PATCH 18. Prior source comparisons reported 0 vs 395 October posted bills, and duplicate employee profiles by user_id 230/246. These **must be rechecked live** before mutation. No fresh MariaDB queries or certified employee 20057 monetary comparison were performed in this patch.

## 18 employee IDs to audit
1003, 10046, 10108, 20029, 20033, 20038, 20049, 20057, 20082, 20083, 30081, 30098, 30133, 30254, 40033, 40140, 40158, 40274.

## Release blockers
1. 18 per-employee exact Basic, allowance, deduction, GPF, 5-arrear-line and net values compared to authoritative paid-period evidence: NOT VERIFIED.
2. 20057 past vs current salary parity: NOT VERIFIED.
3. October GPF evidence/arrear paid-history reconciliation and no double posting: NOT VERIFIED.
4. Full saved calculation batch, per-person retry, bulk edits for Basic/arrears, aggregated provisional Horizontal Bill: INCOMPLETE.
5. Full MariaDB integration, concurrency, workflow and amount parity tests: NOT EXECUTED.
6. Current generated-bill count, sum, Bank Advice sum: NOT VERIFIED.

**Release: NOT APPROVED.** No historical financial values fabricated or modified.
