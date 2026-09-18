import React, { useEffect, useRef, useState } from 'react';
import { EyeOutlined, ReloadOutlined } from '@ant-design/icons';
import { Alert, Button, Card, Col, Input, Row, Select, Space, Statistic, Tag, Typography } from 'antd';
import { useNavigate } from 'react-router-dom';
import { dateOnly } from '../services/dateFormat';
import { openRoute } from '../services/navigation';
import Table from '../components/CsvTable';
import useReportTablePagination from '../hooks/useReportTablePagination';

const { Title, Paragraph, Text } = Typography;
const dateString = (date) => new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const firstOfMonth = () => { const date = new Date(); date.setDate(1); return dateString(date); };
const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 });

export default function TaxReport() {
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [filters, setFilters] = useState({ from_date: firstOfMonth(), to_date: dateString(new Date()), currency_code: '', type: undefined, pricing: undefined, search: '' });
  const [pagination, resetPagination] = useReportTablePagination();
  const pending = useRef(null);
  const navigate = useNavigate();
  const change = (key, value) => setFilters((current) => ({ ...current, [key]: value }));

  const fetchReport = async () => {
    pending.current?.abort();
    const controller = new AbortController();
    pending.current = controller;
    setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams();
      Object.entries(filters).forEach(([key, value]) => { if (value) params.set(key, value); });
      const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
      const response = await fetch(`/api/v1/reports/tax?${params}`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }, signal: controller.signal,
      });
      const data = await response.json();
      if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Could not load tax report');
      if (controller.signal.aborted) return;
      setReport(data);
      resetPagination();
    } catch (failure) {
      if (controller.signal.aborted) return;
      setReport(null);
      setError(failure.message);
    } finally {
      if (!controller.signal.aborted) setLoading(false);
    }
  };

  useEffect(() => { fetchReport(); return () => pending.current?.abort(); }, []);

  const columns = [
    { title: 'Date', dataIndex: 'date', width: 120, render: dateOnly },
    { title: 'Document', dataIndex: 'reference', width: 200 },
    { title: 'Type', dataIndex: 'type', width: 100, render: (value) => <Tag color={value === 'refund' ? 'orange' : 'blue'}>{value === 'refund' ? 'Refund' : 'Invoice'}</Tag> },
    { title: 'Order', dataIndex: 'order_number', width: 170 },
    { title: 'Customer', dataIndex: 'customer_name', width: 190 },
    { title: 'Currency', dataIndex: 'currency_code', width: 100 },
    { title: 'Tax label', dataIndex: 'tax_label', width: 120 },
    { title: 'Registration number', dataIndex: 'registration_number', width: 180 },
    { title: 'Rate (%)', dataIndex: 'rate', width: 100, align: 'right' },
    { title: 'Pricing', dataIndex: 'pricing', width: 110 },
    { title: 'Taxable amount (net)', dataIndex: 'taxable_amount', width: 170, align: 'right', render: money },
    { title: 'Tax amount', dataIndex: 'tax_amount', width: 150, align: 'right', render: money },
    { title: 'Sales excluding tax', dataIndex: 'net_amount', width: 170, align: 'right', render: money },
    { title: 'Total including tax', dataIndex: 'total_amount', width: 170, align: 'right', render: money },
    { title: 'Open', key: 'open', width: 110, fixed: 'right', render: (_, row) => <Button size="small" icon={<EyeOutlined />} onClick={(event) => openRoute(navigate, `/invoices/${row.invoice_uid}`, event)}>View</Button> },
  ];

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2}>Tax Report</Title>
        <Paragraph>Saved tax on issued invoices and confirmed refunds, after discounts. This report follows invoice amounts, not payment collection dates.</Paragraph>
        <Row gutter={[12, 12]} align="bottom">
          <Col xs={12} md={4}><label htmlFor="tax-from">From</label><Input id="tax-from" type="date" value={filters.from_date} onChange={(e) => change('from_date', e.target.value)} /></Col>
          <Col xs={12} md={4}><label htmlFor="tax-to">To</label><Input id="tax-to" type="date" value={filters.to_date} onChange={(e) => change('to_date', e.target.value)} /></Col>
          <Col xs={12} md={4}><label htmlFor="tax-currency">Currency</label><Input id="tax-currency" placeholder="All currencies" maxLength={3} value={filters.currency_code} onChange={(e) => change('currency_code', e.target.value.toUpperCase())} /></Col>
          <Col xs={12} md={4}><label htmlFor="tax-type">Type</label><Select id="tax-type" style={{ width: '100%' }} allowClear placeholder="Invoices and refunds" value={filters.type} onChange={(value) => change('type', value)} options={[{ value: 'invoice', label: 'Invoices' }, { value: 'refund', label: 'Refunds' }]} /></Col>
          <Col xs={12} md={4}><label htmlFor="tax-pricing">Pricing</label><Select id="tax-pricing" style={{ width: '100%' }} allowClear placeholder="All pricing" value={filters.pricing} onChange={(value) => change('pricing', value)} options={[{ value: 'inclusive', label: 'Inclusive' }, { value: 'exclusive', label: 'Exclusive' }]} /></Col>
          <Col xs={12} md={4}><Button block type="primary" icon={<ReloadOutlined />} loading={loading} onClick={fetchReport}>Run report</Button></Col>
          <Col span={24}><Input aria-label="Search tax report" allowClear placeholder="Search invoice, order, customer, tax label or registration number" value={filters.search} onChange={(e) => change('search', e.target.value)} onPressEnter={fetchReport} /></Col>
        </Row>
      </div>
      {error && <Alert type="error" showIcon title={error} style={{ marginBottom: 16 }} />}
      {report && <>
        <Paragraph>{dateOnly(report.period.from)} to {dateOnly(report.period.to)}: {report.summary.invoice_count} invoices and {report.summary.refund_count} refunds. Amounts are kept separate by currency.</Paragraph>
        <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
          {report.summary.by_currency.map((item) => <Col xs={24} xl={12} key={item.currency_code}>
            <Card title={item.currency_code}><Space wrap size="large">
              <Statistic title="Tax charged" value={item.tax_charged} formatter={money} />
              <Statistic title="Tax reversed" value={item.tax_reversed} formatter={money} />
              <Statistic title="Net tax" value={item.net_tax} formatter={money} />
              <Statistic title="Net taxable amount" value={item.taxable_amount} formatter={money} />
            </Space></Card>
          </Col>)}
        </Row>
      </>}
      <Card title="Tax records">
        <Text type="secondary">Refund amounts are negative. Cancelled/void invoices, unconfirmed refund requests and invoices without saved tax settings are excluded.</Text>
        <Table rowKey="key" loading={loading} columns={columns} dataSource={report?.data || []} pagination={pagination} scroll={{ x: 2200 }} csvFileName={`tax-report-${report?.period.from || 'records'}.csv`} locale={{ emptyText: 'No matching tax records' }} />
      </Card>
    </div>
  );
}
