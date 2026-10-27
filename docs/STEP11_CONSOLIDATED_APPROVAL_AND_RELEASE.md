# KAU EMS Step 11 — Consolidated source patch and approval preflight

This is **NOT** a declaration of 100% production readiness.

Sources: actual `app.zip` uploaded on 26 October, the Step 10 September recovery patch, the prior SQL and the current `codershat_kau(2).sql`. No previously installed patch is assumed.

## Included
- All Step 10 September **staging-only** recovery seeder + JSON + SQL, display-only zero UI correction, tests, and audit CSV.
- `app/Services/SalaryFixationService.php` narrow correction: ONLY listed five study-leave *increment allowed* get July 2026 increment, NO July 2027 second increment. Conflicting existing manual override remains stored and is reported as a blocker rather than overwritten.
- `app/Console/Commands/PrepareAndApproveOctoberFixations.php`: default read-only CSV; `--apply` approves only records with unique profile, complete positive fixation values and `prepare()['ready']` true. Calls original `SalaryFixationWorkflowService::approveNow()` with row-locking, audit events, three effective pay schedules. Never directly updates fixation status, salaries, GPF or bank advice.
- `tests/Unit/SalaryStudyLeaveOneIncrementStep11Test.php`, source hashes, verification SQL.

## Known stop conditions
- Current production SQL has only one approved fixation. The command does not manufacture approvals for a missing, mismatched or ambiguous reference. For study leave cases the initial reference PDFs were not supplied; the source currently records this as a blocker. HR must reconcile exceptions and supply evidence using the existing adjustment workflow. 
- Production dump contains zero published component rules, missing effective component/employee override migrations, missing approved September bills, missing required GPF and House Rent entitlement evidence. Pending recovered September records do NOT establish payment.
- Source contains 2026-scale entitlement and a separate activation policy at 2027-07-01; do not simply switch policy without a signed payroll decision. The calculator already uses approved fixation schedules for Basic when available.
- `SalaryStructuredArrearService::sources()` has no supported financial_months entitlement source. No automatic 2026 House Rent or additional GPF values were invented in this patch. They remain non-postable until verified through existing payroll calculation/evidence workflow.

## Safety
- Never import `codershat_kau_STEP10_STAGING_RECONSTRUCTED.sql` into production. It is not inside this ZIP; restore it only to a fresh staging DB.
- Do not run `migrate:fresh`, approve raw SQL updates, or seed unverified rule percentages.
- The uploaded DB contains employee sensitive data; the September recovery fixture inherits that sensitivity.
- An operator who requests `--apply` must identify actor and source attestation; production additionally requires `--allow-production`. Use only AFTER staging test acceptance.

## Safe installer (PowerShell)
Run `INSTALL_STEP11_STAGING.ps1` first without `-Apply` (hash validation/dry-run), then with `-Apply` against an isolated staging project. It backs up overwritten files and installs source/tests/seeder only. It does not run database or financial mutations. Read the step-by-step commands in the accompanying answer.
