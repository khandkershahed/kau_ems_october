# KAU EMS — One-click Salary Fixation Reports patch (26 October 2026)

## উদ্দেশ্য

এই patch কেবল সর্বশেষ `app(1).zip`-এর ভিত্তিতে PDF report generation flow সংশোধন করে। এটি 2015 historical bill, regular-payroll effective-date policy, arrear posting বা GPF ledger পরিবর্তন করে না। পূর্বের 25 October patch source-এ যে কাজ কার্যকর আছে তা অক্ষত রাখা হয়েছে।

## প্রকৃত সমস্যার কারণ

1. `Salary/Fixation/Index.vue`-এ `selectedFixationReportIds` শুধুমাত্র `saved_status === 'approved'` নির্বাচন করত। তাই selected employee থাকলেও bulk PDF disabled দেখাত।
2. `Reports/SalaryReports/Index.vue`-এর `exportFixationPdf` approved snapshot/stage-কে mandatory gate হিসেবে ধরে unapproved selection-কে alert দিয়ে থামাত। Single report iframe-এর `KAU_EXPORT_FIXATION_PDF` ready হওয়ার ওপর নির্ভর করত।
3. `SalaryReportController.php` শুধুমাত্র approved saved `salary_fixations` query করত। Nonapproved বা unsaved কর্মচারীর রিপোর্টে calculation ব্যবহার না করে missing-approved-record দেখাত।
4. `FixationStatement.vue`-এর last column শুধুমাত্র approved record-এ তিন-লাইন breakdown দেখাত এবং provisional row-তে footer signature section সরিয়ে দিত।

## পরিবর্তিত আচরণ

- Fixation table-এর `Download Single Fixation PDF` একটি ক্লিকে original `kaufixation.html` form থেকে PDF ডাউনলোড করে; saved snapshot থাকলে সেটি ব্যবহার হয়; unsaved record-এ current read-only preparation ব্যবহৃত হয়।
- `Preview / Print Form` আগের মতো original full Bengali form খুলবে।
- Selected bulk button approval না দেখেই নির্বাচিত কর্মচারীদের Report Factory-তে পাঠায় এবং স্বয়ংক্রিয়ভাবে landscape PDF export শুরু করে। Report Factory-তে ম্যানুয়াল PDF/Print-ও একই renderer ব্যবহার করে।
- Nonapproved saved `prepared_data` থাকলে সেই snapshot-ই display source; না থাকলে নতুন provisional calculation (অর্থাৎ *not approved*)। Approved snapshot ও stages পূর্বের মতো immutable data source।
- Any record can be included in a PDF. Missing values `—` বা `Missing verified calculation` হিসেবে দেখায়; কখনো `0.00`-কে verified বলে দেখায় না।
- Unapproved or incomplete records have a PROVISIONAL notice. PDF export ≠ HR approval ≠ payroll/GPF posting।
- Single PDF exporter jsPDF A4 portrait-এ ঠিক **one page** তৈরি করে। তার table font ও row padding সামান্য বৃদ্ধি করা হয়েছে; native print CSS-ও A4 one-page offline fixture-এ পরীক্ষা করা হয়েছে।
- Bulk PDF থাকে A4 landscape, six columns, three-line last column, five signature positions, 10/15/custom (1–15) per page।

## পরিবর্তিত ফাইল

- `app/Http/Controllers/SalaryFixationController.php` — one-click source, incomplete preview, embedded-only screen view, A4 style।
- `app/Http/Controllers/SalaryReportController.php` — approved frozen snapshot preferred; safe provisional fallback, no database mutation।
- `resources/fixation/runtime.js` — unresolved data blanked, no report-only PDF gate, provisional notice।
- `resources/js/fixation-pdf.js` — single canonical PDF engine, direct download/print query support, jsPDF autoPrint।
- `resources/js/Pages/Salary/Fixation/Index.vue` — enabled selected PDF and row-level one-click PDF।
- `resources/js/Pages/Reports/SalaryReports/Index.vue` — shared canonical single endpoint, no approved-only bulk gate, selected auto-download।
- `resources/js/Pages/Reports/reportTemplates/FixationStatement.vue` — nonapproved three-line values when calculable; provisional marking; signature layout preserved।

## Windows staging install (PowerShell)

***শুধুমাত্র current `app(1).zip`-এর checkout-এ apply করুন; পুরোনো project version-এ নয়।***

