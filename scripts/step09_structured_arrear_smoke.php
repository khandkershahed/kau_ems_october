<?php
require __DIR__.'/../app/Services/SalaryPayScaleArrearEngine.php';
require __DIR__.'/../app/Services/SalaryStructuredArrearService.php';
use App\Services\SalaryStructuredArrearService as Engine;
function check(bool $pass, string $name): void { if (!$pass) {fwrite(STDERR, "FAIL: $name\n");exit(1);} echo "PASS: $name\n"; }
$months=['2026-07'=>72268,'2026-08'=>72268,'2026-09'=>72268];
$input=['june_basic'=>52480,'old_increment'=>2630,'revised_basics'=>$months];
$rows=Engine::compute($input);
check($rows['BASIC_ARREAR_GROSS']['amount']===59364.0,'Three phased entitlement months');
check($rows['BASIC_INCREMENT_ADJUSTMENT']['amount']===7890.0,'Old increment counted once per month');
check($rows['SPECIAL_BENEFIT_RECOVERY']['amount']===null,'Missing benefit not paid zero');
check($rows['HOUSE_RENT_RECOVERY']['amount']===null,'No inferred future house rent');
check($rows['ADDITIONAL_GPF']['amount']===null,'GPF without evidence unknown');
$input['revised_basics']['2026-08']=73000;
check(Engine::compute($input)['BASIC_ARREAR_GROSS']['amount']===60096.0,'Month-wise entitlement changes');
foreach (array_keys($months) as $month) {
 $input['paid_months'][$month]=['paid_special_benefit'=>0,'paid_house_rent'=>1000,'paid_gpf'=>100];
 $input['financial_months'][$month]=['entitled_house_rent'=>1100,'required_gpf'=>150];
}
$rows=Engine::compute($input);
check($rows['HOUSE_RENT_RECOVERY']['direction']==='payable'&&$rows['HOUSE_RENT_RECOVERY']['amount']===300.0,'Signed house adjustment');
check($rows['ADDITIONAL_GPF']['amount']===150.0,'Additional GPF independent of regular GPF');
check($rows['SPECIAL_BENEFIT_RECOVERY']['amount']===0.0,'Verified zero distinct from unknown');
check(Engine::applicable(2026,10)&&!Engine::applicable(2026,9),'October only: no regressions to other periods');
echo "10 checks passed. PURE TEST: no database was opened.\n";
