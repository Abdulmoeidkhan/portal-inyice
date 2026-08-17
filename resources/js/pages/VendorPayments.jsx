import React, { useEffect, useMemo, useState } from 'react';
import { BankOutlined, CheckCircleOutlined, CheckSquareOutlined, ClearOutlined, CreditCardOutlined, DeleteOutlined, DollarOutlined, EditOutlined, ReloadOutlined, SaveOutlined } from '@ant-design/icons';
import { Button, Card, Col, Input, InputNumber, Modal, Popconfirm, Radio, Row, Select, Space, Statistic, Tabs, Tag, Typography } from 'antd';
import { message } from '../services/feedback';
import { dateOnly } from '../services/dateFormat';
import Table from '../components/CsvTable';

const { Title, Paragraph, Text } = Typography;
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function VendorPayments() {
  const [vendors, setVendors] = useState([]);
  const [vendorId, setVendorId] = useState(null);
  const [payables, setPayables] = useState([]);
  const [payments, setPayments] = useState([]);
  const [accounts, setAccounts] = useState({ cash: [], bank: [] });
  const [selectedKeys, setSelectedKeys] = useState([]);
  const [allocations, setAllocations] = useState({});
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [editing, setEditing] = useState(null);
  const [editOrders, setEditOrders] = useState([]);
  const [advanceAllocating, setAdvanceAllocating] = useState(null);
  const [advancePayables, setAdvancePayables] = useState([]);
  const [form, setForm] = useState({ mode: 'allocate', date: today(), method: 'bank_transfer', account_id: null, reference: '', narration: '', advance_amount: null });
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
  const vendor = vendors.find((item) => item.id === vendorId);

  const loadBaseData = async () => {
    setLoading(true);
    try {
      const [vendorResponse, paymentResponse, cashResponse, bankResponse] = await Promise.all([
        fetch('/api/v1/vendors', { headers }), fetch('/api/v1/payments/vendor', { headers }),
        fetch('/api/v1/accounts/cash', { headers }), fetch('/api/v1/accounts/bank', { headers }),
      ]);
      if (![vendorResponse, paymentResponse, cashResponse, bankResponse].every((response) => response.ok)) throw new Error('Could not load vendor payment data');
      const [vendorData, paymentData, cashData, bankData] = await Promise.all([vendorResponse.json(), paymentResponse.json(), cashResponse.json(), bankResponse.json()]);
      setVendors(vendorData.data || vendorData || []);
      setPayments(paymentData.data || []);
      setAccounts({ cash: cashData || [], bank: bankData || [] });
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { loadBaseData(); }, []);

  const selectVendor = async (value) => {
    setVendorId(value);
    setSelectedKeys([]);
    setAllocations({});
    setPayables([]);
    if (!value) return;
    setLoading(true);
    try {
      const response = await fetch(`/api/v1/payments/vendor/${value}/payables`, { headers });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Could not load vendor payables');
      setPayables(data.data || []);
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };

  const selectedPayables = useMemo(() => payables.filter((item) => selectedKeys.includes(item.id)), [payables, selectedKeys]);
  const outstandingTotal = payables.reduce((sum, item) => sum + Number(item.outstanding_amount || 0), 0);
  const allocationTotal = selectedPayables.reduce((sum, item) => sum + Number(allocations[item.id] || 0), 0);
  const paymentTotal = form.mode === 'advance' ? Number(form.advance_amount || 0) : allocationTotal;
  const paymentRemaining = (payment) => Number(payment?.remaining_amount ?? Math.max(0, Number(payment?.amount || 0) - (payment?.allocations || []).reduce((sum, item) => sum + Number(item.amount || 0), 0)));
  const advanceAllocationTotal = advanceAllocating ? Object.values(advanceAllocating.allocations).reduce((sum, amount) => sum + Number(amount || 0), 0) : 0;
  const accountType = form.method === 'cash' ? 'cash' : 'bank';
  const activeAccounts = accounts[accountType];

  const updateSelection = (keys) => {
    const next = { ...allocations };
    keys.forEach((key) => {
      const payable = payables.find((item) => item.id === key);
      if (payable && !selectedKeys.includes(key)) next[key] = Number(payable.outstanding_amount || 0);
    });
    Object.keys(next).forEach((key) => { if (!keys.includes(Number(key))) delete next[key]; });
    setSelectedKeys(keys);
    setAllocations(next);
  };

  const submitPayment = async () => {
    if (!vendorId || paymentTotal <= 0 || !form.date || (form.mode === 'allocate' && selectedPayables.length === 0)) {
      message.error(form.mode === 'advance' ? 'Select a vendor and enter an advance amount' : 'Select a vendor and allocate a positive amount to at least one order');
      return;
    }
    setSaving(true);
    try {
      const response = await fetch('/api/v1/payments/vendor', {
        method: 'POST',
        headers: { ...headers, 'Content-Type': 'application/json' },
        body: JSON.stringify({
          vendor_id: vendorId,
          amount: paymentTotal,
          payment_method: form.method,
          payment_date: form.date,
          account_id: form.account_id,
          account_type: form.account_id ? accountType : null,
          reference_number: form.reference.trim() || null,
          narration: form.narration.trim() || null,
          ...(form.mode === 'allocate' ? { allocations: selectedPayables.map((item) => ({ order_id: item.id, amount: Number(allocations[item.id]) })) } : {}),
        }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || data.message || 'Could not record vendor payment');
      message.success(`${form.mode === 'advance' ? 'Advance payment' : selectedPayables.length > 1 ? 'Bulk payment' : 'Payment'} ${data.payment.payment_number} recorded`);
      setForm((previous) => ({ ...previous, reference: '', narration: '', advance_amount: null }));
      await Promise.all([selectVendor(vendorId), loadBaseData()]);
    } catch (error) {
      message.error(error.message);
    } finally {
      setSaving(false);
    }
  };

  const openEdit = async (row) => {
    setSaving(true);
    try {
      const [paymentResponse, payableResponse] = await Promise.all([
        fetch(`/api/v1/payments/vendor/payment/${row.uid}`, { headers }),
        fetch(`/api/v1/payments/vendor/${row.vendor_id}/payables`, { headers }),
      ]);
      const [payment, payableData] = await Promise.all([paymentResponse.json(), payableResponse.json()]);
      if (!paymentResponse.ok || !payableResponse.ok) throw new Error('Could not load payment for editing');
      const old = Object.fromEntries(payment.allocations.map((item) => [item.order_id, Number(item.amount)]));
      const merged = new Map((payableData.data || []).map((item) => [item.id, item]));
      payment.allocations.forEach((item) => merged.set(item.order_id, { ...(merged.get(item.order_id) || item.order), id: item.order_id, outstanding_amount: Number(merged.get(item.order_id)?.outstanding_amount || 0) }));
      setEditOrders([...merged.values()]);
      setEditing({ ...payment, date: String(payment.payment_date).slice(0, 10), method: payment.payment_method, reference: payment.reference_number || '', narration: payment.description || '', allocations: old, originalAllocations: old });
    } catch (error) { message.error(error.message); } finally { setSaving(false); }
  };

  const saveEdit = async () => {
    const next = Object.entries(editing.allocations).filter(([, amount]) => Number(amount) > 0).map(([orderId, amount]) => ({ order_id: Number(orderId), amount: Number(amount) }));
    if (!next.length) return message.error('Allocate the payment to at least one order');
    setSaving(true);
    try {
      const response = await fetch(`/api/v1/payments/vendor/payment/${editing.uid}`, { method: 'PATCH', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify({ date: editing.date, payment_method: editing.method, account_id: editing.account_id, reference_number: editing.reference || null, description: editing.narration || null, allocations: next }) });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || data.message || 'Could not update payment');
      message.success('Vendor payment updated and reallocated'); setEditing(null); await loadBaseData();
    } catch (error) { message.error(error.message); } finally { setSaving(false); }
  };

  const deletePayment = async (row) => {
    setSaving(true);
    try {
      const response = await fetch(`/api/v1/payments/vendor/payment/${row.uid}`, { method: 'DELETE', headers });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || data.message || 'Could not delete payment');
      message.success('Vendor payment deleted'); await loadBaseData();
    } catch (error) { message.error(error.message); } finally { setSaving(false); }
  };

  const openAdvanceAllocation = async (row) => {
    setSaving(true);
    try {
      const response = await fetch(`/api/v1/payments/vendor/${row.vendor_id}/payables?ignore_legacy_balance=1`, { headers });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || data.message || 'Could not load payables for advance allocation');
      setAdvancePayables(data.data || []);
      setAdvanceAllocating({ ...row, allocations: {} });
    } catch (error) { message.error(error.message); } finally { setSaving(false); }
  };

  const autoAllocateAdvance = () => {
    if (!advanceAllocating) return;
    let remaining = paymentRemaining(advanceAllocating);
    const next = {};
    advancePayables.forEach((payable) => {
      if (remaining <= 0) return;
      const amount = Math.min(remaining, Number(payable.outstanding_amount || 0));
      if (amount > 0) next[payable.id] = Number(amount.toFixed(2));
      remaining -= amount;
    });
    setAdvanceAllocating((current) => ({ ...current, allocations: next }));
  };

  const saveAdvanceAllocation = async () => {
    const next = Object.entries(advanceAllocating.allocations).filter(([, amount]) => Number(amount) > 0).map(([orderId, amount]) => ({ order_id: Number(orderId), amount: Number(amount) }));
    if (!next.length) return message.error('Allocate the advance to at least one order');
    if (advanceAllocationTotal > paymentRemaining(advanceAllocating)) return message.error('Allocation exceeds the remaining advance payment balance');
    setSaving(true);
    try {
      const response = await fetch(`/api/v1/payments/vendor/payment/${advanceAllocating.uid}/allocate-advance`, { method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify({ allocations: next }) });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || data.message || 'Could not allocate advance payment');
      message.success('Advance payment allocated to orders');
      setAdvanceAllocating(null);
      await Promise.all([vendorId ? selectVendor(vendorId) : Promise.resolve(), loadBaseData()]);
    } catch (error) { message.error(error.message); } finally { setSaving(false); }
  };

  const payableColumns = [
    { title: 'Order no.', dataIndex: 'order_number', width: 150 },
    { title: 'Order date', dataIndex: 'date', width: 120, render: dateOnly },
    { title: 'Booking / folder', dataIndex: 'booking_reference', width: 150, render: (value) => value || '—' },
    { title: 'Net payable', dataIndex: 'net_amount', width: 130, align: 'right', render: money },
    { title: 'Paid', dataIndex: 'paid_amount', width: 120, align: 'right', render: money },
    { title: 'Balance', dataIndex: 'outstanding_amount', width: 130, align: 'right', render: (value) => <Text strong>{money(value)}</Text> },
    {
      title: 'Payment allocation', key: 'allocation', width: 200, align: 'right', className: 'financial-allocation-column',
      render: (_, payable) => <InputNumber className="financial-allocation-input" min={0.01} max={Number(payable.outstanding_amount)} precision={2} disabled={!selectedKeys.includes(payable.id)} value={allocations[payable.id]} onChange={(value) => setAllocations((previous) => ({ ...previous, [payable.id]: Number(value || 0) }))} />,
    },
    { title: 'Description', dataIndex: 'description', width: 220, ellipsis: true, render: (value) => value || '—' },
  ];

  const historyColumns = [
    { title: 'Payment #', dataIndex: 'payment_number', width: 160 },
    { title: 'Date', dataIndex: 'payment_date', width: 120, render: dateOnly },
    { title: 'Vendor', dataIndex: ['vendor', 'name'] },
    { title: 'Method', dataIndex: 'payment_method', render: (value) => <Tag>{String(value).replaceAll('_', ' ').toUpperCase()}</Tag> },
    { title: 'Reference', dataIndex: 'reference_number', render: (value) => value || '—' },
    { title: 'Orders', dataIndex: 'allocations', render: (items = []) => items.map((item) => item.order?.order_number).filter(Boolean).join(', ') || 'Unallocated' },
    { title: 'Amount', dataIndex: 'amount', align: 'right', render: (value, row) => <Text strong>{row.currency_code} {money(value)}</Text> },
    { title: 'Unallocated', key: 'remaining_amount', align: 'right', render: (_, row) => money(paymentRemaining(row)) },
    { title: 'Actions', key: 'actions', fixed: 'right', width: 220, render: (_, row) => <Space><Button size="small" icon={<EditOutlined />} disabled={paymentRemaining(row) > 0} onClick={() => openEdit(row)} /><Button size="small" icon={<CheckSquareOutlined />} disabled={paymentRemaining(row) <= 0} onClick={() => openAdvanceAllocation(row)}>Allocate</Button><Popconfirm title="Delete this vendor payment?" description="Its allocations and ledger entry will be reversed." okText="Delete" okButtonProps={{ danger: true }} onConfirm={() => deletePayment(row)}><Button danger size="small" icon={<DeleteOutlined />} /></Popconfirm></Space> },
  ];

  return (
    <div className="page-shell page-fade-up financial-entry-page">
      <div className="elevated-card financial-entry-hero">
        <div><Title level={2}>Vendor Payments</Title><Paragraph>Record supplier disbursements and allocate one payment across several payable orders.</Paragraph></div>
        <Space size="large" wrap><Statistic title="Open payables" value={payables.length} prefix={<DollarOutlined />} /><Statistic title="Outstanding" value={outstandingTotal} precision={2} prefix={vendor?.currency_code} /></Space>
      </div>

      <Card className="border-beam-aurora financial-entry-card">
        <Row gutter={[16, 14]} align="bottom">
          <Col xs={24} md={10} lg={7}><Text strong>Vendor</Text><Select showSearch allowClear optionFilterProp="label" placeholder="Select vendor" value={vendorId} onChange={selectVendor} options={vendors.map((item) => ({ value: item.id, label: item.name }))} /></Col>
          <Col xs={12} md={7} lg={4}><Text strong>Payment date</Text><Input type="date" value={form.date} onChange={(event) => setForm((previous) => ({ ...previous, date: event.target.value }))} /></Col>
          <Col xs={12} md={7} lg={3}><Text strong>Currency</Text><Input readOnly value={vendor?.currency_code || '—'} /></Col>
          <Col xs={24} lg={10}><Text strong>Payment method</Text><Radio.Group value={form.method} buttonStyle="solid" onChange={(event) => setForm((previous) => ({ ...previous, method: event.target.value, account_id: null }))}><Radio.Button value="cash"><DollarOutlined /> Cash</Radio.Button><Radio.Button value="bank_transfer"><BankOutlined /> Bank</Radio.Button><Radio.Button value="card"><CreditCardOutlined /> Card</Radio.Button><Radio.Button value="check"><CheckCircleOutlined /> Cheque</Radio.Button></Radio.Group></Col>
          <Col xs={24} md={8}><Text strong>Payment type</Text><Radio.Group value={form.mode} buttonStyle="solid" onChange={(event) => { setSelectedKeys([]); setAllocations({}); setForm((previous) => ({ ...previous, mode: event.target.value })); }}><Radio.Button value="allocate">Allocate to orders</Radio.Button><Radio.Button value="advance">Advance</Radio.Button></Radio.Group></Col>
          {form.mode === 'advance' && <Col xs={24} md={8}><Text strong>Advance amount</Text><InputNumber min={0.01} precision={2} value={form.advance_amount} onChange={(value) => setForm((previous) => ({ ...previous, advance_amount: value }))} /></Col>}
          <Col xs={24} md={8}><Text strong>Cash / bank account</Text><Select allowClear placeholder="Optional account" value={form.account_id} onChange={(value) => setForm((previous) => ({ ...previous, account_id: value }))} options={activeAccounts.filter((item) => !vendor?.currency_code || item.currency_code === vendor.currency_code).map((item) => ({ value: item.id, label: item.account_name || `${item.bank_name} · ${item.account_number}` }))} /></Col>
          <Col xs={24} md={8}><Text strong>Reference</Text><Input maxLength={100} placeholder="Bank / cheque reference" value={form.reference} onChange={(event) => setForm((previous) => ({ ...previous, reference: event.target.value }))} /></Col>
          <Col xs={24} md={8}><Text strong>Description</Text><Input maxLength={1000} placeholder="Optional narration" value={form.narration} onChange={(event) => setForm((previous) => ({ ...previous, narration: event.target.value }))} /></Col>
        </Row>

        <div className="financial-entry-toolbar">
          {form.mode === 'allocate' ? <Space wrap><Button onClick={() => updateSelection(payables.map((item) => item.id))} disabled={!payables.length}>Select all</Button><Button onClick={() => updateSelection([])} disabled={!selectedKeys.length}>Clear</Button><Text type="secondary">{selectedKeys.length} order{selectedKeys.length === 1 ? '' : 's'} selected</Text></Space> : <Text type="secondary">Advance payments can be allocated later from payment history.</Text>}
          <Space wrap><div className="allocation-total"><span>Payment total</span><strong>{vendor?.currency_code || ''} {money(paymentTotal)}</strong></div><Button type="primary" size="large" icon={<SaveOutlined />} loading={saving} disabled={form.mode === 'advance' ? !vendorId || paymentTotal <= 0 : !selectedKeys.length} onClick={submitPayment}>{form.mode === 'advance' ? 'Record advance' : 'Record payment'}</Button></Space>
        </div>
        {form.mode === 'allocate' && <Row justify="center" align="middle" className="financial-table-row">
          <Col span={24} className="financial-table-column">
            <Table rowKey="id" loading={loading} columns={payableColumns} dataSource={payables} pagination={false} tableLayout="fixed" scroll={{ x: 1320, y: 410 }} rowSelection={{ selectedRowKeys: selectedKeys, onChange: updateSelection }} locale={{ emptyText: vendorId ? 'No open payables for this vendor' : 'Select a vendor to load payable orders' }} />
          </Col>
        </Row>}
      </Card>

      <Card className="financial-history-card"><Tabs items={[{ key: 'history', label: 'Payment history', children: <Table rowKey="id" loading={loading} columns={historyColumns} dataSource={payments} scroll={{ x: 900 }} /> }]} tabBarExtraContent={<Button icon={<ReloadOutlined />} onClick={loadBaseData}>Refresh</Button>} /></Card>
      <Modal width={980} title={`Edit and reallocate ${editing?.payment_number || ''}`} open={!!editing} onCancel={() => setEditing(null)} cancelButtonProps={{ danger: true }} onOk={saveEdit} confirmLoading={saving} okText="Save changes">
        {editing && <><Row gutter={[12, 12]} align="bottom" style={{ marginBottom: 16 }}>
          <Col xs={24} sm={12} md={6}><Text strong>Date</Text><Input type="date" value={editing.date} onChange={(event) => setEditing((current) => ({ ...current, date: event.target.value }))} style={{ width: '100%', marginTop: 4 }} /></Col>
          <Col xs={24} sm={12} md={6}><Text strong>Method</Text><Select value={editing.method} onChange={(value) => setEditing((current) => ({ ...current, method: value, account_id: null }))} options={['cash', 'bank_transfer', 'card', 'check'].map((value) => ({ value, label: value.replaceAll('_', ' ').toUpperCase() }))} style={{ width: '100%', marginTop: 4 }} /></Col>
          <Col xs={24} md={12}><Text strong>Account</Text><Select allowClear placeholder="Optional account" value={editing.account_id} onChange={(value) => setEditing((current) => ({ ...current, account_id: value }))} options={(editing.method === 'cash' ? accounts.cash : accounts.bank).filter((item) => item.currency_code === editing.currency_code).map((item) => ({ value: item.id, label: item.account_name || `${item.bank_name} · ${item.account_number}` }))} style={{ width: '100%', marginTop: 4 }} /></Col>
          <Col xs={24} md={8}><Text strong>Reference</Text><Input value={editing.reference} onChange={(event) => setEditing((current) => ({ ...current, reference: event.target.value }))} style={{ marginTop: 4 }} /></Col>
          <Col xs={24} md={16}><Text strong>Description</Text><Input value={editing.narration} onChange={(event) => setEditing((current) => ({ ...current, narration: event.target.value }))} style={{ marginTop: 4 }} /></Col>
        </Row><Table rowKey="id" pagination={false} dataSource={editOrders} tableLayout="fixed" scroll={{ x: 860, y: 360 }} columns={[
          { title: 'Order', dataIndex: 'order_number', width: 240 },
          { title: 'Available for reallocation', key: 'available', width: 240, align: 'right', render: (_, order) => money(Number(order.outstanding_amount || 0) + Number(editing.originalAllocations[order.id] || 0)) },
          { title: 'Allocation', key: 'allocation', width: 180, align: 'right', render: (_, order) => <InputNumber min={0} max={Number(order.outstanding_amount || 0) + Number(editing.originalAllocations[order.id] || 0)} precision={2} controls={false} value={editing.allocations[order.id] || 0} onChange={(value) => setEditing((current) => ({ ...current, allocations: { ...current.allocations, [order.id]: Number(value || 0) } }))} style={{ width: 150 }} /> },
        ]} /></>}
      </Modal>
      <Modal width={940} title={`Allocate advance ${advanceAllocating?.payment_number || ''}`} open={!!advanceAllocating} onCancel={() => setAdvanceAllocating(null)} cancelButtonProps={{ danger: true }} onOk={saveAdvanceAllocation} confirmLoading={saving} okText="Apply advance">
        {advanceAllocating && <><Row gutter={[12, 12]} align="bottom" style={{ marginBottom: 16 }}>
          <Col xs={12} md={5}><Text strong>Available</Text><Input readOnly value={`${advanceAllocating.currency_code} ${money(paymentRemaining(advanceAllocating))}`} /></Col>
          <Col xs={24} md={8}><Text strong>Allocation total</Text><Input readOnly value={`${advanceAllocating.currency_code} ${money(advanceAllocationTotal)}`} /></Col>
          <Col xs={24} md={6}><Space wrap><Button icon={<CheckSquareOutlined />} onClick={autoAllocateAdvance}>Auto allocate</Button><Button icon={<ClearOutlined />} onClick={() => setAdvanceAllocating((current) => ({ ...current, allocations: {} }))}>Clear</Button></Space></Col>
        </Row><Table rowKey="id" pagination={false} dataSource={advancePayables} locale={{ emptyText: 'No open payables available for this advance' }} columns={[
          { title: 'Order', dataIndex: 'order_number', width: 180 },
          { title: 'Order date', dataIndex: 'date', width: 130, render: dateOnly },
          { title: 'Balance', dataIndex: 'outstanding_amount', width: 140, align: 'right', render: money },
          { title: 'Allocation', key: 'allocation', width: 180, align: 'right', render: (_, payable) => <InputNumber min={0} max={Math.min(Number(payable.outstanding_amount), paymentRemaining(advanceAllocating))} precision={2} value={advanceAllocating.allocations[payable.id] || 0} onChange={(value) => setAdvanceAllocating((current) => ({ ...current, allocations: { ...current.allocations, [payable.id]: Number(value || 0) } }))} /> },
          { title: 'Description', dataIndex: 'description', ellipsis: true, render: (value) => value || '—' },
        ]} scroll={{ x: 820 }} /></>}
      </Modal>
    </div>
  );
}
