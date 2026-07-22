import React, { useEffect, useMemo, useState } from 'react';
import { DownloadOutlined, ReloadOutlined } from '@ant-design/icons';
import { Button, Card, Col, Empty, Input, Row, Segmented, Select, Space, Statistic, Table, Tag, Typography, theme } from 'antd';
import { message } from '../services/feedback';

const { Title, Paragraph, Text } = Typography;

const dateString = (date) => new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const dateOnly = (value) => String(value || '').slice(0, 10) || '-';
const firstOfMonth = () => {
  const date = new Date();
  date.setDate(1);
  return dateString(date);
};
const money = (value) => Number(value || 0).toFixed(2);
const margin = (value) => `${Number(value || 0).toFixed(2)}%`;
const label = (value) => String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const dateByOptions = [
  { label: 'Invoice Date', value: 'invoice' },
  { label: 'Creation Date', value: 'creation' },
  { label: 'Departure Date', value: 'departure' },
  { label: 'Check-in Date', value: 'checkin' },
  { label: 'Service Date', value: 'service' },
];

export default function ProfitReport() {
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [entityOptions, setEntityOptions] = useState([]);
  const [entityLoading, setEntityLoading] = useState(false);
  const [filters, setFilters] = useState({
    from_date: firstOfMonth(),
    to_date: dateString(new Date()),
    date_by: 'invoice',
    group_by: 'customer',
    entity_id: null,
    search: '',
  });
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
  const { token: themeToken } = theme.useToken();

  const groupLabel = useMemo(() => label(filters.group_by), [filters.group_by]);
  const hasEntitySelection = filters.entity_id === 'all' || Number(filters.entity_id) > 0;
  const entitySelectOptions = useMemo(() => [
    { value: 'all', label: `All ${groupLabel}s` },
    ...entityOptions.map((item) => ({ value: item.id, label: item.name })),
  ], [entityOptions, groupLabel]);

  const fetchEntities = async () => {
    setEntityLoading(true);
    try {
      const endpoint = filters.group_by === 'customer'
        ? '/api/v1/customers'
        : filters.group_by === 'vendor'
          ? '/api/v1/vendors'
          : '/api/v1/staff';

      const response = await fetch(endpoint, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || `Could not load ${groupLabel.toLowerCase()} list`);
      setEntityOptions(data.data || data || []);
    } catch (error) {
      message.error(error.message);
      setEntityOptions([]);
    } finally {
      setEntityLoading(false);
    }
  };

  const fetchReport = async () => {
    if (!hasEntitySelection) {
      setReport(null);
      return;
    }

    setLoading(true);
    try {
      const params = new URLSearchParams();
      Object.entries(filters).forEach(([key, value]) => {
        if (key === 'entity_id' && value === 'all') return;
        if (value) params.set(key, value);
      });

      const response = await fetch(`/api/v1/reports/profit?${params}`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Could not load profit report');
      setReport(data);
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchEntities();
    setReport(null);
  }, [filters.group_by]);

  useEffect(() => {
    if (hasEntitySelection) fetchReport();
  }, [filters.entity_id]);

  const exportCsv = () => {
    if (!report?.data?.length) return;

    const escape = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
    const rows = [
      [`${groupLabel}`, 'Currency', 'Orders', 'Cost', 'Profit', 'Revenue', 'Margin'],
      ...report.data.map((row) => [
        row.group_name,
        row.currency_code,
        row.order_count,
        row.cost,
        row.profit,
        row.revenue,
        row.profit_margin,
      ]),
      [],
      ['Date', 'Creation Date', 'Invoice Date', 'Departure Date', 'Check-in Date', 'Service Date', 'Order', 'Voucher', 'PNR', 'Customer', 'Vendor', 'Staff', 'Status', 'Currency', 'Cost', 'Profit', 'Revenue', 'Margin'],
      ...(report.details || []).map((row) => [
        dateOnly(row.date),
        dateOnly(row.creation_date),
        dateOnly(row.invoice_date),
        dateOnly(row.departure_date),
        dateOnly(row.checkin_date),
        dateOnly(row.service_date),
        row.order_number,
        row.voucher_no,
        row.booking_reference,
        row.customer_name,
        row.vendor_name,
        row.staff_name,
        row.status,
        row.currency_code,
        row.cost,
        row.profit,
        row.revenue,
        row.profit_margin,
      ]),
    ];

    const blob = new Blob([rows.map((row) => row.map(escape).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `profit-report-${filters.group_by}-${filters.from_date}-to-${filters.to_date}.csv`;
    anchor.click();
    URL.revokeObjectURL(url);
  };

  const groupColumns = [
    { title: groupLabel, dataIndex: 'group_name', width: 240 },
    { title: 'Currency', dataIndex: 'currency_code', width: 100, render: (value) => <Tag>{value}</Tag> },
    { title: 'Orders', dataIndex: 'order_count', width: 100, align: 'center' },
    { title: 'Cost', dataIndex: 'cost', width: 140, align: 'right', render: (value, row) => `${row.currency_code} ${money(value)}` },
    {
      title: 'Profit',
      dataIndex: 'profit',
      width: 140,
      align: 'right',
      render: (value, row) => <Text strong type={Number(value) < 0 ? 'danger' : 'success'}>{row.currency_code} {money(value)}</Text>,
    },
    { title: 'Revenue', dataIndex: 'revenue', width: 140, align: 'right', render: (value, row) => `${row.currency_code} ${money(value)}` },
    { title: 'Margin', dataIndex: 'profit_margin', width: 110, align: 'right', render: margin },
  ];

  const detailColumns = [
    { title: 'Date', dataIndex: 'date', width: 115, render: dateOnly },
    { title: 'Creation Date', dataIndex: 'creation_date', width: 125, render: dateOnly },
    { title: 'Invoice Date', dataIndex: 'invoice_date', width: 120, render: dateOnly },
    { title: 'Departure Date', dataIndex: 'departure_date', width: 145, render: dateOnly },
    { title: 'Check-in Date', dataIndex: 'checkin_date', width: 135, render: dateOnly },
    { title: 'Service Date', dataIndex: 'service_date', width: 160, render: dateOnly },
    { title: 'Order #', dataIndex: 'order_number', width: 150 },
    { title: 'Voucher', dataIndex: 'voucher_no', width: 135, render: (value) => value || '-' },
    { title: 'PNR', dataIndex: 'booking_reference', width: 120, render: (value) => value || '-' },
    { title: 'Customer', dataIndex: 'customer_name', width: 180, render: (value) => value || '-' },
    { title: 'Vendor', dataIndex: 'vendor_name', width: 180, render: (value) => value || '-' },
    { title: 'Staff', dataIndex: 'staff_name', width: 160, render: (value) => value || '-' },
    { title: 'Status', dataIndex: 'status', width: 125, render: (value) => <Tag>{label(value)}</Tag> },
    { title: 'Cost', dataIndex: 'cost', width: 135, align: 'right', render: (value, row) => `${row.currency_code} ${money(value)}` },
    {
      title: 'Profit',
      dataIndex: 'profit',
      width: 135,
      align: 'right',
      render: (value, row) => <Text strong type={Number(value) < 0 ? 'danger' : 'success'}>{row.currency_code} {money(value)}</Text>,
    },
    { title: 'Revenue', dataIndex: 'revenue', fixed: 'right', width: 135, align: 'right', render: (value, row) => `${row.currency_code} ${money(value)}` },
  ];

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2}>Profit Report</Title>
        <Paragraph>Check gross profit by customer, vendor, or staff using invoiced order revenue minus supplier costs.</Paragraph>

        <Row gutter={[12, 12]} align="bottom">
          <Col xs={24} lg={6}>
            <Text strong>View</Text>
            <Segmented
              block
              value={filters.group_by}
              onChange={(value) => setFilters((current) => ({ ...current, group_by: value, entity_id: null }))}
              options={[
                { label: 'Customer', value: 'customer' },
                { label: 'Vendor', value: 'vendor' },
                { label: 'Staff', value: 'staff' },
              ]}
            />
          </Col>
          <Col xs={24} lg={6}>
            <Text strong>{groupLabel}</Text>
            <Select
              showSearch
              allowClear
              loading={entityLoading}
              optionFilterProp="label"
              placeholder={`Choose all or one ${groupLabel.toLowerCase()}`}
              value={filters.entity_id || undefined}
              onChange={(value) => {
                setReport(null);
                setFilters((current) => ({ ...current, entity_id: value || null }));
              }}
              options={entitySelectOptions}
              style={{ width: '100%' }}
            />
          </Col>
          <Col xs={24} md={5}>
            <Text strong>Date By</Text>
            <Select
              value={filters.date_by}
              onChange={(value) => setFilters((current) => ({ ...current, date_by: value }))}
              options={dateByOptions}
              style={{ width: '100%' }}
            />
          </Col>
          <Col xs={12} md={4}>
            <Text strong>From</Text>
            <Input type="date" value={filters.from_date} onChange={(event) => setFilters((current) => ({ ...current, from_date: event.target.value }))} />
          </Col>
          <Col xs={12} md={4}>
            <Text strong>To</Text>
            <Input type="date" value={filters.to_date} onChange={(event) => setFilters((current) => ({ ...current, to_date: event.target.value }))} />
          </Col>
          <Col xs={24} md={6}>
            <Text strong>Search</Text>
            <Input allowClear value={filters.search} onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))} onPressEnter={fetchReport} />
          </Col>
          <Col xs={24} md={4}>
            <Button block type="primary" icon={<ReloadOutlined />} disabled={!hasEntitySelection} loading={loading} onClick={fetchReport}>Run</Button>
          </Col>
        </Row>
      </div>

      {report?.summary?.by_currency?.length > 0 && (
        <Card title="Profit by currency" style={{ marginBottom: 16 }}>
          <Space size="large" wrap>
            {report.summary.by_currency.map((item) => (
              <Statistic
                key={item.currency_code}
                title={`${item.currency_code} profit`}
                value={item.profit}
                precision={2}
                prefix={item.currency_code}
                valueStyle={{ color: Number(item.profit) < 0 ? themeToken.colorError : themeToken.colorSuccess }}
                suffix={<Text type="secondary"> {margin(item.profit_margin)}</Text>}
              />
            ))}
          </Space>
        </Card>
      )}

      <Card
        title={`Profit by ${groupLabel.toLowerCase()}`}
        style={{ marginBottom: 16 }}
        extra={<Button icon={<DownloadOutlined />} disabled={!report?.data?.length} onClick={exportCsv}>Export CSV</Button>}
      >
        {!hasEntitySelection ? (
          <Empty description={`Choose all or one ${groupLabel.toLowerCase()} to view profit data`} />
        ) : !loading && report?.data?.length === 0 ? (
          <Empty description="No matching profit data" />
        ) : (
          <Table rowKey="key" loading={loading} columns={groupColumns} dataSource={report?.data || []} scroll={{ x: 1010 }} pagination={{ pageSize: 25, showSizeChanger: true }} />
        )}
      </Card>

      <Card title="Order details">
        {!hasEntitySelection ? (
          <Empty description={`Choose all or one ${groupLabel.toLowerCase()} to view order details`} />
        ) : !loading && report?.details?.length === 0 ? (
          <Empty description="No matching orders" />
        ) : (
          <Table rowKey="key" loading={loading} columns={detailColumns} dataSource={report?.details || []} scroll={{ x: 2060 }} pagination={{ pageSize: 25, showSizeChanger: true }} />
        )}
      </Card>
    </div>
  );
}
