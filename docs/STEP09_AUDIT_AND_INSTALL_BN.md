# KAU EMS Step 09 — Structured Pay Scale Arrear

## Scope and audited baseline

The newly provided `app.zip` contains the Step 08 read-only readiness audit. Previous Windows logs show five published rules with WRONG_ARREAR_PERIOD and (for four) DOUBLE_MONTH_MULTIPLIER. The SQL dump was not provided in this latest message: live amounts, publishing changes, and source evidence cannot be independently confirmed here.

**Important:** The original published formula rows remain untouched. For *October 2026 only*, the five standard arrear codes route to the new `SalaryStructuredArrearService`, which does not evaluate the stored spreadsheet expressions. Custom rules and other months remain on existing logic. This change is intentional and requires staging approval because historical calculation versions using old expressions will differ.

## Financial design

- `BASIC_ARREAR_GROSS`: sum over July, August, September of `(approved phased Basic - approved fixation old_basic)`.
- `BASIC_INCREMENT_ADJUSTMENT`: reconstructed next 2015 pay-stage increment multiplied by **each eligible month**. The reconstruction is an entitlement reference, not proof of a payment.
- `SPECIAL_BENEFIT_RECOVERY`: sum actual approved historical benefit records for each month; missing records => UNKNOWN.
- `HOUSE_RENT_RECOVERY`: sum `(paid House Rent - entitled House Rent)`. Positive means recovery, negative becomes payable. House Rent entitlement must be specifically evidenced; the January 2028 percentage is not applied to 2026.
- `ADDITIONAL_GPF`: sum `(required GPF - already deducted GPF)`, where requirements must have employee-approved evidence. Missing values and negative/refund situations stay UNKNOWN.
- Manual evidence overrides in the existing Salary Calculator remain available. No direct salary / GPF posting is added.

## New/changed project files

1. `app/Services/SalaryStructuredArrearService.php` — new pure calculator + read-only DB source resolver.
2. `app/Services/SalaryArrearRuleService.php` — wrapper to choose structured standard October vs unchanged legacy custom/other-month evaluator.
3. `resources/js/Pages/Salary/Calculator/Entry.vue` — clarifies structured source and archived formula.
4. `resources/js/Pages/Salary/Arrear/Settings.vue` — clarifies that old formulas are audit references for October standard codes.
5. `tests/Unit/SalaryStructuredArrearStep09Test.php` — 5 PHPUnit cases.
6. `scripts/step09_structured_arrear_smoke.php` — pure PHP checks.

## Windows staging steps

Back up application files and the MySQL database FIRST, then extract **only** the patch project paths into the Laravel project root. Do not overwrite `.env`, vendor, storage, source SQL data, or history.

```powershell
cd "D:\Projects\KAU EMS\kau-app-new"
php artisan migrate:status
php scripts/step09_structured_arrear_smoke.php
php artisan test --filter=SalaryStructuredArrearStep09Test
php artisan test --filter=SalaryPayScaleArrearEngineStep05Test
php artisan test --filter=SalaryArrear
php artisan test --filter=SalaryCalculation
php artisan test --filter=SalaryBill
php artisan test --filter=SalaryBulk
php artisan kau:audit-arrear-rule-readiness
npm ci
npm run build
php artisan optimize:clear
```

`kau:audit-arrear-rule-readiness` is a historical rule audit. It can still return REVIEW_REQUIRED for the saved legacy formulas, which remain unchanged. After this patch that audit is not a reliable indicator of whether structured October preview works. Inspect the actual automatic row calculation in Salary Calculator.

## Financial staging acceptance

- Test Grade 6 and Grade 10 employees whose approved July schedule and June old_basic are present.
- Confirm July–September Basic amounts are calculated **without a September salary bill**.
- Check `BASIC_INCREMENT_ADJUSTMENT` against the verified 2015 grade/stage and eligibility; never confuse reconstructed with actual paid.
- Verify any one-month grade/promotion adjustment by checking every month's approved fixation schedule independently.
- With missing Special Benefit/House Rent/GPF payment evidence, only those rows should say UNKNOWN; test explicit manual evidence overrides and save draft.
- For an approved October version saved under old formulas, expect source drift and require a new calculation version; do not silently reuse it.
- Run Single and Bulk preview; ensure same source hash and amounts. Test posting only in a disposable isolated staging MySQL clone.
- Do not automatically execute a seeder or SQL UPDATE to published arrear rules.

## Rollback

Restore the four modified project files from the pre-patch backup, remove the one new service and associated test/smoke files, rebuild Vite assets and clear Laravel cache. No migration rollback is necessary and no data migration was made. Existing saved calculation versions and historical records are preserved.

## Namecheap guidance

Deploy only after the above staging tests and actual hosting PHP/Composer/permission checks succeed. Verify asset compilation separately and upload the built assets. Namecheap PDF rendering is outside Step 09; no Chromium assumption is made.
