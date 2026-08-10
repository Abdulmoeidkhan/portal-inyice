const token = () => localStorage.getItem('auth_token') || localStorage.getItem('token');

const headers = () => ({
  Authorization: `Bearer ${token()}`,
  Accept: 'application/json',
  'Content-Type': 'application/json',
});

export const acquireEditLock = async (type, uid) => {
  const response = await fetch('/api/v1/edit-locks', {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify({ type, uid }),
  });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    const error = new Error(data.message || 'Could not acquire edit lock');
    error.status = response.status;
    error.data = data;
    throw error;
  }

  return data;
};

export const heartbeatEditLock = async (type, uid) => fetch('/api/v1/edit-locks', {
  method: 'PATCH',
  headers: headers(),
  body: JSON.stringify({ type, uid }),
});

export const releaseEditLock = async (type, uid) => fetch('/api/v1/edit-locks', {
  method: 'DELETE',
  headers: headers(),
  body: JSON.stringify({ type, uid }),
  keepalive: true,
});
