KAU EMS: bulk fixation reference-matched workflow

This adds one command and does not change existing code or approved fixation snapshots.
Laravel 12 auto-discovers command classes under app/Console/Commands when using default command discovery. If route:list shows no command, inspect bootstrap/app.php/console registration.

Dry-run:
php artisan kau:bulk-fixation-references
php artisan kau:bulk-fixation-references --employee=10020

Apply only with real HR authorization after CSV review and database backup:
php artisan kau:bulk-fixation-references --apply --actor=1

It skips missing/mismatched/ambiguous employee evidence and existing approvals; writes via SalaryFixationWorkflowService::approveNow. After apply, recalculate arrear previews. This command does not change October salary bills or GPF ledgers.
