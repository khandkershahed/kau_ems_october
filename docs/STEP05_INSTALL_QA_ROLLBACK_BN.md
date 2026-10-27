# KAU EMS — Step 05: Pay Scale–Based Arrear Engine

Date: 2026-10-26 | Status: **STAGING CANDIDATE — NOT PRODUCTION READY**

## Audit basis

- Newest `app(4).zip`, already installed Steps 01–04 as present in source.
- Latest accessible SQL snapshot: `kau-app-local(2).sql` from the earlier project turn; **NOT a current/live MySQL connection**.
- Windows Step 04 migration/unit/Vite output supplied by the user: migration 01/02 ran, Step 04 unit tests passed, build passed; **database-dependent tests skipped**.
- Original `updated payscale 2026(1).pdf`: page 1–2 phased entitlement, page 14 housing rates introduced 2028-01-01. This Gazette is the source for percentage phases, not proof of any employee's historic payments.

## Files changed

- `app/Services/SalaryPayScaleArrearEngine.php` NEW: pure entitlement/reconciliation per month, 2015-stage reference, effective phase audit, signed housing and GPF anomaly handling.
- `app/Services/SalaryArrearRuleService.php`: new scale-related formula token `OLD_SCALE_INCREMENT`; July stage vs saved July salary; dated revised Basic; missing benefits not silently zero; signed housing direction; do not apply undated global housing publications.
- `app/Services/SalaryArrearCalculator.php`: shares the pure month-wise reconciler.
- `app/Support/SalaryArrearTemplateCatalog.php`: five correct post-review formulas, no double month multiplier.
- `app/Console/Commands/ReviewOctoberArrearRules.php`: more informative read-only before/after formula dry run and existing opt-in audited revision action.
- `app/Http/Controllers/SalaryEmployeeComponentOverrideController.php`: removes the dead `authorizePayroll()` role gate and call. **Existing auth middleware is untouched.**
- `app/Http/Controllers/SalaryCalculatorEntryController.php`: accepts component `code` in live formula preview.
- `resources/js/Pages/Salary/Calculator/Entry.vue`: preserves existing UI, displays estimated old-scale deduction distinctly, refreshes source/direction without forcing navigation.
- `tests/Unit/SalaryPayScaleArrearEngineStep05Test.php`: pure calculation cases.
- `tests/Unit/SalaryArrearMeetingMethodTest.php`, `tests/Unit/KauOctoberArrearPolicyPatchTest.php`: existing template assertions updated.
- `scripts/step05_scale_arrear_smoke.php`: standalone PHP executable checks; no DB connection or posting.

## Source policy

1. October 2026 regular payroll activation policy **unchanged**; legal phased entitlement begins July 2026, full phase July 2027.
2. Exactly three arrear months for original July–September 2026 statement.
3. For Basic: `SUM_MONTHS(REVISED_BASIC - JUNE_BASIC)`; reconstructed old-scale stage increment: `SUM_MONTHS(OLD_SCALE_INCREMENT)`; net Basic difference is gross minus old-scale increment **once**.
4. Special Benefit: `SUM_MONTHS(PAID_SPECIAL_BENEFIT)` — requires actual *saved approved bills* for each month or an evidence-backed manual override; September missing bill remains UNKNOWN.
5. Housing: `SUM_MONTHS(OCTOBER_HOUSE_RENT - JUNE_HOUSE_RENT)`. A positive signed difference is payable; negative is a deduction. A verified October housing entitlement is still required; January 2028 rate may not be automatically used in 2026.
6. GPF: `SUM_MONTHS(OCTOBER_GPF - JUNE_GPF)` only when employee-specific approved contribution reference exists. A negative Additional GPF is **not** automatically refunded.
7. Reconstructions are *not bank settlement proof*. Unverified July old-stage increment appears as `estimated_amount` with `calculated_amount=null`; an evidence-based manual final amount is possible.
8. Any authenticated payroll UI user can access Step 03 override endpoint as governed by existing route middleware, **without role-name checks**. No compulsory HR stage introduced. Core duplicate-bill/transaction protection stays.

## IMPORTANT: Review published arrear rule changes

The supplied SQL snapshot's existing five published formulas are still the *legacy* formula strings. The patch **does not change them automatically**. The versioned Artisan command must be reviewed then explicitly applied by the operator. It requires a real actor user ID and an evidence/reference string for the financial audit event, **NOT a role**.

### Staging: backup first

