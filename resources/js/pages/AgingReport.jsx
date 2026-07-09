import React, { useState, useEffect } from 'react';
import { Card, Row, Col, Table, Statistic, Skeleton, Empty, Button } from 'antd';
import { message } from '../services/feedback';
import { DollarOutlined, AlertOutlined } from '@ant-design/icons';
import { Column } from '@ant-design/plots';

const money = (value) => Number(value || 0).toFixed(2);
const wholeNumber = (value) => Math.round(Number(value || 0));
const bucketAmount = (report, key) => Number(report?.buckets?.[key]?.total_outstanding || 0);

export default function AgingReport() {
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  useEffect(() => {
    fetchAgingReport();
  }, []);

  const fetchAgingReport = async () => {
    setLoading(true);
    try {
      const response = await fetch('/api/v1/reports/aging', {
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
        },
      });

      if (!response.ok) throw new Error('Failed to fetch aging report');
      const data = await response.json();
      setReport(data);
    } catch (error) {
      message.error('Failed to load aging report: ' + error.message);
    } finally {
      setLoading(false);
    }
  };

  const getBucketColumns = () => [
    {
      title: 'Invoice',
      dataIndex: 'invoice_number',
      key: 'invoice_number',
    },
    {
      title: 'Customer',
      dataIndex: 'customer_name',
      key: 'customer_name',
    },
    {
      title: 'Amount',
      dataIndex: 'amount',
      render: money,
      align: 'right',
    },
    {
      title: 'Outstanding',
      dataIndex: 'outstanding',
      render: money,
      align: 'right',
    },
    {
      title: 'Days Overdue',
      dataIndex: 'days_overdue',
      render: wholeNumber,
      align: 'center',
    },
  ];

  const chartData = report
    ? [
        { bucket: 'Current', amount: bucketAmount(report, 'current') },
        { bucket: '1-30', amount: bucketAmount(report, 'days_1_30') },
        { bucket: '31-60', amount: bucketAmount(report, 'days_31_60') },
        { bucket: '61-90', amount: bucketAmount(report, 'days_61_90') },
        { bucket: '90+', amount: bucketAmount(report, 'days_over_90') },
      ]
    : [];

  const chartConfig = {
    data: chartData,
    autoFit: true,
    xField: 'bucket',
    yField: 'amount',
    colorField: 'bucket',
    height: 280,
    legend: false,
    label: {
      position: 'top',
      text: (d) => d.amount.toFixed(0),
    },
    axis: {
      y: {
        labelFormatter: (v) => Number(v).toLocaleString(),
      },
    },
  };

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 18 }}>
      <h1>Invoice Aging Report</h1>

      <div style={{ marginBottom: '20px' }}>
        <Button onClick={fetchAgingReport} loading={loading}>Refresh</Button>
      </div>
      </div>

      {loading && (
        <Card className="border-beam-aurora" style={{ marginBottom: 16 }}>
          <Skeleton active paragraph={{ rows: 8 }} />
        </Card>
      )}

      {!loading && !report && (
        <Card className="border-beam-aurora">
          <Empty description="No aging data found" />
        </Card>
      )}

      {!loading && report && (
      <>
      <Row gutter={16} style={{ marginBottom: '20px' }}>
        <Col xs={24} sm={12} lg={6}>
          <Card className="border-beam-aurora">
            <Statistic
              title="Not Yet Due"
              value={bucketAmount(report, 'current')}
              prefix={<DollarOutlined />}
            />
          </Card>
        </Col>
        <Col xs={24} sm={12} lg={6}>
          <Card className="border-beam-aurora">
            <Statistic
              title="1-30 Days"
              value={bucketAmount(report, 'days_1_30')}
              prefix={<DollarOutlined />}
              valueStyle={{ color: '#faad14' }}
            />
          </Card>
        </Col>
        <Col xs={24} sm={12} lg={6}>
          <Card className="border-beam-aurora">
            <Statistic
              title="31-60 Days"
              value={bucketAmount(report, 'days_31_60')}
              prefix={<DollarOutlined />}
              valueStyle={{ color: '#ff7a45' }}
            />
          </Card>
        </Col>
        <Col xs={24} sm={12} lg={6}>
          <Card className="border-beam-aurora">
            <Statistic
              title="Over 90 Days"
              value={bucketAmount(report, 'days_over_90')}
              prefix={<AlertOutlined />}
              valueStyle={{ color: '#ff4d4f' }}
            />
          </Card>
        </Col>
      </Row>

      <Card className="border-beam-aurora" title="Aging Distribution" style={{ marginBottom: 20 }}>
        <Column {...chartConfig} />
      </Card>

      {Object.entries(report.buckets || {}).map(([key, bucket]) => (
        <Card key={key} className="border-beam-aurora" title={`${bucket.description} (${bucket.count})`} style={{ marginBottom: '20px' }}>
          <Table
            scroll={{ x: 'max-content' }}
            columns={getBucketColumns()}
            dataSource={bucket.invoices || []}
            rowKey="invoice_uid"
            pagination={false}
          />
        </Card>
      ))}
      </>
      )}
    </div>
  );
}
