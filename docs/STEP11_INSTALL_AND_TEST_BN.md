# KAU EMS — Step 11 consolidated patch installation (Windows PowerShell)

**Status:** Source patch and safe staged draft recovery; **not certified for payroll posting**. Never run tests that post salary/GPF on the live Namecheap database. Run the complete procedure on a disposable MySQL and codebase clone.

## Required downloaded files

1. `KAU_EMS_STEP11_CONSOLIDATED_FIXATION_AND_SEPTEMBER_PATCH_ONLY.zip`
2. `codershat_kau_STEP10_STAGING_RECONSTRUCTED.sql` — a *staging-only* reconstruction from the supplied current dump and 410 pending September drafts. Do **NOT** restore this into production.

Copy both downloads into `D:\Projects\KAU EMS\` (or modify paths below to the actual downloads directory).

## Step A — Separate staging project

```powershell
$Project = 'D:\Projects\KAU EMS\kau-app-new'
$Stage = 'D:\Projects\KAU EMS\kau-app-step11-staging'
$PatchZip = 'D:\Projects\KAU EMS\KAU_EMS_STEP11_CONSOLIDATED_FIXATION_AND_SEPTEMBER_PATCH_ONLY.zip'
$Patch = 'D:\Projects\KAU EMS\Step11_Consolidated_Patch'
$StageSql = 'D:\Projects\KAU EMS\codershat_kau_STEP10_STAGING_RECONSTRUCTED.sql'

foreach ($path in @($Project, $PatchZip, $StageSql)) {
    if (-not (Test-Path -LiteralPath $path)) { throw "Missing file/directory: $path" }
}
if (Test-Path -LiteralPath $Stage) { throw "Staging directory already exists: $Stage. Do not overwrite without a backup." }
Copy-Item -LiteralPath $Project -Destination $Stage -Recurse -Force
if (Test-Path -LiteralPath $Patch) { throw "Patch extraction directory already exists: $Patch" }
Expand-Archive -LiteralPath $PatchZip -DestinationPath $Patch

# Validates manifest but makes NO changes.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$Patch\INSTALL_STEP11_STAGING.ps1" -ProjectRoot $Stage -PatchRoot $Patch

# Makes only file changes; keeps timestamped copies of overwritten files.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$Patch\INSTALL_STEP11_STAGING.ps1" -ProjectRoot $Stage -PatchRoot $Patch -Apply
```

## Step B — Configure isolated database

Create an empty `kau_step11_staging` MySQL/MariaDB database, using the local MySQL administrator account. The commands below require `mysql.exe` on PATH.

```powershell
mysql.exe --user=root --password --execute="CREATE DATABASE IF NOT EXISTS kau_step11_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cmd.exe /c ('mysql.exe --user=root --password kau_step11_staging < "' + $StageSql + '"')
```

**Never point the staging `.env` to `codershat_kau`.** Edit the staging `.env` (not the original project `.env`) to set `APP_ENV=local`, `APP_DEBUG=true`, and `DB_DATABASE=kau_step11_staging`, with valid local DB host, port, username and password. If you programmatically update `.env`, preserve all other keys and save UTF-8 without BOM. Do not share the `.env` file.

**Choose one recovery method:** the combined SQL already contains the 410 drafts. Do **NOT** run `Step10SeptemberDraftRecoverySeeder` afterward. If instead you restore the unchanged production dump into staging, the seeder may be used with its opt-in staging guard. Never autoapprove September pending drafts.

## Step C — Run read-only and isolated checks

```powershell
Set-Location $Stage
php -v
php -m
php artisan migrate:status
php artisan migrate --pretend
# Carefully inspect the --pretend output; on this disposable staging clone only:
php artisan migrate --force
php artisan optimize:clear

