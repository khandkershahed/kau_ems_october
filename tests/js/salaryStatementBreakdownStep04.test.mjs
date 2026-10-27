import test from 'node:test';
import assert from 'node:assert/strict';
import { salaryStatementBreakdown } from '../../resources/js/utils/salaryStatementBreakdown.js';

const regular = () => salaryStatementBreakdown([
  {name:'Basic Pay', calculated_amount:28715},
  {name:'House Rent', calculated_amount:8757},
  {name:'Medical', calculated_amount:1500},
], [
  {name:'Revenue Stamp',calculated_amount:10},
  {name:'General Provident Fund (GPF)',calculated_amount:2872},
], [
  {title:'Basic Arrear',direction:'payable',final_amount:300},
  {title:'Benefit Recovery',direction:'deduction',final_amount:75},
]);

test('Current, Arrear, Total independent columns reconcile', () => {
  const s=regular();
  assert.equal(s.summary.current.gross,38972);
  assert.equal(s.summary.current.deductions,2882);
  assert.equal(s.summary.arrear.net,225);
  assert.equal(s.summary.total.net,36315);
  assert.equal(s.provisional,false);
});

test('UNKNOWN does not become paid zero', () => {
  const s=salaryStatementBreakdown([{name:'Basic Pay',calculated_amount:1000}],[],[
    {title:'Unverified Special Benefit',direction:'deduction',final_amount:null},
  ]);
  assert.equal(s.provisional,true);
  assert.equal(s.summary.total.net,null);
  assert.equal(s.deductions[0].arrear,null);
  assert.equal(s.summary.provisional_display.net,1000);
});

test('Regular and arrear GPF remain separate',()=>{
  const s=salaryStatementBreakdown([{name:'Basic Pay',calculated_amount:28715}],
    [{name:'GPF',calculated_amount:2872}],
    [{title:'Additional GPF',direction:'deduction',final_amount:100}]);
  assert.equal(s.deductions.length,2);
  assert.equal(s.summary.total.deductions,2972);
});

test('Invalid amounts and directions are rejected',()=>{
  assert.throws(()=>salaryStatementBreakdown([{name:'Bad',calculated_amount:-10}],[],[]));
  assert.throws(()=>salaryStatementBreakdown([],[],[{title:'Oops',direction:'credit',final_amount:0}]));
});
