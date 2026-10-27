# KAU EMS — Step 10 September Recovery and October Payroll Release Gate

**Prepared:** 26 October 2026  
**Data source:** `kau-app(1).sql` (older MySQL 8.4 dump), `codershat_kau(2).sql` (current MariaDB 11.4 production export), `app.zip` (current uploaded source).  
**Status: STAGING RECOVERY / NOT PRODUCTION-READY.** This package is intentionally scoped; it does not fabricate fixation, payment approval or GPF evidence.

## 1. Exact verified findings

- Current production dump SHA256: `3cfa0809001bba3831d7c93fe53015a8f2386792e5f4e108435bea93e5ace723`. The prior uploaded `codershat_kau(1).sql` is byte-identical.
- Production: 5,659 salary bills; 404 approved June, 406 approved July, 412 approved August; 1 pending September, 0 approved September, 0 October.
- Old SQL: 6,070 salary bills; 412 pending September, 0 approved September, 5 September zero amounts.
- Of 412 old September records: 405 match the relevant **current approved August** financial fields, 5 can be reconstructed from the same employee's approved August record, 1 existing September conflict (`20057`), 1 absent approved August reference (`1003`).
- Five employee codes with zero old September: `40033`, `40158`, `10108`, `10046`, `20029`; their restored **pending** September records reproduce August allowances, deductions, gross, net, GPF and other stored financial columns. User confirmed August=September salary for those five; this does not certify bank disbursement.
- Special case `20057`: old September draft net ৳31,180, production September draft net ৳51,564.50, with October-style Basic/arrear components in the production record. **Do not overwrite**; investigate with official payroll source.
- Special case `1003`: old September pending net ৳108,160, with no approved August reference in either dump; **do not auto-import**. This is an active temporary employee; manual reconciliation is required.
- Exactly 410 new **pending** September salary drafts are eligible for guarded import into an empty *clone* of current production. The existing pending `20057` remains. After staging import, September pending count should be 411 (unless clone has changed), with 0 new approved records.
- The current uploaded `app.zip` **contains** `app/Services/SalaryStructuredArrearService.php`; October structured path is in `SalaryArrearRuleService::preview`. Previous archived source absence is no longer applicable.
- However `SalaryStructuredArrearService::sources()` lacks verified `financial_months` House Rent/required GPF sources; the old September salary drafts cannot fill this entitlement requirement. There are only 1 approved fixation and 0 component publications in current production snapshot. Current production is missing two effective-rule/employee override migrations that had reportedly run locally.
- Current production last audit: 423 employee profile rows / 421 distinct profile users; 413 active candidate payroll users including one profile orphan; HR eligibility unverified; 1 approved fixation; 377 fixation reference report rows covering 376 unique employees; 1,421 payroll component assignments; 0 published component rules.

## 2. Patch contents (project-relative files)

- `resources/js/Pages/Salary/Calculator/Entry.vue` — UI shows `0.00` for null monetary cells, never changes actual `null` values; new CSV Source Status column and warning strings.
- `resources/js/utils/salaryDisplayAmount.js` — pure presentation-only helper.
- `database/seeders/Step10SeptemberDraftRecoverySeeder.php` — guarded, atomic, idempotent, staging-only pending salary recovery.
- `database/seeders/data/step10_september_draft_recovery.json` — 410 source-backed rows with provenance and August reference IDs (contains employee payroll data, keep private).
- `tests/js/salaryDisplayAmountStep10.test.mjs` — 3 frontend tests: zero display vs null evidence, posting UI guard and CSV markers.
- `scripts/step10_september_recovery_offline_qa.php` — pure offline 410-row count, duplicate/month, five-corrections, arithmetic checks.
- `sql/KAU_STEP10_STAGING_ONLY_September_Draft_Recovery.sql` — alternative to seeder: 410 guarded `INSERT ... SELECT ... WHERE NOT EXISTS`, staging database guard, one transaction, all pending.
- `docs/STEP10_SEPTEMBER_RECOVERY_AND_RELEASE_GATE_BN.md` and SHA256 manifest.

**Do not run both the SQL import and the seeder in the same staging clone.** Both are idempotent for the same employee/month, but running once is clearer for audit.

## 3. Windows safe staging install (PowerShell)

Run on a **disposable staging copy**, never on Namecheap production. Before any import, take a separate production database backup and source ZIP backup.

```powershell
$Project = 'D:\Projects\KAU EMS\kau-app-new'
$Patch = 'D:\Projects\KAU EMS\Step10_Patch'
Set-Location $Project

# Place the extracted patch ZIP into $Patch beforehand.
# Confirm hashes against SHA256_MANIFEST.txt before copying.
New-Item -ItemType Directory -Force -Path '.\_step10_backup' | Out-Null
Copy-Item 'resources\js\Pages\Salary\Calculator\Entry.vue' '.\_step10_backup\Entry.vue' -Force
Copy-Item "$Patch\resources\js\Pages\Salary\Calculator\Entry.vue" 'resources\js\Pages\Salary\Calculator\Entry.vue' -Force
Copy-Item "$Patch\resources\js\utils\salaryDisplayAmount.js" 'resources\js\utils\salaryDisplayAmount.js' -Force
Copy-Item "$Patch\database\seeders\Step10SeptemberDraftRecoverySeeder.php" 'database\seeders\Step10SeptemberDraftRecoverySeeder.php' -Force
Copy-Item "$Patch\database\seeders\data\step10_september_draft_recovery.json" 'database\seeders\data\step10_september_draft_recovery.json' -Force
Copy-Item "$Patch\tests\js\salaryDisplayAmountStep10.test.mjs" 'tests\js\salaryDisplayAmountStep10.test.mjs' -Force
Copy-Item "$Patch\scripts\step10_september_recovery_offline_qa.php" 'scripts\step10_september_recovery_offline_qa.php' -Force

php scripts\step09_structured_arrear_smoke.php
php scripts\step10_september_recovery_offline_qa.php
node --test tests\js\salaryDisplayAmountStep10.test.mjs
```

