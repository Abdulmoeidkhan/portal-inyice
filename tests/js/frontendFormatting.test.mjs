import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { formatMoney } from '../../resources/js/services/numberFormat.js';
import { localDateString } from '../../resources/js/services/dateFormat.js';

test('shared money formatting preserves legacy rounding, fallbacks, and special values', () => {
  const values = [undefined, null, '', false, 0, -0, '0', '12.345', -12.345, 1.005, 999999999.999, NaN, Infinity, -Infinity, 'invalid'];
  for (const value of values) {
    const previous = Number(value || 0).toLocaleString(undefined, {
      minimumFractionDigits: 2, maximumFractionDigits: 2,
    });
    assert.equal(formatMoney(value), previous, String(value));
  }
});

test('local dates preserve leap days and do not mutate the input', () => {
  const date = new Date(2024, 1, 29, 23, 59);
  const timestamp = date.getTime();
  assert.equal(localDateString(date), '2024-02-29');
  assert.equal(date.getTime(), timestamp);
  assert.throws(() => localDateString(new Date('invalid')), RangeError);
});

test('local dates remain calendar dates across UTC boundaries and daylight saving', () => {
  const moduleUrl = new URL('../../resources/js/services/dateFormat.js', import.meta.url).href;
  for (const timezone of ['UTC', 'Asia/Karachi', 'America/New_York', 'Pacific/Kiritimati']) {
    const result = spawnSync(process.execPath, ['--input-type=module', '-e', `
      import assert from 'node:assert/strict';
      import { localDateString } from ${JSON.stringify(moduleUrl)};
      for (const [year, month, day] of [[2026, 0, 1], [2026, 2, 8], [2026, 10, 1], [2026, 11, 31]]) {
        const expected = [year, String(month + 1).padStart(2, '0'), String(day).padStart(2, '0')].join('-');
        for (const hour of [0, 12, 23]) assert.equal(localDateString(new Date(year, month, day, hour, 30)), expected);
      }
    `], { env: { ...process.env, TZ: timezone }, encoding: 'utf8' });
    assert.equal(result.status, 0, `${timezone}: ${result.stderr}`);
  }
});
