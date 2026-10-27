# Step 08 — Audit and corrective patch (Bangla)

## Scope and limitations
Source: latest uploaded `app(7).zip`. User log indicates both migrations 2026_10_26_000001 and _000002 Ran. The latest live database dump, isolated test MySQL and actual hosting SSH were NOT supplied/accessible in this turn. Therefore published-rule contents cannot be independently verified beyond the provided UI and earlier command output. This patch does not change arrear formulas or posting logic.

## Confirmed defects
1. Five published rules on the screenshot still show SUM_MONTHS expressions; some multiply a 3-month SUM_MONTHS by 3 again. This is incorrect and source of ARREAR_PERIOD_POLICY_MISMATCH.
2. `ReviewOctoberArrearRules` refuses update because BASIC_INCREMENT_ADJUSTMENT was edited independently; this behavior correctly protects against silent overwrite.
3. New SalaryPayScaleArrearEngine is pure but SalaryArrearRuleService::preview still evaluates published formula records; there is no automatic replacement by pay-scale calculation. Therefore Step 05 was not fully wired in.
4. July–September September bills are largely missing (earlier SQL snapshot). Pay-scale-derived entitlement can be computed without them, but actual paid Special Benefit, House Rent and GPF cannot be proven from pay scale alone.
5. Step 01 rules: last known 0 approved, 1421 pending. Percentage calculations may rely on old employee JSON fallback.
6. User log: npm ci and Vite production build passed for Step07; `SalaryOfficialReportSourceStep07Test` 7 passed and feature test 2 passed. `SalaryArrear` suite 1 FAILED because pdo_sqlite missing; 3 skipped. Financial MySQL feature tests remain unverified.

## This corrective patch
- Adds read-only `kau:audit-arrear-rule-readiness` returning nonzero status if published rules require review; never edits data.
- Makes published formulas advanced/read-only in Salary Calculator; labels the source in human language and preserves documented final-amount overrides.
- Makes raw formula editing advanced in Arrear Settings, retaining old fields/routes/data structures.
- Adds pure PHPUnit coverage for rule readiness.
- **Not** a replacement pay-scale arrear engine. No hidden migration, seeder or salary/GPF writes.

## Required additional implementation before launch
- Resolve five published rules with documented before/after financial comparison and explicit approved correction, especially independently edited BASIC_INCREMENT_ADJUSTMENT.
- Build a first-class pay-scale entitlement *source adapter* for `SalaryArrearRuleService` using approved fixation and 2015 stage (independent of missing monthly salary bills); keep actual payment reconciliation separately for recoveries.
- Add admin-selectable manual evidence/amount for each component, preserving existing Step03 and arrear overrides.
- Test single/bulk/posted report parity on disposable MySQL; reconcile 1,10,15,16,25, all eligible employees.
- Verify real Namecheap PDF rendering and review npm audit advisories; do not auto-update dependencies.

## Windows staging steps (DO NOT RUN ON PRODUCTION)
1. Backup code + database.
2. Extract patch-only ZIP over project root on staging.
3. `php -l app/Support/SalaryArrearRuleReadiness.php`
4. `php -l app/Console/Commands/AuditArrearRuleReadiness.php`
5. `php artisan kau:audit-arrear-rule-readiness` (READ ONLY, exit code 1 when review is required)
6. `php artisan test --filter=SalaryArrearRuleReadinessStep08Test`
7. `php artisan test --filter=SalaryArrear`
8. `npm ci && npm run build`
9. `php artisan optimize:clear`
10. Visually inspect Salary Calculator and Arrear Settings and compare its full values with frozen source.

## Deployment / rollback
Do not deploy until independent MySQL financial tests pass. Namecheap hosting details unknown; never assume Chromium or proc_open availability. Restore the four changed/new source files and two Vue files from code backup if needed; there is no migration to roll back. This package does not affect financial records.

## Verdict
NOT PRODUCTION READY. Outstanding: financial rule reconciliation, unresolved entitlement vs actual payment adaptation, actual staging financial integration and PDF/hosting verification.