php scripts\step09_structured_arrear_smoke.php
php scripts\step10_september_recovery_offline_qa.php
node --test tests\js\salaryDisplayAmountStep10.test.mjs
php artisan test --filter=SalaryStudyLeaveOneIncrementStep11Test
php artisan test --filter=SalaryStructuredArrearStep09Test
php artisan test --filter=SalaryFixation
php artisan test --filter=SalaryArrear
php artisan test --filter=SalaryCalculation
php artisan test --filter=SalaryBill
php artisan test --filter=SalaryBulk

npm ci
npm run build
npm audit --json | Out-File -FilePath 'npm-step11-audit.json' -Encoding utf8
php artisan kau:audit-arrear-rule-readiness
php artisan kau:audit-payroll-release --year=2026 --month=10

# Default is dry-run, no fixation changes:
php artisan kau:prepare-october-fixations
```

The dry-run CSV is `storage/app/kau_october_fixation_approval_dry_run.csv`. Reconcile all `NEEDS_SOURCE_REVIEW`, `NOT_CALCULABLE` and `ERROR` rows with the original HR/fixation records, without blanket bypass. The previous dump includes two duplicate employee profiles; these must not be silently selected. Confirm a legitimate actor user ID; do not use an arbitrary ID merely because it exists.

## Step D — Approve verified fixations **on staging only**

After HR checks and before any salary posting, run with the intended audit actor:

```powershell
$ActorId = 1  # Replace/confirm against your intended actual HR operator's existing user ID.
php artisan kau:prepare-october-fixations --apply "--actor-id=$ActorId" --attestation=FIXATIONS_VERIFIED_BY_HR
php artisan kau:prepare-october-fixations
```

Only fixations for which `SalaryFixationService::prepare()` is source-complete and calculable are approved. The command calls the existing workflow, writes fixation schedules/events and **does not** write bills, GPF, arrear disbursements or bank advice. It **will not** approve all employees with missing evidence simply because HR previously checked a spreadsheet. Resolve exceptions through the existing UI and documented source records.

The five study-leave exception employee codes are `10037,10042,10056,10086,10105`. They receive the July 2026 increment but no July 2027 second increment. If a previously saved manual 2027 allowance conflicts, the existing adjustment must be reviewed and set to `hold` with evidence. No historical adjustment is deleted.

## Step E — Financial rehearsal and release checks

Run only on the disposable MySQL clone:

```powershell
php artisan kau:rehearse-october-payroll --year=2026 --month=10 --limit=1 --confirm-isolated
php artisan kau:rehearse-october-payroll --year=2026 --month=10 --limit=10 --confirm-isolated
php artisan kau:rehearse-october-payroll --year=2026 --month=10 --limit=15 --confirm-isolated
php artisan kau:rehearse-october-payroll --year=2026 --month=10 --limit=16 --confirm-isolated
php artisan kau:rehearse-october-payroll --year=2026 --month=10 --limit=25 --confirm-isolated
php artisan kau:rehearse-october-payroll --year=2026 --month=10 --limit=0 --confirm-isolated
```

If any financial integration test was skipped, unavailable or failed, do not generate bills on production. Check both **approved fixture Basic and verified financial evidence**: September pending drafts are not proof of payment; Special Benefit Recovery, 2015 entitlement/House Rent and additional GPF must not be represented as verified zero. Until the final arrear source feed and monthwise reconciliations are present, the system cannot be declared fully ready.

## Step F — Production promotion, only after release gates pass

Make a separate verified production database backup in hosting/cPanel and backup deployed project files. `php artisan migrate:status` and `php artisan migrate --pretend` first. Apply only the vetted, compatible source patch after staging tests and independent financial sign-off; do **not** import the staging reconstructed SQL to production. Fixation approval writes must be performed through the application's existing workflow, with operator identity and audit. Do not deploy until missing inputs and all parity/posting tests are closed.

## Rollback

Source backups are automatically stored in `$Stage\_kau_step11_backup_<timestamp>`; restore their project-relative paths and remove files listed as `new file` in its `backup_inventory.txt`. The staging DB should be dropped and recreated from the original pre-test dump rather than trying to reverse financial rows with DELETE statements. Production financial histories must never be overwritten with an old SQL dump.
