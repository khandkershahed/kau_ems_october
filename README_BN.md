# Phase 1 — ঐতিহাসিক বেতন ইমপোর্টার ও রিকনসিলিয়েশন (KAU EMS)

**সম্পন্ন তারিখ:** ২৭ অক্টোবর ২০২৬
**স্কোপ:** জুন–সেপ্টেম্বর ২০২৬ ঐতিহাসিক বেতন বিল ইমপোর্ট, কনফ্লিক্ট শনাক্তকরণ, রোলব্যাক সাপোর্ট

---

## ১. ফাইল যা তৈরি করা হয়েছে (Created Files)

| ফাইল | উদ্দেশ্য |
|---|---|
| `database/migrations/2026_10_27_000001_create_salary_import_tables.php` | ৩টি নতুন টেবিল: `salary_import_runs`, `salary_import_records`, `salary_import_backups` |
| `app/Services/HistoricalSalaryImportService.php` | মূল ইমপোর্টার সার্ভিস: ডাম্প পার্সিং, ক্লাসিফিকেশন, ইনসার্ট, রিপ্লেসমেন্ট, রোলব্যাক |
| `app/Console/Commands/ImportHistoricalSalaries.php` | `kau:import-historical-salaries` — dry-run / apply / rollback কমান্ড |
| `app/Console/Commands/ReplaceHistoricalConflicts.php` | `kau:replace-historical-conflicts` — স্পষ্ট নিশ্চয়তা ছাড়া কনফ্লিক্ট রিপ্লেস করে না |
| `scripts/phase1_*.php` (৭টি) | যাচাই ও রিপোর্ট স্ক্রিপ্ট (read-only) |
| `docs/phase1_import_report.csv` | সম্পূর্ণ ইমপোর্ট ডিসিশন রিপোর্ট |
| `README_BN.md` | এই ডকুমেন্ট |

## ২. ফাইল যা পরিবর্তন করা হয়েছে (Modified Files)

- **কোনো বিদ্যমান অ্যাপ্লিকেশন ফাইল পরিবর্তন করা হয়নি।** সব পরিবর্তন নতুন ফাইল হিসেবে যোগ করা হয়েছে।
- ডাটাবেসে: মাইগ্রেশন চালানো হয়েছে (৩টি টেবিল তৈরি), ৪১৫টি রো `salary_generates`-এ ইনসার্ট হয়েছে।

## ৩. সোর্স ফাইল SHA256

```
database/sql/kau-app.sql
SHA256: 8fb1574a705e658c7f44263fa9df7b298ef590f2253c288dcc3e6461ba95fae2
```

প্রত্যাশিত রেফারেন্স SHA256-এর সাথে **হুবহু মিলেছে** — কোনো পার্থক্য নেই।

## ৪. ডাটাবেস ব্যাকআপ

```
ফাইল: database/sql/backups/codershat_kau_phase1_backup_20261027_024421.sql
আকার: 29,349,252 bytes (29.3 MB)
SHA256: EAA02C17DC96CF3B228DDA17101252BDAADF5DE38588F068DDC9ECBB0EF8E174
তৈরি: mysqldump -h 127.0.0.1 codershat_kau (ইমপোর্টের আগে)
ভ্যালিডেশন: ৮৩টি CREATE TABLE, INSERT স্টেটমেন্ট উপস্থিত — ভ্যালিড
```

## ৫. ডাটাবেস কানেকশন যাচাই

```
DB      : codershat_kau
HOST    : 127.0.0.1 (লোকাল)
PORT    : 3306
VERSION : MySQL 8.4.3
SELECT DATABASE() = codershat_kau ✓ (কনফিগের সাথে মিলছে)
```

## ৬. ঐতিহাসিক SQL সোর্স যাচাই

- ডাম্প কখনোই সরাসরি এক্সিকিউট করা হয়নি — শুধু `INSERT INTO salary_generates` রো পার্স করা হয়েছে।
- স্কিমা: বর্তমান স্কিমার সাথে হুবহু মিলছে (কলাম অর্ডার সহ)।
- JSON কম্পোনেন্ট ফরম্যাট: `[{name, amount, calcType, is_taxable, component_type, calculated_amount}]`
- পার্স হওয়া ঐতিহাসিক রো (জুন–সেপ্টেম্বর ২০২৬): **১৬৩৪টি**
  - জুন: ৪০৪, জুলাই: ৪০৬, আগস্ট: ৪১২, সেপ্টেম্বর: ৪১২


