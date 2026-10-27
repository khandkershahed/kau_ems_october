# KAU EMS — Step 04 Installation, QA & Rollback (2026-10-26)

## Scope and source

Basis: supplied `app(2).zip`, previous Step 03 Windows test/build log in `Pasted text(20261026-024656).txt`. No updated SQL dump was uploaded **with this turn**; no live MySQL connection was available. The supplied Windows log reports Step 03 migration ran, 10 override unit tests passed, and Vite completed successfully. Two Step 03 feature tests were skipped because they require an isolated MySQL test clone. Treat the ZIP as a staging patch, **not** a production-approved release.

## What changes

- Adds `SalaryStatementBreakdown` (PHP), a side-effect-free projector that builds Current/Arrear/Total rows from `SalaryCalculationService` results and already calculated arrear lines. Missing final amounts remain `null`/UNKNOWN.
- Salary Calculator supplies read-only `statement` data; Salary Setup modal supplies the same regular statement; bill composition supplies a derived statement without changing the original `source_hash` algorithm.
- `Entry.vue`: same existing green/red panels, additional Current/Arrear/Total columns, one unified preview table, CSV, and print preview from the same reactive breakdown.
- Fixes legacy fixed employee GPF being initially mislabeled/defaulted to 10% in the Vue screen; takes initial mode/rate from authoritative server-calculated `financial.gpf_rule`.
- Keeps manual override routes/tables and all published rule/arrear calculations unchanged. No new migrations or seeders.

## Does not change

- The rules for 2015/2026 entitlement, phased Basic and Home Rent effective dates; these stay with their established underlying services.
- No new entitlement, payment or arrear formula. **Step 05** implements scale-based arrear logic.
- No historical `salary_generates`, approved fixation, GPF ledger or bank advice changes.
- No new mandatory HR review gate.

## Before staging deploy

1. Backup current source, database and existing compiled Vite artifacts. Confirm ZIP matches the latest source version (`app(2).zip`).
2. Inspect `PATCH_SHA256_MANIFEST.txt` and modified path list; do not overwrite unrelated source.
3. Extract patch ZIP into the Laravel project root. Use a disposable staging database with a configuration equivalent to production, not live production data.

### Windows PowerShell commands

```powershell
$Project = 'D:\Projects\KAU EMS\kau-app-new'
$Patch = 'D:\Downloads\KAU_EMS_STEP04_UNIFIED_SINGLE_SALARY_PATCH_ONLY.zip'
$Backup = "D:\KAU_EMS_STEP04_Backup_$(Get-Date -Format yyyyMMdd_HHmmss)"
New-Item -ItemType Directory -Force -Path $Backup | Out-Null
Copy-Item -LiteralPath $Project -Destination (Join-Path $Backup 'project') -Recurse
# BACK UP DATABASE separately using your configured MySQL username and credentials.
Expand-Archive -LiteralPath $Patch -DestinationPath $Project -Force
Set-Location $Project
php artisan migrate:status
php scripts/step04_statement_smoke.php
node --test tests/js/salaryStatementBreakdownStep04.test.mjs
php artisan test --filter=SalaryStatementBreakdownStep04Test
php artisan test --filter=SalaryCalculation
php artisan test --filter=SalaryEmployeeComponentOverride
php artisan test --filter=SalaryBill
npm ci
npm run build
php artisan optimize:clear
```

**Important:** `npm ci` modifies `node_modules`; ensure `php artisan serve` / Vite dev server is stopped and no `esbuild.exe` process holds a file lock. `npm audit` should be reviewed for the dependency vulnerabilities mentioned in the earlier user log; do not execute `npm audit fix --force` blindly.

### Manual staging acceptance

1. Employee with legacy **fixed** GPF, not 10%: compare Vue shown GPF to `SalaryCalculationService` result; no value substitution.
2. Employee with effective percentage House Rent: verify correct effective date and percentage base, without automatically activating 2028 housing rates.
3. Employee with Step 03 manual override: selected month only, saved snapshot new version, reset to automatic.
4. July/September/October arrear scenarios: `UNKNOWN` remains visible and never becomes a verified zero; an employee's current regular calculation must not be confused with arrear entitlement.
5. CSV, print preview and on-screen statement show the same row amounts. Compare saved salary bill and server-side preview for at least 10 representative employees.
6. On a disposable staging MySQL clone, run previously skipped feature tests and verify duplicate bill/GPF avoidance, role permissions, bank advice non-generation during preview.
7. Verify no new migration and no new seeder is required for Step 04.

## Safe production deployment (ONLY after staging signoff)

Namecheap/SSH environment may not have Node; compile in CI/Windows and deploy the complete `public/build` manifest/assets together with source if needed. Do not copy `.env`/test secrets. Before pulling code, create a timestamped source snapshot, verify `mysqldump` completed and can be read, and record the Git commit hash.

```bash
cd /path/to/kau-app
php artisan down
# Back up files and DB with your own environment-specific paths/credentials.
git rev-parse HEAD
git pull origin main
php artisan migrate:status
# Step 04 has no database migration; do not run seeders.
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
```

Run route authorization, one employee Current/Arrear/Total preview, a sample report and a read-only calculation smoke test after deploy. **Do not** generate actual salary or GPF postings until full staging reconciliation passes.

## Rollback

1. Stop salary generation; take a snapshot of any new financial activity first.
2. Revert only modified Step 04 files to their backed-up versions / previous Git commit. Redeploy previously matching `public/build` assets.
3. Run `php artisan optimize:clear` and restore config/view/route caches as appropriate.
4. Step 04 introduces **no migration**, so no database rollback is needed; do not run `migrate:rollback` or delete tables.
5. Existing saved financial records must be preserved, not reverted automatically. Re-run the previous salary calculator unit tests.

## Release gate

**STAGING CANDIDATE ONLY.** No Laravel suite or live MySQL tests were executed in this execution environment. Vite full compilation was blocked here by incomplete offline npm cache. The user's previous successful Step 03 Vite log proves only Step 03, not this Step 04 package.
