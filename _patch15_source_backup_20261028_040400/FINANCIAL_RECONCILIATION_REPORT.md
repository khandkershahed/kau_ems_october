# PATCH 14 — Evidence and release integrity

- Attached staging log: PATCH 13 test FAIL because `postJson()` returned HTTP 401, but test expected a redirect. Correct expectation is 401.
- Attached staging log: Vite compilation FAIL: `BulkComponents.vue` around line 102 missing end tag, caused by an extra `</div>` before `</details>`.
- Audited source: `approvedOctoberGpf()` requires an approved October version; upstream bulk save needs arrear preview. Circular dependency is confirmed at code level, but cannot safely repair with invented GPF amounts or by discarding arrear rules.
- Employee 20057: historical monetary parity NOT ESTABLISHED. New command inventories row counts; it does not assert amount parity.
- MySQL integration, concurrency, final generation, bank advice and frontend build for this patch: NOT EXECUTED here.
- Production: BLOCKED.
