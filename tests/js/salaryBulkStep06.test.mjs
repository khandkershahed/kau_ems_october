import test from 'node:test';
import assert from 'node:assert/strict';
import {isReady,readyIds,hashesForSelected,previewCsv} from '../../resources/js/salaryBulkStep06.js';

const source = 'a'.repeat(64);
const ready = {user_id:42,status:'ready',source_hash:source,net_payable:36315};
const unknown = {user_id:43,status:'missing_evidence',reason:'UNKNOWN Special Benefit',net_payable:null};

test('only hash-bound preview rows are selectable',()=>{
 assert.deepEqual(readyIds([ready,unknown]),[42]);
 assert.equal(isReady({...ready,source_hash:null}),false);
});
test('selection preserves each employee source identity',()=>{
 assert.deepEqual(hashesForSelected([ready,unknown],[42]),{'42':source});
 assert.throws(()=>hashesForSelected([ready,unknown],[43]));
});
test('CSV preserves UNKNOWN and neutralizes spreadsheet formulas',()=>{
 const output=previewCsv([ready,{...unknown, name:'=1+1'}]);
 assert.match(output,/UNKNOWN Special Benefit/);
 assert.match(output,/'=1\+1/);
 assert.match(output,/""/);
});
test('exception export does not include ready rows',()=>{
 const output=previewCsv([ready,unknown],true);
 assert.match(output,/43/);
 assert.doesNotMatch(output,/36315/);
});
