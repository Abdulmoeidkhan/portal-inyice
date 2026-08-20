import React, { useEffect, useMemo, useState } from 'react';
import { BankOutlined, CheckCircleOutlined, ClearOutlined, CreditCardOutlined, DollarOutlined, ReloadOutlined, SaveOutlined, SwapOutlined } from '@ant-design/icons';
import { Button, Card, Col, Input, InputNumber, Radio, Row, Select, Space, Statistic, Tabs, Tag, Typography } from 'antd';
import { message } from '../services/feedback';
import { dateOnly } from '../services/dateFormat';
import Table from '../components/CsvTable';

const { Title, Text } = Typography;
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const SIDE_CONFIG = {
  customer: {
    partyLabel: 'Customer',
    partyEndpoint: '/api/v1/customers',
    historyEndpoint: '/api/v1/payments/customer',
    refundEndpoint: (id) => `/api/v1/payments/refund-allocations/customer/${id}`,
    submitEndpoint: '/api/v1/payments/refund-allocations/customer-payment',
    adjustmentEndpoint: '/api/v1/payments/refund-allocations/customer-adjustment',
    partyField: 'customer_id',
    dateField: 'payment_date',
    submitDateField: 'payment_date',
    documentNumber: 'payment_number',
    documentLabel: 'Customer payment',
    relationName: 'customer',
    counterpartyColumn: 'Vendor',
    counterpartyField: 'vendor_name',
    responseKey: 'payment',
  },
  vendor: {
    partyLabel: 'Vendor',
    partyEndpoint: '/api/v1/vendors',
    historyEndpoint: '/api/v1/receipts/vendor',
    refundEndpoint: (id) => `/api/v1/payments/refund-allocations/vendor/${id}`,
    submitEndpoint: '/api/v1/payments/refund-allocations/vendor-receipt',
    partyField: 'vendor_id',
    dateField: 'receipt_date',
    submitDateField: 'receipt_date',
    documentNumber: 'receipt_number',
    documentLabel: 'Vendor receipt',
    relationName: 'vendor',
    counterpartyColumn: 'Customer',
    counterpartyField: 'customer_name',
    responseKey: 'receipt',
  },
};

const emptyForm = () => ({
  party_id: null,
  date: today(),
  method: 'bank_transfer',
  account_id: null,
  reference: '',
  description: '',
});

