export const apiHeaders = () => ({
  Accept: 'application/json',
  Authorization: `Bearer ${localStorage.getItem('auth_token') || localStorage.getItem('token') || ''}`,
});

const CACHE_PREFIX = 'inyice:dev-get:v1:';
export const DEV_CACHE_TTL = 24 * 60 * 60 * 1000;
const pendingReads = new Map();
let cacheGeneration = 0;

export function clearDeveloperCache() {
  cacheGeneration += 1;
  pendingReads.clear();
  try {
    for (const key of Object.keys(localStorage)) {
      if (key.startsWith(CACHE_PREFIX)) localStorage.removeItem(key);
    }
  } catch { /* Storage may be unavailable. */ }
}

function cacheKey(path) {
  try {
    const user = JSON.parse(localStorage.getItem('user') || '{}');
    if (user.role !== 'dev' || !user.tenant_id || !user.company_id || !(user.uid || user.id)
      || !(localStorage.getItem('auth_token') || localStorage.getItem('token'))) return null;
    const url = new URL(path, window.location.origin);
    if (url.origin !== window.location.origin || !url.pathname.startsWith('/api/v1/')
      || ['/api/v1/dev/export', '/api/v1/api-documentation', '/api/v1/company-profile'].includes(url.pathname)) return null;
    url.searchParams.sort();
    return CACHE_PREFIX + JSON.stringify([user.tenant_id, user.company_id, user.uid || user.id, user.role, url.pathname + url.search]);
  } catch { return null; }
}

function cachedRecord(key) {
  try {
    const record = JSON.parse(localStorage.getItem(key));
    return record && Number.isFinite(record.storedAt) && record.storedAt <= Date.now()
      && typeof record.body === 'string' && record.status >= 200 && record.status < 300 ? record : null;
  } catch { return null; }
}

function responseFrom(record, cached = false) {
  return new Response(record.body, { status: record.status, headers: {
    'Content-Type': record.contentType,
    ...(cached ? { 'X-Dev-Cache': 'hit', 'X-Dev-Cached-At': String(record.storedAt) } : {}),
  } });
}

// Abort a subscriber without cancelling a shared request used by another mount.
function subscribed(promise, signal) {
  if (!signal) return promise;
  return new Promise((resolve, reject) => {
    const abort = () => reject(new DOMException('Request aborted', 'AbortError'));
    if (signal.aborted) { abort(); return; }
    signal.addEventListener('abort', abort, { once: true });
    promise.then(resolve, reject).finally(() => signal.removeEventListener('abort', abort));
  });
}

export async function readApiResponse(path, signal) {
  if (signal?.aborted) throw new DOMException('Request aborted', 'AbortError');
  const key = cacheKey(path);
  if (!key) return fetch(path, { headers: apiHeaders(), cache: 'no-store', signal });
  const cached = cachedRecord(key);
  if (cached && Date.now() - cached.storedAt < DEV_CACHE_TTL) return responseFrom(cached, true);
  if (navigator.onLine === false) {
    if (cached) return responseFrom(cached, true);
    throw new Error('This data has not been saved yet. Connect to the internet to load it.');
  }
  if (!pendingReads.has(key)) {
    const generation = cacheGeneration;
    const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
    const pending = (async () => {
      const response = await fetch(path, { headers: apiHeaders(), cache: 'no-store' });
      const record = { body: await response.text(), status: response.status,
        contentType: response.headers.get('Content-Type') || 'application/json', storedAt: Date.now() };
      if (response.ok && record.contentType.includes('application/json') && generation === cacheGeneration
        && cacheKey(path) === key && token === (localStorage.getItem('auth_token') || localStorage.getItem('token'))) {
        try { JSON.parse(record.body); localStorage.setItem(key, JSON.stringify(record)); } catch { /* Quota or invalid JSON: still return the live result. */ }
      }
      return record;
    })();
    pendingReads.set(key, pending);
    pending.finally(() => { if (pendingReads.get(key) === pending) pendingReads.delete(key); }).catch(() => {});
  }
  return responseFrom(await subscribed(pendingReads.get(key), signal));
}

export async function readApi(path, signal) {
  const response = await readApiResponse(path, signal);
  const body = await response.json();
  if (!response.ok) throw new Error(body.message || body.error || `Request failed (${response.status})`);
  return body;
}

export async function downloadCompanyData() {
  const response = await fetch('/api/v1/dev/export', { headers: apiHeaders(), cache: 'no-store' });
  if (!response.ok) {
    const body = await response.json();
    throw new Error(body.message || body.error || 'Export failed');
  }
  const url = URL.createObjectURL(await response.blob());
  const link = document.createElement('a');
  link.href = url;
  link.download = `company-data-${new Date().toISOString().slice(0, 10)}.json`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
