# KAU EMS — Step 1: Pay Scale 2026 Configuration Integration

**উৎস:** `app(6).zip`, `kau-app-new-local(1).sql`, `Pasted markdown.md` ও সংশ্লিষ্ট screenshot। 24 October 2026 SQL snapshot ব্যবহার করা হয়েছে।

## কী বাস্তবায়িত হয়েছে

1. `app/Console/Commands/PreparePayScale2026.php` — নতুন Artisan command `kau:prepare-pay-scale-2026`। Default mode শুধু preview/CSV। `--apply` দিলেই SQL database-এ **inactive** 2026 designation group ও draft component template তৈরি হয়।
2. `app/Http/Controllers/PayrollComponentController.php` — configuration page-এ existing active scales-এর পাশাপাশি **inactive Govt. Pay Scale 2026** এবং তার Grades/Groups দেখাবে। এটি regular payroll নির্বাচন বা employee assignment পাল্টায় না।
3. `app/Http/Controllers/PayrollComponentTypeController.php` — component type configuration filter-এ 2026 স্কেল দেখাবে।
4. `tests/Unit/PayScale2026PreparationPolicyTest.php` — Special Benefit exclusion policy-এর tests।
5. `tests/Feature/PayScale2026ConfigurationTest.php` — preview no-write, inactive template creation, no monetary copy, idempotency-এর SQLite integration tests।

**সূত্র ডেটা থেকে অনুমিত প্রস্তুতির আকার:** সরকারি ২০১৫ স্কেলে ৮১টি Payroll Group ও ৭৪৯টি Payroll Component; এর মধ্যে ৭৭টি `Special benefit`; ২০২৬-এর জন্য ৮১টি draft group ও সর্বাধিক ৬৭২টি inactive zero-value component template প্রস্তাবিত। নতুন target-এর পূর্বেই manual setup থাকলে command বিদ্যমান row বদলাবে না। ২০২৬ গ্রেড ২০টি এবং stage ৩১৯টি আগেই আছে।

## কেন পুরোনো component amount কপি করা হয়নি

২০১৫ স্কেলের House Rent, Medical, Tax, GPF ও Special Benefit-এর amount/rate ২০২৬-এর সরকারি অনুমোদিত হার নয়। **`amount=0`, `isActive=false` মানে “HR configuration pending”; শূন্য entitlement নয়।** ২০১৫-এর amount CSV-তে কেবল review reference হিসেবে থাকবে। `Special benefit` নতুন স্কেলে template হিসেবে তৈরি হবে না। প্রকৃত ২০২৬ rate ও calculation rule Step 3-এ approved নথি অনুযায়ী কনফিগার ও পরীক্ষা হবে।

## Staging installation (Windows PowerShell)

### 1. প্রস্তুতি

- বর্তমান project এবং MySQL database-এর আলাদা backup নিন।
- ZIP-এর ফাইলগুলো **শুধু** বর্তমান Laravel project root-এ একই relative path-এ copy করুন। পুরো application overwrite করবেন না।
- `php artisan migrate:status` দেখে নিন; Step 1-এ নতুন database migration নেই।
- `php artisan optimize:clear` চালান।

### 2. আগে read-only preview

```powershell
php artisan kau:prepare-pay-scale-2026
```

Command report লেখে `storage/app/kau_pay_scale_2026_step1_preview.csv`-তে; database mutate করে না। Grade missing থাকলে fail করে।

### 3. Staging database-এ apply

```powershell
php artisan kau:prepare-pay-scale-2026 --apply
```

Expected result (বর্তমান SQL snapshot-এ): `group_created: 81`, `component_created: 672`, `special_skipped: 77`। রান করার আগেই কেউ ২০২৬ গ্রুপ বা component তৈরি করলে সংখ্যাগুলো কম হবে; command বিদ্যমান row overwrite করে না। Generated CSV: `storage/app/kau_pay_scale_2026_step1_applied.csv`।

চাইলে প্রথমে শুধু group তৈরি করা যায়: `php artisan kau:prepare-pay-scale-2026 --apply --groups-only`; পরে সাধারণ `--apply` দিলে missing draft component আসবে।

### 4. Tests

