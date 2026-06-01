import React, { useState } from 'react';
import { Card, Row, Col, Table, DatePicker, Button, Select, Spin, message, Statistic, Skeleton, Empty } from 'antd';
import { DollarOutlined, CheckCircleOutlined, AlertOutlined, FileTextOutlined } from '@ant-design/icons';
import { Column, Line } from '@ant-design/plots';

export default function RevenueReport() {
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [fromDate, setFromDate] = useState(null);
  const [toDate, setToDate] = useState(null);
  const [groupBy, setGroupBy] = useState('month');
  const [companyId, setCompanyId] = useState(null);
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  const fetchReport = async () => {
    if (!fromDate || !toDate || !companyId) {
      message.error('Please select date range and company');
      return;
    }

    setLoading(true);
    try {
      const params = new URLSearchParams({
        from_date: fromDate,
        to_date: toDate,
        group_by: groupBy,
        company_id: companyId,
      });

      const response = await fetch(`/api/v1/reports/revenue?${params}`, {
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
        },
      });

      if (!response.ok) throw new Error('Failed to fetch revenue report');
      const data = await response.json();
      setReport(data);
    } catch (error) {
      message.error('Failed to load revenue report: ' + error.message);
    } finally {
      setLoading(false);
    }
  };

  const columns = [
    {
      title: 'Period',
      dataIndex: 'period',
      key: 'period',
    },
    {
      title: 'Total Revenue',
      dataIndex: 'total_revenue',
      render: (value) => value.toFixed(2),
      align: 'right',
    },
    {
      title: 'Collected',
      dataIndex: 'total_paid',
      render: (value) => value.toFixed(2),
      align: 'right',
    },
    {
      title: 'Outstanding',
      dataIndex: 'total_outstanding',
      render: (value) => value.toFixed(2),
      align: 'right',
    },
    {
      title: 'Invoices',
      dataIndex: 'invoice_count',
      align: 'center',
    },
    {
      title: 'Avg Invoice',
      dataIndex: 'average_invoice_value',
      render: (value) => value.toFixed(2),
      align: 'right',
    },
  ];

  const revenueChartData = report
    ? report.data.map((row) => ({
        period: row.period,
        revenue: Number(row.total_revenue || 0),
      }))
    : [];

  const collectionLineData = report
    ? report.data.flatMap((row) => [
        { period: row.period, metric: 'Collected', value: Number(row.total_paid || 0) },
        { period: row.period, metric: 'Outstanding', value: Number(row.total_outstanding || 0) },
      ])
    : [];

  const columnConfig = {
    data: revenueChartData,
    autoFit: true,
    xField: 'period',
    yField: 'revenue',
    height: 280,
    color: '#3b82f6',
    label: {
      position: 'top',
      text: (d) => d.revenue.toFixed(0),
    },
  };

  const lineConfig = {
    data: collectionLineData,
    autoFit: true,
    xField: 'period',
    yField: 'value',
    colorField: 'metric',
    height: 280,
    point: true,
  };

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
      <h1>Revenue Report</h1>

      <Card className="border-beam-aurora" style={{ marginBottom: '20px' }}>
        <Row gutter={16} align="middle">
          <Col xs={24} sm={12} lg={4}>
            <DatePicker
              placeholder="From Date"
              onChange={(date) => setFromDate(date?.format('YYYY-MM-DD'))}
              style={{ width: '100%' }}
            />
          </Col>
          <Col xs={24} sm={12} lg={4}>
            <DatePicker
              placeholder="To Date"
              onChange={(date) => setToDate(date?.format('YYYY-MM-DD'))}
              style={{ width: '100%' }}
            />
          </Col>
          <Col xs={24} sm={12} lg={4}>
            <Select
              placeholder="Group By"
              value={groupBy}
              onChange={setGroupBy}
              options={[
                { label: 'Day', value: 'day' },
                { label: 'Week', value: 'week' },
                { label: 'Month', value: 'month' },
                { label: 'Year', value: 'year' },
              ]}
              style={{ width: '100%' }}
            />
          </Col>
          <Col xs={24} sm={12} lg={4}>
            <Select
              placeholder="Company"
              onChange={setCompanyId}
              style={{ width: '100%' }}
              // TODO: Load companies from API
            />
          </Col>
          <Col xs={24} sm={12} lg={8}>
            <Button type="primary" onClick={fetchReport} block loading={loading}>
              Generate Report
            </Button>
          </Col>
        </Row>
      </Card>
      </div>

      {loading && (
        <Card className="border-beam-aurora" style={{ marginBottom: 16 }}>
          <Skeleton active paragraph={{ rows: 8 }} />
        </Card>
      )}

      {!loading && !report && (
        <Card className="border-beam-aurora">
          <Empty description="Select filters and generate report" />
        </Card>
      )}

      {!loading && report && (
        <>
          <Row gutter={16} style={{ marginBottom: '20px' }}>
            <Col xs={24} sm={12} lg={6}>
              <Card className="border-beam-aurora">
                <div style={{ textAlign: 'center' }}>
                  <DollarOutlined style={{ fontSize: '24px' }} />
                  <h3>Total Revenue</h3>
                  <p style={{ fontSize: '20px', fontWeight: 'bold' }}>
                    {report.summary.total_revenue.toFixed(2)}
                  </p>
                </div>
              </Card>
            </Col>
            <Col xs={24} sm={12} lg={6}>
              <Card className="border-beam-aurora">
                <div style={{ textAlign: 'center' }}>
                  <CheckCircleOutlined style={{ fontSize: '24px', color: '#52c41a' }} />
                  <h3>Collected</h3>
                  <p style={{ fontSize: '20px', fontWeight: 'bold', color: '#52c41a' }}>
                    {report.summary.total_collected.toFixed(2)}
                  </p>
                </div>
              </Card>
            </Col>
            <Col xs={24} sm={12} lg={6}>
              <Card className="border-beam-aurora">
                <div style={{ textAlign: 'center' }}>
                  <AlertOutlined style={{ fontSize: '24px', color: '#faad14' }} />
                  <h3>Outstanding</h3>
                  <p style={{ fontSize: '20px', fontWeight: 'bold', color: '#faad14' }}>
                    {report.summary.total_outstanding.toFixed(2)}
                  </p>
                </div>
              </Card>
            </Col>
            <Col xs={24} sm={12} lg={6}>
              <Card className="border-beam-aurora">
                <div style={{ textAlign: 'center' }}>
                  <FileTextOutlined style={{ fontSize: '24px' }} />
                  <h3>Invoices</h3>
                  <p style={{ fontSize: '20px', fontWeight: 'bold' }}>
                    {report.summary.total_invoices}
                  </p>
                </div>
              </Card>
            </Col>
          </Row>

          <Row gutter={[16, 16]} style={{ marginBottom: 20 }}>
            <Col xs={24} xl={12}>
              <Card className="border-beam-aurora" title="Revenue Trend">
                <Column {...columnConfig} />
              </Card>
            </Col>
            <Col xs={24} xl={12}>
              <Card className="border-beam-aurora" title="Collections vs Outstanding">
                <Line {...lineConfig} />
              </Card>
            </Col>
          </Row>

          <Card className="border-beam-aurora" title="Revenue by Period">
            <Spin spinning={loading}>
              <Table
                columns={columns}
                dataSource={report.data}
                rowKey="period"
                pagination={false}
              />
            </Spin>
          </Card>
        </>
      )}
    </div>
  );
}
