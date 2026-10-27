# October 2026 Regular-only Bank Advice preparation

This patch adds ONE artisan command. It does not replace existing services.

Default: DRY RUN. `--apply` inserts October 2026 REGULAR salary bills only, with zero arrear amount, into salary_generates; creates source links and monthly GPF ledger contributions in per-employee transactions. Employee exceptions are written to CSV instead of blocking everyone.

Always back up your database. Do NOT run `--apply` before reviewing the dry-run CSV and testing on an isolated clone. Generated records remain `pending`. Bank Advice PDF must be checked against the generated October bills; the command does not generate a PDF itself.

Commands:

```
php artisan kau:october-regular-bulk --employee=20057
php artisan kau:october-regular-bulk
php artisan kau:october-regular-bulk --employee=20057 --apply --actor=1
php artisan kau:october-regular-bulk --apply --actor=1
```

Replace `--actor=1` with a real authorized operator ID. If the first apply test succeeds and its source-link/GPF/bill totals reconcile, run full apply only after backup and approval.

`--limit=10` allows a small batch dry-run. `--output=...` controls CSV location. Existing or invalid accounts are not guessed; such rows are listed as `needs_review`.

October 2026 uses the currently implemented 2015 regular-only preview. July–September arrears are deliberately EXCLUDED, not incorrectly set to paid zero. Regular salary generation cannot later be converted silently to full arrear payroll; arrears require a separate audited process.
