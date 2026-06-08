const API_BASE = '/api/v1';

const authHeaders = () => {
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
  return {
    Authorization: `Bearer ${token}`,
    'Content-Type': 'application/json',
    Accept: 'application/json',
  };
};

const apiCall = async (endpoint, options = {}) => {
  const response = await fetch(`${API_BASE}${endpoint}`, {
    ...options,
    headers: {
      ...authHeaders(),
      ...(options.headers || {}),
    },
  });

  const data = await response.json();
  if (!response.ok) {
    throw new Error(data?.message || data?.error || 'Request failed');
  }

  return data;
};

export const parseGdsApi = (payload) =>
  apiCall('/orders/parse-gds', {
    method: 'POST',
    body: JSON.stringify(payload),
  });

export const createOrderFromVoucherApi = (payload) =>
  apiCall('/orders/create-from-voucher', {
    method: 'POST',
    body: JSON.stringify(payload),
  });

export const createInvoiceFromOrderApi = (payload) =>
  apiCall('/invoices/create-from-order', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
