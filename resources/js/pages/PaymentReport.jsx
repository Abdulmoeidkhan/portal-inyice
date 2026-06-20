import React, { useEffect, useState } from 'react';
import { DownloadOutlined, ReloadOutlined } from '@ant-design/icons';
import { Button, Card, Col, Empty, Input, Row, Select, Space, Statistic, Table, Tag, Typography } from 'antd';
import { message } from '../services/feedback';

const { Title, Paragraph, Text } = Typography;
const dateString = (date) => new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const firstOfMonth = () => { const date = new Date(); date.setDate(1); return dateString(date); };
const money = (value) => Number(value || 0).toFixed(2);
const label = (value) => String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

export default function PaymentReport({ direction = 'payment' }) {
  const isReceipt = direction === 'receipt';
  const title = isReceipt ? 'Receipt Report' : 'Payment Report';
  const [report, setReport] = useState(null);
  const [counterparties, setCounterparties] = useState({ customers: [], vendors: [] });
  const [loading, setLoading] = useState(false);
  const [filters, setFilters] = useState({ from_date: firstOfMonth(), to_date: dateString(new Date()), counterparty_type: undefined, counterparty_id: undefined, payment_method: undefined, search: '' });
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  const fetchReport = async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams(); Object.entries(filters).forEach(([key, value]) => { if (value) params.set(key, value); });
      const response = await fetch(`/api/v1/reports/${isReceipt ? 'receipts' : 'payments'}?${params}`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
      const data = await response.json(); if (!response.ok) throw new Error(data.message || `Could not load ${title.toLowerCase()}`); setReport(data);
    } catch (error) { message.error(error.message); } finally { setLoading(false); }
  };
  useEffect(() => {
    fetchReport();
    Promise.all([
      fetch('/api/v1/customers', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } }),
      fetch('/api/v1/vendors', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } }),
    ]).then(async ([customerResponse, vendorResponse]) => {
      if (!customerResponse.ok || !vendorResponse.ok) throw new Error('Could not load customer and vendor filters');
      const [customers, vendors] = await Promise.all([customerResponse.json(), vendorResponse.json()]);
      setCounterparties({ customers: customers.data || customers || [], vendors: vendors.data || vendors || [] });
    }).catch((error) => message.error(error.message));
  }, [direction]);

  const exportCsv = () => {
    if (!report?.data?.length) return;
    const escape = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
    const rows = [['Date', 'Document', 'Counterparty type', 'Counterparty', 'Method', 'Reference', 'Description', 'Currency', 'Amount', 'Created by'], ...report.data.map((row) => [row.date, row.document_number, row.counterparty_type, row.counterparty_name, row.payment_method, row.reference_number, row.description, row.currency_code, row.amount, row.created_by])];
    const blob = new Blob([rows.map((row) => row.map(escape).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob); const anchor = document.createElement('a'); anchor.href = url; anchor.download = `${direction}-report-${filters.from_date}-to-${filters.to_date}.csv`; anchor.click(); URL.revokeObjectURL(url);
  };

  const columns = [
    { title: 'Date', dataIndex: 'date', width: 115 }, { title: `${isReceipt ? 'Receipt' : 'Payment'} #`, dataIndex: 'document_number', width: 165 },
    { title: 'Type', dataIndex: 'counterparty_type', width: 110, render: (value) => <Tag color={value === 'customer' ? 'blue' : 'purple'}>{label(value)}</Tag> },
    { title: 'Customer / vendor', dataIndex: 'counterparty_name', width: 190 }, { title: 'Method', dataIndex: 'payment_method', width: 135, render: (value) => <Tag>{label(value)}</Tag> },
    { title: 'Reference', dataIndex: 'reference_number', width: 145, render: (value) => value || '—' }, { title: 'Description', dataIndex: 'description', width: 220, ellipsis: true, render: (value) => value || '—' },
    { title: 'Amount', dataIndex: 'amount', fixed: 'right', width: 155, align: 'right', render: (value, row) => <Text strong type={isReceipt ? undefined : 'danger'}>{isReceipt ? '+' : '−'} {row.currency_code} {money(value)}</Text> },
  ];

  return <div className="page-shell page-fade-up">
    <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}><Title level={2}>{title}</Title><Paragraph>{isReceipt ? 'Money in: all customer and vendor receipts.' : 'Money out: all customer and vendor payments.'}</Paragraph>
      <Row gutter={[12, 12]} align="bottom">
        <Col xs={12} md={4}><Text strong>From</Text><Input type="date" value={filters.from_date} onChange={(event) => setFilters((current) => ({ ...current, from_date: event.target.value }))} /></Col>
        <Col xs={12} md={4}><Text strong>To</Text><Input type="date" value={filters.to_date} onChange={(event) => setFilters((current) => ({ ...current, to_date: event.target.value }))} /></Col>
        <Col xs={12} md={3}><Text strong>Counterparty</Text><Select allowClear placeholder="All" value={filters.counterparty_type} onChange={(value) => setFilters((current) => ({ ...current, counterparty_type: value, counterparty_id: undefined }))} options={[{ value: 'customer', label: 'Customers' }, { value: 'vendor', label: 'Vendors' }]} /></Col>
        <Col xs={12} md={4}><Text strong>Specific party</Text><Select showSearch allowClear optionFilterProp="label" disabled={!filters.counterparty_type} placeholder={filters.counterparty_type ? `All ${filters.counterparty_type}s` : 'Choose type first'} value={filters.counterparty_id} onChange={(value) => setFilters((current) => ({ ...current, counterparty_id: value }))} options={(filters.counterparty_type === 'customer' ? counterparties.customers : counterparties.vendors).map((item) => ({ value: item.id, label: item.name }))} /></Col>
        <Col xs={12} md={3}><Text strong>Method</Text><Select allowClear placeholder="All methods" value={filters.payment_method} onChange={(value) => setFilters((current) => ({ ...current, payment_method: value }))} options={['cash', 'bank_transfer', 'card', 'check'].map((value) => ({ value, label: label(value) }))} /></Col>
        <Col xs={24} md={4}><Text strong>Search</Text><Input allowClear value={filters.search} onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))} onPressEnter={fetchReport} /></Col>
        <Col xs={24} md={2}><Button block type="primary" icon={<ReloadOutlined />} loading={loading} onClick={fetchReport}>Run</Button></Col>
      </Row>
    </div>
    {report && <><Row gutter={[16, 16]} style={{ marginBottom: 16 }}><Col xs={8}><Card><Statistic title="All records" value={report.summary.total_records} /></Card></Col><Col xs={8}><Card><Statistic title="Customer records" value={report.summary.customer_records} /></Card></Col><Col xs={8}><Card><Statistic title="Vendor records" value={report.summary.vendor_records} /></Card></Col></Row>
      {report.summary.by_currency.length > 0 && <Card title={`${isReceipt ? 'Money in' : 'Money out'} by currency`} style={{ marginBottom: 16 }}><Space size="large" wrap>{report.summary.by_currency.map((item) => <Statistic key={item.currency_code} title={`${item.currency_code} · ${item.count} records`} value={item.amount} precision={2} />)}</Space></Card>}</>}
    <Card title={`${isReceipt ? 'Receipt' : 'Payment'} records`} extra={<Button icon={<DownloadOutlined />} disabled={!report?.data?.length} onClick={exportCsv}>Export CSV</Button>}>{!loading && report?.data?.length === 0 ? <Empty description="No matching records" /> : <Table rowKey="key" loading={loading} columns={columns} dataSource={report?.data || []} scroll={{ x: 1235 }} pagination={{ pageSize: 25, showSizeChanger: true }} />}</Card>
  </div>;
}
