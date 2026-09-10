// Fixed-point arithmetic keeps decimal input exact, including values beyond Number precision.
function decimal(value, label) {
  const text = String(value ?? '').trim();
  if (!/^\d{1,18}(?:\.\d{1,6})?$/.test(text)) {
    throw new Error(`${label}: enter a non-negative number with up to 18 whole and 6 decimal digits.`);
  }
  const [whole, fraction = ''] = text.split('.');
  return { value: BigInt(whole + fraction), scale: 10n ** BigInt(fraction.length) };
}

const round = (numerator, denominator) => (numerator * 2n + denominator) / (denominator * 2n);

export function formatVatMoney(value, precision = 2) {
  const text = value.toString().padStart(precision + 1, '0');
  return precision ? `${text.slice(0, -precision)}.${text.slice(-precision)}` : text;
}

export function calculateVat(rows, mode = 'exclusive', precision = 2) {
  if (!['exclusive', 'inclusive'].includes(mode)) throw new Error('Invalid calculation mode.');
  if (!Number.isInteger(precision) || precision < 0 || precision > 4) throw new Error('Invalid currency precision.');
  const unit = 10n ** BigInt(precision);
  const groups = new Map();
  const totals = { net: 0n, vat: 0n, gross: 0n };
  const lines = rows.map((row) => {
    try {
      const amount = decimal(row.amount, 'Amount');
      const rate = decimal(row.rate, 'VAT rate');
      const input = round(amount.value * unit, amount.scale);
      const base = 100n * rate.scale;
      const vat = mode === 'exclusive'
        ? round(input * rate.value, base)
        : round(input * rate.value, base + rate.value);
      const net = mode === 'exclusive' ? input : input - vat;
      const gross = mode === 'exclusive' ? input + vat : input;
      const rateKey = formatVatMoney(rate.value * 1000000n / rate.scale, 6).replace(/\.?0+$/, '');
      const key = rateKey || '0';
      const group = groups.get(key) || { rate: key, net: 0n, vat: 0n, gross: 0n };
      for (const [field, value] of Object.entries({ net, vat, gross })) {
        totals[field] += value;
        group[field] += value;
      }
      groups.set(key, group);
      return { ...row, net, vat, gross, error: null };
    } catch (error) {
      return { ...row, error: error.message };
    }
  });
  const valid = lines.length > 0 && lines.every((line) => !line.error);
  return { lines, valid, totals: valid ? totals : null, groups: valid ? [...groups.values()] : [] };
}
