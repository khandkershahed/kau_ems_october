# Step 03 QA Report — KAU EMS

## Source audit

- Audited latest `app(1).zip` (26 Oct 2026): Step 02 `SalaryEffectiveComponentResolver`, `SalaryComponentCatalog`, `SalaryCalculationService`, `SalaryCalculatorEntryController`, `SalaryBillCompositionService`, `SalarySetupController`, Vue Calculator, relevant routes/migrations/tests.
- Reviewed SQL snapshot `kau-app-local(2).sql` for `salary_calculation_versions` and `payroll_component_types` schema; snapshot predates the already-installed Step 01 migration. Step 01 user terminal confirms migration ran and Seeder dry run pending all 1421 assignments.
- Reviewed attached Windows test output: Step 02 resolver 7 tests passed, salary calculation 11 passed/1 skipped, SalaryBill 8 passed/1 skipped. These were user-run tests on the preceding patch, **not** Step 03 runtime acceptance.

## Tests actually executed in this environment

| Test | Status |
| --- | --- |
| PHP lint of all new/modified `.php` files | PASS |
| JavaScript syntax check of extracted Vue `<script setup>` | PASS |
| Standalone PHP service smoke scenarios | 12 PASS |
| ZIP structure/integrity and SHA256 | PASS when packaging verified |
| New PHPUnit tests via Laravel | NOT RUN: composer vendor absent |
| MySQL feature integration and migration compatibility | NOT RUN: isolated MySQL unavailable |
| Full Vue SFC compile/Vite build | NOT RUN: `npm ci --offline` failed `ENOTCACHED` |
| Browser workflow, concurrency, permissions | NOT RUN |
| Single/SalarySetup/Bulk live monetary parity | NOT RUN |
| Production deploy | NOT DONE |

## Required staging checks

1. Migration `--pretend` then actual run on disposable MySQL clone; verify three structures and no historic row change.
2. Open existing employee with configured Fixed House Rent; override Percentage of eligible Basic; confirm same calculation for Salary Calculator and Salary Setup; reset to automatic; compare screenshots.
3. Force legacy-basic 40% with documented base; ensure revised Basic not mistakenly used.
4. Apply current-month-only Fixed GPF; verify HALF UP, paid/saved bill source; no ledger during preview.
5. Override one employee in October; November and another employee unaffected.
6. Disable and restore an existing component; custom allowance/deduction row in same month.
7. Concurrent-edit test: second browser attempt returns HTTP 409; non-HR should receive 403.
8. Save a calculation version after override; stale snapshot cannot be posted; compare Single and Bulk generated amount only in staging.
9. Verify no historical salary bill, GPF ledger, employee salary JSON or approved fixation changed through editing.
10. Full PHPUnit suite and Vite build; review npm security advisory separately before production.

**Release determination:** PATCH PREPARED FOR STAGING, NOT PRODUCTION READY.
