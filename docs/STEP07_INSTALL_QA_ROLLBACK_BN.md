# Step 07 — Installation, Staging, Namecheap & Rollback

## Scope and priority
The patch **only** updates Salary Bill and Arrear report presentation and read-only reporting sources. It also fixes the genuine Step 06 `GenerateBills/Index.vue` parse error. Do not overwrite other app files with older Step 06 or earlier ZIPs. No new migration/seeder. Keep existing auth login but **no role-based payroll HR restriction**.

## 1. Backup first (Windows PowerShell)

```powershell
cd "D:\Projects\KAU EMS\kau-app-new"
$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$backup = "D:\Projects\KAU EMS\backups\Step07_$stamp"
New-Item -ItemType Directory -Force $backup | Out-Null
Copy-Item -Recurse app,config,resources,routes,tests,scripts $backup
# Export local MySQL DB using your real staging DB credentials / preferred backup UI.
# Ensure the backup is restorable; do not place secrets in a shared chat.
```

## 2. Install
Extract the PATCH ZIP **into the Laravel project root**, keeping relative directories (`app`, `config`, `resources`, `routes`, `scripts`, `tests`). Review changed files first; ZIP contains only changed/new files and docs. No `php artisan migrate` is needed for Step 07.

## 3. Staging tests (PowerShell)

```powershell
cd "D:\Projects\KAU EMS\kau-app-new"
php -l app\Services\SalaryOfficialReportSource.php
php -l app\Http\Controllers\SalaryOfficialReportController.php
php -l app\Http\Controllers\SalaryReportController.php
php -l routes\web.php
php scripts\step07_report_smoke.php
php artisan route:list --path=salary-official
php artisan test --filter=SalaryOfficialReportSourceStep07Test
php artisan test --filter=SalaryOfficialReportStep07FeatureTest
php artisan test --filter=SalaryBill
php artisan test --filter=SalaryArrear
php artisan test --filter=SalaryBulk
npm ci
npm run build
php artisan optimize:clear
```

**Expected Step 06 blocker correction:** Vite should no longer fail on `GenerateBills/Index.vue:1498` because its malformed `:href` expression has been replaced. Do not assume other build errors are absent until the actual Windows build passes.

## 4. Browser acceptance (on isolated staging clone)
1. Log in; open Report Factory; choose `Salary Bill`, select exactly one employee with an already **generated/posted bill**. Click `Print A4`, verify embedded preview and generated A4 portrait document match.
2. Select 10, 15, 16 and 25 posted employees and open `Bulk Overview`. Verify A4 Landscape page counts, row count, repeated headings, page sums and grand total. Test custom rows/page (1–25).
3. Click `Excel` (XLSX), `CSV`, `PDF` and `Print A4`; compare Basic, Current, Arrear, Deductions, GPF and Net against frozen saved bill. Direct PDF is enabled only when server Chromium is configured; otherwise `PDF` opens the canonical HTML for browser Save as PDF.
4. Open `Arrear & Deduction Statement` for a posted October 2026 bill; ensure July–September amounts without frozen monthly allocation read `NOT ALLOCATED`, not zero. Check Special Benefit, House Rent and GPF are shown only where actually present in saved source.
5. Select an ungenerated employee: official printing must reject the request and never synthesize a posted bill.
6. Test old `SalaryGenerate` without `salary_bill_source_links`, and a modern bill with frozen `source_snapshot`. Any total mismatch must yield error, not falsely balanced report.
7. Before/after query counts in `salary_generates`, `general_provident_funds`, `salary_arrear_disbursements`, `salary_bill_arrear_entitlements`, `salary_fixations`, `salary_component_employee_overrides` and `salary_bill_source_links`: no changes from GET/print/export.
8. Run same report with missing/malformed frozen component and verify UNKNOWN is preserved without fake arithmetic.

## 5. Optional direct PDF renderer (Namecheap / staging)
`composer.json` does **not** include Dompdf/MPDF. This patch uses native HTML printing unconditionally. Direct PDF requires a real server-installed Chromium/Chrome executable. Verify whether your exact Namecheap plan supports running it, has a writable private storage temp directory, and supports PHP `proc_open`. If verified, configure in `.env`:

```ini
KAU_REPORT_CHROMIUM_BINARY=/absolute/path/to/chromium
```

Windows example (adjust to real installed binary):

```ini
KAU_REPORT_CHROMIUM_BINARY="C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe"
```

Then `php artisan config:clear`, open canonical HTML and test its `Download PDF` link on staging. If this fails or Chromium is unavailable, **leave the setting empty** and use `Print / Save as PDF`. Do not add `--no-sandbox` in production solely to bypass sandbox errors; investigate host sandbox support. Do not upload the local Chromium or font files to Namecheap via this patch. No new Composer dependencies are required.

## 6. Rollback
1. Disable optional renderer (unset `KAU_REPORT_CHROMIUM_BINARY`).
2. Restore the exact files in this patch from the backup in Step 1. Remove only the newly introduced report controller/service/config/Blade/tests/script if restoring to original state.
3. Run `npm ci`, `npm run build`, `php artisan optimize:clear` after restore.
4. No migration rollback or database data restoration is required for this Step 07 change itself because it contains no DB schema/data operations.
5. If unrelated payroll data changed during staging, restore from the independently validated database backup only after confirming which process caused it.

## 7. Release requirements
Do NOT state production readiness until PHP feature tests, staging MySQL no-write checks, browser/PDF visual QA, real employee comparisons and full Windows Vite build pass. Keep Step 05 published rule reconciliation separate: DO NOT auto-apply a financial rule update as part of Step 07.
