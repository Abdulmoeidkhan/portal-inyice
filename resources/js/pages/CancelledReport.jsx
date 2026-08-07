import React, { useEffect, useState } from 'react';
import { DownloadOutlined, ReloadOutlined, RetweetOutlined } from '@ant-design/icons';
import { Button, Card, Col, Empty, Input, Popconfirm, Row, Statistic, Table, Tag, Typography } from 'antd';
import { message } from '../services/feedback';
import { dateOnly } from '../services/dateFormat';

const { Title, Paragraph, Text } = Typography;
const dateString = (date) => new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const firstOfMonth = () => { const date = new Date(); date.setDate(1); return dateString(date); };
const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function CancelledReport({ embedded = false }) {
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [filters, setFilters] = useState({ from_date: firstOfMonth(), to_date: dateString(new Date()), search: '' });
  const [recreatingUid, setRecreatingUid] = useState(null);
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  const fetchReport = async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      Object.entries(filters).forEach(([key, value]) => {
        if (value) params.set(key, value);
      });

      const response = await fetch(`/api/v1/reports/cancelled?${params}`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || data.message || 'Could not load cancelled report');
      }

      setReport(data);
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };

  const recreateOrder = async (row) => {
    if (!row.order_uid) return;

    setRecreatingUid(row.order_uid);
    try {
      const response = await fetch(`/api/v1/orders/${row.order_uid}/recreate`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || data.message || 'Could not recreate order');
      }

      message.success(data.message || `New order ${data.order?.order_number || ''} created`);
      fetchReport();
    } catch (error) {
      message.error(error.message);
    } finally {
      setRecreatingUid(null);
    }
  };

  useEffect(() => {
    fetchReport();
  }, []);

  const exportCsv = () => {
    if (!report?.data?.length) return;
    const escape = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
    const rows = [
      ['Type', 'Invoice', 'Date', 'Agency', 'Company', 'Customer', 'Order', 'Booking Ref', 'Cancelled By', 'Cancelled User ID', 'New Order', 'Currency', 'Amount', 'Outstanding', 'Notes'],
      ...report.data.map((row) => [
        row.document_type,
        row.invoice_number,
        dateOnly(row.invoice_date),
        row.tenant_name,
        row.company_name,
        row.customer_name,
        row.order_number,
        row.booking_reference,
        row.cancelled_by,
        row.cancelled_by_user_id,
        row.linked_new_order_number,
        row.currency_code,
        row.total_amount,
        row.outstanding_amount,
        row.notes,
      ]),
    ];
    const blob = new Blob([rows.map((row) => row.map(escape).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `cancelled-report-${filters.from_date}-to-${filters.to_date}.csv`;
    anchor.click();
    URL.revokeObjectURL(url);
  };

  const columns = [
    { title: 'Type', dataIndex: 'document_type', width: 95, render: (value) => <Tag color={value === 'Order' ? 'orange' : 'default'}>{value || 'Invoice'}</Tag> },
    { title: 'Invoice', dataIndex: 'invoice_number', width: 165, render: (value) => value || '-' },
    { title: 'Date', dataIndex: 'invoice_date', width: 120, render: dateOnly },
    ...(report?.scope === 'all_companies' ? [
      { title: 'Agency', dataIndex: 'tenant_name', width: 170, render: (value) => value || '-' },
      { title: 'Company', dataIndex: 'company_name', width: 180, render: (value) => value || '-' },
    ] : []),
    { title: 'Customer', dataIndex: 'customer_name', width: 180, render: (value) => value || '-' },
    { title: 'Order', dataIndex: 'order_number', width: 150, render: (value) => value || '-' },
    { title: 'Booking Ref', dataIndex: 'booking_reference', width: 135, render: (value) => value || '-' },
    { title: 'Status', key: 'status', width: 105, render: () => <Tag color="default">CANCEL</Tag> },
    { title: 'Cancelled By', dataIndex: 'cancelled_by', width: 150, render: (value) => value || '-' },
    { title: 'User ID', dataIndex: 'cancelled_by_user_id', width: 100, render: (value) => value || '-' },
    { title: 'New Order', dataIndex: 'linked_new_order_number', width: 140, render: (value) => value || '-' },
    { title: 'Amount', dataIndex: 'total_amount', width: 145, align: 'right', render: (value, row) => `${row.currency_code || ''} ${money(value)}` },
    { title: 'Outstanding', dataIndex: 'outstanding_amount', width: 145, align: 'right', render: (value, row) => `${row.currency_code || ''} ${money(value)}` },
    { title: 'Notes', dataIndex: 'notes', width: 260, ellipsis: true, render: (value) => value || '-' },
    {
      title: 'Actions',
      key: 'actions',
      width: 145,
      fixed: 'right',
      render: (_, row) => row.document_type === 'Order' && !row.linked_new_order_number ? (
        <Popconfirm
          title="Create new order?"
          description="This copies the cancelled order into a new order number."
          okText="Create"
          onConfirm={() => recreateOrder(row)}
        >
          <Button size="small" icon={<RetweetOutlined />} loading={recreatingUid === row.order_uid}>
            Recreate
          </Button>
        </Popconfirm>
      ) : null,
    },
  ];

  const content = (
    <>
      <div className={embedded ? undefined : 'elevated-card border-beam-aurora'} style={{ marginBottom: 16 }}>
        <Title level={embedded ? 3 : 2} style={{ marginTop: 0 }}>Cancelled Report</Title>
        <Paragraph type="secondary">Cancelled invoices and cancelled orders.</Paragraph>
        <Row gutter={[12, 12]} align="bottom">
          <Col xs={12} md={embedded ? 6 : 4}><Text strong>From</Text><Input type="date" value={filters.from_date} onChange={(event) => setFilters((current) => ({ ...current, from_date: event.target.value }))} /></Col>
          <Col xs={12} md={embedded ? 6 : 4}><Text strong>To</Text><Input type="date" value={filters.to_date} onChange={(event) => setFilters((current) => ({ ...current, to_date: event.target.value }))} /></Col>
          <Col xs={24} md={embedded ? 8 : 6}><Text strong>Search</Text><Input allowClear value={filters.search} onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))} onPressEnter={fetchReport} /></Col>
          <Col xs={24} md={embedded ? 4 : 3}><Button block type="primary" icon={<ReloadOutlined />} loading={loading} onClick={fetchReport}>Run</Button></Col>
        </Row>
      </div>

      {report && (
        <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
          <Col xs={24} md={8}><Card><Statistic title="Cancelled records" value={report.summary.total_records} /></Card></Col>
          {report.summary.by_currency.map((item) => (
            <Col xs={24} md={8} key={item.currency_code}>
              <Card><Statistic title={`${item.currency_code} · ${item.count} records`} value={item.amount} precision={2} /></Card>
            </Col>
          ))}
        </Row>
      )}

      <Card title="Cancelled records" extra={<Button icon={<DownloadOutlined />} disabled={!report?.data?.length} onClick={exportCsv}>Export CSV</Button>}>
        {!loading && report?.data?.length === 0
          ? <Empty description="No cancelled records" />
          : <Table rowKey="uid" loading={loading} columns={columns} dataSource={report?.data || []} scroll={{ x: 'max-content' }} pagination={{ pageSize: 25, showSizeChanger: true }} />}
      </Card>
    </>
  );

  return embedded ? content : <div className="page-shell page-fade-up">{content}</div>;
}
