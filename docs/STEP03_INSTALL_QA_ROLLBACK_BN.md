# KAU EMS — Step 03 | Employee Monthly Component Overrides

## Scope & source

Source: latest uploaded `app(1).zip` (2026-10-26). Reviewed latest available SQL snapshot `kau-app-local(2).sql`, not a live database. Step 01/02 log confirms migration installed and Step 02 unit tests passed in local Windows; do not confuse with runtime integration in this environment.

This patch does not activate or publish any of the 1421 still-pending payroll component rules. Its effect is employee/month only. It retains current 2015/2026 pay-scale and arrear policies. Does not post salaries, bank advice or GPF ledgers. Financial approval and correct rules remain separate.

## Changes

- `database/migrations/2026_10_26_000002_create_salary_component_employee_overrides.php`: monthly overrides, immutable audit events and component override source fingerprint on calculation versions. Idempotent `up`; non-destructive `down` intentionally disallowed.
- `app/Services/SalaryEmployeeComponentOverrideService.php`: pure override applier, effective source signature, snapshot verification.
- `app/Http/Controllers/SalaryEmployeeComponentOverrideController.php`: save/reset/disable, validates amount, type, basis, role, concurrency and exact configured component.
- `app/Services/SalaryComponentCatalog.php`: automatically applies Step 03 override after Step 02 effective rule.
- `app/Services/SalaryCalculationService.php`: GPF employee monthly source and percentage base/rounding.
- `app/Services/ApprovedSalaryCalculationVersionResolver.php`: stale approved calculated snapshots stop masking new override sources.
- `app/Services/SalaryBillCompositionService.php`: bill composition rejects old snapshots after override changes.
- `app/Http/Controllers/SalaryCalculatorEntryController.php`: lists overrides, verifies version signature, prevents saving stale or tampered component values; saves source fingerprint.
- `resources/js/Pages/Salary/Calculator/Entry.vue`: existing component rows get Override action and employee-month modal, disabled list/reset, custom monthly entries, source display.
- `routes/web.php`: one authenticated POST action `/dashboard/salary/calculator/{user}/component-override`, named `salary-calculator.component-override`.
- 10 pure unit scenarios and 2 isolated MySQL feature test scenarios.

## Important behavior

- Approved Basic is immutable by the Step 03 editor.
- Monthly override takes precedence over published rule/legacy values only in its specific salary period.
- `Automatic` removes the explicit override; it does not restore or rewrite employee component JSON.
- `Disabled` removes that component from that month's calculation, not from global policy.
- `Fixed`, `Percentage of Effective Basic`, `Percentage of documented Legacy Basic`, optional reason and evidence are supported.
- HR/Payroll role: active `superadmin` or `payroll_hr`; unauthorized write is 403.
- Legacy component key uses normalized name if no component type ID exists. Duplicate same-name rows are rejected rather than silently choosing one.
- Custom one-off employee components do not modify `payroll_components` and are distinct from old `salary_manual_adjustments`.
- After updating a monthly component, reload the calculator and **save a new calculation version** before bill generation; the old saved version is preserved and cannot silently mask new values.
- Do not silently replace the default 10%/house-rent policy if no published evidence exists; previously saved legacy rows remain a fallback.

## Windows staging, PowerShell

Replace `$Project` with your actual staging directory if different. Use a disposable staging MySQL clone, not live production data.

