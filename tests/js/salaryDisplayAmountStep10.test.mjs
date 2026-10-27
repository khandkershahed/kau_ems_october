import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { salaryDisplayAmount } from '../../resources/js/utils/salaryDisplayAmount.js';
import { salaryStatementBreakdown } from '../../resources/js/utils/salaryStatementBreakdown.js';

test('presentation-only missing values render 0.00', () => {
    assert.equal(salaryDisplayAmount(null), '0.00');
    assert.equal(salaryDisplayAmount(undefined), '0.00');
    assert.equal(salaryDisplayAmount(''), '0.00');
    assert.equal(salaryDisplayAmount(0), '0.00');
    assert.equal(salaryDisplayAmount(28715), '28,715.00');
});

test('missing arrear remains null and provisional, unlike a verified zero', () => {
    const data = (value) => salaryStatementBreakdown(
        [{name:'Basic Pay', calculated_amount:10000}],
        [{name:'Revenue Stamp', calculated_amount:10}],
        [{title:'Special Benefit Recovery',direction:'deduction', final_amount:value}],
    );
    const missing = data(null);
    const zero = data(0);
    assert.equal(missing.provisional, true);
    assert.equal(missing.summary.total.net, null);
    assert.equal(missing.deductions.at(-1).arrear, null);
    assert.equal(zero.provisional, false);
    assert.equal(zero.summary.total.net, 9990);
    assert.equal(zero.deductions.at(-1).arrear, 0);
    assert.equal(salaryDisplayAmount(missing.summary.total.net), '0.00');
});

test('calculator keeps posting guard and CSV evidence markers', () => {
    const source = readFileSync(new URL('../../resources/js/Pages/Salary/Calculator/Entry.vue', import.meta.url), 'utf8');
    assert.match(source, /if\(validation\.value\|\|arrearUnknown\.value\.length\)/);
    assert.match(source, /MISSING_EVIDENCE/);
    assert.match(source, /0\.00 \(unverified\)/);
});