export default function CustomerRefundAllocation() {
  const [side, setSide] = useState('customer');
  const [parties, setParties] = useState({ customer: [], vendor: [] });
  const [refundRows, setRefundRows] = useState({ customer: [], vendor: [] });
  const [history, setHistory] = useState({ customer: [], vendor: [] });
  const [selectedKeys, setSelectedKeys] = useState({ customer: [], vendor: [] });
  const [allocations, setAllocations] = useState({ customer: {}, vendor: {} });
  const [customerMode, setCustomerMode] = useState('payment');
  const [targetInvoices, setTargetInvoices] = useState([]);
  const [selectedInvoiceKeys, setSelectedInvoiceKeys] = useState([]);
  const [invoiceAllocations, setInvoiceAllocations] = useState({});
  const [forms, setForms] = useState({ customer: emptyForm(), vendor: emptyForm() });
  const [accounts, setAccounts] = useState({ cash: [], bank: [] });
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };

  const config = SIDE_CONFIG[side];
  const form = forms[side];
  const selectedParty = parties[side].find((item) => item.id === form.party_id);
  const selectedRows = useMemo(() => refundRows[side].filter((item) => selectedKeys[side].includes(item.id)), [refundRows, selectedKeys, side]);
  const selectedInvoiceRows = useMemo(() => targetInvoices.filter((item) => selectedInvoiceKeys.includes(item.uid)), [targetInvoices, selectedInvoiceKeys]);
  const isCustomerAdjustment = side === 'customer' && customerMode === 'adjustment';
  const allocationTotal = selectedRows.reduce((sum, item) => sum + Number(allocations[side][item.id] || 0), 0);
  const invoiceAllocationTotal = selectedInvoiceRows.reduce((sum, item) => sum + Number(invoiceAllocations[item.uid] || 0), 0);
  const outstandingTotal = refundRows[side].reduce((sum, item) => sum + Number(item.outstanding_amount || 0), 0);
  const accountType = form.method === 'cash' ? 'cash' : 'bank';
  const activeAccounts = accounts[accountType].filter((item) => !selectedParty?.currency_code || item.currency_code === selectedParty.currency_code);
  const historyRows = history[side].filter((row) => Number(row.allocated_refund_amount || 0) > 0 || (row.refund_allocations || []).length > 0);

  const setForm = (patch) => setForms((current) => ({ ...current, [side]: { ...current[side], ...patch } }));
  const historyUrl = (nextSide, partyId) => `${SIDE_CONFIG[nextSide].historyEndpoint}?${SIDE_CONFIG[nextSide].partyField}=${partyId}`;

  const loadBaseData = async () => {
    setLoading(true);
    try {
      const responses = await Promise.all([
        fetch(SIDE_CONFIG.customer.partyEndpoint, { headers }),
        fetch(SIDE_CONFIG.vendor.partyEndpoint, { headers }),
        fetch('/api/v1/accounts/cash', { headers }),
        fetch('/api/v1/accounts/bank', { headers }),
      ]);
      if (!responses.every((response) => response.ok)) throw new Error('Could not load refund allocation data');
      const [customers, vendors, cash, bank] = await Promise.all(responses.map((response) => response.json()));
      setParties({ customer: customers.data || customers || [], vendor: vendors.data || vendors || [] });
      setAccounts({ cash: cash || [], bank: bank || [] });
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { loadBaseData(); }, []);

  const loadHistory = async (nextSide, partyId) => {
    setHistory((current) => ({ ...current, [nextSide]: [] }));
    if (!partyId) return;
    setLoading(true);
    try {
      const response = await fetch(historyUrl(nextSide, partyId), { headers });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || data.message || 'Could not load refund history');
      setHistory((current) => ({ ...current, [nextSide]: data.data || [] }));
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };

  const loadRefundRows = async (nextSide, partyId) => {
    setRefundRows((current) => ({ ...current, [nextSide]: [] }));
    setSelectedKeys((current) => ({ ...current, [nextSide]: [] }));
    setAllocations((current) => ({ ...current, [nextSide]: {} }));
    if (!partyId) return;
    setLoading(true);
    try {
      const response = await fetch(SIDE_CONFIG[nextSide].refundEndpoint(partyId), { headers });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || data.message || 'Could not load refund orders');
      setRefundRows((current) => ({ ...current, [nextSide]: data.data || [] }));
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };

  const loadCustomerOpenInvoices = async (customerId) => {
    setTargetInvoices([]);
    setSelectedInvoiceKeys([]);
    setInvoiceAllocations({});
    if (!customerId) return;
    setLoading(true);
    try {
      const response = await fetch(`/api/v1/invoices?customer_id=${customerId}&per_page=100`, { headers });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || data.message || 'Could not load customer invoices');
      const rows = (data.data || [])
        .filter((item) => !item.is_refund_order)
        .filter((item) => Number(item.total_amount || 0) > 0)
        .filter((item) => Number(item.outstanding_amount || 0) > 0)
        .filter((item) => !['paid', 'void', 'cancel'].includes(String(item.status || '').toLowerCase()));
      setTargetInvoices(rows);
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };

  const updateSelection = (keys) => {
    const next = { ...allocations[side] };
    keys.forEach((key) => {
      const row = refundRows[side].find((item) => item.id === key);
      if (row && !selectedKeys[side].includes(key)) next[key] = Number(row.outstanding_amount || 0);
    });
    Object.keys(next).forEach((key) => { if (!keys.includes(Number(key))) delete next[key]; });
    setSelectedKeys((current) => ({ ...current, [side]: keys }));
    setAllocations((current) => ({ ...current, [side]: next }));
  };

  const updateInvoiceSelection = (keys) => {
    const next = { ...invoiceAllocations };
    keys.forEach((key) => {
      const row = targetInvoices.find((item) => item.uid === key);
      if (row && !selectedInvoiceKeys.includes(key)) next[key] = Number(row.outstanding_amount || 0);
    });
    Object.keys(next).forEach((key) => { if (!keys.includes(key)) delete next[key]; });
    setSelectedInvoiceKeys(keys);
    setInvoiceAllocations(next);
  };

  const submit = async () => {
    if (!form.party_id || !selectedRows.length || allocationTotal <= 0) {
      message.error(`Select a ${config.partyLabel.toLowerCase()} and allocate at least one refund order`);
      return;
    }
    if (isCustomerAdjustment && (!selectedInvoiceRows.length || invoiceAllocationTotal <= 0)) {
      message.error('Select at least one open customer invoice to adjust');
      return;
    }
    if (isCustomerAdjustment && Math.abs(allocationTotal - invoiceAllocationTotal) > 0.0001) {
      message.error('Refund allocation total must equal invoice adjustment total');
      return;
    }

    setSaving(true);
    try {
      const response = await fetch(isCustomerAdjustment ? SIDE_CONFIG.customer.adjustmentEndpoint : config.submitEndpoint, {
        method: 'POST',
        headers: { ...headers, 'Content-Type': 'application/json' },
        body: JSON.stringify(isCustomerAdjustment ? {
          customer_id: form.party_id,
          amount: invoiceAllocationTotal,
          adjustment_date: form.date,
          description: form.description.trim() || null,
          allocations: selectedRows.map((item) => ({ order_id: item.id, amount: Number(allocations[side][item.id]) })),
          invoice_allocations: selectedInvoiceRows.map((item) => ({ invoice_uid: item.uid, amount: Number(invoiceAllocations[item.uid]) })),
        } : {
          [config.partyField]: form.party_id,
          amount: allocationTotal,
          payment_method: form.method,
          [config.submitDateField]: form.date,
          account_id: form.account_id,
          reference_number: form.reference.trim() || null,
          description: form.description.trim() || null,
          allocations: selectedRows.map((item) => ({ order_id: item.id, amount: Number(allocations[side][item.id]) })),
        }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || data.message || 'Could not save refund allocation');
      message.success(isCustomerAdjustment ? 'Customer refund adjustment recorded' : `${config.documentLabel} ${data[config.responseKey]?.[config.documentNumber] || ''} recorded`);
      setForms((current) => ({ ...current, [side]: { ...current[side], reference: '', description: '' } }));
      await Promise.all([
        loadRefundRows(side, form.party_id),
        loadHistory(side, form.party_id),
        loadBaseData(),
        isCustomerAdjustment ? loadCustomerOpenInvoices(form.party_id) : Promise.resolve(),
      ]);
    } catch (error) {
      message.error(error.message);
    } finally {
      setSaving(false);
    }
  };

  const refundColumns = [
    { title: 'Refund order', dataIndex: 'order_number', width: 160 },
    { title: 'Date', dataIndex: 'date', width: 120, render: dateOnly },
    { title: 'Booking / folder', dataIndex: 'booking_reference', width: 150, render: (value) => value || '-' },
    { title: config.counterpartyColumn, dataIndex: config.counterpartyField, width: 180, render: (value) => value || '-' },
    { title: 'Refund amount', dataIndex: 'refund_amount', width: 140, align: 'right', render: money },
    { title: 'Allocated', dataIndex: 'allocated_amount', width: 120, align: 'right', render: money },
    { title: 'Balance', dataIndex: 'outstanding_amount', width: 130, align: 'right', render: (value) => <Text strong>{money(value)}</Text> },
    {
      title: 'Allocation',
      key: 'allocation',
      width: 180,
      align: 'right',
      className: 'financial-allocation-column',
      render: (_, row) => <InputNumber className="financial-allocation-input" min={0.01} max={Number(row.outstanding_amount)} precision={2} disabled={!selectedKeys[side].includes(row.id)} value={allocations[side][row.id]} onChange={(value) => setAllocations((current) => ({ ...current, [side]: { ...current[side], [row.id]: Number(value || 0) } }))} />,
    },
  ];

  const invoiceColumns = [
    { title: 'Invoice', dataIndex: 'invoice_number', width: 160 },
    { title: 'Date', dataIndex: 'invoice_date', width: 120, render: dateOnly },
    { title: 'Order', dataIndex: ['order', 'order_number'], width: 160, render: (value) => value || '-' },
    { title: 'Total', dataIndex: 'total_amount', width: 130, align: 'right', render: money },
    { title: 'Balance', dataIndex: 'outstanding_amount', width: 130, align: 'right', render: (value) => <Text strong>{money(value)}</Text> },
    {
      title: 'Adjustment',
      key: 'adjustment',
      width: 180,
      align: 'right',
      className: 'financial-allocation-column',
      render: (_, row) => <InputNumber className="financial-allocation-input" min={0.01} max={Number(row.outstanding_amount)} precision={2} disabled={!selectedInvoiceKeys.includes(row.uid)} value={invoiceAllocations[row.uid]} onChange={(value) => setInvoiceAllocations((current) => ({ ...current, [row.uid]: Number(value || 0) }))} />,
    },
  ];

  const historyColumns = [
    { title: `${config.documentLabel} #`, dataIndex: config.documentNumber, width: 170 },
    { title: 'Date', dataIndex: config.dateField, width: 120, render: dateOnly },
    { title: config.partyLabel, dataIndex: [config.relationName, 'name'], width: 180 },
    { title: 'Method', dataIndex: 'payment_method', width: 130, render: (value) => <Tag>{String(value).replaceAll('_', ' ').toUpperCase()}</Tag> },
    { title: 'Refund orders', dataIndex: 'refund_allocations', render: (items = []) => items.map((item) => item.order?.order_number).filter(Boolean).join(', ') || '-' },
    { title: 'Amount', dataIndex: 'amount', width: 140, align: 'right', render: (value, row) => <Text strong>{row.currency_code} {money(value)}</Text> },
  ];

  const panel = (
    <>
      <Card className="border-beam-aurora financial-entry-card">
        <Row gutter={[16, 14]} align="bottom">
          <Col xs={24} md={9} lg={7}><Text strong>{config.partyLabel}</Text><Select showSearch allowClear optionFilterProp="label" placeholder={`Select ${config.partyLabel.toLowerCase()}`} value={form.party_id} onChange={(value) => { setForm({ party_id: value, account_id: null }); loadRefundRows(side, value); loadHistory(side, value); if (side === 'customer') loadCustomerOpenInvoices(value); }} options={parties[side].map((item) => ({ value: item.id, label: item.name }))} /></Col>
          <Col xs={12} md={5} lg={4}><Text strong>Date</Text><Input type="date" value={form.date} onChange={(event) => setForm({ date: event.target.value })} /></Col>
          <Col xs={12} md={4} lg={3}><Text strong>Currency</Text><Input readOnly value={selectedParty?.currency_code || '-'} /></Col>
          {side === 'customer' && <Col xs={24} lg={10}><Text strong>Allocation type</Text><Radio.Group value={customerMode} buttonStyle="solid" onChange={(event) => setCustomerMode(event.target.value)}><Radio.Button value="payment"><DollarOutlined /> Pay customer</Radio.Button><Radio.Button value="adjustment"><SwapOutlined /> Adjust invoices</Radio.Button></Radio.Group></Col>}
          {!isCustomerAdjustment && <Col xs={24} lg={10}><Text strong>Method</Text><Radio.Group value={form.method} buttonStyle="solid" onChange={(event) => setForm({ method: event.target.value, account_id: null })}><Radio.Button value="cash"><DollarOutlined /> Cash</Radio.Button><Radio.Button value="bank_transfer"><BankOutlined /> Bank</Radio.Button><Radio.Button value="card"><CreditCardOutlined /> Card</Radio.Button><Radio.Button value="check"><CheckCircleOutlined /> Cheque</Radio.Button></Radio.Group></Col>}
          {!isCustomerAdjustment && <Col xs={24} md={8}><Text strong>Cash / bank account</Text><Select allowClear placeholder="Optional account" value={form.account_id} onChange={(value) => setForm({ account_id: value })} options={activeAccounts.map((item) => ({ value: item.id, label: item.account_name || `${item.bank_name} · ${item.account_number}` }))} /></Col>}
          {!isCustomerAdjustment && <Col xs={24} md={8}><Text strong>Reference</Text><Input maxLength={100} value={form.reference} onChange={(event) => setForm({ reference: event.target.value })} /></Col>}
          <Col xs={24} md={isCustomerAdjustment ? 12 : 8}><Text strong>Description</Text><Input maxLength={1000} value={form.description} onChange={(event) => setForm({ description: event.target.value })} /></Col>
        </Row>

        <div className="financial-entry-toolbar">
          <Space wrap><Button onClick={() => updateSelection(refundRows[side].map((item) => item.id))} disabled={!refundRows[side].length}>Select all</Button><Button icon={<ClearOutlined />} onClick={() => updateSelection([])} disabled={!selectedKeys[side].length}>Clear</Button><Text type="secondary">{selectedKeys[side].length} refund order{selectedKeys[side].length === 1 ? '' : 's'} selected</Text></Space>
          <Space wrap><div className="allocation-total"><span>{isCustomerAdjustment ? 'Refund selected' : `${config.documentLabel} total`}</span><strong>{selectedParty?.currency_code || ''} {money(allocationTotal)}</strong></div>{isCustomerAdjustment && <div className="allocation-total"><span>Invoice adjustment</span><strong>{selectedParty?.currency_code || ''} {money(invoiceAllocationTotal)}</strong></div>}<Button type="primary" size="large" icon={<SaveOutlined />} loading={saving} disabled={!selectedKeys[side].length || (isCustomerAdjustment && !selectedInvoiceKeys.length)} onClick={submit}>{isCustomerAdjustment ? 'Adjust invoices' : 'Record allocation'}</Button></Space>
        </div>
        <Row justify="center" align="middle" className="financial-table-row">
          <Col span={24} className="financial-table-column">
            <Table rowKey="id" loading={loading} columns={refundColumns} dataSource={refundRows[side]} pagination={false} tableLayout="fixed" scroll={{ x: 1230, y: 420 }} rowSelection={{ selectedRowKeys: selectedKeys[side], onChange: updateSelection }} locale={{ emptyText: form.party_id ? 'No open refund balances' : `Select a ${config.partyLabel.toLowerCase()} to load refund orders` }} />
          </Col>
        </Row>
        {isCustomerAdjustment && (
          <Row justify="center" align="middle" className="financial-table-row">
            <Col span={24} className="financial-table-column">
              <Table rowKey="uid" loading={loading} columns={invoiceColumns} dataSource={targetInvoices} pagination={false} tableLayout="fixed" scroll={{ x: 900, y: 360 }} rowSelection={{ selectedRowKeys: selectedInvoiceKeys, onChange: updateInvoiceSelection }} locale={{ emptyText: form.party_id ? 'No open invoices to adjust' : 'Select a customer to load open invoices' }} />
            </Col>
          </Row>
        )}
      </Card>
      <br />
      <Card className="financial-history-card" title={`${config.documentLabel} history`}>
        <Table rowKey="id" loading={loading} columns={historyColumns} dataSource={form.party_id ? historyRows : []} scroll={{ x: 900 }} locale={{ emptyText: form.party_id ? 'No refund allocation history for this selection' : `Select a ${config.partyLabel.toLowerCase()} to view history` }} />
      </Card>
    </>
  );

  return (
    <div className="page-shell page-fade-up financial-entry-page">
      <div className="elevated-card financial-entry-hero">
        <div><Title level={2}>Customer Refund Allocation</Title></div>
        <Space size="large" wrap>
          <Statistic title="Open refunds" value={refundRows[side].length} />
          <Statistic title="Outstanding" value={outstandingTotal} precision={2} prefix={selectedParty?.currency_code} />
        </Space>
      </div>
      <Tabs activeKey={side} onChange={(nextSide) => { setSide(nextSide); if (nextSide === 'customer' && forms.customer.party_id) loadCustomerOpenInvoices(forms.customer.party_id); }} tabBarExtraContent={<Button icon={<ReloadOutlined />} onClick={() => { loadBaseData(); if (form.party_id) { loadHistory(side, form.party_id); loadRefundRows(side, form.party_id); if (side === 'customer') loadCustomerOpenInvoices(form.party_id); } }}>Refresh</Button>} items={[
        { key: 'customer', label: 'Customer refund payments', children: panel },
        { key: 'vendor', label: 'Vendor refund receipts', children: panel },
      ]} />
    </div>
  );
}
