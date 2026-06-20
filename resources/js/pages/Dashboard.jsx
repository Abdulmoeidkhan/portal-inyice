import React from 'react';
import { Layout, Card, Row, Col, Statistic, Button } from 'antd';
import { FileTextOutlined, DollarOutlined, UserOutlined, BarChartOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';

export default function Dashboard() {
  const navigate = useNavigate();

  const stats = [
    {
      title: 'Total Invoices',
      value: 0,
      icon: <FileTextOutlined />,
      color: '#1890ff',
    },
    {
      title: 'Pending Payments',
      value: 0,
      icon: <DollarOutlined />,
      color: '#faad14',
    },
    {
      title: 'Customers',
      value: 0,
      icon: <UserOutlined />,
      color: '#52c41a',
    },
    {
      title: 'This Month Revenue',
      value: 0,
      icon: <BarChartOutlined />,
      color: '#722ed1',
    },
  ];

  return (
    <div className="page-shell page-fade-up">
      <Layout.Content>
        <div className="elevated-card border-beam-aurora" style={{ marginBottom: 18 }}>
          <h1 style={{ margin: 0 }}>Dashboard</h1>
          <p style={{ margin: '8px 0 0', color: 'var(--app-muted)' }}>
            Monitor cash flow and act quickly on invoices, collections, and reporting.
          </p>
        </div>

        <Row gutter={[16, 16]} style={{ marginBottom: 20 }}>
          {stats.map((stat, index) => (
            <Col xs={24} sm={12} lg={6} key={index}>
              <Card hoverable className={`border-beam-aurora stagger-${(index % 3) + 1}`}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                  <div>
                    <p style={{ color: 'var(--app-muted)', marginBottom: '10px' }}>{stat.title}</p>
                    <h2 style={{ margin: 0, fontSize: '28px', fontWeight: 'bold' }}>{stat.value}</h2>
                  </div>
                  <div style={{ fontSize: '40px', color: stat.color, opacity: 0.3 }}>{stat.icon}</div>
                </div>
              </Card>
            </Col>
          ))}
        </Row>

        <Row gutter={[16, 16]}>
          <Col xs={24} lg={12}>
            <Card title="Quick Actions" style={{ height: '100%' }} className="border-beam-aurora stagger-2">
              <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
                <Button type="primary" block onClick={() => navigate('/invoices')}>
                  Create Invoice
                </Button>
                <Button block onClick={() => navigate('/reports/aging')}>
                  View Aging Report
                </Button>
                <Button block onClick={() => navigate('/reports/revenue')}>
                  Revenue Analysis
                </Button>
              </div>
            </Card>
          </Col>

          <Col xs={24} lg={12}>
            <Card title="Welcome to inYice Lite" style={{ height: '100%' }} className="border-beam-aurora stagger-3">
              <div>
                <p>Your all-in-one order and invoicing management platform for travel agencies.</p>
                <ul>
                  <li>📄 Smart invoicing from orders</li>
                  <li>💰 Multi-currency receipt and payment tracking</li>
                  <li>📊 Advanced financial reporting</li>
                  <li>👥 Customer & vendor management</li>
                  <li>🔐 Secure multi-tenant architecture</li>
                </ul>
              </div>
            </Card>
          </Col>
        </Row>
      </Layout.Content>
    </div>
  );
}
