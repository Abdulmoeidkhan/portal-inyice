import React, { useEffect, useState } from 'react';
import { EyeOutlined, FileSearchOutlined, ReloadOutlined } from '@ant-design/icons';
import { Button, Card, Col, Empty, Input, Row, Select, Space, Statistic, Tag, Typography } from 'antd';
import { useNavigate } from 'react-router-dom';
import { message } from '../services/feedback';
import { dateOnly } from '../services/dateFormat';
import Table from '../components/CsvTable';
import useReportTablePagination from '../hooks/useReportTablePagination';

const { Title, Paragraph, Text } = Typography;
const dateString = (date) => new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const firstOfMonth = () => { const date = new Date(); date.setDate(1); return dateString(date); };
const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const label = (value) => String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

export default function DiscountReport() {
  const [report, setReport] = useState(null);
  const [customers, setCustomers] = useState([]);
  const [loading, setLoading] = useState(false);
  const [filters, setFilters] = useState({ from_date: firstOfMonth(), to_date: dateString(new Date()), discount_type: undefined, customer_id: undefined, search: '' });
  const [tablePagination, resetTablePagination] = useReportTablePagination();
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
  const navigate = useNavigate();

  const fetchReport = async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      Object.entries(filters).forEach(([key, value]) => { if (value) params.set(key, value); });
      const response = await fetch(`/api/v1/reports/discounts?${params}`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Could not load discount report');
      resetTablePagination();
      setReport(data);
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchReport();
    fetch('/api/v1/customers', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } })
      .then(async (response) => {
        if (!response.ok) throw new Error('Could not load customer filters');
        const data = await response.json();
        setCustomers(data.data || data || []);
      })
      .catch((error) => message.error(error.message));
  }, []);

  const columns = [
    { title: 'Discount Date', dataIndex: 'discount_date', width: 130, render: dateOnly },
    { title: 'Invoice #', dataIndex: 'invoice_number', width: 170, render: (value, row) => <Space direction="vertical" size={0}><Text strong>{value || '-'}</Text><Text type="secondary">{dateOnly(row.invoice_date)}</Text></Space> },
    { title: 'Order', dataIndex: 'order_number', width: 150, render: (value, row) => <Space direction="vertical" size={0}><Text>{value || '-'}</Text><Text type="secondary">{row.booking_reference || ''}</Text></Space> },
    { title: 'Customer', dataIndex: 'customer_name', width: 190, render: (value) => value || '-' },
    { title: 'Type', dataIndex: 'discount_type', width: 120, render: (value) => <Tag color={value === 'percentage' ? 'purple' : 'blue'}>{label(value)}</Tag> },
    { title: 'Percent', dataIndex: 'percentage', width: 105, align: 'right', render: (value) => value === null || value === undefined ? '-' : `${Number(value).toLocaleString(undefined, { maximumFractionDigits: 4 })}%` },
    { title: 'Amount', dataIndex: 'amount', width: 145, align: 'right', render: (value, row) => <Text type="danger" strong>{row.currency_code || ''} {money(value)}</Text> },
    { title: 'Invoice Total', dataIndex: 'invoice_total', width: 145, align: 'right', render: (value, row) => `${row.currency_code || ''} ${money(value)}`.trim() },
    { title: 'Reason', dataIndex: 'reason', width: 220, ellipsis: true, render: (value) => value || '-' },
    { title: 'Created By', dataIndex: 'created_by', width: 145, render: (value) => value || '-' },
    {
      title: 'Open',
      key: 'open',
      fixed: 'right',
      width: 185,
      render: (_, row) => (
        <Space wrap size={[6, 6]}>
          {row.invoice_uid && <Button size="small" icon={<EyeOutlined />} onClick={() => navigate(`/invoices/${row.invoice_uid}`)}>Invoice</Button>}
          {row.order_uid && <Button size="small" icon={<FileSearchOutlined />} onClick={() => navigate(`/orders/${row.order_uid}/voucher`)}>Voucher</Button>}
        </Space>
      ),
    },
  ];

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2}>Discount Report</Title>
        <Paragraph>Invoice discounts by date, customer, type, invoice, and order.</Paragraph>
        <Row gutter={[12, 12]} align="bottom">
          <Col xs={12} md={4}><Text strong>From</Text><Input type="date" value={filters.from_date} onChange={(event) => setFilters((current) => ({ ...current, from_date: event.target.value }))} /></Col>
          <Col xs={12} md={4}><Text strong>To</Text><Input type="date" value={filters.to_date} onChange={(event) => setFilters((current) => ({ ...current, to_date: event.target.value }))} /></Col>
          <Col xs={12} md={4}><Text strong>Type</Text><Select allowClear placeholder="All types" value={filters.discount_type} onChange={(value) => setFilters((current) => ({ ...current, discount_type: value }))} options={[{ value: 'amount', label: 'Amount' }, { value: 'percentage', label: 'Percentage' }]} /></Col>
          <Col xs={12} md={5}><Text strong>Customer</Text><Select showSearch allowClear optionFilterProp="label" placeholder="All customers" value={filters.customer_id} onChange={(value) => setFilters((current) => ({ ...current, customer_id: value }))} options={customers.map((customer) => ({ value: customer.id, label: customer.name }))} /></Col>
          <Col xs={24} md={5}><Text strong>Search</Text><Input allowClear value={filters.search} onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))} onPressEnter={fetchReport} /></Col>
          <Col xs={24} md={2}><Button block type="primary" icon={<ReloadOutlined />} loading={loading} onClick={fetchReport}>Run</Button></Col>
        </Row>
      </div>

      {report && (
        <>
          <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
            <Col xs={12} md={6}><Card><Statistic title="Discounts" value={report.summary.total_records} /></Card></Col>
            <Col xs={12} md={6}><Card><Statistic title="Total Discount" value={report.summary.total_discount} precision={2} /></Card></Col>
            <Col xs={12} md={6}><Card><Statistic title="Amount Discounts" value={report.summary.by_type.find((item) => item.discount_type === 'amount')?.count || 0} /></Card></Col>
            <Col xs={12} md={6}><Card><Statistic title="Percentage Discounts" value={report.summary.by_type.find((item) => item.discount_type === 'percentage')?.count || 0} /></Card></Col>
          </Row>
          {report.summary.by_currency.length > 0 && (
            <Card title="Discounts by currency" style={{ marginBottom: 16 }}>
              <Space size="large" wrap>{report.summary.by_currency.map((item) => <Statistic key={item.currency_code || 'none'} title={`${item.currency_code || 'Currency'} · ${item.count} records`} value={item.amount} precision={2} />)}</Space>
            </Card>
          )}
        </>
      )}

      <Card title="Discount records">
        {!loading && report?.data?.length === 0 ? <Empty description="No matching discounts" /> : <Table rowKey="key" loading={loading} columns={columns} dataSource={report?.data || []} scroll={{ x: 1700 }} pagination={tablePagination} />}
      </Card>
    </div>
  );
}
