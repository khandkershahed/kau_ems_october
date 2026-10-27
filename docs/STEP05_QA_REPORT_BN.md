# Step 05 QA — Executed vs pending

**Audit input:** latest `app(4).zip`; earlier `kau-app-local(2).sql` (not live DB); `updated payscale 2026(1).pdf`, pages 1, 2 and 14. Windows Step 04 logs were reviewed; they do not constitute Step 05 test results.

## Executed

- `php -l` on 11 modified/new PHP source/test/script files: PASS, all syntax valid.
- `node --check` on extracted Vue `<script setup>` JavaScript: PASS (not a full SFC compilation).
- `php scripts/step05_scale_arrear_smoke.php`: PASS, **17 checks**, no database or posting.
- Source ZIP integrity and SHA256: verified during packaging.

## Not run here

- `php artisan test` / PHPUnit (vendor packages not installed in supplied app archive).
- MySQL migration/feature tests (no isolated test database provided).
- Step 05 full Vue/Vite compilation (node_modules not provided; user must run `npm ci && npm run build`).
- Actual employee SQL reconciliation, housing category, prior benefits/GPF payment source confirmation.
- Real posting/GPF duplicate prevention, bank advice isolation and cross-employee bulk parity.

## Financial behavior

The pure projection distinguishes `ESTIMATED_RECONCILIATION`, `PROVISIONAL_MISSING_PAYMENT_EVIDENCE`, and `VERIFIED_RECONCILIATION`. The rule preview will keep a reconstructed July increment estimated until checked against an approved July paid Basic, instead of treating it as a verified payment. Signed House Rent adjustments cannot silently become a deduction when positive. Absent September benefit remains UNKNOWN. `SUM_MONTHS` adds selected months once.

### Mandatory acceptance

Production-readiness declaration is prohibited until all skipped tests and source reconciliation pass on Windows staging, including explicit rule revisions, current/arrear/total consistency and duplicate posting checks.