```powershell
$Project = 'D:\Projects\KAU EMS\kau-app-new'
$Patch = 'D:\Downloads\KAU_EMS_STEP03_EMPLOYEE_COMPONENT_OVERRIDES_PATCH_ONLY.zip'
$Backup = 'D:\KAU_step03_' + (Get-Date -Format 'yyyyMMdd_HHmmss')
New-Item -ItemType Directory -Force -Path $Backup | Out-Null
Copy-Item -LiteralPath $Project -Destination (Join-Path $Backup 'kau-app-new') -Recurse -Force
# Independently export your isolated STAGING MySQL database with mysqldump before migrating.
Expand-Archive -LiteralPath $Patch -DestinationPath $Project -Force
Set-Location $Project
php artisan migrate:status
php artisan migrate --pretend --path=database/migrations/2026_10_26_000002_create_salary_component_employee_overrides.php
# Inspect the SQL and obtain staging approval BEFORE the next command.
php artisan migrate --path=database/migrations/2026_10_26_000002_create_salary_component_employee_overrides.php
php artisan test --filter=SalaryEmployeeComponentOverrideStep03Test
php artisan test --filter=SalaryEmployeeComponentOverrideStep03FeatureTest
php artisan test --filter=SalaryEffectiveComponentResolverStep02Test
php artisan test --filter=SalaryCalculation
php artisan test --filter=SalaryBill
npm ci
npm run build
php artisan optimize:clear
php artisan route:list --name=salary-calculator
```

After the command, open Salary Calculator for a staging employee in October 2026. Check Override Fixed→Percentage; after saving reload, compare with current result; November must remain unchanged. Check GPF HALF UP; custom component; Disable; Reset to Automatic; concurrent tab returning 409; unauthorized user returning 403. Save a new calculation version, compare Single/Salary Setup/Bulk preview and attempt generation only in isolated disposable staging.

Your previous `npm ci` ran successfully after an initial Windows `EPERM` on esbuild.exe; previous `npm run build` failed because Vite was absent before the successful reinstall. Run `npm run build` again now. `npm audit` reported 10 vulnerabilities; audit before upgrading packages. Do not run `npm audit fix --force` blindly.

## Namecheap production — REVIEW ONLY, NOT YET CLEARED FOR DEPLOY

1. Merge tested patch into Git after staging acceptance, record commit SHA; make complete file+MySQL backups.
2. `git pull origin main` in the correct release directory.
3. `php artisan migrate:status` and `php artisan migrate --pretend --path=database/migrations/2026_10_26_000002_create_salary_component_employee_overrides.php` (review output and MySQL permissions).
4. ONLY once approved: `php artisan migrate --force --path=database/migrations/2026_10_26_000002_create_salary_component_employee_overrides.php`.
5. `php artisan optimize:clear`, `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`.
6. Vite assets must have been successfully built on CI or staging with matching lockfile and deployed; Namecheap may not have Node. If Node is permitted: `npm ci && npm run build` in a controlled build process.
7. Check file permissions (`storage` and `bootstrap/cache` writable only where needed), role permissions, and test one employee without posting.
8. Never run general `php artisan db:seed`. New Step 03 does not require a seeder.

## Rollback

- Stop salary component override editing; preserve backups of database and files.
- Revert the deployed Git commit or restore ONLY overwritten Step 03 source files; clear Laravel caches and restore the prior matching Vite build.
- New tables/column are intentionally left in place with audit records. **Do not use `migrate:rollback`** (the migration's `down()` refuses destructive deletion). A data reversal requires a reviewed, separately backed-up migration.
- If a salary bill has been posted using a new override, financial reconciliation must be performed before rollback. Never delete recorded bills or GPF ledgers to force old totals.

## QA executed in this environment

- PHP syntax checks: passed for 9 changed/new PHP application, migration and routes files.
- Node syntax check for `Entry.vue` script: passed.
- Standalone PHP smoke runner: 12 passed (original source unchanged, Fixed→Percentage, effective/legacy bases, missing override bypass rejection, disabled, GPF HALF UP, custom, key normalization).
- PHPUnit: NOT RUN (vendor dependencies absent); new feature tests require isolated cloned MySQL.
- MySQL `migrate --pretend`/execution: NOT RUN in this environment.
- `npm ci --offline`: FAILED ENOTCACHED (yargs-parser unavailable); Vue/Vite build NOT RUN here.
- No salary/GPF/arrear ledgers written by this patch preparation.
- Production readiness: NOT VERIFIED. Staging mandatory.
