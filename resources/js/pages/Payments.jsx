import React, { useEffect, useMemo, useState } from 'react';
import {
  BankOutlined,
  CheckCircleOutlined,
  CheckSquareOutlined,
  ClearOutlined,
  CreditCardOutlined,
  DollarOutlined,
  PrinterOutlined,
  ReloadOutlined,
  SaveOutlined,
  EditOutlined,
  DeleteOutlined,
} from '@ant-design/icons';
import { Button, Card, Col, Divider, Form, Grid, Input, InputNumber, Modal, Popconfirm, Radio, Row, Select, Space, Statistic, Tabs, Tag, Typography, Watermark } from 'antd';
import { message } from '../services/feedback';
import { useSearchParams } from 'react-router-dom';
import { createCustomerApi } from '../services/salesFlowApi';
import { dateOnly } from '../services/dateFormat';
import { printDocument } from '../services/printDocument';
import Table from '../components/CsvTable';

const { Title, Paragraph, Text } = Typography;
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const formatMethod = (value) => String(value || '—').replaceAll('_', ' ').toUpperCase();
const isAllocatableInvoice = (invoice) => Number(invoice.outstanding_amount) > 0 && !['void', 'cancel'].includes(invoice.status);

export default function Payments() {
  const [searchParams] = useSearchParams();
  const [customers, setCustomers] = useState([]);
  const [customerId, setCustomerId] = useState(null);
  const [invoices, setInvoices] = useState([]);
  const [receipts, setReceipts] = useState([]);
  const [accounts, setAccounts] = useState({ cash: [], bank: [] });
  const [selectedKeys, setSelectedKeys] = useState([]);
  const [allocations, setAllocations] = useState({});
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [savingCustomer, setSavingCustomer] = useState(false);
  const [customerModalOpen, setCustomerModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [editInvoices, setEditInvoices] = useState([]);
  const [advanceAllocating, setAdvanceAllocating] = useState(null);
  const [advanceInvoices, setAdvanceInvoices] = useState([]);
  const [printableReceipt, setPrintableReceipt] = useState(null);
  const [printingReceiptUid, setPrintingReceiptUid] = useState(null);
  const [customerForm] = Form.useForm();
  const [form, setForm] = useState({ mode: 'allocate', date: today(), method: 'bank_transfer', account_id: null, reference: '', narration: '', advance_amount: null });
  const screens = Grid.useBreakpoint();
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
  const customer = customers.find((item) => item.id === customerId);
  const compactActions = !screens.sm;

  const loadBaseData = async () => {
    setLoading(true);
    try {
      const [customerResponse, receiptResponse, cashResponse, bankResponse] = await Promise.all([
        fetch('/api/v1/customers', { headers }),
        fetch('/api/v1/receipts/customer', { headers }),
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
  useEffect(() => {
    if (!printableReceipt) return undefined;

    const frame = window.requestAnimationFrame(() => {
      printDocument('.receipt-print-host .receipt-slip', `Receipt ${printableReceipt.receipt_number || ''}`);
    });

    return () => window.cancelAnimationFrame(frame);
  }, [printableReceipt]);

  useEffect(() => {
    const invoiceUid = searchParams.get('invoice');
    if (!invoiceUid) return;
    fetch(`/api/v1/invoices/${invoiceUid}`, { headers }).then((response) => response.json().then((data) => ({ response, data }))).then(({ response, data }) => {
      if (!response.ok) throw new Error('Could not open the selected invoice');
      setCustomerId(data.customer_id);
      return fetch(`/api/v1/invoices?customer_id=${data.customer_id}&per_page=200`, { headers });
    }).then((response) => response.json()).then((data) => {
      const open = (data.data || []).filter(isAllocatableInvoice);
      setInvoices(open);
      const selected = open.find((invoice) => invoice.uid === invoiceUid);
      if (selected) { setSelectedKeys([selected.id]); setAllocations({ [selected.id]: Number(selected.outstanding_amount) }); }
    }).catch((error) => message.error(error.message));
  }, []);

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
      setInvoices((data.data || []).filter(isAllocatableInvoice));
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };

  const handleCreateCustomer = async () => {
    setSavingCustomer(true);
    try {
      const values = await customerForm.validateFields();
      const data = await createCustomerApi(values);
      const createdCustomer = data.customer;
      setCustomers((current) => {
        const exists = current.some((item) => item.id === createdCustomer.id);
        return exists ? current : [...current, createdCustomer].sort((a, b) => a.name.localeCompare(b.name));
      });
      customerForm.resetFields();
      setCustomerModalOpen(false);
      await selectCustomer(createdCustomer.id);
      await loadBaseData();
      message.success('Customer created');
    } catch (error) {
      if (error?.errorFields) return;
      message.error(error.message || 'Customer creation failed');
    } finally {
      setSavingCustomer(false);
    }
  };

  const selectedInvoices = useMemo(() => invoices.filter((invoice) => selectedKeys.includes(invoice.id)), [invoices, selectedKeys]);
  const totalOutstanding = invoices.reduce((sum, invoice) => sum + Number(invoice.outstanding_amount || 0), 0);
  const allocationTotal = selectedInvoices.reduce((sum, invoice) => sum + Number(allocations[invoice.id] || 0), 0);
  const receiptTotal = form.mode === 'advance' ? Number(form.advance_amount || 0) : allocationTotal;
  const advanceAllocationTotal = advanceAllocating ? Object.values(advanceAllocating.allocations).reduce((sum, amount) => sum + Number(amount || 0), 0) : 0;
  const receiptRemaining = (receipt) => Number(receipt?.remaining_amount ?? Math.max(0, Number(receipt?.amount || 0) - (receipt?.settlements || []).reduce((sum, item) => sum + Number(item.amount_received || 0), 0)));

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
    if (!customerId || receiptTotal <= 0 || !form.date || (form.mode === 'allocate' && selectedInvoices.length === 0)) {
      message.error(form.mode === 'advance' ? 'Select a customer and enter an advance amount' : 'Select a customer and allocate a positive amount to at least one invoice');
      return;
    }
    if (form.method !== 'card' && form.account_id && !activeAccounts.some((account) => account.id === form.account_id)) {
      message.error('Select a valid account for this receipt method');
      return;
    }
    const payload = {
      amount: receiptTotal,
      payment_method: form.method,
      payment_date: form.date,
      account_id: form.account_id,
      reference_number: form.reference.trim() || null,
      narration: form.narration.trim() || null,
    };
    const isBulk = selectedInvoices.length > 1;
    let endpoint = '/api/v1/receipts/customer/record';
    if (form.mode === 'advance') {
      endpoint = '/api/v1/receipts/customer/advance';
      payload.customer_id = customerId;
    } else if (isBulk) {
      endpoint = '/api/v1/receipts/customer/record-bulk';
      payload.allocations = selectedInvoices.map((invoice) => ({ invoice_uid: invoice.uid, amount: Number(allocations[invoice.id]) }));
    } else {
      payload.invoice_uid = selectedInvoices[0].uid;
    }

    setSaving(true);
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { ...headers, 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || data.message || 'Could not record receipt');
      message.success(`${form.mode === 'advance' ? 'Advance receipt' : isBulk ? 'Bulk receipt' : 'Receipt'} recorded successfully`);
      setForm((previous) => ({ ...previous, reference: '', narration: '', advance_amount: null }));
      await Promise.all([selectCustomer(customerId), loadBaseData()]);
    } catch (error) {
      message.error(error.message);
    } finally {
      setSaving(false);
    }
  };

  const openEdit = async (row) => {
    setSaving(true);
    try {
      const [receiptResponse, invoiceResponse] = await Promise.all([
        fetch(`/api/v1/receipts/customer/${row.uid}`, { headers }),
        fetch(`/api/v1/invoices?customer_id=${row.customer_id}&per_page=200`, { headers }),
      ]);
      const [receipt, invoiceData] = await Promise.all([receiptResponse.json(), invoiceResponse.json()]);
      if (!receiptResponse.ok || !invoiceResponse.ok) throw new Error('Could not load receipt for editing');
      const oldAllocations = Object.fromEntries(receipt.settlements.map((item) => [item.invoice_id, Number(item.amount_received)]));
      setEditInvoices((invoiceData.data || []).filter((invoice) => !['void', 'cancel'].includes(invoice.status) && (Number(invoice.outstanding_amount) > 0 || oldAllocations[invoice.id])));
      setEditing({ ...receipt, date: String(receipt.receipt_date).slice(0, 10), method: receipt.payment_method, reference: receipt.reference_number || '', narration: receipt.description || '', allocations: oldAllocations, originalAllocations: oldAllocations });
    } catch (error) { message.error(error.message); } finally { setSaving(false); }
  };

  const saveEdit = async () => {
    const editAllocations = Object.entries(editing.allocations).filter(([, amount]) => Number(amount) > 0).map(([invoiceId, amount]) => ({ invoice_id: Number(invoiceId), amount: Number(amount) }));
    if (!editAllocations.length) return message.error('Allocate the receipt to at least one invoice');
    setSaving(true);
    try {
      const response = await fetch(`/api/v1/receipts/customer/${editing.uid}`, { method: 'PATCH', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify({ date: editing.date, payment_method: editing.method, account_id: editing.account_id, reference_number: editing.reference || null, description: editing.narration || null, allocations: editAllocations }) });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || data.message || 'Could not update receipt');
      message.success('Receipt updated and reallocated'); setEditing(null); await loadBaseData();
    } catch (error) { message.error(error.message); } finally { setSaving(false); }
  };

  const deleteReceipt = async (row) => {
    setSaving(true);
    try {
      const response = await fetch(`/api/v1/receipts/customer/${row.uid}`, { method: 'DELETE', headers });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || data.message || 'Could not delete receipt');
      message.success('Receipt deleted and invoice balances restored'); await loadBaseData();
    } catch (error) { message.error(error.message); } finally { setSaving(false); }
  };

  const openAdvanceAllocation = async (row) => {
    setSaving(true);
    try {
      const [receiptResponse, invoiceResponse] = await Promise.all([
        fetch(`/api/v1/receipts/customer/${row.uid}`, { headers }),
        fetch(`/api/v1/invoices?customer_id=${row.customer_id}&per_page=200`, { headers }),
      ]);
      const [receipt, invoiceData] = await Promise.all([receiptResponse.json(), invoiceResponse.json()]);
      if (!receiptResponse.ok || !invoiceResponse.ok) throw new Error('Could not load advance receipt for allocation');
      const openInvoices = (invoiceData.data || []).filter((invoice) => isAllocatableInvoice(invoice) && invoice.currency_code === receipt.currency_code);
      setAdvanceInvoices(openInvoices);
      setAdvanceAllocating({ ...receipt, date: today(), notes: `Applied from advance receipt ${receipt.receipt_number}`, remaining_amount: receiptRemaining(row), allocations: {} });
    } catch (error) { message.error(error.message); } finally { setSaving(false); }
  };

  const printReceipt = async (row) => {
    setPrintingReceiptUid(row.uid);
    try {
      const response = await fetch(`/api/v1/receipts/customer/${row.uid}`, { headers });
      const receipt = await response.json();
      if (!response.ok) throw new Error(receipt.error || receipt.message || 'Could not load receipt slip');
      setPrintableReceipt({ ...receipt, remaining_amount: receiptRemaining(row) });
    } catch (error) {
      message.error(error.message);
    } finally {
      setPrintingReceiptUid(null);
    }
  };

  const autoAllocateAdvance = () => {
    if (!advanceAllocating) return;
    let remaining = receiptRemaining(advanceAllocating);
    const next = {};
    advanceInvoices.forEach((invoice) => {
      if (remaining <= 0) return;
      const amount = Math.min(remaining, Number(invoice.outstanding_amount || 0));
      if (amount > 0) next[invoice.id] = Number(amount.toFixed(2));
      remaining -= amount;
    });
    setAdvanceAllocating((current) => ({ ...current, allocations: next }));
  };

  const saveAdvanceAllocation = async () => {
    const nextAllocations = Object.entries(advanceAllocating.allocations).filter(([, amount]) => Number(amount) > 0).map(([invoiceId, amount]) => ({ invoice_id: Number(invoiceId), amount: Number(amount) }));
    if (!nextAllocations.length) return message.error('Allocate the advance to at least one invoice');
    if (advanceAllocationTotal > receiptRemaining(advanceAllocating)) return message.error('Allocation exceeds the remaining advance balance');
    setSaving(true);
    try {
      const response = await fetch(`/api/v1/receipts/customer/${advanceAllocating.uid}/allocate-advance`, { method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify({ date: advanceAllocating.date, notes: advanceAllocating.notes || null, allocations: nextAllocations }) });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || data.message || 'Could not allocate advance');
      message.success('Advance allocated to invoices');
      setAdvanceAllocating(null);
      await Promise.all([customerId ? selectCustomer(customerId) : Promise.resolve(), loadBaseData()]);
    } catch (error) { message.error(error.message); } finally { setSaving(false); }
  };

  const invoiceColumns = [
    { title: 'Invoice no.', dataIndex: 'invoice_number', width: 150 },
    { title: 'Invoice date', dataIndex: 'invoice_date', width: 125, render: dateOnly },
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
    { title: 'Date', dataIndex: 'receipt_date', width: 120, render: dateOnly },
    { title: 'Customer', dataIndex: ['customer', 'name'], width: 210 },
    { title: 'Method', dataIndex: 'payment_method', width: 145, render: (value) => <Tag>{formatMethod(value)}</Tag> },
    { title: 'Reference', dataIndex: 'reference_number', width: 180, ellipsis: true, render: (value) => value || '—' },
    { title: 'Invoices', dataIndex: 'settlements', width: 260, ellipsis: true, render: (items = []) => items.map((item) => item.invoice?.invoice_number).filter(Boolean).join(', ') || 'Advance' },
    { title: 'Amount', dataIndex: 'amount', width: 145, align: 'right', render: (value, row) => <Text strong>{row.currency_code} {money(value)}</Text> },
    { title: 'Unallocated', key: 'remaining_amount', width: 145, align: 'right', render: (_, row) => money(receiptRemaining(row)) },
    {
      title: 'Actions',
      key: 'actions',
      fixed: 'right',
      width: compactActions ? 170 : 300,
      render: (_, row) => (
        <Space>
          <Button size="small" icon={<EditOutlined />} disabled={receiptRemaining(row) > 0} onClick={() => openEdit(row)} title="Edit receipt" aria-label="Edit receipt" />
          <Button size="small" icon={<CheckSquareOutlined />} disabled={receiptRemaining(row) <= 0} onClick={() => openAdvanceAllocation(row)} title="Allocate advance" aria-label="Allocate advance">
            {compactActions ? null : 'Allocate'}
          </Button>
          <Button size="small" icon={<PrinterOutlined />} loading={printingReceiptUid === row.uid} onClick={() => printReceipt(row)} title="Print receipt slip" aria-label="Print receipt slip">
          </Button>
          <Popconfirm title="Delete this receipt?" description="Its invoice allocations and ledger entry will be reversed." okText="Delete" okButtonProps={{ danger: true }} onConfirm={() => deleteReceipt(row)}>
            <Button danger size="small" icon={<DeleteOutlined />} title="Delete receipt" aria-label="Delete receipt" />
          </Popconfirm>
        </Space>
      ),
    },
  ];

  const receiptCompany = printableReceipt?.company || {};
  const receiptCustomer = printableReceipt?.customer || {};
  const receiptCompanyName = receiptCompany.display_name || receiptCompany.legal_name || 'Company';
  const receiptCompanyContact = [receiptCompany.address, receiptCompany.phone, receiptCompany.email].filter(Boolean);
  const receiptCustomerContact = [receiptCustomer.name, receiptCustomer.phone, receiptCustomer.email, receiptCustomer.address].filter(Boolean);
  const receiptAllocationRows = (printableReceipt?.settlements || [])
    .filter((item) => Number(item.amount_received || 0) > 0)
    .map((item, index) => ({
      ...item,
      key: item.id || `${item.invoice_id}-${index}`,
    }));
  const receiptAllocatedTotal = receiptAllocationRows.reduce((sum, item) => sum + Number(item.amount_received || 0), 0);
  const receiptBalance = printableReceipt ? Math.max(0, Number(printableReceipt.amount || 0) - receiptAllocatedTotal) : 0;

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
            <Select
              showSearch
              allowClear
              optionFilterProp="label"
              placeholder="Select customer"
              value={customerId}
              onChange={selectCustomer}
              options={customers.map((item) => ({ value: item.id, label: `${item.name}${item.phone ? ` - ${item.phone}` : ''}` }))}
              dropdownRender={(menu) => (
                <>
                  {menu}
                  <Button type="link" block onClick={() => setCustomerModalOpen(true)}>+ Add Customer</Button>
                </>
              )}
            />
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
            <Text strong>Receipt type</Text>
            <Radio.Group value={form.mode} buttonStyle="solid" onChange={(event) => { setSelectedKeys([]); setAllocations({}); setForm((previous) => ({ ...previous, mode: event.target.value })); }}>
              <Radio.Button value="allocate">Allocate to invoices</Radio.Button>
              <Radio.Button value="advance">Advance/Unallocated</Radio.Button>
            </Radio.Group>
          </Col>
          {form.mode === 'advance' && (
            <Col xs={24} md={8}>
              <Text strong>Advance amount</Text>
              <InputNumber min={0.01} precision={2} value={form.advance_amount} onChange={(value) => setForm((previous) => ({ ...previous, advance_amount: value }))} />
            </Col>
          )}
          <Col xs={24} md={8}>
            <Text strong>Cash / bank account</Text>
            <Select allowClear disabled={form.method === 'card' || form.method === 'check'} placeholder="Optional account" value={form.account_id} onChange={(value) => setForm((previous) => ({ ...previous, account_id: value }))} options={activeAccounts.filter((item) => !customer?.currency_code || item.currency_code === customer.currency_code).map((item) => ({ value: item.id, label: item.account_name || `${item.bank_name} · ${item.account_number}` }))} />
          </Col>
          <Col xs={24} md={8}><Text strong>Reference</Text><Input maxLength={100} placeholder="Bank / cheque reference" value={form.reference} onChange={(event) => setForm((previous) => ({ ...previous, reference: event.target.value }))} /></Col>
          <Col xs={24} md={8}><Text strong>Description</Text><Input maxLength={1000} placeholder="Optional narration" value={form.narration} onChange={(event) => setForm((previous) => ({ ...previous, narration: event.target.value }))} /></Col>
        </Row>

        <div className="financial-entry-toolbar">
          {form.mode === 'allocate' ? (
            <Space wrap>
              <Button icon={<CheckSquareOutlined />} onClick={() => updateSelection(invoices.map((invoice) => invoice.id))} disabled={!invoices.length}>{compactActions ? null : 'Select all'}</Button>
              <Button icon={<ClearOutlined />} onClick={() => updateSelection([])} disabled={!selectedKeys.length}>{compactActions ? null : 'Clear'}</Button>
              <Text type="secondary">{selectedKeys.length} invoice{selectedKeys.length === 1 ? '' : 's'} selected</Text>
            </Space>
          ) : <Text type="secondary">Advance receipts can be allocated later from receipt history.</Text>}
          <Space wrap>
            <div className="allocation-total"><span>Receipt total</span><strong>{customer?.currency_code || ''} {money(receiptTotal)}</strong></div>
            <Button type="primary" size="large" icon={<SaveOutlined />} loading={saving} disabled={form.mode === 'advance' ? !customerId || receiptTotal <= 0 : !selectedKeys.length} onClick={submitReceipt}>{compactActions ? null : form.mode === 'advance' ? 'Record advance' : 'Record receipt'}</Button>
          </Space>
        </div>
        {form.mode === 'allocate' && (
          <Row justify="center" align="middle" className="financial-table-row">
            <Col span={24} className="financial-table-column">
              <Table rowKey="id" loading={loading} columns={invoiceColumns} dataSource={invoices} pagination={false} tableLayout="fixed" scroll={{ x: 1255, ...(invoices.length > 6 ? { y: 410 } : {}) }} rowSelection={{ selectedRowKeys: selectedKeys, onChange: updateSelection }} locale={{ emptyText: customerId ? 'No open invoices for this customer' : 'Select a customer to load open invoices' }} />
            </Col>
          </Row>
        )}
      </Card>

      <Card className="financial-history-card">
        <Tabs items={[{ key: 'history', label: 'Receipt history', children: <Table rowKey="id" loading={loading} columns={historyColumns} dataSource={receipts} scroll={{ x: 1670 }} /> }]} tabBarExtraContent={<Button icon={<ReloadOutlined />} onClick={loadBaseData}>{compactActions ? null : 'Refresh'}</Button>} />
      </Card>
      {printableReceipt && (
        <div className="receipt-print-host" aria-hidden="true">
          <div className="receipt-slip">
            <Watermark
              content={receiptCompany && receiptCompany.is_paid === false ? 'InYice' : undefined}
              rotate={-24}
              gap={[120, 100]}
              font={{ color: 'rgba(0, 0, 0, 0.12)', fontSize: 24, fontWeight: 700 }}
            >
              <div className="receipt-slip-header">
                <div className="receipt-slip-brand">
                  {receiptCompany.logo_url && <img className="receipt-slip-logo" src={receiptCompany.logo_url} alt={`${receiptCompanyName} logo`} />}
                  <div>
                    <Title level={3}>{receiptCompanyName}</Title>
                    {receiptCompanyContact.map((item) => <Text key={item}>{item}<br /></Text>)}
                  </div>
                </div>
                <div className="receipt-slip-heading">
                  <Title level={1}>RECEIPT</Title>
                  <Text strong># {printableReceipt.receipt_number}</Text>
                  <Text type="secondary">Date</Text>
                  <Title level={5}>{dateOnly(printableReceipt.receipt_date)}</Title>
                </div>
              </div>

              <div className="receipt-slip-meta">
                <div>
                  <Text type="secondary">Received From</Text>
                  <div className="receipt-slip-party">
                    {receiptCustomerContact.length ? receiptCustomerContact.map((item, index) => <Text key={`${item}-${index}`} strong={index === 0}>{item}<br /></Text>) : <Text>-</Text>}
                  </div>
                </div>
                <div className="receipt-slip-payment">
                  <div><Text>Payment Method</Text><Text strong>{formatMethod(printableReceipt.payment_method)}</Text></div>
                  <div><Text>Reference</Text><Text>{printableReceipt.reference_number || '—'}</Text></div>
                  <div><Text>Currency</Text><Text>{printableReceipt.currency_code}</Text></div>
                </div>
              </div>

              <div className="receipt-slip-amount">
                <Text>Amount Received</Text>
                <Title level={2}>{printableReceipt.currency_code} {money(printableReceipt.amount)}</Title>
              </div>

              <Table
                className="receipt-slip-lines"
                size="small"
                rowKey="key"
                pagination={false}
                dataSource={receiptAllocationRows}
                columns={[
                  { title: 'Invoice', render: (_, row) => row.invoice?.invoice_number || 'Advance balance' },
                  { title: 'Date', width: 120, render: (_, row) => dateOnly(row.settlement_date) },
                  { title: 'Amount', width: 150, align: 'right', render: (_, row) => money(row.amount_received) },
                ]}
                locale={{ emptyText: 'Advance receipt, not yet allocated to invoices' }}
              />

              <Divider />
              <Row justify="end">
                <Col xs={24} md={12}>
                  <div className="receipt-slip-totals">
                    <div><Text>Allocated</Text><Text>{money(receiptAllocatedTotal)}</Text></div>
                    <div><Text>Unallocated</Text><Text>{money(receiptBalance)}</Text></div>
                    <div className="receipt-slip-total-row"><Text strong>Total Received</Text><Text strong>{printableReceipt.currency_code} {money(printableReceipt.amount)}</Text></div>
                  </div>
                </Col>
              </Row>

              <div className="receipt-slip-notes">
                <Text strong>Notes</Text>
                <Paragraph>{printableReceipt.description || 'Thank you for your payment.'}</Paragraph>
              </div>
              <div className="receipt-slip-signatures">
                <div><span /> <Text>Received by</Text></div>
                <div><span /> <Text>Customer signature</Text></div>
              </div>
            </Watermark>
          </div>
        </div>
      )}
      <Modal
        title="Add Customer"
        open={customerModalOpen}
        onOk={handleCreateCustomer}
        onCancel={() => setCustomerModalOpen(false)}
        confirmLoading={savingCustomer}
      >
        <Form layout="vertical" form={customerForm} initialValues={{ type: 'B2C' }}>
          <Form.Item name="name" label="Name" rules={[{ required: true, message: 'Customer name required' }]}>
            <Input />
          </Form.Item>
          <Form.Item name="type" label="Type">
            <Select options={[{ value: 'B2C', label: 'B2C' }, { value: 'B2B', label: 'B2B' }]} />
          </Form.Item>
          <Form.Item name="email" label="Email">
            <Input />
          </Form.Item>
          <Form.Item name="phone" label="Phone">
            <Input />
          </Form.Item>
          <Form.Item name="currency_code" label="Currency Code">
            <Input placeholder="PKR" maxLength={3} />
          </Form.Item>
        </Form>
      </Modal>
      <Modal width={900} title={`Edit and reallocate ${editing?.receipt_number || ''}`} open={!!editing} onCancel={() => setEditing(null)} onOk={saveEdit} confirmLoading={saving} okText="Save changes">
        {editing && <><Row gutter={[12, 12]} style={{ marginBottom: 16 }}>
          <Col xs={12} md={5}><Text strong>Date</Text><Input type="date" value={editing.date} onChange={(event) => setEditing((current) => ({ ...current, date: event.target.value }))} /></Col>
          <Col xs={12} md={5}><Text strong>Method</Text><Select value={editing.method} onChange={(value) => setEditing((current) => ({ ...current, method: value, account_id: null }))} options={['cash', 'bank_transfer', 'card', 'check'].map((value) => ({ value, label: value.replaceAll('_', ' ').toUpperCase() }))} /></Col>
          <Col xs={24} md={7}><Text strong>Account</Text><Select allowClear value={editing.account_id} onChange={(value) => setEditing((current) => ({ ...current, account_id: value }))} options={(editing.method === 'cash' ? accounts.cash : accounts.bank).filter((item) => item.currency_code === editing.currency_code).map((item) => ({ value: item.id, label: item.account_name || `${item.bank_name} · ${item.account_number}` }))} /></Col>
          <Col xs={24} md={7}><Text strong>Reference</Text><Input value={editing.reference} onChange={(event) => setEditing((current) => ({ ...current, reference: event.target.value }))} /></Col>
          <Col span={24}><Text strong>Description</Text><Input value={editing.narration} onChange={(event) => setEditing((current) => ({ ...current, narration: event.target.value }))} /></Col>
        </Row><Table rowKey="id" pagination={false} dataSource={editInvoices} columns={[
          { title: 'Invoice', dataIndex: 'invoice_number' },
          { title: 'Current balance', dataIndex: 'outstanding_amount', align: 'right', render: money },
          { title: 'Available for reallocation', key: 'available', align: 'right', render: (_, invoice) => money(Number(invoice.outstanding_amount) + Number(editing.originalAllocations[invoice.id] || 0)) },
          { title: 'Allocation', key: 'allocation', align: 'right', render: (_, invoice) => <InputNumber min={0} max={Number(invoice.outstanding_amount) + Number(editing.originalAllocations[invoice.id] || 0)} precision={2} value={editing.allocations[invoice.id] || 0} onChange={(value) => setEditing((current) => ({ ...current, allocations: { ...current.allocations, [invoice.id]: Number(value || 0) } }))} /> },
        ]} /></>}
      </Modal>
      <Modal width={940} title={`Allocate advance ${advanceAllocating?.receipt_number || ''}`} open={!!advanceAllocating} onCancel={() => setAdvanceAllocating(null)} onOk={saveAdvanceAllocation} confirmLoading={saving} okText="Apply advance">
        {advanceAllocating && <><Row gutter={[12, 12]} align="bottom" style={{ marginBottom: 16 }}>
          <Col xs={12} md={5}><Text strong>Date</Text><Input type="date" value={advanceAllocating.date} onChange={(event) => setAdvanceAllocating((current) => ({ ...current, date: event.target.value }))} /></Col>
          <Col xs={12} md={5}><Text strong>Available</Text><Input readOnly value={`${advanceAllocating.currency_code} ${money(receiptRemaining(advanceAllocating))}`} /></Col>
          <Col xs={24} md={8}><Text strong>Allocation total</Text><Input readOnly value={`${advanceAllocating.currency_code} ${money(advanceAllocationTotal)}`} /></Col>
          <Col xs={24} md={6}><Space wrap><Button icon={<CheckSquareOutlined />} onClick={autoAllocateAdvance}>Auto allocate</Button><Button icon={<ClearOutlined />} onClick={() => setAdvanceAllocating((current) => ({ ...current, allocations: {} }))}>Clear</Button></Space></Col>
          <Col span={24}><Text strong>Notes</Text><Input value={advanceAllocating.notes} onChange={(event) => setAdvanceAllocating((current) => ({ ...current, notes: event.target.value }))} /></Col>
        </Row><Table rowKey="id" pagination={false} dataSource={advanceInvoices} locale={{ emptyText: 'No open invoices available for this advance' }} columns={[
          { title: 'Invoice', dataIndex: 'invoice_number', width: 180 },
          { title: 'Invoice date', dataIndex: 'invoice_date', width: 130, render: dateOnly },
          { title: 'Balance', dataIndex: 'outstanding_amount', width: 140, align: 'right', render: money },
          { title: 'Allocation', key: 'allocation', width: 180, align: 'right', render: (_, invoice) => <InputNumber min={0} max={Math.min(Number(invoice.outstanding_amount), receiptRemaining(advanceAllocating))} precision={2} value={advanceAllocating.allocations[invoice.id] || 0} onChange={(value) => setAdvanceAllocating((current) => ({ ...current, allocations: { ...current.allocations, [invoice.id]: Number(value || 0) } }))} /> },
          { title: 'Description', dataIndex: 'notes', ellipsis: true, render: (value) => value || '—' },
        ]} scroll={{ x: 820 }} /></>}
      </Modal>
    </div>
  );
}
