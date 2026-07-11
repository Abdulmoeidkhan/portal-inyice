import React, { useState } from 'react';
import { Card, Row, Col, Table, DatePicker, Button, Select, Spin, Skeleton, Empty, Grid, theme } from 'antd';
import { message } from '../services/feedback';
import { DollarOutlined, CheckCircleOutlined, AlertOutlined, FileTextOutlined } from '@ant-design/icons';
import { Column, Line } from '@ant-design/plots';

export default function RevenueReport() {
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [fromDate, setFromDate] = useState(null);
  const [toDate, setToDate] = useState(null);
  const [groupBy, setGroupBy] = useState('month');
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
  const screens = Grid.useBreakpoint();
  const { token: themeToken } = theme.useToken();
  const chartHeight = screens.md ? 300 : 240;
  const chartTextColor = themeToken.colorText;
  const chartMutedColor = themeToken.colorTextSecondary;
  const chartGridColor = themeToken.colorSplit;

  const fetchReport = async () => {
    if (!fromDate || !toDate) {
      message.error('Please select date range');
      return;
    }

    setLoading(true);
    try {
      const params = new URLSearchParams({
        from_date: fromDate,
        to_date: toDate,
        group_by: groupBy,
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

  const chartAxis = {
    x: {
      title: false,
      labelFill: chartMutedColor,
      labelFontSize: screens.md ? 12 : 11,
      labelAutoHide: true,
      labelAutoRotate: false,
      lineStroke: chartGridColor,
      tickStroke: chartGridColor,
    },
    y: {
      title: false,
      labelFill: chartMutedColor,
      labelFontSize: screens.md ? 12 : 11,
      grid: true,
      gridStroke: chartGridColor,
      gridLineDash: [4, 4],
    },
  };

  const chartTheme = {
    view: {
      viewFill: 'transparent',
      plotFill: 'transparent',
    },
  };

  const legendConfig = {
    color: {
      itemLabelFill: chartMutedColor,
      itemLabelFontSize: screens.md ? 12 : 11,
    },
  };

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
    height: chartHeight,
    color: themeToken.colorPrimary,
    theme: chartTheme,
    axis: chartAxis,
    padding: screens.md ? 'auto' : [20, 16, 44, 44],
    label: {
      position: 'top',
      text: (d) => d.revenue.toFixed(0),
      fill: chartTextColor,
      fontSize: screens.md ? 12 : 11,
      fontWeight: 600,
    },
    tooltip: {
      title: (d) => d.period,
    },
  };

  const lineConfig = {
    data: collectionLineData,
    autoFit: true,
    xField: 'period',
    yField: 'value',
    colorField: 'metric',
    height: chartHeight,
    theme: chartTheme,
    axis: chartAxis,
    legend: legendConfig,
    padding: screens.md ? 'auto' : [20, 16, 44, 44],
    scale: {
      color: {
        range: [themeToken.colorSuccess, themeToken.colorWarning],
      },
    },
    point: true,
  };

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
      <h1>Revenue Report</h1>

      <Card className="border-beam-aurora" style={{ marginBottom: '20px' }}>
        <Row gutter={[16, 16]} align="middle">
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
          <Row className="revenue-summary-grid" gutter={[16, 16]} style={{ marginBottom: '20px' }}>
            <Col xs={24} sm={12} lg={6}>
              <Card className="revenue-summary-card border-beam-aurora">
                <div className="revenue-summary-content">
                  <DollarOutlined style={{ fontSize: '24px' }} />
                  <h3>Total Revenue</h3>
                  <p>
                    {report.summary.total_revenue.toFixed(2)}
                  </p>
                </div>
              </Card>
            </Col>
            <Col xs={24} sm={12} lg={6}>
              <Card className="revenue-summary-card border-beam-aurora">
                <div className="revenue-summary-content">
                  <CheckCircleOutlined style={{ fontSize: '24px', color: themeToken.colorSuccess }} />
                  <h3>Collected</h3>
                  <p style={{ color: themeToken.colorSuccess }}>
                    {report.summary.total_collected.toFixed(2)}
                  </p>
                </div>
              </Card>
            </Col>
            <Col xs={24} sm={12} lg={6}>
              <Card className="revenue-summary-card border-beam-aurora">
                <div className="revenue-summary-content">
                  <AlertOutlined style={{ fontSize: '24px', color: themeToken.colorWarning }} />
                  <h3>Outstanding</h3>
                  <p style={{ color: themeToken.colorWarning }}>
                    {report.summary.total_outstanding.toFixed(2)}
                  </p>
                </div>
              </Card>
            </Col>
            <Col xs={24} sm={12} lg={6}>
              <Card className="revenue-summary-card border-beam-aurora">
                <div className="revenue-summary-content">
                  <FileTextOutlined style={{ fontSize: '24px' }} />
                  <h3>Invoices</h3>
                  <p>
                    {report.summary.total_invoices}
                  </p>
                </div>
              </Card>
            </Col>
          </Row>

          <Row gutter={[16, 16]} style={{ marginBottom: 20 }}>
            <Col xs={24} xl={12}>
              <Card className="report-chart-card revenue-chart-card border-beam-aurora" title="Revenue Trend">
                <Column {...columnConfig} />
              </Card>
            </Col>
            <Col xs={24} xl={12}>
              <Card className="report-chart-card revenue-chart-card border-beam-aurora" title="Collections vs Outstanding">
                <Line {...lineConfig} />
              </Card>
            </Col>
          </Row>

          <Card className="border-beam-aurora" title="Revenue by Period">
            <Spin spinning={loading}>
              <Table
                scroll={{ x: 'max-content' }}
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
