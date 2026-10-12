import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { readApi, readApiResponse, clearDeveloperCache, DEV_CACHE_TTL } from '../../resources/js/services/developerApi.js';

let calls;
let clock;
const realNow = Date.now;
const user = { uid: 'dev-one', role: 'dev', company_id: 1, tenant_id: 1 };

test('plan checks and paid documentation always use live responses', async () => {
  for (const path of ['/api/v1/company-profile', '/api/v1/api-documentation']) {
    const first = await readApi(path);
    const second = await readApi(path);
    assert.notEqual(first.version, second.version);
  }
});
beforeEach(() => {
  globalThis.localStorage = {};
  Object.defineProperties(localStorage, {
    getItem: { value: (key) => localStorage[key] ?? null },
    setItem: { value: (key, value) => { localStorage[key] = String(value); }, configurable: true },
    removeItem: { value: (key) => { delete localStorage[key]; } },
  });
  globalThis.window = { location: { origin: 'https://portal.test' } };
  Object.defineProperty(globalThis, 'navigator', { value: { onLine: true }, configurable: true });
  clearDeveloperCache();
  localStorage.setItem('user', JSON.stringify(user));
  localStorage.setItem('auth_token', 'session-one');
  calls = 0;
  clock = realNow();
  Date.now = () => clock;
  globalThis.fetch = async () => { calls++; return Response.json({ version: calls }); };
});

test('reuses saved data until exactly 24 hours and canonicalizes query order', async () => {
  assert.deepEqual(await readApi('/api/v1/dev/data/orders?page=1&per_page=25'), { version: 1 });
  clock += DEV_CACHE_TTL - 1;
  assert.deepEqual(await readApi('/api/v1/dev/data/orders?per_page=25&page=1'), { version: 1 });
  assert.equal(calls, 1);
  clock++;
  assert.deepEqual(await readApi('/api/v1/dev/data/orders?page=1&per_page=25'), { version: 2 });
});

test('isolates pages, users and companies; non-dev requests stay live', async () => {
  const path = '/api/v1/dev/data/orders?page=1';
  await readApi(path);
  await readApi('/api/v1/dev/data/orders?page=2');
  localStorage.setItem('user', JSON.stringify({ ...user, company_id: 2 }));
  await readApi(path);
  localStorage.setItem('user', JSON.stringify({ ...user, uid: 'dev-two' }));
  await readApi(path);
  localStorage.setItem('user', JSON.stringify({ ...user, role: 'admin' }));
  await readApi(path); await readApi(path);
  assert.equal(calls, 6);
});

test('offline uses an expired saved response but never issues a request', async () => {
  const path = '/api/v1/dev/data/orders';
  await readApi(path);
  clock += DEV_CACHE_TTL;
  navigator.onLine = false;
  assert.deepEqual(await readApi(path), { version: 1 });
  await assert.rejects(readApi('/api/v1/dev/data/invoices'), /not been saved/);
  assert.equal(calls, 1);
});

test('deduplicates requests without one aborted subscriber cancelling another', async () => {
  let finish;
  globalThis.fetch = () => { calls++; return new Promise((resolve) => { finish = resolve; }); };
  const controller = new AbortController();
  const first = readApi('/api/v1/dev/data/orders', controller.signal);
  const second = readApi('/api/v1/dev/data/orders');
  controller.abort();
  await assert.rejects(first, { name: 'AbortError' });
  finish(Response.json({ ok: true }));
  assert.deepEqual(await second, { ok: true });
  assert.equal(calls, 1);
});

test('does not cache errors or export downloads', async () => {
  globalThis.fetch = async () => { calls++; return Response.json({ error: 'Denied' }, { status: 403 }); };
  await assert.rejects(readApi('/api/v1/dev/data/orders'), /Denied/);
  await assert.rejects(readApi('/api/v1/dev/data/orders'), /Denied/);
  globalThis.fetch = async () => { calls++; return Response.json({ ok: true }); };
  await readApiResponse('/api/v1/dev/export'); await readApiResponse('/api/v1/dev/export');
  assert.equal(calls, 4);
});

test('logout clears only dev cache and stops an in-flight response from restoring it', async () => {
  localStorage.setItem('theme', 'dark');
  await readApi('/api/v1/dev/data');
  let finish;
  globalThis.fetch = () => new Promise((resolve) => { finish = resolve; });
  const pending = readApi('/api/v1/dev/data/orders');
  clearDeveloperCache();
  finish(Response.json({ ok: true }));
  await pending;
  assert.equal(Object.keys(localStorage).filter((key) => key.startsWith('inyice:dev-get:')).length, 0);
  assert.equal(localStorage.getItem('theme'), 'dark');
});

test('corrupt cache and quota failures do not break live reads', async () => {
  await readApi('/api/v1/dev/data');
  const key = Object.keys(localStorage).find((key) => key.startsWith('inyice:dev-get:'));
  localStorage.setItem(key, 'invalid-json');
  Object.defineProperty(localStorage, 'setItem', { value: () => { throw new Error('Quota exceeded'); } });
  assert.deepEqual(await readApi('/api/v1/dev/data'), { version: 2 });
});
