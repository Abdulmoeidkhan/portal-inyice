import React, { useEffect, useMemo, useState } from 'react';
import {
  BankOutlined,
  CheckCircleOutlined,
  CreditCardOutlined,
  DollarOutlined,
  ReloadOutlined,
  SaveOutlined,
} from '@ant-design/icons';
import { Button, Card, Col, Input, InputNumber, Radio, Row, Select, Space, Statistic, Table, Tabs, Tag, Typography } from 'antd';
import { message } from '../services/feedback';

const { Title, Paragraph, Text } = Typography;
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const money = (value) => Number(value || 0).toFixed(2);

export default function Payments() {
  const [customers, setCustomers] = useState([]);
  const [customerId, setCustomerId] = useState(null);
  const [invoices, setInvoices] = useState([]);
  const [receipts, setReceipts] = useState([]);
  const [accounts, setAccounts] = useState({ cash: [], bank: [] });
  const [selectedKeys, setSelectedKeys] = useState([]);
  const [allocations, setAllocations] = useState({});
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState({ date: today(), method: 'bank_transfer', account_id: null, reference: '', narration: '' });
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
  const customer = customers.find((item) => item.id === customerId);

  const loadBaseData = async () => {
    setLoading(true);
    try {
      const [customerResponse, receiptResponse, cashResponse, bankResponse] = await Promise.all([
        fetch('/api/v1/customers', { headers }),
        fetch('/api/v1/payments/customer', { headers }),
        fetch('/api/v1/accounts/cash', { headers }),
        fetch('/api/v1/accounts/bank', { headers }),
      ]);
      if (![customerResponse, receiptResponse, cashResponse, bankResponse].every((response) => response.ok)) throw new Error('Could not load receipt data');
      const [customerData, receiptData, cashData, bankData] = await Promise.all([
        customerResponse.json(), receiptResponse.json(), cashResponse.json(), bankResponse.json(),
      ]);
      setCustomers(customerData.data || customerData || []);
      setReceipts(receiptData.data || []);
      setAccounts({ cash: cashData || [], bank: bankData || [] });
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { loadBaseData(); }, []);

  const selectCustomer = async (value) => {
    setCustomerId(value);
    setSelectedKeys([]);
    setAllocations({});
    setInvoices([]);
    if (!value) return;
    setLoading(true);
    try {
      const response = await fetch(`/api/v1/invoices?customer_id=${value}&per_page=200`, { headers });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Could not load customer invoices');
      setInvoices((data.data || []).filter((invoice) => Number(invoice.outstanding_amount) > 0 && invoice.status !== 'void'));
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };

  const selectedInvoices = useMemo(() => invoices.filter((invoice) => selectedKeys.includes(invoice.id)), [invoices, selectedKeys]);
  const totalOutstanding = invoices.reduce((sum, invoice) => sum + Number(invoice.outstanding_amount || 0), 0);
  const allocationTotal = selectedInvoices.reduce((sum, invoice) => sum + Number(allocations[invoice.id] || 0), 0);

  const updateSelection = (keys) => {
    const next = { ...allocations };
    keys.forEach((key) => {
      const invoice = invoices.find((item) => item.id === key);
      if (invoice && !selectedKeys.includes(key)) next[key] = Number(invoice.outstanding_amount || 0);
    });
    Object.keys(next).forEach((key) => { if (!keys.includes(Number(key))) delete next[key]; });
    setSelectedKeys(keys);
    setAllocations(next);
  };

  const activeAccounts = form.method === 'cash' ? accounts.cash : accounts.bank;
  const submitReceipt = async () => {
    if (!customerId || selectedInvoices.length === 0 || allocationTotal <= 0 || !form.date) {
      message.error('Select a customer and allocate a positive amount to at least one invoice');
      return;
    }
    if (form.method !== 'card' && form.account_id && !activeAccounts.some((account) => account.id === form.account_id)) {
      message.error('Select a valid account for this receipt method');
      return;
    }
    const payload = {
      amount: allocationTotal,
      payment_method: form.method,
      payment_date: form.date,
      account_id: form.account_id,
      reference_number: form.reference.trim() || null,
      narration: form.narration.trim() || null,
    };
    const isBulk = selectedInvoices.length > 1;
    if (isBulk) payload.allocations = selectedInvoices.map((invoice) => ({ invoice_uid: invoice.uid, amount: Number(allocations[invoice.id]) }));
    else payload.invoice_uid = selectedInvoices[0].uid;

    setSaving(true);
    try {
      const response = await fetch(isBulk ? '/api/v1/payments/record-bulk' : '/api/v1/payments/record', {
        method: 'POST',
        headers: { ...headers, 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || data.message || 'Could not record receipt');
      message.success(`${isBulk ? 'Bulk receipt' : 'Receipt'} recorded successfully`);
      setForm((previous) => ({ ...previous, reference: '', narration: '' }));
      await Promise.all([selectCustomer(customerId), loadBaseData()]);
    } catch (error) {
      message.error(error.message);
    } finally {
      setSaving(false);
    }
  };

  const invoiceColumns = [
    { title: 'Invoice no.', dataIndex: 'invoice_number', width: 150 },
    { title: 'Invoice date', dataIndex: 'invoice_date', width: 125, render: (value) => String(value || '').slice(0, 10) },
    { title: 'Booking / folder', dataIndex: ['order', 'booking_reference'], width: 150, render: (value) => value || '—' },
    { title: 'Net amount', dataIndex: 'total_amount', width: 130, align: 'right', render: (value) => money(value) },
    { title: 'Balance', dataIndex: 'outstanding_amount', width: 130, align: 'right', render: (value) => <Text strong>{money(value)}</Text> },
    {
      title: 'Receipt allocation', key: 'allocation', width: 200, align: 'right', className: 'financial-allocation-column',
      render: (_, invoice) => (
        <InputNumber
          className="financial-allocation-input"
          min={0.01}
          max={Number(invoice.outstanding_amount)}
          precision={2}
          disabled={!selectedKeys.includes(invoice.id)}
          value={allocations[invoice.id]}
          onChange={(value) => setAllocations((previous) => ({ ...previous, [invoice.id]: Number(value || 0) }))}
        />
      ),
    },
    { title: 'Description', dataIndex: 'notes', width: 220, ellipsis: true, render: (value) => value || '—' },
  ];

  const historyColumns = [
    { title: 'Receipt #', dataIndex: 'receipt_number', width: 160 },
    { title: 'Date', dataIndex: 'receipt_date', width: 120, render: (value) => String(value || '').slice(0, 10) },
    { title: 'Customer', dataIndex: ['customer', 'name'] },
    { title: 'Method', dataIndex: 'payment_method', render: (value) => <Tag>{String(value).replaceAll('_', ' ').toUpperCase()}</Tag> },
    { title: 'Reference', dataIndex: 'reference_number', render: (value) => value || '—' },
    { title: 'Invoices', dataIndex: 'settlements', render: (items = []) => items.map((item) => item.invoice?.invoice_number).filter(Boolean).join(', ') || '—' },
    { title: 'Amount', dataIndex: 'amount', align: 'right', render: (value, row) => <Text strong>{row.currency_code} {money(value)}</Text> },
  ];

  return (
    <div className="page-shell page-fade-up financial-entry-page">
      <div className="elevated-card financial-entry-hero">
        <div>
          <Title level={2}>Customer Receipts</Title>
          <Paragraph>Record cash, bank, or card receipts and allocate one receipt across several invoices.</Paragraph>
        </div>
        <Space size="large" wrap>
          <Statistic title="Open invoices" value={invoices.length} prefix={<DollarOutlined />} />
          <Statistic title="Outstanding" value={totalOutstanding} precision={2} prefix={customer?.currency_code} />
        </Space>
      </div>

      <Card className="border-beam-aurora financial-entry-card">
        <Row gutter={[16, 14]} align="bottom">
          <Col xs={24} md={10} lg={7}>
            <Text strong>Customer</Text>
            <Select showSearch allowClear optionFilterProp="label" placeholder="Select customer" value={customerId} onChange={selectCustomer} options={customers.map((item) => ({ value: item.id, label: item.name }))} />
          </Col>
          <Col xs={12} md={7} lg={4}><Text strong>Receipt date</Text><Input type="date" value={form.date} onChange={(event) => setForm((previous) => ({ ...previous, date: event.target.value }))} /></Col>
          <Col xs={12} md={7} lg={3}><Text strong>Currency</Text><Input readOnly value={customer?.currency_code || '—'} /></Col>
          <Col xs={24} lg={10}>
            <Text strong>Receipt method</Text>
            <Radio.Group value={form.method} buttonStyle="solid" onChange={(event) => setForm((previous) => ({ ...previous, method: event.target.value, account_id: null }))}>
              <Radio.Button value="cash"><DollarOutlined /> Cash</Radio.Button>
              <Radio.Button value="bank_transfer"><BankOutlined /> Bank</Radio.Button>
              <Radio.Button value="card"><CreditCardOutlined /> Card</Radio.Button>
              <Radio.Button value="check"><CheckCircleOutlined /> Cheque</Radio.Button>
            </Radio.Group>
          </Col>
          <Col xs={24} md={8}>
            <Text strong>Cash / bank account</Text>
            <Select allowClear disabled={form.method === 'card' || form.method === 'check'} placeholder="Optional account" value={form.account_id} onChange={(value) => setForm((previous) => ({ ...previous, account_id: value }))} options={activeAccounts.filter((item) => !customer?.currency_code || item.currency_code === customer.currency_code).map((item) => ({ value: item.id, label: item.account_name || `${item.bank_name} · ${item.account_number}` }))} />
          </Col>
          <Col xs={24} md={8}><Text strong>Reference</Text><Input maxLength={100} placeholder="Bank / cheque reference" value={form.reference} onChange={(event) => setForm((previous) => ({ ...previous, reference: event.target.value }))} /></Col>
          <Col xs={24} md={8}><Text strong>Description</Text><Input maxLength={1000} placeholder="Optional narration" value={form.narration} onChange={(event) => setForm((previous) => ({ ...previous, narration: event.target.value }))} /></Col>
        </Row>

        <div className="financial-entry-toolbar">
          <Space wrap>
            <Button onClick={() => updateSelection(invoices.map((invoice) => invoice.id))} disabled={!invoices.length}>Select all</Button>
            <Button onClick={() => updateSelection([])} disabled={!selectedKeys.length}>Clear</Button>
            <Text type="secondary">{selectedKeys.length} invoice{selectedKeys.length === 1 ? '' : 's'} selected</Text>
          </Space>
          <Space wrap>
            <div className="allocation-total"><span>Receipt total</span><strong>{customer?.currency_code || ''} {money(allocationTotal)}</strong></div>
            <Button type="primary" size="large" icon={<SaveOutlined />} loading={saving} disabled={!selectedKeys.length} onClick={submitReceipt}>Record receipt</Button>
          </Space>
        </div>
        <Row justify="center" align="middle" className="financial-table-row">
          <Col span={24} className="financial-table-column">
            <Table rowKey="id" loading={loading} columns={invoiceColumns} dataSource={invoices} pagination={false} tableLayout="fixed" scroll={{ x: 1255, y: 410 }} rowSelection={{ selectedRowKeys: selectedKeys, onChange: updateSelection }} locale={{ emptyText: customerId ? 'No open invoices for this customer' : 'Select a customer to load open invoices' }} />
          </Col>
        </Row>
      </Card>

      <Card className="financial-history-card">
        <Tabs items={[{ key: 'history', label: 'Receipt history', children: <Table rowKey="id" loading={loading} columns={historyColumns} dataSource={receipts} scroll={{ x: 900 }} /> }]} tabBarExtraContent={<Button icon={<ReloadOutlined />} onClick={loadBaseData}>Refresh</Button>} />
      </Card>
    </div>
  );
}
