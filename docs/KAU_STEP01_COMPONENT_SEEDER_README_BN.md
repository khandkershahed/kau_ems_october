# KAU EMS — Step 01: Verified component rule readiness

**Scope:** The latest `app(3).zip` and `kau-app-local(2).sql` snapshot, dated 26 October 2026. No payroll or financial table was modified to prepare this package.

## What the evidence says

- 1,421 source `payroll_components` assignments; 749 active 2015 assignments all configured as `fixed` and `0.00`.
- 668 inactive 2026 `fixed` assignments with `0.00`; four active 2026 House Rent percentage assignments at `40.00`.
- `salary_component_publications` has zero records. It is keyed by component **type**, not grade/designation/effective date. Publishing a rate here can incorrectly affect other groups.
- Existing `DesignationSalaryComponentSeeder` may generate fallback grade mappings and zero-valued active components. Do not rerun it on production.
- Existing `DatabaseSeeder` generates demo/sample entities with factories. Never invoke `php artisan db:seed` without an explicit `--class` on a production database.

The 2026 Gazette contains phased Basic salary rules and a House Rent rate schedule effective from January 2028. It does NOT provide every employee-specific GPF amount, special benefit actually paid, tax subscription, or every designation's approved component configuration. Therefore this package **does not invent rates or bulk-enable zero placeholders**.

## Files

1. `database/migrations/2026_10_26_000001_create_salary_component_effective_rules.php`: new, separate, effective-dated configuration table; historical tables unchanged.
2. `database/seeders/KauVerifiedPayrollComponentRulesSeeder.php`: full repeat-safe, documented `APPROVED`/`EXCLUDED`/`PENDING` import; dry-run by default; transactional publication only after complete approval.
3. `database/seeders/data/KAU_payroll_component_rules_REVIEW.csv`: 1,421 rows, accurately mapped by pay scale, grade, designation, and component. The `source_*` fields are reference-only: `source_value=0.00` does NOT mean approved salary zero.
4. `database/seeders/data/KAU_payroll_component_rate_matrix_APPROVED.csv`: optional bulk approved-rule matrix, initially empty.
5. `scripts/kau_apply_approved_rate_matrix.py`: safely expands matching approved rules by grade and designation into the review inventory. Use `*` only for `grade` or `designation` after approving that group-wide rule.

## Review columns / policies

- `status`: `APPROVED`, `EXCLUDED`, or `PENDING`.
- `calc_type`: `fixed`, `percentage`, or `calculated` (Basic Pay only).
- `value`: must be explicitly sourced for Fixed/Percentage; may be **0** only when an approved source explicitly confirms 0. For Basic Pay, leave blank and set `calc_type=calculated`, `basis=payable_basic`.
- `basis`: `payable_basic` or `legacy_basic` for Percentage; empty/`none` for Fixed.
- `frequency`: `monthly`, `annual`, `event`, or `one_time`. Festival/bonus/event components must not silently enter each month's gross.
- `effective_from`/`effective_until`: rule entitlement dates. Do not use 2028 House Rent percentages for 2026.
- `evidence_reference`: approval order/HR reference; required for each `APPROVED` and `EXCLUDED` decision.

## Steps for Windows staging

### 1. Back up database and project files FIRST

Create SQL backup using your existing vetted database backup process. Take a copy of the current project before extracting this ZIP. Work in STAGING, not production.

### 2. Copy package to Laravel project root

Only new project-relative files are included. Existing classes, files, migrations and historical salary data are not replaced.

### 3. Review migration SQL before applying

```powershell
cd 'D:\Projects\KAU EMS\kau-app-new'
php artisan migrate:status
php artisan migrate --pretend --path=database/migrations/2026_10_26_000001_create_salary_component_effective_rules.php
```

After review and DB backup, on staging:

```powershell
php artisan migrate --path=database/migrations/2026_10_26_000001_create_salary_component_effective_rules.php
```

### 4. Prepare the HR-approved rate matrix

