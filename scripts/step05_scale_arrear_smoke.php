<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/Support/SalaryOldScaleIncrementReference.php';
require dirname(__DIR__).'/app/Support/SalaryArrearTemplateCatalog.php';
require dirname(__DIR__).'/app/Services/SalaryPayScaleArrearEngine.php';
require dirname(__DIR__).'/app/Services/SalaryArrearFormulaEngine.php';

use App\Services\SalaryPayScaleArrearEngine as Engine;
use App\Services\SalaryArrearFormulaEngine;
use App\Support\SalaryArrearTemplateCatalog;

$tests = 0;
function check(bool $condition, string $label): void {
    global $tests;
    if (!$condition) throw new RuntimeException('FAIL: '.$label);
    $tests++;
    echo 'PASS: '.$label.PHP_EOL;
}
$m = array_fill_keys(Engine::MONTHS, [
    'june_basic'=>18530,'old_scale_basic'=>19460,'entitled_basic'=>28715,
    'paid_special_benefit'=>2919,'paid_house_rent'=>8757,'entitled_house_rent'=>8757,
    'paid_gpf'=>1947,'required_gpf'=>2872,
]);
$v = Engine::reconcile($m);
check($v['totals']['basic_gross'] === 30555.0, 'Scale-based gross Basic');
check($v['totals']['old_increment'] === 2790.0, 'Old increment once');
check($v['totals']['basic_net'] === 27765.0, 'Basic net reconciles');
check($v['net_arrear'] === 16233.0, '3-month net arrear');
check($v['status'] === 'ESTIMATED_RECONCILIATION', 'Not invented verified payments');
$changed=$m; $changed['2026-09']['entitled_basic']=30000;
check(Engine::reconcile($changed)['totals']['basic_net'] === 29050.0, 'Distinct monthly entitlement');
$missing=$m; unset($missing['2026-09']['paid_special_benefit']);
check(Engine::reconcile($missing)['net_arrear'] === null, 'Missing benefit remains UNKNOWN');
$noBenefit=$m; $noBenefit['2026-09']['paid_special_benefit']=0;
check(Engine::reconcile($noBenefit)['net_arrear'] === 19152.0, 'Verified zero distinct from UNKNOWN');
$positive=Engine::directionalHouseRent(100.0);
$negative=Engine::directionalHouseRent(-100.0);
check($positive['direction']==='payable' && $negative['direction']==='deduction', 'Signed House Rent direction');
$stages=[18530,19460,20440];
check(Engine::oldScaleIncrement($stages,18530,['hold_increment'=>true])['amount'] === 0.0, 'Increment hold');
check(Engine::oldScaleIncrement($stages,18530,['special_eligibility_category'=>'adhoc'])['amount'] === 0.0, 'AD HOC no increment');
check(Engine::oldScaleIncrement($stages,18530,['special_eligibility_category'=>'study_leave_exception','first_increment_exception'=>true])['amount'] === 930.0,'Study Leave approved exception');
check(Engine::oldScaleIncrement([18000,18900],18530,[])['amount'] === null,'Unknown old stage stays UNKNOWN');
check(Engine::phasedReference(6,'2026-07',52480,90700)['rate']===40,'Grade 6 July rate');
check(Engine::phasedReference(10,'2027-01',18530,38900)['rate']===75,'Grade 10 Jan 2027 rate');
$defs=SalaryArrearTemplateCatalog::definitions();
check(count($defs)===5 && !str_contains($defs[0]['formula'],'* 3'), 'Five revision-safe rule templates');
$inputs=array_fill(0,3,['REVISED_BASIC'=>28715,'JUNE_BASIC'=>18530,
    'OLD_SCALE_INCREMENT'=>930,'PAID_SPECIAL_BENEFIT'=>2919,
    'OCTOBER_HOUSE_RENT'=>8757,'JUNE_HOUSE_RENT'=>8757,
    'OCTOBER_GPF'=>2872,'JUNE_GPF'=>1947]);
$formula=new SalaryArrearFormulaEngine;
$values=array_map(static fn($r)=>$formula->evaluate($r['formula'],[], $inputs),$defs);
check(array_sum([$values[0],-$values[1],-$values[2],-$values[3],-$values[4]])===16233.0,'Published expression arithmetic agrees with reconciliation');
echo "PASS: {$tests} checks. No database was opened or written.".PHP_EOL;
