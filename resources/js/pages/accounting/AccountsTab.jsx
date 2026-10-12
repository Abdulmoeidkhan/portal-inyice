import React, { useState } from 'react';
import { Alert, Button, Form, Input, Modal, Select, Space, Switch, Tag, Typography } from 'antd';
import Table from '../../components/CsvTable';
import { PlusOutlined } from '@ant-design/icons';
import { accountingApi, localDate, money } from './api';
import { message } from '../../services/feedback';

export default function AccountsTab({ accounts, book, canManage, onChange }) {
  const [search, setSearch] = useState('');
  const [type, setType] = useState('');
  const visibleAccounts = accounts.filter((row) => (!type || row.type === type) && `${row.code} ${row.name}`.toLowerCase().includes(search.toLowerCase()));
  const [editor, setEditor] = useState(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [account, setAccount] = useState(null);
  const [ledger, setLedger] = useState(null);
  const [range, setRange] = useState(null);
  const [loading, setLoading] = useState(false);
  const loadLedger = async (values, page = 1) => {
    setLoading(true); setError(''); setLedger(null); setRange(values);
    try { setLedger(await accountingApi(`/accounts/${account.uid}/ledger?${new URLSearchParams({ ...values, page })}`)); }
    catch (e) { setError(e.message); } finally { setLoading(false); }
  };
  return <Space orientation="vertical" style={{ width: '100%' }} size="middle">
    <Typography.Paragraph>Manage accounts in {book.currency_code}. Account codes and types are fixed after creation. Cash and bank accounts in this ledger are maintained separately from payment accounts.</Typography.Paragraph>
    {error && <Alert type="error" title={error} />}
    <div className="accounting-toolbar">
      <Space wrap><Input.Search allowClear aria-label="Search accounts" placeholder="Search code or account name" value={search} onChange={(event) => setSearch(event.target.value)} style={{ width: 280, maxWidth: '100%' }} />
        <Select aria-label="Account type" value={type} onChange={setType} style={{ width: 160 }} options={[{ value: '', label: 'All types' }, ...['asset', 'liability', 'equity', 'income', 'expense'].map((value) => ({ value, label: value[0].toUpperCase() + value.slice(1) }))]} /></Space>
      {canManage && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditor({})}>Add account</Button>}
    </div>
    <Table csvFileName="chart-of-accounts.csv" rowKey="uid" size="small" pagination={{ defaultPageSize: 25 }} dataSource={visibleAccounts} scroll={{ x: 650 }} columns={[
      { title: 'Code', dataIndex: 'code' }, { title: 'Name', dataIndex: 'name' }, { title: 'Type', dataIndex: 'type' },
      { title: 'Status', dataIndex: 'is_active', render: (value) => <Tag color={value ? 'green' : 'default'}>{value ? 'Active' : 'Inactive'}</Tag> },
      { title: 'Actions', render: (_, row) => <Space><Button size="small" onClick={() => { setAccount(row); setLedger(null); setRange(null); }}>Ledger</Button>
        {canManage && <Button size="small" onClick={() => setEditor(row)}>Edit</Button>}</Space> },
    ]} />
    {editor && <Modal open title={editor.uid ? 'Edit account' : 'Add account'} footer={null} onCancel={() => !saving && setEditor(null)}>
      <Form layout="vertical" initialValues={{ ...editor, is_active: !!editor.is_active }} onFinish={async (values) => {
        setSaving(true);
        try {
          await accountingApi(editor.uid ? `/accounts/${editor.uid}` : '/accounts', editor.uid ? 'PATCH' : 'POST', values);
          setEditor(null); onChange(); message.success('Account saved.');
        } catch (e) { message.error(e.message); } finally { setSaving(false); }
      }}>
        {!editor.uid && <Form.Item name="code" label="Code" rules={[{ required: true }, { pattern: /^[A-Za-z0-9-]+$/, message: 'Use letters, numbers or hyphens.' }]}><Input maxLength={20} /></Form.Item>}
        <Form.Item name="name" label="Name" rules={[{ required: true, whitespace: true }]}><Input maxLength={160} /></Form.Item>
        {!editor.uid && <Form.Item name="type" label="Type" rules={[{ required: true }]}><Select options={['asset', 'liability', 'equity', 'income', 'expense'].map((value) => ({ value, label: value }))} /></Form.Item>}
        {editor.uid && <Form.Item name="is_active" label="Active" valuePropName="checked"><Switch /></Form.Item>}
        <Button type="primary" htmlType="submit" loading={saving}>Save account</Button>
      </Form>
    </Modal>}
    {account && <Modal open title={`${account.code} · ${account.name} ledger`} width={1000} footer={null} onCancel={() => setAccount(null)}>
      {error && <Alert type="error" title={error} style={{ marginBottom: 16 }} />}
      <Form layout="vertical" className="accounting-filter-form" initialValues={{ from_date: book.start_date, to_date: localDate() < book.start_date ? book.start_date : localDate() }} onFinish={(values) => loadLedger(values)}>
        <Form.Item name="from_date" label="From" rules={[{ required: true }]}><Input type="date" min={book.start_date} /></Form.Item>
        <Form.Item name="to_date" label="To" rules={[{ required: true }]}><Input type="date" min={book.start_date} /></Form.Item>
        <Button htmlType="submit" type="primary" loading={loading}>Run ledger</Button>
      </Form>
      {ledger && <><Typography.Paragraph style={{ marginTop: 16 }}>Opening debit minus credit: {money(ledger.opening_debit_minus_credit)} {book.currency_code}</Typography.Paragraph>
        <Table csvDownload={false} scroll={{ x: 650 }} rowKey="line_id" size="small" dataSource={ledger.entries.data} loading={loading} pagination={{ current: ledger.entries.current_page, total: ledger.entries.total, pageSize: 50, showSizeChanger: false, onChange: (page) => loadLedger(range, page) }} columns={[
          { title: 'Date', dataIndex: 'entry_date' }, { title: 'Journal', dataIndex: 'number' }, { title: 'Description', dataIndex: 'description' },
          { title: 'Debit', dataIndex: 'debit', align: 'right', render: money }, { title: 'Credit', dataIndex: 'credit', align: 'right', render: money },
        ]} /></>}
    </Modal>}
  </Space>;
}