## ৮. প্রকৃত ইমপোর্ট ফলাফল (Run id=2)

```
kau:import-historical-salaries --apply --report=docs/phase1_import_report.csv
Run id: 2 — status: completed
inserted: 415
skipped:  1217
conflict: 2 (NOT overwritten)
unmatched: 0
```

**ইনসার্ট বিতরণ (মাস অনুযায়ী):**
- ২০২৬-৬: ২টি (40173 জুন, 40033 জুন)
- ২০২৬-৭: ২টি (40173 জুলাই, 40033 জুলাই)
- ২০২৬-৯: ৪১১টি (সম্পূর্ণ সেপ্টেম্বর বিল, ১টি conflict বাদে)
- আগস্ট: ০টি (সব বিদ্যমান ছিল)

**ইনসার্ট হওয়া রো-র সারসংক্ষেপ:**
- reviewStatus: সব ৪১৫টি = `approved` (ঐতিহাসিক বিল approved হিসেবে ইমপোর্ট; approval ≠ payment)
- মোট আলাউন্স: ৳১৪,৭৮৩,০৫২.২৩
- মোট ডিডাকশন: ৳৯৯৫,৯২৯.০০
- মোট নেট (salaryTotal): ৳১৩,৭৮৭,১২৩.২৩

## ৯. কর্মচারী ম্যাচিং রিপোর্ট

- ম্যাচিং নীতি: `employeeCode → employee_infos.employeeCode → users.id`
- ম্যাচড: ১০২৬ ইউনিক কোড (সব রো ম্যাপ হয়েছে)
- আনম্যাচড: ০
- অ্যাম্বিগুয়াস (ডুপ্লিকেট কোড): ০
- উদাহরণ: 20057 → user_id=58 (Ashik Ahmed)

## ১০. বিদ্যমান বেতন কনফ্লিক্ট রিপোর্ট

মাত্র ২টি কনফ্লিক্ট — কোনোটিই অটো-ওভাররাইট হয়নি:

### কনফ্লিক্ট ১: 40173 (আগস্ট ২০২৬)
- সোর্স id=5448, বর্তমান target id=5455
- সোর্স net: ৳18478.00 — বর্তমান net: ৳18478.00 (**আর্থিক মান সমান**)
- পার্থক্য শুধু **JSON কম্পোনেন্ট স্ট্রাকচারে** (বর্তমান রো নতুন কম্পোনেন্ট স্ট্রাকচারে regenerate হয়েছে)
- বর্তমান অবস্থা: approved
- **সিদ্ধান্ত:** আর্থিক মান সমান হওয়ায় এটি কোনো আসল ডেটা ইনটিগ্রিটি সমস্যা নয়। ওভাররাইট **প্রয়োজন নেই**।

### কনফ্লিক্ট ২: 20057 (সেপ্টেম্বর ২০২৬)
- সোর্স id=5903, বর্তমান target id=5850
- ঐতিহাসিক (সোর্স): totalAllowance=33136, totalDeduction=1956, net=31180, gpf=1946
- বর্তমান: totalAllowance=70027, totalDeduction=18462.50, net=51564.50, gpf=2872
- বর্তমান কম্পোনেন্ট: Basic Pay=28715 (উচ্চ!), Basic Arrear=30555, Basic Difference/Old Increment=2790, Special Benefit Recovery=8757, House Rent Difference=1255.5, Additional GPF=2778
- বর্তমান অবস্থা: pending
- **সিদ্ধান্ত:** এটি fixation/arrear প্রসেসিং-এর ফলাফল — বর্তমান pending রো অ্যারিয়ার-সহ নতুন মানে তৈরি হয়েছে। এটি **রিপোর্ট করা হয়েছে** এবং অটো-ওভাররাইট করা হয়নি। সিদ্ধান্ত নিতে হবে: ঐতিহাসিক মানে প্রতিস্থাপন (rollback) করা হবে নাকি অ্যারিয়ার-সহ মান রাখা হবে।

## ১১. কর্মচারী 20057 রিকনসিলিয়েশন

| মাস | বিল | Basic (ঐতিহাসিক) | Basic (বর্তমান) | মিলছে | অবস্থা |
|---|---|---|---|---|---|
| জুন ২০২৬ | id=4770 | 18530 | 18530 | ✓ | approved |
| জুলাই ২০২৬ | id=5175 | 19460 | 19460 | ✓ | approved |
| আগস্ট ২০২৬ | id=5614 | 19460 | 19460 | ✓ | approved |
| সেপ্টেম্বর ২০২৬ | id=5850 | 19460 | 28715 (+arrear) | ✗ | pending — conflict |

