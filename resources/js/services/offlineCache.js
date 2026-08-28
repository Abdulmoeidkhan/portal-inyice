const DB_NAME = 'inyice-offline-cache';
const DB_VERSION = 1;
const STORE_NAME = 'apiResponses';
const OFFLINE_ERROR_BODY = {
  offline: true,
  message: 'You are offline. Changes are disabled until connection is restored.',
};

const openDatabase = () => new Promise((resolve, reject) => {
  const request = indexedDB.open(DB_NAME, DB_VERSION);

  request.onupgradeneeded = () => {
    const database = request.result;
    if (!database.objectStoreNames.contains(STORE_NAME)) {
      database.createObjectStore(STORE_NAME, { keyPath: 'key' });
    }
  };

  request.onsuccess = () => resolve(request.result);
  request.onerror = () => reject(request.error);
});

const withStore = async (mode, callback) => {
  const database = await openDatabase();

  return new Promise((resolve, reject) => {
    const transaction = database.transaction(STORE_NAME, mode);
    const store = transaction.objectStore(STORE_NAME);
    const request = callback(store);
    let result;

    if (request && 'onsuccess' in request) {
      request.onsuccess = () => {
        result = request.result;
      };
    }

    transaction.oncomplete = () => {
      database.close();
      resolve(result);
    };
    transaction.onerror = () => {
      database.close();
      reject(transaction.error);
    };
  });
};

const requestToUrl = (input) => {
  if (input instanceof URL) return input.toString();
  if (input instanceof Request) return input.url;

  return typeof input === 'string' ? input : input?.url || '';
};

export const requestMethod = (input, init = {}) => String(init.method || (input instanceof Request ? input.method : 'GET') || 'GET').toUpperCase();

export const authCacheScope = () => {
  try {
    const user = JSON.parse(localStorage.getItem('user') || '{}');

    return [
      user.tenant_id || 'tenant',
      user.company_id || 'company',
      user.uid || user.id || 'user',
    ].join(':');
  } catch {
    return 'anonymous';
  }
};

export const apiCacheKey = (input, init = {}) => {
  const url = new URL(requestToUrl(input), window.location.origin);

  return `${authCacheScope()}:${requestMethod(input, init)}:${url.pathname}${url.search}`;
};

export const readCachedApiResponse = async (input, init = {}) => {
  const key = apiCacheKey(input, init);

  try {
    const record = await withStore('readonly', (store) => store.get(key));

    return record || null;
  } catch {
    return null;
  }
};

export const writeCachedApiResponse = async (input, init = {}, response) => {
  const contentType = response.headers.get('Content-Type') || '';
  if (!response.ok || !contentType.includes('application/json')) {
    return;
  }

  try {
    const body = await response.clone().json();
    const key = apiCacheKey(input, init);
    await withStore('readwrite', (store) => store.put({
      key,
      status: response.status,
      statusText: response.statusText,
      body,
      cachedAt: new Date().toISOString(),
    }));
  } catch {
    // Cache failures should never break live app requests.
  }
};

export const cachedResponse = (record) => new Response(JSON.stringify(record.body), {
  status: record.status || 200,
  statusText: record.statusText || 'OK',
  headers: {
    'Content-Type': 'application/json',
    'X-InYice-Offline': '1',
    'X-InYice-Cached-At': record.cachedAt || '',
  },
});

export const offlineBlockedResponse = (message = OFFLINE_ERROR_BODY.message) => new Response(JSON.stringify({
  ...OFFLINE_ERROR_BODY,
  message,
}), {
  status: 503,
  statusText: 'Offline',
  headers: {
    'Content-Type': 'application/json',
    'X-InYice-Offline': '1',
  },
});
