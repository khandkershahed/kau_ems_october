<?php
require __DIR__.'/../app/Services/SalaryBulkPreviewPresenter.php';
require __DIR__.'/../app/Services/SalaryBulkSourceGuard.php';
use App\Services\SalaryBulkPreviewPresenter as Presenter;
use App\Services\SalaryBulkSourceGuard as Guard;
$checks=0;
function check(bool $ok, string $label): void {global $checks;if(!$ok)throw new RuntimeException('FAILED: '.$label);$checks++;echo 'PASS: '.$label."\n";}
$source=[
 'user_id'=>42,'source_hash'=>str_repeat('a',64),'regular'=>['basic'=>28715],
 'regular_additions'=>[], 'regular_deductions'=>[], 'net_payable'=>36315,
 'statement'=>['provisional'=>false,'unknown_arrears'=>[],
  'summary'=>['current'=>['gross'=>38972,'deductions'=>2882,'net'=>36090],
    'arrear'=>['gross'=>300,'deductions'=>75,'net'=>225],
    'total'=>['gross'=>39272,'deductions'=>2957,'net'=>36315]]]];
$r=Presenter::present(42,$source);
check($r['net_payable']===36315.0, 'Same net as authoritative composition');
check($r['arrear_net']===225.0, 'Current and Arrear reconciled');
check($r['source_hash']===str_repeat('a',64), 'Source hash included');
check(Presenter::failure(42,'UNKNOWN Benefit')['net_payable']===null, 'UNKNOWN not converted to zero');
check(Presenter::failure(42,'UNKNOWN Benefit')['status']==='missing_evidence', 'Missing evidence classified');
Guard::assertMatches(str_repeat('a',64),$r['source_hash']);
check(true,'Exact preview source accepted');
try {Guard::assertMatches(str_repeat('b',64),$r['source_hash']);throw new RuntimeException('Stale source accepted');}
catch(DomainException $e){check(true,'Stale source rejected');}
$source['statement']['provisional']=true;
try {Presenter::present(42,$source);throw new RuntimeException('Provisional amount was ready');}
catch(DomainException $e){check(true,'Provisional source excluded from posting');}
echo "$checks read-only checks passed.\n";
