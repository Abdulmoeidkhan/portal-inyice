import React, { useCallback, useEffect, useState } from 'react';
import { Alert, Button, Card, Checkbox, Form, Input, Spin, Typography } from 'antd';
import { ReloadOutlined } from '@ant-design/icons';
import './accounting.css';
import { accountingApi } from './api';



import { message } from '../../services/feedback';

export default function AccountingPage({ title, description, needsAccounts = false, children }) {
  const user = JSON.parse(localStorage.getItem('user') || '{}');
  const isOwner = user.role === 'owner';
  const canPost = ['owner', 'admin'].includes(user.role);
  const canWrite = ['owner', 'admin', 'accounts'].includes(user.role);
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [revision, setRevision] = useState(0);
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(false);
  const refresh = useCallback(() => setRevision((value) => value + 1), []);
  useEffect(() => {
    const controller = new AbortController(); setError(''); setLoading(true);
    accountingApi(needsAccounts ? '' : '?include_accounts=0', 'GET', undefined, controller.signal).then(setData)
      .catch((e) => { if (e.name !== 'AbortError') setError(e.message); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [revision, needsAccounts]);
  const book = data?.book;
  return <div className="page-shell page-fade-up accounting-page">
    <div className="elevated-card border-beam-aurora">
      <div className="accounting-toolbar">
        <div><Typography.Title level={2} style={{ margin: 0 }}>{title}</Typography.Title>
          <Typography.Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>{description}</Typography.Paragraph></div>
        <Button icon={<ReloadOutlined />} loading={loading} onClick={refresh}>Refresh</Button>
      </div>
    </div>
    <Alert type="info" showIcon title="Manual accounting ledger" description="Reports include posted journals and opening balances entered here. Sales, invoices, receipts and payments are not imported automatically." />
    {user.role === 'dev' && <Alert type="info" title="Read-only developer view" description="Responses use your 24-hour browser cache. Saved figures can be older than the live ledger." />}
    {error && <Alert type="error" title={error} action={<Button onClick={refresh}>Retry</Button>} />}
    {!data && !error && <Spin />}
    {data && !data.available && <Alert type="warning" title={data.message} />}
    {data?.available && !book && <Card className="border-beam-aurora" title="Set up accounting">
      <Typography.Paragraph>Use an agreed cutover date and reviewed opening balances. The ledger inherits your company currency ({data.company_currency}); its start date and currency are fixed after setup. No historical transactions or balances are imported automatically.</Typography.Paragraph>
      {isOwner ? <Form layout="vertical" style={{ maxWidth: 640 }} onFinish={async (values) => {
        setSaving(true); setError('');
        try { await accountingApi('/setup', 'POST', values); refresh(); message.success('Ledger created. Review the chart and prepare opening balances.'); }
        catch (e) { setError(e.message); } finally { setSaving(false); }
      }}>
        <Form.Item name="start_date" label="Cutover date" rules={[{ required: true }]}><Input type="date" /></Form.Item>
        <Form.Item name="confirm_separate_ledger" valuePropName="checked" rules={[{ validator: (_, value) => value ? Promise.resolve() : Promise.reject(new Error('Confirm the scope before creating the ledger.')) }]}>
          <Checkbox>I understand that opening balances require review and existing transactions are not imported.</Checkbox>
        </Form.Item>
        <Button type="primary" htmlType="submit" loading={saving}>Create accounting ledger</Button>
      </Form> : <Typography.Text>The company owner must set up the accounting ledger.</Typography.Text>}
    </Card>}
    {book && <Card className="border-beam-aurora">
      {children({ book, accounts: data.accounts || [], canPost, canWrite, isOwner, revision, refresh })}
    </Card>}
  </div>;
}