```powershell
$Project = 'D:\Projects\KAU EMS\kau-app-new'
$Patch = 'D:\Downloads\KAU_EMS_2026_10_26_FIXATION_REPORTS_ONECLICK_PATCH_ONLY.zip'
$Backup = 'D:\KAU_EMS_FIXATION_BACKUP_' + (Get-Date -Format 'yyyyMMdd_HHmmss')
New-Item -Path $Backup -ItemType Directory -Force | Out-Null
Copy-Item -LiteralPath $Project -Destination (Join-Path $Backup 'project') -Recurse -Force
# Separately export the staging MySQL database before any integration work.
Expand-Archive -LiteralPath $Patch -DestinationPath $Project -Force
Set-Location $Project
php -l app/Http/Controllers/SalaryFixationController.php
php -l app/Http/Controllers/SalaryReportController.php
php artisan migrate:status
php artisan test --filter=SalaryFixation
php artisan test --filter=SalaryArrear
php artisan test --filter=PayrollEffectivePolicyTest
npm ci
npm run build
php artisan optimize:clear
php artisan route:list --path=dashboard/salary/fixation
php artisan route:list --path=dashboard/reports/salary-reports
```

**নতুন migration নেই; `php artisan migrate` অথবা seeder চালাতে হবে না।** বিদ্যমান staging tests-এ SQLite PDO driver অনুপস্থিত হলে database-integrated cases আলাদাভাবে fail করতে পারে; সেগুলো test failure হিসেবেই গণনা করতে হবে। `npm audit` warning থাকলে পর্যালোচনা করুন; `npm audit fix --force` চালিয়ে dependency versions স্বয়ংক্রিয়ভাবে পরিবর্তন করবেন না।

## Staging QA — Browser / PDF manual acceptance

1. `/dashboard/salary/fixation`-এ 1, 10, 15, 16 জন staff নির্বাচন করে selected bulk report তৈরি করুন; 16 জন, rows/page 15 হলে 2 pages; rows/page 10 হলে 2 pages। সমস্ত কর্মচারীর count ও selection IDs মিলিয়ে দেখুন।
2. **No saved**, **saved provisional**, **pending review**, **approved** status-এর একজন করে employee row থেকে `Download Single Fixation PDF` পরীক্ষা করুন। A4 portrait 1 page, full original 29-row form ও signatures, numbers and provisional badge যাচাই করুন।
3. Report Factory single button দিয়ে সেই একই employee-এর original template / numeric source মিলিয়ে দেখুন।
4. Report Factory bulk PDF: mixed approved/unapproved selection-এ কোনো blocking alert হওয়া যাবে না। 6 columns, repeat header, 3-line values, no clipped text, 5 signatories, provisional marking যাচাই করুন।
5. Each PDF `pdfinfo`/Adobe/Chrome-এ page count দেখুন। Web browser `Print`-এ `Headers and footers` বন্ধ করে A4 নির্বাচন করুন; auto-generated PDF-তে URL/page headers থাকা উচিত নয়।
6. No source evidence থাকলে `—` এবং `PROVISIONAL` থাকতে হবে, copied example amount না। Approval state, salary bill/GPF totals এবং DB row counts PDF print-এর আগে ও পরে একই থাকতে হবে।
7. Log in with read-only/non-payroll user: existing route access permissions যাচাই করুন। এই patch authentication/authorization policy পরিবর্তন করে না।

## Namecheap production — staging acceptance ও Git merge-এর পরে, এখনই নয়

1. Production DB dump এবং Git commit/hash সহ app/asset backup নিন। Document root, PHP binary, `public/build`, storage symlink ও permission যাচাই করুন।
2. সাতটি source file review করে Git commit/PR মারফত `main`-এ merge করুন। Git checkout-এ সরাসরি ZIP extract করে tracked-file conflict তৈরি করবেন না।
3. Hosted checkout-এ নিচের safe commands (সঠিক hosting PHP binary ব্যবহার করে):

```bash
php artisan down
# BACKUP database and application files here BEFORE git pull
# e.g. mysqldump --single-transaction -u DB_USER -p DB_NAME > /secure/backup.sql
# e.g. tar -czf /secure/kau-application-backup.tar.gz app routes resources public/build

git rev-parse HEAD
git pull origin main
php artisan migrate:status
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
```

`npm ci && npm run build` সফল staging/CI environment-এ করে নতুন `public/build` assets deploy করুন; Namecheap-এ Node না থাকলে staging compiled assets স্থানান্তর করুন। এই patch-এর জন্য `migrate --force` বা seeder প্রয়োজন নেই।

## Rollback

1. Problems হলে maintenance mode করে আগের Git commit checkout অথবা backup থেকে **এই সাতটি changed source file** পুনঃস্থাপন করুন।
2. আগের matched Vite `public/build` assets restore করুন; নতুন source ও পুরোনো compiled assets মিশিয়ে রাখবেন না।
3. `php artisan optimize:clear`, প্রয়োজনীয় cache rebuild ও smoke tests চালিয়ে maintenance mode বন্ধ করুন।
4. **Database rollback প্রয়োজন নেই**, কারণ এই patch-এর কোনো migration / SQL change নেই এবং report creation writes করে না।

**Status: release candidate / staging validation required. Production-ready নয়।**
