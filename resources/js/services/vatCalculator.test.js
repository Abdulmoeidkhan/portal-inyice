import test from 'node:test';
import assert from 'node:assert/strict';
import { calculateVat, formatVatMoney } from './vatCalculator.js';

test('adds mixed rates and groups equivalent decimal rates', () => {
  const result = calculateVat([{ amount: '100', rate: '5' }, { amount: '200', rate: '5.00' }, { amount: '80', rate: '12.5' }, { amount: '20', rate: '0' }]);
  assert.deepEqual(result.totals, { net: 40000n, vat: 2500n, gross: 42500n });
  assert.deepEqual(result.groups.map(({ rate, vat }) => ({ rate, vat })), [{ rate: '5', vat: 1500n }, { rate: '12.5', vat: 1000n }, { rate: '0', vat: 0n }]);
});

test('extracts included VAT and preserves gross amount', () => {
  const result = calculateVat([{ amount: '112.50', rate: '12.5' }], 'inclusive');
  assert.deepEqual(result.totals, { net: 10000n, vat: 1250n, gross: 11250n });
});

test('rounds half up per row and sums rounded amounts', () => {
  const result = calculateVat([{ amount: '0.05', rate: '10' }, { amount: '0.05', rate: '10' }]);
  assert.equal(result.totals.vat, 2n);
  assert.equal(calculateVat([{ amount: '1.005', rate: '0' }]).totals.net, 101n);
  assert.deepEqual(calculateVat([{ amount: '0.05', rate: '100' }], 'inclusive').totals, { net: 2n, vat: 3n, gross: 5n });
});

test('supports currency precision and large values without floating point loss', () => {
  assert.equal(formatVatMoney(calculateVat([{ amount: '999999999999999999.99', rate: '0' }]).totals.gross), '999999999999999999.99');
  assert.equal(formatVatMoney(calculateVat([{ amount: '1', rate: '0.5' }], 'exclusive', 3).totals.vat, 3), '0.005');
  assert.equal(formatVatMoney(calculateVat([{ amount: '10', rate: '5' }], 'exclusive', 0).totals.vat, 0), '1');
});

test('rejects incomplete or invalid rows instead of showing partial totals', () => {
  for (const invalid of [null, '', '-1', 'abc', 'Infinity', '1e5', '1.1234567']) {
    const result = calculateVat([{ amount: '100', rate: '5' }, { amount: invalid, rate: '5' }]);
    assert.equal(result.valid, false);
    assert.equal(result.totals, null);
    assert.deepEqual(result.groups, []);
    assert.ok(result.lines[1].error);
    assert.equal(calculateVat([{ amount: '100', rate: invalid }]).valid, false);
  }
  assert.equal(calculateVat([{ amount: '0', rate: '0' }]).valid, true);
  assert.equal(calculateVat([]).valid, false);
  assert.throws(() => calculateVat([], 'invalid'));
  assert.throws(() => calculateVat([], 'exclusive', 6));
});
