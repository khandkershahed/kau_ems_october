import assert from 'node:assert/strict';
import { salaryProvisionalTotals } from '../../resources/js/utils/salaryProvisionalTotals.js';
const preview=salaryProvisionalTotals(39472,2882,[
  {direction:'payable',final_amount:30555},
  {direction:'deduction',final_amount:null},
  {direction:'deduction',final_amount:null},
  {direction:'deduction',final_amount:null},
  {direction:'deduction',final_amount:null},
]);
assert.deepEqual(preview,{payable:30555,arrearDeductions:0,gross:70027,deductions:2882,net:67145,provisional:true,unknownCount:4});
const confirmed=salaryProvisionalTotals(39472,2882,[
  {direction:'payable',final_amount:30555},
  {direction:'deduction',final_amount:2790},
  {direction:'deduction',final_amount:8757},
  {direction:'deduction',final_amount:0},
  {direction:'deduction',final_amount:2775},
]);
assert.equal(confirmed.net,52823);
assert.equal(confirmed.provisional,false);
const original=salaryProvisionalTotals(33136,1956,[]);
assert.equal(original.net,31180);
assert.throws(()=>salaryProvisionalTotals(100,0,[{direction:'deduction',final_amount:-1}]));
console.log('PASS: 4 salary provisional display tests (preview, verified, historical, invalid)');
