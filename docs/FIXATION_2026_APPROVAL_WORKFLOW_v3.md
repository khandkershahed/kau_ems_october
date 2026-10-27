# KAU Salary Fixation 2026 — Database, review, and effective-dated payroll patch

**Patch based on:** `app(3).zip` supplied 23 October 2026.

## Scope

This is a focused **staging patch**, not a verified production release. Original application features are retained. It introduces an employee-specific, auditable path:

1. Read the **approved 30 June 2026** historical basic, grade and personal pay (or block on missing/ambiguous evidence).
2. Use 2026 pay-scale stages stored in `pay_scales`, `grades`, and the new `pay_scale_stages` table. The old 2015 scale/assignments are kept.
3. Resolve special increment rules from the existing employee-code lists, the imported original report figures, and HR's **dated, evidence-backed** per-employee decision for 2026/2027.
4. Compare calculated 2026 increment and all 2026/2027 phase values against the corresponding employee reference PDF figures **before review submission**. Duplicates and missing reports fail closed.
5. Save an immutable fixation snapshot, email the individual employee a private review link, and accept their `confirm` or `dispute` response after authentication.
6. After employee confirmation, allow authorized HR/Superadmin to approve **only if input hash, data consistency, PDF report comparison and calculations remain unchanged**.
7. HR approval creates July 2026, January 2027 and July 2027 effective-dated *payable basic* schedule records. The existing salary generator uses those amounts for **new payroll runs** with matching effective months; no existing salary bill or employee setup JSON is rewritten by the approval action.
8. Provide a server-locked one-page printable form plus client-side `Download one-page PDF` using the existing `jspdf` and `html2canvas` packages. The export is a rasterized PDF of the HTML form; it is not a PDF/A, cryptographically signed or official iBAS++ document.

## Important mathematical distinction

For report `001_10026_Dr.-Md.-Taslim-Hossain.pdf`, Grade 6:

| Component | Reference | Calculated independently |
|:--|--:|--:|
| 2015 old basic | 52,480 | 52,480 |
| 2026 fitted scale stage | 90,700 | 90,700 |
| 2026 first increment | 4,500 | 4,500 |
| July 2026 payable | 72,268 | 72,268 |
| January 2027 payable | 83,734 | 83,734 |
| July 2027 payable | 100,000 | 100,000 |

Do **not** overwrite current pay with 90,700 when the applicable payable amount for July 2026 is 72,268.

## Included reference report data

- 377 user-supplied original PDF reference rows; 376 distinct employee codes.
- 349 standard, 14 ad hoc, 12 increment hold and 2 bad-basic-review reports.
- Employee code **30132 occurs in two different filenames**, so approval for this code is blocked until its identity and record are reconciled.
- 33 education-leave employee codes are listed in the existing eligibility file; five are explicitly first-increment eligible, while 28 are not. **These 33 have no individual original reference PDF in the earlier archive**, so automatic HR approval remains blocked until official evidence is supplied and reconciled. Future 2027 eligibility must be expressly recorded per employee.
- The archive folder `ADHOC-16` contains 14 PDF files, not 16. Missing records must not be synthesized.
- The imported report figures match the 2026 grade-stage formula under **at least one** grade and increment-eligibility combination for all 377 rows; this mathematical compatibility does **not** verify that the database has assigned the correct grade, or that all source PDF names/figures were independently checked against the originals.

## Exact installation on a staging clone

Back up source and SQL first, then extract the patch into the project root preserving paths. No original SQL dump is included.

```powershell
php artisan migrate
php artisan db:seed --class=PayScale2026StagesSeeder
php artisan db:seed --class=SalaryFixationReferenceReportsSeeder
php artisan db:seed --class=SalaryFixationHrRoleSeeder
php artisan optimize:clear
npm ci
npm run build
php artisan test --filter=SalaryFixationCalculationTest
php artisan route:list --name=salary-fixation
```