```powershell
$Project = 'D:\Projects\KAU EMS\kau-app-new'
$Backup = 'D:\KAU_Step05_Backup_' + (Get-Date -Format 'yyyyMMdd_HHmmss')
New-Item -ItemType Directory -Path $Backup -Force | Out-Null
Copy-Item -LiteralPath $Project -Destination (Join-Path $Backup 'project') -Recurse -Force
# Export staging MySQL with known account/database, e.g.:
# mysqldump --single-transaction --routines --triggers -u YOUR_DB_USER -p --result-file="D:\KAU_staging_before_step05.sql" YOUR_STAGING_DB
```

Copy only patch ZIP project-path files into the project after comparing your current repository version and saving your backup.

### Staging: run

```powershell
cd 'D:\Projects\KAU EMS\kau-app-new'
php artisan migrate:status
php artisan kau:review-october-arrear-rules
php scripts/step05_scale_arrear_smoke.php
php artisan test --filter=SalaryPayScaleArrearEngineStep05Test
php artisan test --filter=SalaryArrearMeetingMethodTest
php artisan test --filter=KauOctoberArrearPolicyPatchTest
php artisan test --filter=SalaryArrear
php artisan test --filter=SalaryCalculation
php artisan test --filter=SalaryEmployeeComponentOverride
php artisan test --filter=SalaryBill
npm ci
npm run build
php artisan optimize:clear
```

**Only after** the five original formulas, months, employee calculations and audit reference are confirmed on a disposable staging DB, explicitly apply reviewed rule revision:

```powershell
php artisan kau:review-october-arrear-rules --apply --actor=REPLACE_WITH_EXISTING_USER_ID --evidence="REPLACE_WITH_APPROVED_RULE_REFERENCE"
php artisan kau:review-october-arrear-rules
```

The revision is idempotent when correctly applied. The command refuses unexpected independently edited rows, duplicates, or absent tables. No arrear payment or GPF posting occurs here. The previous historical rule state is recorded in `salary_rule_events.before_value`.

### Acceptance tests that require Windows/staging MySQL

- Live July/September saved bill source, missing evidence and approved fixation schedule per employee.
- Published rule **dry-run before/after**, then calculate both named test employees with approved stages.
- Single/Salary Setup/Bill Composition parity on employee-month with and without monthly overrides.
- Monthly promotion/Study Leave/AD HOC/inc hold inputs, July stage comparison.
- House Rent legal category vs effective date, October 2026 rate **not** Jan 2028 rate.
- Regular vs Additional GPF separation, no GPF ledger write during preview.
- Explicitly confirmed arrear rule revision, save calculation, then duplicate-posting/idempotency test on **disposable staging only**.
- Full Vue/Vite build and browser UI estimated amounts and `Recalculate formulas` without data loss.

If any fails, do not deploy/pay arrears.

## Namecheap production — only after staging acceptance

1. Backup Namecheap code, `.env`, DB, `public/build`, and existing salary snapshot and rules; verify a restore copy.
2. Merge reviewed patch through Git; deploy source, then `git pull origin main` on the hosting account. Avoid changing `.env` or uploading `node_modules`.
3. `php artisan migrate:status` (no Step 05 migration); `php artisan optimize:clear`; `php artisan config:cache`; `php artisan route:cache`; `php artisan view:cache`.
4. Upload **staging-built verified `public/build`** if Namecheap has no Node. Otherwise use `npm ci && npm run build` only when supported.
5. Dry run `php artisan kau:review-october-arrear-rules` against production rules, verify against backup. Do not automatically apply. If fully approved, run the explicit audited `--apply` command with real actor/reference.
6. Read-only smoke check a few single employees, one with no July increment, one with verified July and one missing September. Do not create bill/bank advice during smoke test.

**Do not run any seeders.** This patch adds no new migration. The September snapshot is not regenerated.

## Rollback

- Stop salary posting, preserve logs and snapshots; restore only modified source files from the pre-install backup or Git revert the exact Step 05 commit. Rebuild matching Vite assets; clear caches.
- If the five rules were **applied**, reverting PHP files alone is not a full rollback. Use the prior values recorded in `salary_rule_events.before_value`, and review a documented database restoration transaction on staging before applying in production. Never truncate salary/gpf/history tables, and never delete audit events.
- If an arrear statement or salary bill has already been posted after the rule change, do NOT silently revert rule values or payment. Perform a formal accounting correction referencing affected employee/month/payment records.

## Limitations

No live MySQL integration, feature tests, full Laravel PHPUnit or Vite build ran in this tool environment. The patch is **not production-ready** until these pass on Windows staging with an isolated MySQL clone. No authorization based on role names was added.
