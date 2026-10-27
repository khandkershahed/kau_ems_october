# Step 07 — Actual QA log

Source: user-provided `app(6).zip`, Step 06 build/test log, generated patch source.

| QA item | Observed result |
|---|---|
| `php -l` newly added/modified PHP | PASS (all reported checked files) |
| Standalone PHP saved-row arithmetic | PASS 4/4 (`scripts/step07_report_smoke.php`, assertions enabled) |
| Official Blade template compiler (`compileString`) | PASS; compiled PHP `php -l` PASS |
| `node --check` extracted `<script setup>` of both modified Vue pages | PASS; not a Vue template compilation |
| `php artisan route:list --path=salary-official` | PASS (1 GET route registered) |
| Synthetic single portrait PDF via WeasyPrint | PASS: 1 A4 Portrait page; visually inspected |
| Synthetic 15-employee compact overview via WeasyPrint | PASS: 1 A4 Landscape page after spacing revision |
| Chromium renderer in this runtime | Attempted: timed out, PDF not verified |
| Composer PHPUnit via `php artisan test` | NOT RUN SUCCESSFULLY: missing PHP extensions DOM, mbstring, xmlwriter on this container |
| Vite `npm ci` | TIMED OUT (no completed installation) |
| Step 07 Vite production build | NOT RUN (dependency install incomplete) |
| MySQL integration | NOT RUN (no isolated staging MySQL and necessary PDO driver) |
| Namecheap direct PDF | NOT TESTED; host browser availability unknown |
| Full real-employee Source vs CSV/Excel/PDF | NOT TESTED |
| No-financial-POST audit | Source-level audit only; DB before/after test is outstanding |

**Previously provided Step 06 Windows log**: `php scripts/step06_bulk_smoke.php` PASS (8); JS PASS (4); PHPUnit `SalaryBulkStep06Test` PASS (8); Step 06 Vite build **FAILED** at `GenerateBills/Index.vue` line 1498, addressed by Step 07 code. Step 05 published arrear rule remains unresolved. These are previous Step 06 results, not new Step 07 feature test results.

**Financial limitations**: No new report should pretend that a three-month aggregate represents verified July, August and September payments. Legacy percentage rows lacking frozen amount remain unknown in detail view. Component rules remain unpublished pending evidence. Unresolved historical rule conflict is not modified.

**Release verdict:** STAGING CANDIDATE ONLY, NOT PRODUCTION READY.
