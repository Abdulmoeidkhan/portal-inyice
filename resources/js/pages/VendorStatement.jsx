import React, { useEffect, useState } from 'react';
import { Button, Card, Col, Input, Row, Select, Space, Spin, Statistic, Table, Tag, Typography } from 'antd';
import { PrinterOutlined } from '@ant-design/icons';
import { message } from '../services/feedback';

const { Title, Paragraph } = Typography;

export default function VendorStatement() {
  const [vendors, setVendors] = useState([]);
  const [vendorId, setVendorId] = useState(null);
  const [statement, setStatement] = useState(null);
  const [loading, setLoading] = useState(false);
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  useEffect(() => {
    fetch('/api/v1/vendors', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } })
      .then((response) => response.ok ? response.json() : Promise.reject(new Error('Could not load vendors')))
      .then(setVendors)
      .catch((error) => message.error(error.message));
  }, []);

  const fetchStatement = async () => {
    if (!vendorId) {
      message.error('Select a vendor');
      return;
    }
    setLoading(true);
    try {
      const params = new URLSearchParams();
      if (fromDate) params.set('from_date', fromDate);
      if (toDate) params.set('to_date', toDate);
      const response = await fetch(`/api/v1/statements/vendor/${vendorId}?${params}`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Could not load vendor statement');
      setStatement(data);
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };

  const columns = [
    { title: 'Date', dataIndex: 'date', key: 'date' },
    { title: 'Type', dataIndex: 'type', render: (value) => <Tag color={value === 'payment' ? 'green' : 'orange'}>{value.toUpperCase()}</Tag> },
    { title: 'Reference', dataIndex: 'reference', key: 'reference' },
    { title: 'Narration', dataIndex: 'description', key: 'description' },
    { title: 'Payable', dataIndex: 'debit', align: 'right', render: (value) => Number(value || 0).toFixed(2) },
    { title: 'Paid', dataIndex: 'credit', align: 'right', render: (value) => Number(value || 0).toFixed(2) },
    { title: 'Balance', dataIndex: 'balance', align: 'right', render: (value) => Number(value || 0).toFixed(2) },
  ];

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2} style={{ margin: 0 }}>Vendor Statement</Title>
        <Paragraph type="secondary" style={{ margin: '8px 0 0' }}>Review vendor payables, payments, and the running balance.</Paragraph>
      </div>
      <Card className="border-beam-aurora" style={{ marginBottom: 16 }}>
        <Space wrap className="responsive-toolbar">
          <Select showSearch optionFilterProp="label" placeholder="Select vendor" value={vendorId} onChange={(value) => { setVendorId(value); setStatement(null); }} options={vendors.map((vendor) => ({ value: vendor.id, label: vendor.name }))} style={{ width: 300 }} className="responsive-control" />
          <Input type="date" value={fromDate} onChange={(event) => setFromDate(event.target.value)} style={{ width: 165 }} />
          <Input type="date" value={toDate} onChange={(event) => setToDate(event.target.value)} style={{ width: 165 }} />
          <Button type="primary" onClick={fetchStatement} loading={loading}>Generate Statement</Button>
        </Space>
      </Card>
      <Spin spinning={loading}>
        {statement && (
          <>
            <Card className="border-beam-aurora" style={{ marginBottom: 16 }}>
              <Title level={4} style={{ marginTop: 0 }}>{statement.vendor.name}</Title>
              <Paragraph type="secondary">{statement.vendor.email || '-'} · {statement.vendor.phone || '-'}</Paragraph>
              <Row gutter={[16, 16]}>
                <Col xs={12} lg={6}><Statistic title="Opening" value={Number(statement.summary.opening_balance)} precision={2} prefix={statement.vendor_currency} /></Col>
                <Col xs={12} lg={6}><Statistic title="Period Payables" value={Number(statement.summary.period_payables)} precision={2} prefix={statement.vendor_currency} /></Col>
                <Col xs={12} lg={6}><Statistic title="Period Paid" value={Number(statement.summary.period_paid)} precision={2} prefix={statement.vendor_currency} /></Col>
                <Col xs={12} lg={6}><Statistic title="Outstanding" value={Number(statement.summary.outstanding_balance)} precision={2} prefix={statement.vendor_currency} /></Col>
              </Row>
            </Card>
            <Card className="border-beam-aurora" title="Vendor Activity" extra={<Button icon={<PrinterOutlined />} onClick={() => window.print()}>Print</Button>}>
              <Table scroll={{ x: 'max-content' }} columns={columns} dataSource={statement.transactions} rowKey="id" pagination={false} />
            </Card>
          </>
        )}
      </Spin>
    </div>
  );
}
