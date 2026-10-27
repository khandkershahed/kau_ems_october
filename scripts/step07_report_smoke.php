<?php
// Standalone pure report-source arithmetic smoke test; no Laravel DB required.
require dirname(__DIR__).'/app/Services/SalaryOfficialReportSource.php';
use App\Services\SalaryOfficialReportSource;
$entries = new ReflectionMethod(SalaryOfficialReportSource::class, 'entries');
$sum = new ReflectionMethod(SalaryOfficialReportSource::class, 'sumRows');
$checks = [
    ['type'=>'fixed','name'=>'Basic Pay','amount'=>43170],
    ['type'=>'percentage','name'=>'House Rent','calcType'=>'percentage','amount'=>40,'calculated_amount'=>17268],
    ['type'=>'fixed','name'=>'Medical','amount'=>1500],
];
$rows = $entries->invoke(null, $checks, 'allowance');
assert(abs($rows[0]['current'] - 43170) < .001);
assert($sum->invoke(null, $rows) === 6193800);
echo "PASS saved components and payable cents\n";
$arrear = $entries->invoke(null, [['name'=>'Basic arrear','amount'=>1000,'arrear_phase5'=>true]], 'allowance');
assert($arrear[0]['current'] === 0.0 && $arrear[0]['arrear'] === 1000.0);
echo "PASS independent current and arrear\n";
$unknown = $entries->invoke(null, [['name'=>'Rent percent','calcType'=>'percentage','amount'=>40]], 'allowance');
assert($sum->invoke(null, $unknown) === null);
echo "PASS missing frozen amount preserved as UNKNOWN\n";
try { $entries->invoke(null, [['name'=>'Bad','amount'=>-2]], 'deduction'); throw new Exception('Not rejected'); }
catch (DomainException $expected) { echo "PASS invalid amount rejected\n"; }
echo "4 pure checks passed; NO DATABASE OPENED.\n";
