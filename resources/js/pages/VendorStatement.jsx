import React, { useEffect, useState } from 'react';
import { Button, Card, Col, Descriptions, Drawer, Input, Row, Select, Space, Spin, Statistic, Tag, Typography } from 'antd';
import { ExportOutlined } from '@ant-design/icons';
import { message } from '../services/feedback';
import { dateOnly } from '../services/dateFormat';
import Table from '../components/CsvTable';

const { Title, Paragraph, Text } = Typography;
const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const statementTotals = (rows = []) => ({
  debit: rows.reduce((sum, row) => sum + Number(row.debit || 0), 0),
  credit: rows.reduce((sum, row) => sum + Number(row.credit || 0), 0),
  balance: Number(rows.at(-1)?.balance || 0),
});

export default function VendorStatement() {
  const [vendors, setVendors] = useState([]);
  const [vendorId, setVendorId] = useState(null);
  const [statement, setStatement] = useState(null);
  const [loading, setLoading] = useState(false);
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');
  const [purchaseSummary, setPurchaseSummary] = useState(null);
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

  const openPurchaseSummary = (event, record) => {
    if (!record.purchase_summary) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;

    event.preventDefault();
    setPurchaseSummary(record.purchase_summary);
  };

  const columns = [
    { title: 'Date', dataIndex: 'date', key: 'date', render: dateOnly },
    { title: 'Type', dataIndex: 'type', render: (value) => <Tag color={String(value).includes('payment') ? 'green' : 'orange'}>{String(value).replace(/_/g, ' ').toUpperCase()}</Tag> },
    {
      title: 'Reference',
      dataIndex: 'reference',
      key: 'reference',
      render: (value, record) => record.reference_url
        ? <a href={record.reference_url} target="_blank" rel="noreferrer">{value || '-'}</a>
        : value || '-',
    },
    {
      title: 'Narration',
      dataIndex: 'description',
      key: 'description',
      render: (value, record) => record.purchase_summary
        ? <a href={record.reference_url || '#'} onClick={(event) => openPurchaseSummary(event, record)}>{value || '-'}</a>
        : value || '-',
    },
    { title: 'Debit', dataIndex: 'debit', align: 'right', render: money },
    { title: 'Credit', dataIndex: 'credit', align: 'right', render: money },
    { title: 'Balance', dataIndex: 'balance', align: 'right', render: money },
  ];
  const purchaseColumns = [
    { title: 'Service', dataIndex: 'service', key: 'service', width: 120, render: (value) => <Tag>{value || 'SERVICE'}</Tag> },
    { title: 'Passenger', dataIndex: 'passenger', key: 'passenger', width: 180, render: (value) => value || '-' },
    { title: 'Details', dataIndex: 'details', key: 'details', width: 260, render: (value) => value || '-' },
    { title: 'Amount', dataIndex: 'amount', key: 'amount', align: 'right', width: 140, render: money },
  ];
  const passengerColumns = [
    { title: 'Passenger Name', dataIndex: 'name', key: 'name', width: 200, render: (value) => value || '-' },
    { title: 'Passport', dataIndex: 'passport_no', key: 'passport_no', width: 150, render: (value) => value || '-' },
    { title: 'Ticket', dataIndex: 'ticket_no', key: 'ticket_no', width: 160, render: (value) => value || '-' },
    { title: 'Visa Publisher', dataIndex: 'visa_publisher', key: 'visa_publisher', width: 180, render: (value) => value || '-' },
    { title: 'Visa No', dataIndex: 'visa_no', key: 'visa_no', width: 150, render: (value) => value || '-' },
    { title: 'Notes', dataIndex: 'notes', key: 'notes', width: 220, render: (value) => value || '-' },
  ];
  const totals = statementTotals(statement?.transactions || []);
  const purchaseRows = purchaseSummary?.rows || [];
  const passengerRows = purchaseSummary?.passenger_details || [];
  const purchaseTotal = purchaseRows.reduce((sum, row) => sum + Number(row.amount || 0), 0);
  const summaryStats = statement ? [
    { title: 'Opening', value: Number(statement.summary.opening_balance), money: true },
    { title: 'Payables', value: Number(statement.summary.period_payables), money: true },
    { title: 'Payment', value: Number(statement.summary.period_payments ?? statement.summary.period_paid ?? 0), money: true },
    { title: 'Receipt', value: Number(statement.summary.period_receipts || 0), money: true },
    { title: 'Outstanding', value: Number(statement.summary.outstanding_balance), money: true },
  ] : [];

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
              <Row gutter={[16, 16]} wrap={false} style={{ overflowX: 'auto' }}>
                {summaryStats.map((item) => (
                  <Col key={item.title} flex="1 0 160px">
                    <Statistic title={item.title} value={item.value} precision={item.money ? 2 : 0} prefix={item.money ? statement.vendor_currency : undefined} />
                  </Col>
                ))}
              </Row>
            </Card>
            <Card className="border-beam-aurora" title="Vendor Activity">
              <Table
                scroll={{ x: 'max-content' }}
                columns={columns}
                dataSource={statement.transactions}
                rowKey="id"
                summary={() => (
                  <Table.Summary.Row>
                    <Table.Summary.Cell index={0} colSpan={4}><Text strong>Total</Text></Table.Summary.Cell>
                    <Table.Summary.Cell index={4} align="right"><Text strong>{money(totals.debit)}</Text></Table.Summary.Cell>
                    <Table.Summary.Cell index={5} align="right"><Text strong>{money(totals.credit)}</Text></Table.Summary.Cell>
                    <Table.Summary.Cell index={6} align="right"><Text strong>{money(totals.balance)}</Text></Table.Summary.Cell>
                  </Table.Summary.Row>
                )}
              />
            </Card>
            <Drawer
              title="Purchase Summary"
              open={Boolean(purchaseSummary)}
              onClose={() => setPurchaseSummary(null)}
              width={860}
              extra={purchaseSummary?.order_uid && (
                <Button
                  icon={<ExportOutlined />}
                  href={`/orders/${purchaseSummary.order_uid}/edit`}
                  target="_blank"
                  rel="noreferrer"
                >
                  Open Order
                </Button>
              )}
            >
              {purchaseSummary && (
                <Space direction="vertical" size="middle" style={{ width: '100%' }}>
                  <Descriptions bordered column={1} size="small">
                    <Descriptions.Item label="Order #">{purchaseSummary.order_number || '-'}</Descriptions.Item>
                    <Descriptions.Item label="Booking Ref">{purchaseSummary.booking_reference || '-'}</Descriptions.Item>
                    <Descriptions.Item label="Customer">{purchaseSummary.customer_name || '-'}</Descriptions.Item>
                    <Descriptions.Item label="Passengers">{purchaseSummary.passenger_count || 0}</Descriptions.Item>
                    <Descriptions.Item label="Services">{(purchaseSummary.services || []).join(', ') || '-'}</Descriptions.Item>
                    <Descriptions.Item label="Purchase Total">
                      {purchaseSummary.currency_code ? `${purchaseSummary.currency_code} ` : ''}{money(purchaseSummary.total_purchase_amount ?? purchaseTotal)}
                    </Descriptions.Item>
                  </Descriptions>
                  <Text strong>Passenger Details</Text>
                  <Table
                    size="small"
                    columns={passengerColumns}
                    dataSource={passengerRows}
                    rowKey="key"
                    pagination={false}
                    scroll={{ x: 1060 }}
                  />
                  <Text strong>Purchase Details</Text>
                  <Table
                    size="small"
                    columns={purchaseColumns}
                    dataSource={purchaseRows}
                    rowKey="key"
                    pagination={false}
                    scroll={{ x: 700 }}
                    summary={() => (
                      <Table.Summary.Row>
                        <Table.Summary.Cell index={0} colSpan={3}><Text strong>Total</Text></Table.Summary.Cell>
                        <Table.Summary.Cell index={3} align="right"><Text strong>{money(purchaseTotal)}</Text></Table.Summary.Cell>
                      </Table.Summary.Row>
                    )}
                  />
                </Space>
              )}
            </Drawer>
          </>
        )}
      </Spin>
    </div>
  );
}
