import React, { useEffect, useState } from 'react';
import { ReloadOutlined } from '@ant-design/icons';
import { Button, Card, Col, Empty, Input, Row, Space, Statistic, Tag, Typography } from 'antd';
import { message } from '../services/feedback';
import Table from '../components/CsvTable';
import useReportTablePagination from '../hooks/useReportTablePagination';

const { Title, Paragraph, Text } = Typography;

const dateString = (date) => new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const firstOfYear = () => {
  const date = new Date();
  date.setMonth(0, 1);
  return dateString(date);
};
const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const monthLabel = (value) => {
  const [year, month] = String(value || '').split('-').map(Number);
  if (!year || !month) return value || '-';

  return new Date(year, month - 1, 1).toLocaleString(undefined, { month: 'short', year: 'numeric' });
};

export default function PerformanceReport() {
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [filters, setFilters] = useState({
    from_date: firstOfYear(),
    to_date: dateString(new Date()),
  });
  const [tablePagination, resetTablePagination] = useReportTablePagination();
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  const fetchReport = async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams(filters);
      const response = await fetch(`/api/v1/reports/performance?${params}`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Could not load performance report');
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
  }, []);

  const columns = [
    { title: 'Month', dataIndex: 'period', width: 140, render: monthLabel },
    { title: 'Currency', dataIndex: 'currency_code', width: 105, render: (value) => <Tag>{value}</Tag> },
    {
      title: 'Sales',
      dataIndex: 'sales',
      width: 160,
      align: 'right',
      render: (value, row) => <Text strong style={{ color: '#058b49' }}>{row.currency_code} {money(value)}</Text>,
    },
    {
      title: 'Purchase',
      dataIndex: 'purchase',
      width: 160,
      align: 'right',
      render: (value, row) => `${row.currency_code} ${money(value)}`,
    },
    {
      title: 'Profit/Loss',
      dataIndex: 'profit_loss',
      width: 160,
      align: 'right',
      render: (value, row) => <Text strong style={{ color: '#d9a51d' }}>{row.currency_code} {money(value)}</Text>,
    },
  ];

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2}>Performance Report</Title>
        <Paragraph>Monthly sales, purchase, and profit/loss from invoiced orders.</Paragraph>

        <Row gutter={[12, 12]} align="bottom">
          <Col xs={12} md={5}>
            <Text strong>From</Text>
            <Input type="date" value={filters.from_date} onChange={(event) => setFilters((current) => ({ ...current, from_date: event.target.value }))} />
          </Col>
          <Col xs={12} md={5}>
            <Text strong>To</Text>
            <Input type="date" value={filters.to_date} onChange={(event) => setFilters((current) => ({ ...current, to_date: event.target.value }))} />
          </Col>
          <Col xs={24} md={4}>
            <Button block type="primary" icon={<ReloadOutlined />} loading={loading} onClick={fetchReport}>Run</Button>
          </Col>
        </Row>
      </div>

      {report?.summary?.by_currency?.length > 0 && (
        <Card title="Performance by currency" style={{ marginBottom: 16 }}>
          <Space size="large" wrap>
            {report.summary.by_currency.map((item) => (
              <Statistic
                key={item.currency_code}
                title={item.currency_code}
                value={item.profit_loss}
                precision={2}
                prefix="Profit/Loss"
                valueStyle={{ color: '#d9a51d' }}
                suffix={<Text type="secondary"> Sales {money(item.sales)} / Purchase {money(item.purchase)}</Text>}
              />
            ))}
          </Space>
        </Card>
      )}

      <Card title="Monthly performance">
        {!loading && report?.data?.length === 0 ? (
          <Empty description="No matching performance data" />
        ) : (
          <Table
            rowKey="key"
            loading={loading}
            columns={columns}
            dataSource={report?.data || []}
            scroll={{ x: 725 }}
            pagination={tablePagination}
            csvFileName="performance-report.csv"
          />
        )}
      </Card>
    </div>
  );
}
