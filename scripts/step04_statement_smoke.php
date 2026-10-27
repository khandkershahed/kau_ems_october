<?php
/** Standalone, read-only test: php scripts/step04_statement_smoke.php */
require dirname(__DIR__).'/app/Services/ApprovedFixationComponentProjector.php';
require dirname(__DIR__).'/app/Services/SalaryCalculationService.php';
require dirname(__DIR__).'/app/Services/SalaryStatementBreakdown.php';

use App\Services\SalaryCalculationService;
use App\Services\SalaryStatementBreakdown;

$passed = 0;
$check = static function(bool $condition, string $label) use (&$passed): void {
    if (!$condition) throw new RuntimeException('FAIL: '.$label);
    echo 'PASS: '.$label.PHP_EOL;
    $passed++;
};
$financial = (new SalaryCalculationService())->calculate([
    ['name'=>'Basic Pay','calcType'=>'fixed','amount'=>28715],
    ['name'=>'House Rent','calcType'=>'percentage','amount'=>40,'percentage_base_amount'=>21892.5],
    ['name'=>'Medical Allowance','calcType'=>'fixed','amount'=>1500],
],[
    ['name'=>'General Provident Fund (GPF)','calcType'=>'fixed','amount'=>2872,'component_source'=>'employee_month_override'],
    ['name'=>'Revenue Stamp','calcType'=>'fixed','amount'=>10],
]);
$statement = SalaryStatementBreakdown::build($financial, [
    ['title'=>'Basic Arrear','direction'=>'payable','final_amount'=>300],
    ['title'=>'Benefit Recovery','direction'=>'deduction','final_amount'=>75],
]);
$check($statement['summary']['current']['gross'] == 38972.0, 'Current Gross');
$check($statement['summary']['current']['deductions'] == 2882.0, 'Current GPF + stamp');
$check($statement['summary']['arrear']['net'] == 225.0, 'Arrear Net');
$check($statement['summary']['total']['net'] == 36315.0, 'Total Net');
$check(!$statement['provisional'], 'Complete evidence');
$unknown = SalaryStatementBreakdown::build($financial, [
    ['title'=>'Missing Paid Benefit','direction'=>'deduction','final_amount'=>null],
]);
$check($unknown['provisional'] && $unknown['summary']['total']['net'] === null, 'UNKNOWN never paid zero');
$check($unknown['deductions'][2]['arrear'] === null, 'UNKNOWN line preserved');
$invalid = false;
try { SalaryStatementBreakdown::build($financial, [['title'=>'Bad','direction'=>'deduction','final_amount'=>-1]]); }
catch (DomainException) { $invalid = true; }
$check($invalid, 'Negative deduction rejected');
echo "{$passed} checks passed.".PHP_EOL;
