# KAU EMS Step 06 — Installation, Staging Verification & Rollback

**Release classification: Staging candidate; NOT production-ready.**

## Scope
Patch-only, project-relative files. Does not include `vendor`, `node_modules`, `.env`, SQL dumps, user data, historical bills, or compiled Vite assets. No new migrations or seeders and no role restrictions.

## Before applying
1. Take an application source backup or commit the complete currently running source to Git.
2. Export a complete MySQL backup (`mysqldump --single-transaction --routines --triggers --events ...`) from your own authorized environment. Use your actual connection details from the `.env` safely; never paste credentials into chat.
3. Ensure `app(5).zip` corresponds to your latest project source (source hashes/working tree). If local work has changed since zip was uploaded, merge manually; do **not** blindly overwrite newer changes.
4. Complete the manual reconciliation flagged by `php artisan kau:review-october-arrear-rules`. Avoid posting actual October payroll until the published arrear rules are consistent.

## Install (Windows PowerShell)
```powershell
cd "D:\Projects\KAU EMS\kau-app-new"
# Extract patch-only ZIP into project root, preserving folder structure.
# Check modified files in Git / backup comparison before running.
php artisan migrate:status
php scripts/step06_bulk_smoke.php
node --test tests/js/salaryBulkStep06.test.mjs
php artisan test --filter=SalaryBulkStep06Test
php artisan test --filter=SalaryBulkStep06IsolationTest
php artisan test --filter=SalaryPayScaleArrearEngineStep05Test
php artisan test --filter=SalaryEmployeeComponentOverrideStep03Test
php artisan test --filter=SalaryStatementBreakdownStep04Test
php artisan test --filter=SalaryCalculation
php artisan test --filter=SalaryBill
php artisan kau:review-october-arrear-rules
npm ci
npm run build
php artisan optimize:clear
```
The test with MySQL must run **only** against a disposable, local database with a name matching `kau_*_test` or `kau_*_staging`. Tests may explicitly skip on any other connection. Do not force these tests against production.

## How to use
1. Visit **Review & Generate Salary Bills**, select Year/Month and relevant filters.
2. Click **Preview All / Select**; wait until the count equals total candidates. The UI requests 25 employees at a time without any posting.
3. Inspect CURRENT/ARREAR/TOTAL and reasons. For a blocked employee choose **Edit**, save an override in the existing single Salary Calculator, then click **Recalculate** on the preview row. No global component or other employee is changed.
4. Select the evidence-complete ready rows (default: all ready). Use **Export Exceptions CSV** to reconcile the rest.
5. Click **Generate Selected** and confirm. The backend will verify each previously displayed `source_hash` inside the transaction and post at most 25 per request; a mismatch blocks only that employee.
6. Resume interrupted runs by the shown UUID. Download result CSV. Saved Salary Reports remain accessible using existing reporting routes.

## Acceptance criteria
- 1, 10, 15, 16 and 423 selected employee simulations (where available); preview remains read-only.
- All filtered candidates are represented, not only the first 25.
- Single Calculator and bulk summary agree for each hash-bound ready employee (Basic, allowances, deductions, arrear, GPF and Net).
- Changing a monthly override between preview and generation blocks the stale source, without affecting other employees.
- Correct skip when bill already exists; no duplicate GPF posting; atomic employee transaction preserved.
- Repeated 25-person next() resumes without duplicating bills.
- Proper treatment of UNKNOWN values (not `0.00`), CSV injection controls, and no role-based approval checks.
- No actual payroll generation until the staged financial audits and MySQL integration tests pass.

## Rollback
- If no salary was generated, revert exactly the modified project files from the source backup/Git; rebuild assets and clear caches.
- **Do not drop database tables or roll back historic payroll after generation.** If any bills were generated, keep their source/GPF ledger records and perform an audited financial correction instead of SQL deletion.
- Old batch tokens can still be resumed; new batch `start()` requires preview hashes. Reverting code during an active batch may change this contract; finish or freeze the run before rollback.

## Namecheap deployment (only AFTER staging acceptance)
- Confirm server PHP extensions, MySQL version, application permissions and Composer dependencies before uploading.
- Keep `.env`, secret keys, DB credentials, vendor and uploads intact; deploy only reviewed PHP/JS code and locally generated `public/build` assets (if Node is unavailable on shared hosting).
- Use maintenance mode for a controlled deployment if needed, run `php artisan optimize:clear`, test one read-only employee preview and monitor Laravel logs.
- No `php artisan migrate` is required by Step 06. Do not run `db:seed` or `migrate:fresh`.
- Keep bank advice/export and bulk financial generation disabled operationally until financial reconciliation and staging posting checks pass.
