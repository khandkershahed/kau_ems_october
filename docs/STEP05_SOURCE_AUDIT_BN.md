# Step 05 — Actual Source Audit (app(4).zip)

## Evidence

- `app(4).zip` extracted and relevant Laravel/Vue source inspected directly.
- The latest accessible SQL **snapshot**, `kau-app-local(2).sql`, inspected read-only. It contains legacy five published arrear rules; it is not the live database after Step 04.
- `updated payscale 2026(1).pdf` pages 1–2: effective phased pay from July 2026, 40%/50% in first phase, 70%/75% second phase, full July 2027; page 14: revised housing rates are effective January 2028. Actual employee approvals/payments cannot be inferred from this PDF alone.
- Step 04 user Windows test log shows migration completed and unit/Vite passed; MySQL-dependent tests skipped. No assumption that live Namecheap matches staging.

## Main code sources and defects

1. `SalaryArrearRuleService::preview()` previously used `SUM_MONTHS` templates referencing `OCTOBER_BASIC`, `JULY_INCREMENT` and `JULY_SPECIAL_BENEFIT`; five SQL published rules in the snapshot still had a trailing `* 3`, causing period and double-multiplier errors. Fixed only the **template source** and exposed the existing audited revision command; no database values changed.
2. `SalaryArrearCalculator::calculateMonth()` independently reconciled saved historic bills. `SalaryPayScaleArrearEngine` is now the shared pure pay-scale month-wise reference with an entry point from `SalaryArrearCalculator`; no competing posting pathway created.
3. `ApprovedFixationResolver` selects approved dated `SalaryFixationPaySchedule` rows from July 2026. Do not overwrite its Basic or use globally active PayScale to infer payable entitlement.
4. `PayrollEffectivePolicy::PAY_SCALE_2026_ACTIVATION_DATE` remains `2027-07-01`. The legal entitlement and the legacy regular payment-policy date must be tracked separately until payroll management explicitly changes regular payment activation.
5. `SalaryArrearTemplateCatalog` now calculates scale Basic per each selected month, and separately reconstructs the prior 2015 stage increment. The reconstruction is NOT actual payment evidence, and an approved July bill is checked before it is trusted as a verified deduction.
6. `configuredOctoberHouseRent()` formerly offered an undated global percentage-publication fallback, which could include future January 2028 rates. That fallback was removed for October 2026; employee-specific approved source remains required. Signed House Rent differences are classified as additions when positive, deductions when negative.
7. `SPECIAL_BENEFIT_RECOVERY` now uses `PAID_SPECIAL_BENEFIT` for each of the three months. Where September is missing, the line remains UNKNOWN and editable with evidence. No automatic July benefit extrapolation is treated as verified money.
8. `OCTOBER_GPF` continues to require employee-specific saved calculation policy. No 10% blanket default introduced; regular GPF and Additional GPF remain separate. The pure reconciler refuses automatic GPF refunds on a negative difference.
9. The role-name `authorizePayroll()` stub/call in `SalaryEmployeeComponentOverrideController` has been removed. No replacement role check added. Existing authentication middleware remains; no financial posting safeguard removed.
10. Vue Salary Calculator `Recalculate formulas` used to omit rule codes, so live recalculation could skip code-specific checks. Rule codes, calculated direction, stage estimation and source-warning fields are now carried forward. UI design/classes otherwise retained.

## Unresolved/needs evidence

- No verified full-rate House Rent category and monthly housing history for all 423 employees in the accessible snapshot.
- No verified Special Benefit payment in September for most employees.
- Pay Scale old increment stage does not prove the employee actually received the July difference. A manual evidence-backed confirmation is available in the existing Salary Calculator.
- Employee-specific promotion, changes of grade, partial employment and multiple stage events require distinct verified monthly source inputs, not uniform multiplication.
- Current published SQL formulas remain the previous version until the operator runs the reviewed `kau:review-october-arrear-rules --apply` command. No automatic migration/seeder was executed.
- MySQL single/bulk parity, duplicate GPF posting checks and bank advice protections must be tested in staging.
