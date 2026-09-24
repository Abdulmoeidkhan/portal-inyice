import React, { useEffect, useState } from 'react';
import { Alert, Button, Card, Collapse, Empty, Input, Select, Space, Spin, Table, Tag, Typography } from 'antd';
import { readApi, readApiResponse } from '../services/developerApi';
import ApiSharingGuide from '../components/ApiSharingGuide';

const colors = { GET: 'blue', POST: 'green', PATCH: 'orange', PUT: 'orange', DELETE: 'red' };
const roleDescriptions = [
  { role: 'owner', access: 'Admin access plus company settings. Owner accounts are protected from company-user edits and deletion.' },
  { role: 'admin', access: 'Orders, finance, reports and company-user management. Cannot update owner-only company settings.' },
  { role: 'accounts', access: 'Finance, receipts, payments and reports; selected order changes. No company-user management or owner settings.' },
  { role: 'sales', access: 'Sales orders, invoices, customers, vendors and receiving entry. No financial reports or payment management. Cost visibility follows company settings.' },
  { role: 'dev', access: 'Read-only company API and data browser. No business writes or profile edits. Exclusive access to on-demand company JSON export. Sign-out is allowed.' },
];
const preStyle = { whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', maxHeight: 420, overflow: 'auto' };

const exampleValue = (field) => {
  if (field.rules.includes('email')) return 'user@example.com';
  if (field.rules.includes('date')) return new Date().toISOString().slice(0, 10);
  if (field.rules.includes('boolean')) return false;
  if (/integer|numeric|exists:/.test(field.rules)) return 1;
  if (field.rules.includes('array')) return [];
  const options = field.rules.match(/(?:^|\||\s)in:([^|]+)/);
  return options ? options[1].split(',')[0].trim() : 'example';
};

const requestExample = (endpoint) => Object.fromEntries(endpoint.fields
  .filter((field) => field.rules.startsWith('required') && !field.name.includes('.'))
  .map((field) => [field.name, exampleValue(field)]));

function Endpoint({ endpoint }) {
  const [parameters, setParameters] = useState({});
  const [query, setQuery] = useState(() => JSON.stringify(endpoint.method === 'GET' ? requestExample(endpoint) : {}, null, 2));
  const [result, setResult] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const curl = [
    `curl -X ${endpoint.method} '${window.location.origin}${endpoint.path}'`,
    ...(endpoint.authenticated ? ["  -H 'Authorization: Bearer YOUR_API_TOKEN'"] : []),
    "  -H 'Accept: application/json'",
    ...(endpoint.field_location === 'body' ? ["  -H 'Content-Type: application/json'", `  --data '${JSON.stringify(requestExample(endpoint), null, 2)}'`] : []),
  ].join(' \\\n');

  const execute = async () => {
    setLoading(true); setError(''); setResult(null);
    try {
      let path = endpoint.path;
      for (const name of endpoint.parameters) {
        if (!parameters[name]?.trim()) throw new Error(`Enter ${name}`);
        path = path.replace(`{${name}}`, encodeURIComponent(parameters[name].trim()));
      }
      const values = JSON.parse(query);
      if (!values || Array.isArray(values) || typeof values !== 'object') throw new Error('Query parameters must be a JSON object.');
      const search = new URLSearchParams();
      Object.entries(values).forEach(([key, value]) => {
        if (typeof value === 'object') throw new Error('Use string, number or boolean query values.');
        search.set(key, String(value));
      });
      const url = `${path}${search.size ? `?${search}` : ''}`;
      const response = await readApiResponse(url);
      const content = await response.text();
      let body;
      try { body = JSON.parse(content); } catch { body = content; }
      setResult({ status: response.status, url, body, cachedAt: Number(response.headers.get('X-Dev-Cached-At')) || null });
    } catch (e) { setError(e.message); } finally { setLoading(false); }
  };

  return <Space orientation="vertical" style={{ width: '100%' }} size="middle">
    <Typography.Paragraph>{endpoint.notes}</Typography.Paragraph>
    <div><strong>Access: </strong>{endpoint.authenticated ? endpoint.roles.map((role) => <Tag key={role}>{role}</Tag>) : 'Public; endpoint-specific credentials or signed links may be required.'}</div>
    <Typography.Text>Authentication: {endpoint.authenticated ? 'Bearer token. Browser session tokens also require their matching portal cookie.' : 'No existing session required.'}</Typography.Text>
    {!!endpoint.parameters.length && <div><strong>Required path parameters</strong>
      {endpoint.parameters.map((name) => <Input key={name} aria-label={name} addonBefore={name} placeholder={`Enter ${name}`} value={parameters[name] || ''}
        onChange={(event) => setParameters((current) => ({ ...current, [name]: event.target.value }))} style={{ marginTop: 8 }} />)}</div>}
    {!!endpoint.fields.length && <Table rowKey="name" size="small" pagination={false} dataSource={endpoint.fields}
      columns={[{ title: `${endpoint.field_location === 'query' ? 'Query' : 'Body'} field`, dataIndex: 'name' }, { title: 'Validation', dataIndex: 'rules' }]} />}
    <Typography.Text type="secondary">Fields show literal controller validation; conditional rules, nested service validation and record-state restrictions still apply. Writes accept JSON unless noted otherwise.</Typography.Text>
    <div><strong>Responses: </strong>200 success; 201 when a record is created; 401 missing/expired authentication; 403 denied access; 404 missing record; 409 conflict; 422 validation errors; 429 rate limited.</div>
    {!!endpoint.throttles.length && <Typography.Text type="secondary">Rate limits: {endpoint.throttles.join(', ')}</Typography.Text>}
    <Typography.Text strong>Request template — replace example values and supply any required nested fields</Typography.Text>
    <pre style={preStyle}>{curl}</pre>
    {endpoint.can_try && <>
      <Typography.Text strong>Try a GET request using your current session</Typography.Text>
      <Input.TextArea aria-label="Query parameters as JSON" rows={3} value={query} onChange={(event) => setQuery(event.target.value)} />
      <Button onClick={execute} loading={loading}>Execute GET</Button>
    </>}
    {error && <Alert type="error" title={error} />}
    {result && <Card size="small" title={`${result.status} · ${result.url}`}>
      {result.cachedAt && <Typography.Paragraph type="secondary">Saved response from {new Date(result.cachedAt).toLocaleString()}</Typography.Paragraph>}
      <pre style={preStyle}>{JSON.stringify(result.body, null, 2)}</pre></Card>}
  </Space>;
}

export default function ApiDocumentation() {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [role, setRole] = useState('all');
  useEffect(() => {
    const controller = new AbortController();
    readApi('/api/v1/api-documentation', controller.signal).then(setData)
      .catch((e) => { if (e.name !== 'AbortError') setError(e.message); });
    return () => controller.abort();
  }, []);
  const endpoints = (data?.endpoints || []).filter((endpoint) =>
    `${endpoint.method} ${endpoint.path} ${endpoint.summary}`.toLowerCase().includes(search.toLowerCase())
    && (role === 'all' || !endpoint.authenticated || endpoint.roles.includes(role)));
  const groups = [...new Set(endpoints.map((endpoint) => endpoint.group))];

  return <Space orientation="vertical" size="large" style={{ width: '100%' }}>
    <div><Typography.Title level={2}>API documentation</Typography.Title>
      <Typography.Paragraph>API v1 · Browse endpoints, inspect role restrictions and try read requests.</Typography.Paragraph></div>
    <Card title="Authentication and scope">
      <Typography.Paragraph>All business requests use the signed-in user’s company. External API credentials require a paid agency plan. Portal sign-in uses a bearer token plus its browser cookie.</Typography.Paragraph>
      <Typography.Paragraph>For a dev API token, POST /api/v1/auth/login with the body below. The account must be active and email-verified. Store the returned token securely and send it in Authorization: Bearer YOUR_API_TOKEN. Tokens expire after 30 days. A 409 response means another session is active; force_logout: true revokes that session and existing connections.</Typography.Paragraph>
      <pre style={preStyle}>{JSON.stringify({ email: 'developer@example.com', password: 'YOUR_PASSWORD', access_mode: 'api' }, null, 2)}</pre>
      <Typography.Paragraph>Developer credentials permit reads only. Other roles retain their existing portal/integration restrictions. Only dev can download GET /api/v1/dev/export from the Company data tab. The server generates the JSON on demand.</Typography.Paragraph>
    </Card>
    <ApiSharingGuide />
    <Table rowKey="role" size="small" pagination={false} dataSource={roleDescriptions} columns={[{ title: 'Role', dataIndex: 'role', width: 110 }, { title: 'Permissions and restrictions', dataIndex: 'access' }]} />
    <Space wrap><Input.Search aria-label="Search endpoints" placeholder="Search endpoint or method" value={search} onChange={(event) => setSearch(event.target.value)} style={{ width: 300 }} />
      <Select aria-label="Filter by role" value={role} onChange={setRole} style={{ width: 180 }} options={['all', ...(data?.roles || [])].map((value) => ({ value, label: value === 'all' ? 'All roles' : value }))} /></Space>
    {error && <Alert type="error" title={error} />}
    {!data && !error && <Spin />}
    {data && !endpoints.length && <Empty description="No matching endpoints" />}
    {groups.map((group) => <section key={group}>
      <Typography.Title level={4}>{group}</Typography.Title>
      <Collapse items={endpoints.filter((endpoint) => endpoint.group === group).map((endpoint) => ({
        key: endpoint.id,
        label: <Space wrap><Tag color={colors[endpoint.method]} style={{ minWidth: 66, textAlign: 'center' }}>{endpoint.method}</Tag><Typography.Text code>{endpoint.path}</Typography.Text><span>{endpoint.summary}</span></Space>,
        children: <Endpoint endpoint={endpoint} />,
      }))} />
    </section>)}
  </Space>;
}
