# KAU EMS — PATCH 14: Verified recovery fixes and evidence audit (PARTIAL)

## What this actually fixes
- `BulkComponents.vue`: removes an extra closing `</div>` before `</details>`, the source of the reported Vue SFC compiler failure.
- `SalaryPatch13WorkflowRecoveryTest`: `postJson` unauthenticated requests correctly expect HTTP **401**, not a redirect.
- Adds read-only `kau:audit-october-18` Artisan command to inventory employee/profile/workflow/approved-version/posted-bill presence and historical June–September bill counts, including employee 20057.

## Financial safety and outstanding blockers
- October GPF rule in `SalaryArrearRuleService::approvedOctoberGpf()` requires an already-approved October salary calculation; the bulk save path requires arrear preview. This is circular. **This patch does NOT change arithmetic or replace missing evidence with 0 or an assumed rate**. A financially approved two-stage input contract and MySQL-backed parity tests are required before changing that rule.
- This package does **not** resolve all 18 salaries, save calculation versions, or approve/post bills. Do not claim final calculation success.
- Roster initialization is a separate explicit write operation, not part of the read-only audit.
- On user staging environment, existing tests may have passed yet financial tests are missing: report PASS only for tests you run.

## Staging application and rollback
1. Back up source and isolated staging database; NEVER use a production DB.
2. Extract the 3 changed/new paths preserving directory structure.
3. Run `php -l` for the two PHP files, `php artisan test tests/Feature/SalaryPatch13WorkflowRecoveryTest.php`, `npm run build` and `php artisan kau:audit-october-18 --json`.
4. Review all 18 evidence rows and October financial parity before approvals.
5. Roll back by restoring the two overwritten Vue/test files; remove the new Artisan command. No migration and no financial data writes in this patch.

## Production readiness
NOT APPROVED. No isolated MySQL environment or vendor/node_modules supplied in uploaded source, so integration suite and Vite build cannot be executed here.