- জুন–আগস্ট: সম্পূর্ণ আর্থিক মিল (totalAllowance/totalDeduction/salaryTotal সহ)
- সেপ্টেম্বর: বর্তমান pending রো অ্যারিয়ার কম্পোনেন্ট সহ (28715 Basic + 30555 Arrear) — ঐতিহাসিক ডাম্পের সাথে ভিন্ন। রিপোর্ট করা হয়েছে।

## ১২. ইমপোর্ট করা বেতন যাচাই

- ৪১৫টি ইনসার্ট হওয়া রো-র প্রতিটির প্রোভেন্যান্স `salary_import_records`-এ রেকর্ড করা হয়েছে (source_row_id → target_salary_generate_id)
- অরফান প্রোভেন্যান্স রো: ০
- মাস/বছর বিতরণ ডাম্পের সাথে মিলছে: সেপ্টেম্বর 2026 বর্তমানে ৪১২ রো (১টি প্রি-এক্সিস্টিং + ৪১১ ইনসার্ট)
- জুন 2026: ৪০৬, জুলাই ২০২৬: ৪০৮, আগস্ট ২০২৬: ৪১২

## ১৩. ডুপ্লিকেট ডিটেকশন

- user_id + monthId + year দ্বারা জুন–সেপ্টেম্বর ২০২৬ পরিদর্শন:
- **ডুপ্লিকেট কর্মচারী-মাস পেয়ার: ০** ✓

## ১৪. রোলব্যাক নির্দেশনা

```bash
# Run id=2 দ্বারা ইনসার্ট হওয়া সব রো ডিলিট করবে (শুধু প্রোভেন্স ম্যাচড রো):
php artisan kau:import-historical-salaries --rollback-token=2

# কনফ্লিক্ট রিপ্লেসমেন্ট হলে ব্যাকআপ টেবিল:
# salary_import_backups টেবিলে প্রতিটি রিপ্লেস করা রো-র JSON ব্যাকআপ আছে
```

ব্যাকআপ দিয়ে পূর্ণ পুনরুদ্ধার:
```bash
mysql -h 127.0.0.1 -u root codershat_kau < database/sql/backups/codershat_kau_phase1_backup_20261027_024421.sql
```

## ১৫. টেস্ট / চেক ফলাফল

- `php artisan migrate:status` — ৩টি নতুন টেবিল মাইগ্রেশন রান হয়েছে
- ইমপোর্টের আগে: প্রথম আংশিক apply রানে NULL হ্যান্ডলিং বাগ পাওয়া যায়; ট্রানজেকশন পূর্ণ রোলব্যাক হয়েছে (run 1 = rolled_back), বাগ ফিক্সের পরে রান ২ সফলভাবে সম্পন্ন।
- ইমপোর্টের পরে ডুপ্লিকেট চেক: ০ ডুপ্লিকেট
- অরফান প্রোভেন্যান্স চেক: ০
- কনফ্লিক্ট ফাইন্যান্সিয়াল ম্যাচ চেক: 40173 আগস্ট আর্থিকভাবে সমান, 20057 সেপ্টেম্বর ভিন্ন

## ১৬. অবশিষ্ট সমস্যা / সিদ্ধান্ত প্রয়োজন

1. **20057 সেপ্টেম্বর ২০২৬ কনফ্লিক্ট** — বর্তমান pending রো অ্যারিয়ার-সহ (Basic 28715 + Arrear 30555) ভিন্ন মানে আছে; ঐতিহাসিক ডাম্প Basic 19460। সিদ্ধান্ত প্রয়োজন: কোন মান রাখবেন?
2. **40173 আগস্ট ২০২৬** — শুধু JSON কম্পোনেন্ট স্ট্রাকচার ভিন্ন; আর্থিক মান সমান; অ্যাকশন প্রয়োজন নেই (কিন্তু চাইলে `kau:replace-historical-conflicts --apply` চালানো যায়)।
3. **Phase 2** এর জন্য অপেক্ষা: Salary Fixation Filters, Bulk Approval, Arrear Calculation, etc.

## ৭. Dry-run ফলাফল

| ক্লাসিফিকেশন | সংখ্যা |
|---|---|
| insert (missing) | ৪১৫ |
| skip (identical) | ১২১৭ |
| conflict (differs, NOT overwritten) | ২ |
| unmatched (no employeeCode match) | ০ |
| ambiguous (duplicate code) | ০ |
