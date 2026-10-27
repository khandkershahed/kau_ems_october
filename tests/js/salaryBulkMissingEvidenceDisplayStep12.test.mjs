import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const vue = readFileSync(new URL('../../resources/js/Pages/Salary/GenerateBills/Index.vue', import.meta.url), 'utf8');
test('bulk preview shows 0.00 (unverified), not a trusted zero, for missing arrear amounts', () => {
  assert.match(vue, /r\.arrear_payable==null\?'0\.00 \(unverified\)'/);
  assert.match(vue, /r\.arrear_deductions==null\?'0\.00 \(unverified\)'/);
  assert.doesNotMatch(vue, /r\.arrear_payable==null\?'UNKNOWN'/);
  assert.doesNotMatch(vue, /r\.arrear_deductions==null\?'UNKNOWN'/);
});
