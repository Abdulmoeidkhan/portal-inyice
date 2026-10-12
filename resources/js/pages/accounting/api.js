import { apiHeaders, readApi } from '../../services/developerApi';

export async function accountingApi(path = '', method = 'GET', body, signal) {
  const url = `/api/v1/accounting${path}`;
  if (method === 'GET') return readApi(url, signal);
  const response = await fetch(url, {
    method, signal, cache: 'no-store', headers: { ...apiHeaders(), 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const payload = await response.json();
  if (!response.ok) {
    throw new Error(Object.values(payload.errors || {}).flat().join(' ') || payload.message || payload.error || 'Request failed');
  }
  return payload;
}

export function localDate() {
  const now = new Date();
  return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

export const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 });

export function lineTotals(lines = []) {
  const units = (value) => {
    const text = String(value ?? '0');
    if (!/^\d{1,11}(\.\d{1,4})?$/.test(text)) return 0n;
    const [whole, fraction = ''] = text.split('.');
    return BigInt(whole) * 10000n + BigInt(fraction.padEnd(4, '0'));
  };
  const debit = lines.reduce((total, line) => total + units(line?.debit), 0n);
  const credit = lines.reduce((total, line) => total + units(line?.credit), 0n);
  const decimal = (value) => `${value / 10000n}.${String(value % 10000n).padStart(4, '0')}`;
  return { debit: decimal(debit), credit: decimal(credit), balanced: debit > 0n && debit === credit };
}
