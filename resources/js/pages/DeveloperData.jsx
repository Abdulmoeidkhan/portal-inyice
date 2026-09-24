import React, { useEffect, useState } from 'react';
import { Alert, Button, Card, Select, Space, Table, Typography } from 'antd';
import { downloadCompanyData, readApi } from '../services/developerApi';

export default function DeveloperData() {
  const [resources, setResources] = useState([]);
  const [resource, setResource] = useState('orders');
  const [page, setPage] = useState(1);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [downloading, setDownloading] = useState(false);
  const [error, setError] = useState('');
  const [revision, setRevision] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    readApi('/api/v1/dev/data', controller.signal).then((body) => setResources(body.resources))
      .catch((e) => { if (e.name !== 'AbortError') setError(e.message); });
    return () => controller.abort();
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError('');
    setData(null);
    readApi(`/api/v1/dev/data/${resource}?page=${page}&per_page=25`, controller.signal)
      .then(setData)
      .catch((e) => { if (e.name !== 'AbortError') setError(e.message); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [resource, page, revision]);

  const rows = data?.data || [];
  const columns = Object.keys(rows[0] || {}).map((key) => ({
    key, dataIndex: key, title: key.replaceAll('_', ' '), width: 180, ellipsis: true,
    render: (value) => value === null ? '—' : typeof value === 'object' ? JSON.stringify(value) : String(value),
  }));

  return <Space orientation="vertical" size="large" style={{ width: '100%' }}>
    <div><Typography.Title level={2}>Company data</Typography.Title>
      <Typography.Paragraph>Read-only access to your company’s business records. Expand a row to inspect every field. Reads are saved in this browser for 24 hours; the next online request after expiry refreshes them. Offline viewing may show older saved data.</Typography.Paragraph></div>
    <Alert type="info" showIcon title="Developer access is read-only" description="API and portal changes are blocked. The JSON export is generated only when you download it and contains the company datasets listed below. Passwords, access tokens and share tokens are excluded." />
    {error && <Alert type="error" showIcon title={error} />}
    <Card>
      <Space wrap style={{ marginBottom: 20 }}>
        <Select aria-label="Business dataset" value={resource} style={{ minWidth: 240 }} showSearch
          options={resources.map((value) => ({ value, label: value.replaceAll('_', ' ') }))}
          onChange={(value) => { setResource(value); setPage(1); }} />
        <Button onClick={() => setRevision((value) => value + 1)} loading={loading}>Reload saved data</Button>
        <Button type="primary" loading={downloading} onClick={async () => {
          setDownloading(true); setError('');
          try { await downloadCompanyData(); } catch (e) { setError(e.message); } finally { setDownloading(false); }
        }}>Download company JSON</Button>
      </Space>
      <Table rowKey="id" size="small" columns={columns} dataSource={rows} loading={loading}
        scroll={{ x: Math.max(columns.length * 180, 700) }}
        pagination={{ current: page, pageSize: 25, total: data?.total || 0, showSizeChanger: false, onChange: setPage }}
        expandable={{ expandedRowRender: (row) => <pre style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{JSON.stringify(row, null, 2)}</pre> }} />
    </Card>
  </Space>;
}
