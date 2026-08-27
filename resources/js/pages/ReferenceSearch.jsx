import React, { useState } from 'react';
import { Button, Card, Col, DatePicker, Empty, Form, Input, InputNumber, Row, Select, Space, Tabs, Tag, Typography } from 'antd';
import { ClearOutlined, EditOutlined, EyeOutlined, FileSearchOutlined, SearchOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { message } from '../services/feedback';
import { dateOnly } from '../services/dateFormat';
import { openRoute } from '../services/navigation';
import Table from '../components/CsvTable';

const { Title, Paragraph, Text } = Typography;

const field = (name, label, component = <Input allowClear />) => ({ name, label, component });
const folderFields = [
  field('pnr', 'PNR'),
  field('airline_pnr', 'Airline PNR'),
  field('internal_ref', 'Inet Ref'),
  field('order_no', 'Order No'),
  field('lead_passenger', 'Lead Passenger'),
  field('passenger_phone', 'Passenger Phone'),
  field('passenger_email', 'Passenger Email'),
  field('passenger_postcode', 'Passenger Postcode'),
  field('passenger_name', 'Any Pax Name'),
  field('invoice_no', 'Invoice No.'),
  field('ticket_no', 'Ticket No.'),
  field('customer_name', 'Customer Name'),
  field('customer_postcode', 'Customer Postcode'),
  field('your_ref', 'Your Ref'),
  field('destination', 'Destination'),
];

const advancedFields = [
  field('invoice_no', 'Invoice No.'),
  field('customer_name', 'Customer Name'),
  field('passenger_name', 'Passenger Name'),
  field('pnr', 'PNR'),
  field('ticket_no', 'Ticket No.'),
  field('destination', 'Destination'),
  field('booked_by', 'BKD By'),
  field('status', 'Status', <Select allowClear options={[
    'quote', 'order', 'cancel', 'invoice', 'void', 'refund_request', 'refund', 'partial_refund', 'paid', 'partial_paid',
  ].map((value) => ({ value, label: value.replaceAll('_', ' ') }))} />),
  field('pay_status', 'Pay Status', <Select allowClear options={[
    'draft', 'issued', 'sent', 'partial_paid', 'paid', 'overdue', 'void', 'cancel',
  ].map((value) => ({ value, label: value.replaceAll('_', ' ') }))} />),
];

const rangeFields = [
  ['invoice_date_from', 'invoice_date_to', 'Inv. Date'],
  ['departure_date_from', 'departure_date_to', 'Dep. Date'],
  ['creation_date_from', 'creation_date_to', 'Creation Date'],
  ['quote_convert_date_from', 'quote_convert_date_to', 'Quote Convert Date'],
];

const dateToString = (value) => value?.format?.('YYYY-MM-DD') || undefined;
const money = (value, currency) => value === null || value === undefined || value === '' ? '-' : `${currency || ''} ${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim();

export default function ReferenceSearch() {
  const [form] = Form.useForm();
  const [results, setResults] = useState([]);
  const [summary, setSummary] = useState({ total: 0 });
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  const search = async () => {
    const rawValues = form.getFieldsValue();
    const params = new URLSearchParams();

    Object.entries(rawValues).forEach(([key, value]) => {
      const nextValue = dateToString(value) ?? value;
      if (nextValue !== undefined && nextValue !== null && String(nextValue).trim() !== '') {
        params.set(key, String(nextValue).trim());
      }
    });

    setLoading(true);
    try {
      const response = await fetch(`/api/v1/reference-search?${params}`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Search failed');
      setResults(data.data || []);
      setSummary(data.summary || { total: 0 });
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };

  const reset = () => {
    form.resetFields();
    setResults([]);
    setSummary({ total: 0 });
  };

  const renderFields = (fields) => (
    <Row gutter={[12, 8]}>
      {fields.map((item) => (
        <Col xs={24} sm={12} md={8} lg={6} xl={4} key={item.name}>
          <Form.Item name={item.name} label={item.label}>
            {item.component}
          </Form.Item>
        </Col>
      ))}
    </Row>
  );

  const columns = [
    { title: 'Type', dataIndex: 'type', width: 110, render: (value) => <Tag color={value === 'Invoice' ? 'blue' : value === 'Order' ? 'green' : 'default'}>{value}</Tag> },
    { title: 'Reference', dataIndex: 'reference', width: 180, render: (value, row) => <Space direction="vertical" size={0}><Text strong>{value || '-'}</Text><Text type="secondary">{row.secondary_reference || ''}</Text></Space> },
    { title: 'Customer / Party', dataIndex: 'customer', width: 200, render: (value) => value || '-' },
    { title: 'Date', dataIndex: 'date', width: 120, render: dateOnly },
    { title: 'Status', dataIndex: 'status', width: 130, render: (value) => value ? String(value).replaceAll('_', ' ') : '-' },
    { title: 'Amount', dataIndex: 'amount', align: 'right', width: 130, render: (value, row) => money(value, row.currency_code) },
    { title: 'Matched', dataIndex: 'matched', ellipsis: true, render: (value) => value || '-' },
    {
      title: 'Open',
      key: 'open',
      fixed: 'right',
      width: 270,
      render: (_, row) => (
        <Space wrap size={[6, 6]}>
          {row.invoice_uid && (
            <Button size="small" icon={<EyeOutlined />} onClick={(event) => openRoute(navigate, `/invoices/${row.invoice_uid}`, event)}>
              Invoice
            </Button>
          )}
          {row.order_uid && (
            <Button size="small" icon={<FileSearchOutlined />} onClick={(event) => openRoute(navigate, `/orders/${row.order_uid}/voucher`, event)}>
              Voucher
            </Button>
          )}
          {row.order_uid && (
            <Button size="small" icon={<EditOutlined />} onClick={(event) => openRoute(navigate, `/orders/${row.order_uid}/edit`, event)}>
              Edit
            </Button>
          )}
        </Space>
      ),
    },
  ];

  return (
    <div className="page-shell page-fade-up reference-search-page">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2} style={{ margin: 0 }}>Reference Search</Title>
        <Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>
          Search PNRs, invoice numbers, tickets, passengers, customers, amounts, dates, and other references.
        </Paragraph>
      </div>

      <Card className="border-beam-aurora">
        <Form form={form} layout="vertical" size="small" onFinish={search}>
          <Form.Item name="q" label="Search Any Value">
            <Input.Search allowClear enterButton={<><SearchOutlined /> Search</>} placeholder="Search across orders, invoices, customers, vendors, receipts, payments, and voucher data" onSearch={search} />
          </Form.Item>

          <Tabs
            items={[
              { key: 'folder', label: 'Folder Search', children: renderFields(folderFields) },
              {
                key: 'advanced',
                label: 'Advanced',
                children: (
                  <>
                    {renderFields(advancedFields)}
                    <Row gutter={[12, 8]}>
                      {rangeFields.map(([from, to, label]) => (
                        <Col xs={24} md={12} xl={8} key={label}>
                          <Text strong>{label}</Text>
                          <Space.Compact block style={{ marginTop: 6 }}>
                            <Form.Item name={from} noStyle><DatePicker style={{ width: '50%' }} /></Form.Item>
                            <Form.Item name={to} noStyle><DatePicker style={{ width: '50%' }} /></Form.Item>
                          </Space.Compact>
                        </Col>
                      ))}
                      <Col xs={24} md={12} xl={8}>
                        <Text strong>Inv. Amount</Text>
                        <Space.Compact block style={{ marginTop: 6 }}>
                          <Form.Item name="amount_from" noStyle><InputNumber min={0} style={{ width: '50%' }} placeholder="From" /></Form.Item>
                          <Form.Item name="amount_to" noStyle><InputNumber min={0} style={{ width: '50%' }} placeholder="To" /></Form.Item>
                        </Space.Compact>
                      </Col>
                    </Row>
                  </>
                ),
              },
            ]}
          />
          <br />
          <Space wrap>
            <Button type="primary" htmlType="submit" icon={<SearchOutlined />} loading={loading}>Search</Button>
            <Button icon={<ClearOutlined />} onClick={reset}>Clear</Button>
            <Text type="secondary">{summary.total || 0} result{summary.total === 1 ? '' : 's'}{summary.capped ? ' (showing first 150)' : ''}</Text>
          </Space>
        </Form>
      </Card>

      <Card className="border-beam-aurora" style={{ marginTop: 16 }}>
        {!loading && results.length === 0 ? (
          <Empty description="No reference results" />
        ) : (
          <Table
            rowKey="key"
            loading={loading}
            columns={columns}
            dataSource={results}
            scroll={{ x: 1120 }}
            pagination={{ pageSize: 25, showSizeChanger: true }}
          />
        )}
      </Card>
    </div>
  );
}
