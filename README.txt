KAU Historical Arrear Source Patch (READ-ONLY calculation, no DB migration)
Patch basis: app(20261027-054346).zip.
Change: June 2026 approved historical salary used as baseline; month-specific actual old-scale increment (historical July/Aug/Sept basic - June basic); approved fixation still required for revised Basic; verified policy still required for revised House Rent and GPF.
WARNING: Check git diff before applying: this patch is from the attached codebase before later local Cline regular-only modifications. Only SalaryStructuredArrearService.php is replaced.
Commands: php -l app/Services/SalaryStructuredArrearService.php; php artisan test --filter=SalaryArrearCalculatorTest
DO NOT post payroll until preview verified and bank report reconciled.
