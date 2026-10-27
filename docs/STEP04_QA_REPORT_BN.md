# KAU EMS — Step 04 QA report

## Input and audit
- `app(2).zip` unpacked; inspected the SalaryCalculationService, SalaryComponentCatalog, published effective resolver, employee override service, controllers, approved fixation component projector, bill composition/posting, arrear service, routes and Vue screen.
- Confirmed the prior Step 03 Windows log (submitted by user): migration ran; 10 Step 03 unit tests, 7 Step 02 unit tests, 11 calculation tests and 8 salary bill tests passed; Step 03 MySQL feature tests skipped; user's npm/Vite build completed successfully. These are **user-local results**, not new Step 04 test runs.
- A separate up-to-date MySQL `.sql` dump was not supplied in this turn. No SQL records were written.

## Confirmed defect fixed
- Vue initial GPF type/rate could treat a legacy employee-specific Fixed subscription as the Default 10% mode; the server's `financial.gpf_rule` now initializes the displayed mode/rate. All override workflows remain delegated to Step 03.

## Test results actually run in container
| Test | Outcome |
|---|---|
| PHP lint — four modified/new PHP source files | PASS |
| PHP lint — unit test and smoke script | PASS |
| PHP standalone `scripts/step04_statement_smoke.php` | PASS: 8 checks |
| Node `--test tests/js/salaryStatementBreakdownStep04.test.mjs` | PASS: 4 tests |
| `node --check` Vue `<script setup>` extracted source | PASS: parsing only |
| `node --check` JavaScript utility | PASS |
| Full Laravel PHPUnit/feature | NOT RUN: vendor installation unavailable |
| MySQL migration/database tests | NOT RUN: no live/test database, no new migration |
| `npm ci --offline` | FAILED: npm cache missing yargs-parser tarball |
| Full Vue/Vite build of Step 04 | NOT RUN: dependencies not available offline |
| Browser print/PDF visual test | NOT RUN |
| Live Single/Bulk financial parity | NOT RUN |
| Authorization/GPF posting regression | NOT RUN; unchanged code paths but must validate staging |

## Calculation smoke example (not a KAU employee)
- Current Basic 28,715 + House Rent 8,757 + Medical 1,500 = Gross 38,972.
- Fixed GPF 2,872 + Revenue Stamp 10 = Deductions 2,882; Current Net 36,090.
- Arrear Basic +300, Benefit Recovery -75 = Arrear Net 225; Total Net 36,315.
- When one arrear amount is unknown, `total.net === null` and a separate provisional_display value is provided; UNKNOWN is never a verified zero.

## Unresolved release blockers
1. No published component rules (Step 01 user previously reported all 1,421 PENDING), so global designation rates cannot be claimed complete.
2. Arrear rules and source evidence need Step 05 financial reconciliation; Step 04 intentionally does not change them.
3. Full Vue compilation and MySQL parity are unverified for this patch.
4. Report print output and duplicate ledger guards must pass staging acceptance before production use.
5. Npm security vulnerabilities reported in previous user-local log require separate dependency audit.

**Status: staging candidate, NOT production-ready.**
