# KAU Pay Fixation 2026 — database integration (review-only)

## Scope and source evidence
- Preserved the user's `kaufixation.html` unchanged as `resources/fixation/kaufixation.html`.
- Same form is served with read-only automatic employee values (2026-06 payroll snapshot where available; otherwise **unverified** current salary setup).
- Numeric stages are transcribed from the supplied HTML into `config/kau_fixation_2026.php`. A formal line-by-line reconciliation against the PDF must be signed off before payroll activation. The optional seeder adds inactive Grade 1–20 rows to a dedicated inactive 2026 scale.
- The uploaded PDF is headed **SRO 347-law/2026** dated 17 September 2026, while the reference HTML says **SRO 348**. Served copy corrects the citation only; static source remains intact.
- HTML's grade 10 and grade 20 display strings each omit a zero in the starting salary; rendered copy corrects display only.
- The user-supplied PDF was not independently authenticated against government websites.

## Database mapping
| Fixation field | Source |
|--|--|
| Name | `users.name` |
| Employee ID | `employee_infos.employeeCode` |
| Grade | `employee_infos.payroll_group_id` → `payroll_groups.grade_id` → `grades.grade_name` |
| Old scale | `employee_infos.pay_scale_id` → `pay_scales.scale_name` |
| Old basic | June 2026 `salary_generates.salaryAllowanceCalcObject` Basic Pay; fallback `employee_infos.additionObjects` (unverified) |
| PP | Explicit Personal Pay component, if available (otherwise zero with warning) |
| Department/designation | `employee_infos.department_office_id`, `payroll_group_id` |
| NID/phone/joining | Existing `nids`, `phone_numbers`, `joining_dates` relations |
| Increment hold / date | `employee_infos.holdIncrement`, `employee_infos.incrementDate` |
| Medical/house rent/deduction | Existing salary component amounts; 2028 future rent is not inferable |

## User flow
1. Navigate to **Salary & Bonus Management → Salary fixation 2026**, or `/dashboard/salary/fixation`.
2. Search employee by code or name; results calculate the three basic-pay phases automatically.
3. Open **View full form** for the supplied layout filled from DB. Inputs are locked to preserve source of truth.
4. Read warnings; if appropriate, **Save audit snapshot**. Records are stored in new `salary_fixations`, always as `pending_review`.
5. The existing Salary estimation, monthly salary generation, bonus, and historical payroll records **are not updated** by this patch.
6. Optional: an idempotent `PayScale2026Seeder` creates an **inactive** 2026 pay scale plus all 20 **inactive** grade rows in the existing scale management module. It does not move any employee or designation.

## Installation
Back up the database and existing project, copy the ZIP contents over the project root, then run:

```bash
php artisan migrate
# Optional only after scale-data review (inactive configuration records)
php artisan db:seed --class=PayScale2026Seeder
php artisan optimize:clear
npm install
npm run build
php artisan test --filter=SalaryFixationCalculationTest
```

The app's existing login and `verified` middleware apply. The new controller also requires an active role with `is_admin=true` for every route including POST; non-admins receive HTTP 403.

## Unresolved blockers before paying changed salaries
- Existing schema has no explicit effective-dated salary history except generated monthly bills; when June 2026 salary is missing, old basic is not historically provable.
- Promotion events currently have no effective-date field. Pay protection, selection grade, time scale, fixed pay, leave and grade ceiling exceptions need a human review path.
- PP may not be in a separate salary component, and `holdIncrement` default and populated value must be checked for accuracy.
- Phase percentages/increments need independent final sign-off against the original gazette and employee-specific orders; the standalone HTML cannot itself establish official iBAS++ parity.
- New house-rent rates depend on area and additional eligibility rules. No 2028 house-rent amount is auto-generated; the HTML's 2028 allowance comparison should not be used for payroll decisions.
- Deliberately no automatic salary mutation, no retroactive payroll recalculation, no bulk application, and no official print certification workflow. Those must be built only after source validation and payroll acceptance rules are agreed.

## Verification
Fixation HTML now uses the locally compiled app stylesheet instead of Tailwind CDN JavaScript. Compile the frontend before opening the preview. A restrictive CSP and no-referrer policy protect staff data; printable forms carry an UNVERIFIED watermark.

PHP syntax lint each changed PHP file, `php artisan route:list --path=dashboard/salary/fixation`, Vite build and PHPUnit tests after dependencies are installed. In production inspect several employee snapshots in different grades and staff categories before any future pay-activation step.

## Production SQL audit update (20 October 2026)

The user has now supplied the `kau-app.sql` production dump. See `docs/2026-PRODUCTION-DATABASE-AUDIT.md` for aggregate findings. Of 423 employee profiles, 385 meet minimal historical/source consistency checks for a **provisional preview** and 38 are blocked pending source repair (reason counts overlap). Unlike the first draft, this version does **not** permit preview/snapshot saving with an unverified current-salary fallback, conflicting scale IDs, conflicting historical grades, or nonstandard basic-pay stages. The payroll and staff tables are not modified.
