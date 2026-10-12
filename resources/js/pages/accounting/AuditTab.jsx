import React, { useEffect, useState } from 'react';
import { Alert, Typography } from 'antd';
import Table from '../../components/CsvTable';
import { accountingApi } from './api';

export default function AuditTab({ revision }) {
  const [page, setPage] = useState(1);
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setError('');
    accountingApi(`/audit?page=${page}`, 'GET', undefined, controller.signal).then(setData)
      .catch((e) => { if (e.name !== 'AbortError') setError(e.message); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [page, revision]);
  return <>
    {error && <Alert type="error" title={error} />}
    <Typography.Paragraph>Accounting actions are recorded with the actor and before/after values. Existing operational activity is outside this log.</Typography.Paragraph>
    <Table csvDownload={false} rowKey="id" size="small" loading={loading} dataSource={data?.data || []} scroll={{ x: 650 }}
      pagination={{ current: page, total: data?.total || 0, pageSize: 25, showSizeChanger: false, onChange: setPage }}
      columns={[{ title: 'Time', dataIndex: 'created_at' }, { title: 'User', dataIndex: 'user_name' }, { title: 'Action', dataIndex: 'action' }, { title: 'Subject', dataIndex: 'subject' }]}
      expandable={{ expandedRowRender: (row) => <pre style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', maxHeight: 400, overflow: 'auto' }}>{JSON.stringify({ before: row.before, after: row.after }, null, 2)}</pre> }} />
  </>;
}

