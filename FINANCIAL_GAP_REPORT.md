# Financial audit: PATCH 20

- October 2026 historical 413/395/18 is **reference only**, not verified in the live DB.
- No SQL snapshot has been mutated. Existing generated bills, GPF ledger, and salary arithmetic remain untouched.
- This patch reads *only* persisted `salary_calculation_versions` belonging to reviewed workflow rows. It does not make a preview authoritative or convert an unapproved version into an approved version.
- Missing canonical arrear fields are printed **NOT RECORDED**; this is not a zero entitlement. No misleading grand totals are provided where any field is incomplete.
- Batch report exclusions are visible in the document. Duplicate employee profiles are excluded; never chosen arbitrarily.
- Employee code 20057 source-to-payment parity is NOT VERIFIED; authoritative historical value comparison still pending.
- GPF evidence completeness, double-post protection, and stale source/hash financial parity need real MariaDB transaction tests.
- PRODUCTION RELEASE: NOT APPROVED.