Clone the dump to a newly created `kau_step10_staging` database. The combined file `codershat_kau_STEP10_STAGING_RECONSTRUCTED.sql` contains the unchanged production baseline plus safe pending recovery; import it **into an empty staging database only**, and do not also run the seeder. In Windows CMD, after configuring the MySQL client path:

```cmd
mysql -u root -p -e "CREATE DATABASE kau_step10_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p kau_step10_staging < "D:\Projects\KAU EMS\codershat_kau_STEP10_STAGING_RECONSTRUCTED.sql"
```

**Alternative**: Restore the unchanged `codershat_kau(2).sql` into staging; set your local `.env` `APP_ENV=local` and `DB_DATABASE=kau_step10_staging`, then run the guarded seeder:

```powershell
php artisan migrate:status
$env:KAU_STEP10_ALLOW_SEPTEMBER_DRAFT_SEED='1'
php artisan db:seed --class=Step10SeptemberDraftRecoverySeeder
Remove-Item Env:\KAU_STEP10_ALLOW_SEPTEMBER_DRAFT_SEED
```

The seeder deliberately refuses database names without `staging` or `_test`, and refuses application environments other than local/staging/testing. It is not registered in `DatabaseSeeder` and cannot be triggered by a routine default `db:seed` call.

Staging MySQL checks after import:

```sql
SELECT monthId, reviewStatus, COUNT(*) AS bill_count
FROM salary_generates
WHERE salaryGenerationYear=2026 AND monthId IN ('8','9','10')
GROUP BY monthId, reviewStatus;
SELECT employeeCode, totalAllowance, totalDeduction, salaryTotal, gpfDeduction, reviewStatus
FROM salary_generates
WHERE salaryGenerationYear=2026 AND monthId='9'
  AND employeeCode IN ('40033','40158','10108','10046','20029','20057','1003')
ORDER BY employeeCode;
```

## 4. Mandatory financial readiness before October payroll

**Legacy September draft recovery is not proof of September payment.** `SalaryArrearCalculator::paidFromPayroll()` accepts only approved records, and rightly rejects these restored pending ones. Never automatically mark all 410 as approved or invent Special Benefit/GPF received. For missing September disbursement records, obtain actual bank/payment/GPF vouchers or documented individual authorized confirmation using the existing source-override/payment-confirmation workflow.

1. **Schema:** On staging only, inspect and apply verified project migrations for `salary_component_effective_rules` and `salary_component_employee_overrides`; check Laravel `migrate:status`. Production SQL snapshot lacks these and the override events table. Never use `migrate:fresh`.
2. **Fixation:** 1 employee has an approved fixation. For other eligible employees, use 2015 stage, approved June basic, Gazette grade rule, increment hold/ad hoc/study leave/promotion events, HR-supported 2026 reference, then independently verify/approve employee-specific fixation. The 377 reference rows are candidates, not automatically approved fixations.
3. **Components:** No published component rule exists in the production snapshot; do not publish all 1,421 assignments or fixed zero placeholders. Configure official grade/designation/effective-date inputs first; check per-employee overrides and conflicting 2015-vs-2026 defaults.
4. **Arrear:** Reconcile July–September month by month. Basic entitlement does not require September salary. Old increment must be deducted once only. Actual paid Special Benefit/GPF and entitled versus paid House Rent require separate evidence. Never substitute January 2028 rent percentages.
5. **Bulk parity:** Compare 1, 10, 15, 16, 25, then all eligible employees across Single, Bulk, frozen bill, CSV and printed PDF. Validate duplicate-bill/arrear prevention, GPF ledger and bank advice on disposable MySQL clone with atomic rollback checks.
6. **Release:** Need Composer PHPUnit tests, `npm ci`, `npm run build`, isolated MySQL posting tests, PDF QA, Namecheap hosting capabilities, and security/dependency audit. No production sign-off is possible from offline analysis alone.

## 5. QA honestly recorded

| Check | Result |
|---|---|
| Old vs current SQL employee September comparison | PASS — 412 classified; 410 safe draft candidates and 2 manual exceptions |
| Five specified zero employees reconstructed to approved August financial amounts | PASS — calculated payload |
| PHP recovery seeder syntax | PASS — `php -l` |
| Offline recovery fixture QA | PASS — 410 entries, 5 corrections, no duplicate or approved rows |
| Node frontend test | PASS — 3 tests |
| Existing Step 09 smoke test | PASS — 10 pure checks |
| Actual Laravel PHPUnit suite for new source | NOT RUN (vendor/autoload.php absent) |
| Offline npm install/build | FAILED / NOT RUN (`ENOTCACHED`, missing cached `yargs-parser` package) |
| MySQL staging recovery import and posting tests | NOT RUN (database server unavailable in audit environment) |
| PDF/print visual test / final bulk payroll | NOT RUN |

**Security:** The combined SQL and seeder data include employee payroll/personally identifying information from uploaded dumps. Keep local, password-protect backups, never commit to a public repository, and do not upload to unrelated services.

## 6. Rollback

For source-only UI: restore `Entry.vue` from `_step10_backup`, remove the added pure helper, seed data, seeder, test and smoke script if not needed, then rebuild frontend. For staged salary recovery, revert to the pre-import staging database backup; do not delete any actual historical employee rows in a live DB. No rollback SQL that deletes salary/GPF data is provided. For production deployment, obtain an approved DB migration/version plan and a tested rollback on a separately cloned dataset first.
