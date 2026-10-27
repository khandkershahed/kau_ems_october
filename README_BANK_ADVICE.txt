KAU Bank Advice Safe Report Patch
Based on app(20261027-062712).zip
Only report filters and read-only validation are changed. Does NOT calculate arrears or modify saved financial data.
Applied via Expand-Archive from project root.
Run php -l app/Http/Controllers/SalaryReportController.php and npm run build.
In Report Factory choose October 2026 / Bank Advice / Generated Only.
If needed remove patch by restoring backups.