Open the review CSV in Excel/LibreOffice (do not use it to rewrite the SQL dump). For repeat rates, fill a matrix row per approved pay scale+grade+component (or more specific designation). Never use `*` unless one signed rule truly covers every matched designation.

Sample MATRIX structure (amounts below NOT provided intentionally):

```
pay_scale,grade,designation,component,direction,calc_type,value,basis,frequency,effective_from,effective_until,evidence_reference
```

Expand in staging:

```powershell
python scripts/kau_apply_approved_rate_matrix.py database/seeders/data/KAU_payroll_component_rules_REVIEW.csv database/seeders/data/KAU_payroll_component_rate_matrix_APPROVED.csv KAU_payroll_component_rules_EXPANDED.csv
```

Inspect the result, obtain approvals, and only then explicitly copy the reviewed file over `database/seeders/data/KAU_payroll_component_rules_REVIEW.csv`. Mark non-applicable items `EXCLUDED` with a reference, not zero.

### 5. Read-only Seeder preflight

```powershell
php artisan db:seed --class=KauVerifiedPayrollComponentRulesSeeder
```

This preflight reads the current DB and compares **all** source assignments. It will print how many are `APPROVED`, `EXCLUDED`, `PENDING`. It does not write rows by default.

### 6. Explicit application — ONLY after full review and approval

```powershell
$env:KAU_COMPONENT_SEED_APPLY='YES_I_APPROVED'
$env:KAU_COMPONENT_SEED_ACTOR_ID='PUT_REAL_ADMIN_USER_ID_HERE'
php artisan db:seed --class=KauVerifiedPayrollComponentRulesSeeder --force
Remove-Item Env:KAU_COMPONENT_SEED_APPLY
Remove-Item Env:KAU_COMPONENT_SEED_ACTOR_ID
```

Only an all-reviewed CSV (no `PENDING`) may be applied. Existing published rules are never overwritten with different values. MySQL advisory lock + DB transaction + unique indexes provide concurrency safety. Inserts are logged in `salary_rule_events`.

**No current payroll calculation reads the new rules table yet.** Step 2 must implement and test an effective-dated resolver that reads these rules before assigning payable components. This package by itself does not change current salary results, does not make 2026 payroll active, and must not be represented as a complete production payroll release.

## Recommended release steps (8)

1. **Step 01:** audit rules, gather approvals, populate effective-dated component configuration and safe seeder (this package).
2. **Step 02:** create centralized effective-dated resolver (Scale × Grade × Designation × Month × Frequency × Percentage Base).
3. **Step 03:** employee/month component-level overrides and `Reset to Automatic`; audit who changed what.
4. **Step 04:** update Single Salary Calculator to use resolver, phased Basic and Current/Arrear/Total display.
5. **Step 05:** revise July–September scale-based arrear with old-scale increment, benefit, housing and GPF reconciliation; fix `SUM_MONTHS`/`×3` mismatch.
6. **Step 06:** integrate same shared service in Bulk Salary Preview, edit, generation and exception report.
7. **Step 07:** canonical single/bulk Salary Bill and Arrear PDFs; verify print layout and pagination.
8. **Step 08:** end-to-end QA (MySQL staging, payroll parity, exceptions, duplicates, ledger and rollback), then approved deployment.

## Rollback

- Dry run: no rollback needed.
- If unpublished review CSV is edited: restore the CSV backup.
- For an applied rule set, do not blindly run `migrate:rollback`: the migration intentionally refuses to delete financial history. Restore staged DB backup or create an audited reversal that retains rule and event history. Production application code can leave the new table unused until Step 2 passes staging.

## QA status

- PHP syntax: checked on migration and seeder.
- CSV: inventory shape/count and source reconciliation against the supplied **offline** SQL dump checked.
- Bulk matrix script: syntax and smoke tests checked.
- Laravel migration against live MySQL: **NOT RUN**.
- Laravel Seeder in app runtime: **NOT RUN** (no vendor dependencies/live MySQL in this environment).
- End-to-end salary/bulk behavior: **NOT RUN**. It requires Steps 02–08.