**Pay-scale seed safety:** The `Govt. Pay Scale 2026` and its 20 grades remain **inactive** for 2015 employee assignments. The stage seeder aborts if existing stage amounts disagree with source values. The reference-report seeder aborts rather than silently overwriting a conflicting report. Repeat imports are safe only when existing figures agree.

**Permissions:** Only an active `superadmin` or explicitly assigned active `payroll_hr` role can prepare, adjust, send or approve. Seeder creates a `payroll_hr` role but **never assigns it to a person**. Employees may open and respond only to their own fixation. A mailed link requires login.

**Email:** A valid `APP_URL`, verified staff email and working `MAIL_MAILER` are required. The email sends a secure portal link, not a PDF attachment; the employee can open the exact saved form and use Print / Save as PDF or Download one-page PDF.

**PDF:** `npm run build` must be rerun to generate `resources/js/fixation-pdf.js` into the Vite manifest. Test both `Download one-page PDF` and the browser print in Brave/Chrome on Windows for short/long names and each major grade. The CSS removes print scroll containers, shrinks the official table and targets A4, but a full GUI visual regression has **not** been completed in this environment. The provided official-report sample cites SRO 348 while the provided gazette cites SRO 347; this patch displays 347 based on the uploaded gazette, subject to HR/legal confirmation.

## HR operation

1. Navigate to `/dashboard/salary/fixation`.
2. Find an employee by name or employee code; open a **provisional** form to investigate blocked records.
3. Where appropriate, open **Manual adjustment / Eligibility**. Set July 2026 and July 2027 entitlement **independently** (`automatic`, `allow`, `hold`); optionally enter verified old basic, PP, or additional titled information. Every adjustment requires a written **evidence reference and reason**. Extra named fields are explanatory metadata only, not hidden monetary operations.
4. Recalculate and check the original PDF status `matched`; any missing/mismatched/multiple report blocks submission and approval. Resolve source inconsistencies rather than overriding the check.
5. **Save fixation snapshot** → **Send review to employee**.
6. Employee signs in, opens their own saved form, downloads PDF, and chooses **Confirm** or **Dispute**.
7. HR may **Approve & Schedule Pay** only after confirmation and an unchanged reconciliation. The schedule table is consulted by `SalaryGenerateController` only for matching future payroll generation.

## Approval states

`pending_review` → `sent` → `employee_confirmed` → `approved`

or `sent` → `employee_disputed` (HR investigates and produces new snapshot), and any edited in-progress draft → `superseded`.

After approval, new pay schedule creation for that employee is blocked if a schedule already exists. **Corrections to approved fixation and their reversal** require a separate documented, permission-controlled HR reversal implementation; this patch intentionally does not offer an unsafe one-click mutation.

## Verification already completed here

- PHP syntax checks passed for changed PHP files.
- Standalone formula checks passed for Grades 6, 9, and 10 and the separated held-2026/allowed-2027 case.
- Extracted report CSV: 377/377 had at least one mathematically matching 2026 grade and increment combination, while code 30132 has duplicate report files.
- JavaScript `node --check` passed for both fixation runtime and PDF-download entrypoint.

## Not verified / limitations

- Full Laravel migrations and integration tests on the user's restored production database.
- Actual email delivery/employee login and role assignment.
- Frontend Vite compilation in this environment (offline npm cache lacks `yargs-parser`; `npm ci` must be run in the user's project).
- End-to-end PDF layout and single-page print parity against every original sample. The headless test browser blocked localhost and file navigation here.
- Legal/gazette reconciliation of the SRO reference and institutional HR approval of all exceptions.
- Automatic calculation of arrears, deductions, special promotions or reversals; these are outside this fixation patch.
- Updates to all employee profile views showing 2026 pay scale; `employee_infos` retains 2015 historical assignment, while new payroll records use the effective-dated schedule after approval.

**Do not deploy to live payroll until staging tests, finance/HR validation and an independent backup-based rollback exercise pass.**
