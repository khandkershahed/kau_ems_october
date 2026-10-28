# PATCH 17 Financial release assessment

- Verified from source: previous index queried ONLY salary_monthly_workflows, so 0 rows rendered when October workflows were empty.
- Change: employee_infos grouped by user_id, left-joined onto existing monthly workflows. Duplicate profiles visible via profile_count and not selectable.
- Existing reconcile() only inserts workflow/event records and checks posted bills, does not edit salary amounts.
- Existing preview and approvals are reused unchanged. No new salary/version engine, no GPF amounts inferred.
- **Unverified**: full source SQL snapshot parity, actual 18 per-employee values, employee 20057, independent GPF evidence, real MariaDB tests, browser build.
- **Production NOT APPROVED**.
