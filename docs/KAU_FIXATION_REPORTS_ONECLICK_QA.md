# KAU Fixation Reports — QA (26 October 2026)

## Source basis

Fresh user upload `app(1).zip`, source baseline, user screenshots, `SIngle-salary-fixation report.pdf`, original `004_10027_Dr.-Nahid-Rahman(2).pdf` and original bulk reference। Pasted test log reviewed; those tests are user-local baseline tests, **not rerun in sandbox**.

## Tests actually run in this environment

| Check | Status | Evidence |
|---|---|---|
| PHP syntax `SalaryFixationController.php` | PASS | `php -l` executed |
| PHP syntax `SalaryReportController.php` | PASS | `php -l` executed |
| `resources/js/fixation-pdf.js` syntax | PASS | `node --check` executed |
| 3 modified Vue `<script setup>` parsing | PASS | extracted scripts with `node --check` |
| Bulk six-column/five-signature template static checks | PASS | source structure assertions |
| Approval-only export code removed | PASS (static) | comparison of latest source and modified source |
| Pagination arithmetic for 1/10/15/16 with 10/15 page sizes | PASS (arithmetic only) | isolated Python calculations |
| WeasyPrint offline static form A4 with provisional label | PASS (layout fixture) | 1 A4 portrait page, signatories present; **not live report** |
| `npm ci --offline` | FAILED | `ENOTCACHED` for yargs-parser dependency, no complete node_modules |
| Vue/Vite compiled build | NOT RUN | npm dependency installation incomplete |
| Laravel PHPUnit/Feature suite | NOT RUN | project's composer `vendor` absent |
| Live MySQL DB integration | NOT RUN | no isolated staging MySQL initialized |
| Report Factory UI end-to-end + real downloads | NOT RUN | requires Laravel login/browser/live DB |
| Full real PDF visual comparison & 1/10/15/16 pagination PDFs | NOT RUN | no live authenticated app browser |
| Exact PDF font metrics across user Windows/browser/Namecheap | NOT VERIFIED | offline renderer differs from html2canvas/jsPDF |
| Duplicate GPF/bank posting regression | NOT RUN | patch contains no posting-path change; requires staging |

## Existing user terminal log reviewed (not independently rerun)

- `KauOctoberArrearPolicyPatchTest`: 3 passed / 24 assertions.
- `SalaryArrear`: 39 passed, 3 skipped, 1 failed due `could not find driver (Connection: sqlite)`.
- `SalaryFixation`: 23 passed, 1 failed due missing SQLite driver.
- `PayrollEffectivePolicyTest`: 3 passed.
- `npm ci` and `npm run build` passed **before this new patch** on the user's machine. npm reports 10 vulnerabilities for review.

## Specific release gates

- Staging must confirm selected approved / non-approved / unsaved reports all export without policy alerts and without mutating database state.
- Actual single PDF must be **1 page** under the same browser route using jsPDF A4 portrait; reference visual comparison required.
- Bulk 15 rows/page must not overlap five-member signature block or clip long Bengali/English names; test 16 rows pagination.
- Financial figures are informational until independently verified; export is not an authorization or payment gate.
- **Do not deploy to production until staging acceptance is signed off.**
