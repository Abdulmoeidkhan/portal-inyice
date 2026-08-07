import React, { useEffect, useState } from 'react';
import { DeleteOutlined, EditOutlined, SaveOutlined } from '@ant-design/icons';
import { Button, Card, Col, Input, InputNumber, Popconfirm, Row, Select, Space, Table, Tag, Typography } from 'antd';
import { message } from '../services/feedback';
import { dateOnly } from '../services/dateFormat';

const { Title, Paragraph, Text } = Typography;
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function CounterpartyTransaction({ direction, partyType }) {
  const isReceipt = direction === 'receipt';
  const partyLabel = partyType === 'customer' ? 'Customer' : 'Vendor';
  const transactionLabel = isReceipt ? 'Receipt' : 'Payment';
  const endpoint = `/api/v1/${isReceipt ? 'receipts' : 'payments'}/${partyType}`;
  const partyEndpoint = `/api/v1/${partyType === 'customer' ? 'customers' : 'vendors'}`;
  const [parties, setParties] = useState([]);
  const [records, setRecords] = useState([]);
  const [accounts, setAccounts] = useState({ cash: [], bank: [] });
  const [loading, setLoading] = useState(false);
  const [editingUid, setEditingUid] = useState(null);
  const [form, setForm] = useState({ party_id: null, amount: null, date: today(), method: 'bank_transfer', account_id: null, reference: '', description: '' });
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
  const party = parties.find((item) => item.id === form.party_id);

  const load = async () => {
    setLoading(true);
    try {
      const responses = await Promise.all([fetch(partyEndpoint, { headers }), fetch(endpoint, { headers }), fetch('/api/v1/accounts/cash', { headers }), fetch('/api/v1/accounts/bank', { headers })]);
      if (!responses.every((response) => response.ok)) throw new Error(`Could not load ${transactionLabel.toLowerCase()} data`);
      const [partyData, recordData, cash, bank] = await Promise.all(responses.map((response) => response.json()));
      setParties(partyData.data || partyData || []); setRecords(recordData.data || []); setAccounts({ cash, bank });
    } catch (error) { message.error(error.message); } finally { setLoading(false); }
  };
  useEffect(() => { load(); }, [endpoint]);

  const save = async () => {
    if (!form.party_id || !form.amount || !form.date) return message.error(`Select a ${partyLabel.toLowerCase()} and enter an amount`);
    setLoading(true);
    const dateField = isReceipt ? 'receipt_date' : 'payment_date';
    try {
      const payload = { [`${partyType}_id`]: form.party_id, amount: form.amount, payment_method: form.method, [dateField]: form.date, date: form.date, account_id: form.account_id, reference_number: form.reference || null, description: form.description || null };
      const response = await fetch(editingUid ? `${endpoint}/${editingUid}` : endpoint, { method: editingUid ? 'PATCH' : 'POST', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || data.message || `Could not record ${transactionLabel.toLowerCase()}`);
      message.success(`${partyLabel} ${transactionLabel.toLowerCase()} ${editingUid ? 'updated' : 'recorded'}`);
      setEditingUid(null); setForm((current) => ({ ...current, amount: null, reference: '', description: '' })); await load();
    } catch (error) { message.error(error.message); } finally { setLoading(false); }
  };

  const remove = async (record) => {
    setLoading(true);
    try {
      const response = await fetch(`${endpoint}/${record.uid}`, { method: 'DELETE', headers });
      const data = await response.json(); if (!response.ok) throw new Error(data.error || data.message || 'Could not delete record');
      message.success(`${transactionLabel} deleted`); await load();
    } catch (error) { message.error(error.message); } finally { setLoading(false); }
  };

  const activeAccounts = form.method === 'cash' ? accounts.cash : accounts.bank;
  const numberField = isReceipt ? 'receipt_number' : 'payment_number';
  const dateField = isReceipt ? 'receipt_date' : 'payment_date';
  const edit = (record) => {
    setEditingUid(record.uid);
    setForm({ party_id: record[`${partyType}_id`], amount: Number(record.amount), date: String(record[dateField]).slice(0, 10), method: record.payment_method, account_id: record.account_id, reference: record.reference_number || '', description: record.description || '' });
  };
  return <div className="page-shell page-fade-up financial-entry-page">
    <div className="elevated-card financial-entry-hero"><div><Title level={2}>{partyLabel} {transactionLabel}s</Title><Paragraph>{isReceipt ? `Money received from a ${partyLabel.toLowerCase()}.` : `Money paid to a ${partyLabel.toLowerCase()}.`}</Paragraph></div></div>
    <Card className="border-beam-aurora financial-entry-card"><Row gutter={[16, 14]} align="bottom">
      <Col xs={24} md={7}><Text strong>{partyLabel}</Text><Select showSearch optionFilterProp="label" value={form.party_id} onChange={(value) => setForm((current) => ({ ...current, party_id: value, account_id: null }))} options={parties.map((item) => ({ value: item.id, label: item.name }))} /></Col>
      <Col xs={12} md={4}><Text strong>{transactionLabel} date</Text><Input type="date" value={form.date} onChange={(event) => setForm((current) => ({ ...current, date: event.target.value }))} /></Col>
      <Col xs={12} md={4}><Text strong>Amount</Text><InputNumber min={0.01} precision={2} value={form.amount} onChange={(value) => setForm((current) => ({ ...current, amount: value }))} /></Col>
      <Col xs={12} md={4}><Text strong>Method</Text><Select value={form.method} onChange={(value) => setForm((current) => ({ ...current, method: value, account_id: null }))} options={['cash', 'bank_transfer', 'card', 'check'].map((value) => ({ value, label: value.replaceAll('_', ' ').toUpperCase() }))} /></Col>
      <Col xs={12} md={5}><Text strong>Account</Text><Select allowClear value={form.account_id} onChange={(value) => setForm((current) => ({ ...current, account_id: value }))} options={activeAccounts.filter((item) => !party?.currency_code || item.currency_code === party.currency_code).map((item) => ({ value: item.id, label: item.account_name || `${item.bank_name} · ${item.account_number}` }))} /></Col>
      <Col xs={24} md={8}><Text strong>Reference</Text><Input value={form.reference} onChange={(event) => setForm((current) => ({ ...current, reference: event.target.value }))} /></Col>
      <Col xs={24} md={12}><Text strong>Description</Text><Input value={form.description} onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))} /></Col>
      <Col xs={24} md={4}><Space.Compact block><Button block type="primary" icon={<SaveOutlined />} loading={loading} onClick={save}>{editingUid ? 'Update' : 'Record'}</Button>{editingUid && <Button onClick={() => { setEditingUid(null); setForm((current) => ({ ...current, amount: null, reference: '', description: '' })); }}>Cancel</Button>}</Space.Compact></Col>
    </Row></Card>
    <Card className="financial-history-card" title={`${transactionLabel} history`}><Table rowKey="id" loading={loading} dataSource={records} scroll={{ x: 850 }} columns={[
      { title: `${transactionLabel} #`, dataIndex: numberField }, { title: 'Date', dataIndex: dateField, render: dateOnly },
      { title: partyLabel, dataIndex: [partyType, 'name'] }, { title: 'Method', dataIndex: 'payment_method', render: (value) => <Tag>{String(value).replaceAll('_', ' ').toUpperCase()}</Tag> },
      { title: 'Reference', dataIndex: 'reference_number', render: (value) => value || '—' }, { title: 'Amount', dataIndex: 'amount', align: 'right', render: (value, row) => <Text strong>{row.currency_code} {money(value)}</Text> },
      { title: '', width: 100, render: (_, row) => <Space><Button size="small" icon={<EditOutlined />} onClick={() => edit(row)} /><Popconfirm title={`Delete this ${transactionLabel.toLowerCase()}?`} onConfirm={() => remove(row)}><Button danger size="small" icon={<DeleteOutlined />} /></Popconfirm></Space> },
    ]} /></Card>
  </div>;
}