```powershell
php artisan test --filter=PayScale2026PreparationPolicyTest
php artisan test --filter=PayScale2026ConfigurationTest
php artisan test --filter=PayrollEffectivePolicyTest
php artisan test --filter=SalaryFixationCalculationTest
```

`Feature` tests PHPUnit-এর `DB_CONNECTION=sqlite` এবং `DB_DATABASE=:memory:` ব্যবহার করে; SQLite driver না থাকলে test environment ঠিক করতে হবে। **Test command কখনো live production database-এর বিরুদ্ধে চালাবেন না।**

### 5. UI verification

- **Payroll management → Assign desig. to grades:** `Govt. Pay Scale 2026 - Grade 1` নির্বাচন করলে ২০১৫-এর Grade 1 থেকে কপি করা inactive designation mappings দেখা যাবে (যদি source assignment থাকে)।
- **Payroll management → Payroll Components:** Pay Scale dropdown-এ 2026 স্কেল দেখা যাবে। 2026 filter নির্বাচন করলে **Inactive** draft components দেখা যাবে (Status filter `All` রাখুন)।
- Component editing page-এ designation group dropdown-এ ২০২৬-এর inactive groups-ও দেখা যাবে। HR অনুমোদনের আগে ২০২৬ template amount/active status বদলাবেন না।
- Salary Generate, Employee Salary Setup এবং approved historical bills-এর আচরণ অপরিবর্তিত থাকবে।

### 6. Database read-only checks (Tinker)

```powershell
php artisan tinker --execute='dump(DB::table("payroll_groups")->join("grades", "grades.id", "=", "payroll_groups.grade_id")->join("pay_scales", "pay_scales.id", "=", "grades.pay_scale_id")->where("pay_scales.scale_name", "Govt. Pay Scale 2026")->count());'
```

2026 group count verify করার পর components ও activity status UI-তে পরীক্ষা করুন। ভুল দেখে পুরোনো record drop/rollback করবেন না।

## Safety rules

- ২০১৫ Pay Scale, Grades, Components, salary setups বা generated salary bills **একটিও পরিবর্তন করা হয় না**।
- `Govt. Pay Scale 2026` `isActive=false` থাকে। ২০২৭-০৭-০১ কার্যকর হওয়ার policy অপরিবর্তিত।
- নতুন groups/components `isActive=false`। নতুন component amount `0.00` — approved rate নয়।
- Existing 2026 groups/components overwrite বা overwrite-সদৃশ update নেই।
- Dry-run DB-তে পরিবর্তন করে না। `--apply` database transaction ব্যবহার করে।
- `National Pay Scale 2015`-এর পৃথক Honorarium mapping-এর ২০২৬ counterpart নেই; আলাদাভাবে HR সিদ্ধান্ত ছাড়া clone করা হবে না।
- নতুন migration নয়; `migrate:rollback` বা `migrate:fresh` চালানোর প্রয়োজন নেই।

## Step 1 completion / acceptance criteria

- [ ] Preview command সফল এবং mapping CSV HR review করেছে।
- [ ] ২০২৬-এ ৮১টি group expected (আগে ০, staging-এ অন্য data না থাকলে)।
- [ ] ৬৭২টি template expected, সব inactive/zero; ৭৭টি Special Benefit exclude।
- [ ] Existing 2015 group=81 and component=749 unchanged।
- [ ] 2026 Pay Scale ও Grades inactive।
- [ ] Designation mapping ও Payroll Components dropdown কাজ করে।
- [ ] PHP/Feature tests pass এবং দুবার apply করলে row count বাড়ে না।
- [ ] Salary Generator অক্টোবর ২০২৬-এর ক্ষেত্রে ২০১৫ নিয়মেই চলে।

## টেস্টিং সীমাবদ্ধতা

এই artifact প্রস্তুতির পরিবেশে local MySQL এবং Composer vendor packages নেই; তাই এই turn-এ live Laravel migration, DB command, Vue browser UI এবং PHPUnit feature tests সম্পাদন করা সম্ভব হয়নি। PHP syntax, source-data inventory ও standalone policy smoke checks পাস করেছে। **কোনো live database-এ patch apply করা হয়নি।**
