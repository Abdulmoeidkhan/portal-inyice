import React, { useEffect, useState } from 'react';
import { ArrowLeftOutlined, DownloadOutlined, PrinterOutlined, ShareAltOutlined } from '@ant-design/icons';
import { Button, Card, Col, Descriptions, Divider, Empty, Row, Skeleton, Space, Table, Tag, Typography } from 'antd';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { message } from '../services/feedback';

const { Title, Text, Paragraph } = Typography;
const money = (value) => Number(value || 0).toFixed(2);
const firstFilled = (...values) => values.find((value) => value !== null && value !== undefined && String(value).trim() !== '') || '';
const toArray = (value) => (Array.isArray(value) ? value : []);
const toNumber = (value) => {
  const parsed = Number(value || 0);
  return Number.isFinite(parsed) ? parsed : 0;
};
const uniqueNames = (values) => {
  const seen = new Set();

  return values
    .map((value) => String(value || '').trim())
    .filter(Boolean)
    .filter((value) => {
      const key = value.toUpperCase();
      if (seen.has(key)) {
        return false;
      }

      seen.add(key);
      return true;
    });
};
const hasRowValue = (row, keys) => keys.some((key) => firstFilled(row?.[key]));
const serviceDefinitions = [
  { key: 'flights', label: 'Flights', metaKey: 'pricing', fields: ['pax_name', 'flight_ticket_no', 'flight_sales'] },
  { key: 'hotels', label: 'Hotels', metaKey: 'hotels', fields: ['hcn', 'city', 'hotel_name', 'room_type', 'check_in', 'check_out', 'sales', 'amount'] },
  { key: 'transfers', label: 'Transfers', metaKey: 'transfers', fields: ['tn', 'service', 'from_city', 'to_city', 'vehicle', 'sales', 'amount'] },
  { key: 'city_tours', label: 'City Tours/Ziarat', metaKey: 'city_tours', fields: ['city', 'title', 'attractions', 'date', 'sales', 'amount'] },
  { key: 'visa', label: 'Visa', metaKey: 'visa', fields: ['passenger_name', 'visa_type', 'validity', 'visa_no', 'visa_publisher', 'sales', 'amount'] },
  { key: 'other_services', label: 'Other Services', metaKey: 'other_services', fields: ['description', 'sales', 'amount'] },
];

const classifyLine = (line) => {
  const description = String(line?.description || '').trim();

  if (/^(Flight|Flight Fare|Package Total)\b/i.test(description)) return 'flights';
  if (/^Hotel\b/i.test(description)) return 'hotels';
  if (/^Transfer\b/i.test(description)) return 'transfers';
  if (/^City Tour\b/i.test(description)) return 'city_tours';
  if (/^Visa\b/i.test(description)) return 'visa';

  return 'other_services';
};

const serviceNameForLine = (line) => ({
  flights: 'Flights',
  hotels: 'Hotels',
  transfers: 'Transfers',
  city_tours: 'City Tours/Ziarat',
  visa: 'Visa',
  other_services: String(line?.description || '').replace(/\s+Vendor:\s+.+$/i, '').trim() || 'Other Services',
}[classifyLine(line)]);

const breakupForLine = (line) => {
  const description = String(line?.description || '').trim();

  if (/^Flight Fare\b/i.test(description)) return 'Flight Fare';
  if (/^Package Total\b/i.test(description)) return 'Package Total';
  if (/^Flight\b/i.test(description)) return 'Flight';
  if (/^Hotel\b/i.test(description)) return 'Hotel';
  if (/^Transfer\b/i.test(description)) return 'Transfer';
  if (/^City Tour\b/i.test(description)) return 'City Tour';
  if (/^Visa\b/i.test(description)) return 'Visa';

  return 'Service';
};

const buildDetailedLineRows = (invoice) => toArray(invoice.lines).map((line, index) => ({
  ...line,
  id: line.id || `line-${index}`,
  service_name: serviceNameForLine(line),
  breakup: breakupForLine(line),
}));

const buildPassengerRows = (invoice) => {
  const meta = invoice.order?.meta || {};
  const names = uniqueNames([
    ...toArray(meta.passengers).map((row) => row.name),
    ...toArray(meta.pricing).map((row) => row.pax_name),
    ...toArray(meta.visa).map((row) => row.passenger_name),
    ...toArray(invoice.lines).map((line) => {
      const match = String(line.description || '').match(/(?:Flight Fare|Package Total)\s+-\s+(.+)$/i);
      return match?.[1];
    }),
  ]);

  return names.map((name, index) => ({ id: `passenger-${index}`, name }));
};

const buildServiceRows = (invoice) => {
  const meta = invoice.order?.meta || {};
  const lines = toArray(invoice.lines);

  return serviceDefinitions
    .map((service) => {
      const serviceLines = lines.filter((line) => classifyLine(line) === service.key);
      const serviceTotal = serviceLines.reduce((sum, line) => sum + toNumber(line.total_price), 0);
      const metaRows = toArray(meta[service.metaKey]).filter((row) => hasRowValue(row, service.fields));
      const pricedLines = serviceLines.filter((line) => toNumber(line.total_price) > 0);
      const quantity = metaRows.length || pricedLines.length || serviceLines.length;

      if (!quantity && serviceTotal <= 0) {
        return null;
      }

      return {
        key: service.key,
        service: service.label,
        quantity: quantity || 1,
        unit_price: serviceTotal / (quantity || 1),
        total_price: serviceTotal,
      };
    })
    .filter(Boolean);
};

export default function InvoiceDetail({ shared = false }) {
  const { uid, token: shareToken } = useParams();
  const navigate = useNavigate();
  const location = useLocation();
  const [invoice, setInvoice] = useState(null);
  const [loading, setLoading] = useState(true);
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
  const detailed = new URLSearchParams(location.search).get('view') === 'detailed';

  useEffect(() => {
    const endpoint = shared ? `/api/v1/shared-invoices/${shareToken}` : `/api/v1/invoices/${uid}`;
    fetch(endpoint, { headers: shared ? { Accept: 'application/json' } : { Authorization: `Bearer ${token}`, Accept: 'application/json' } })
      .then(async (response) => {
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Invoice is unavailable');
        setInvoice(data);
      })
      .catch((error) => message.error(error.message))
      .finally(() => setLoading(false));
  }, [shared, shareToken, uid]);

  const share = async () => {
    const response = await fetch(`/api/v1/invoices/${uid}/share`, { method: 'POST', headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
    const data = await response.json();
    if (!response.ok) return message.error(data.message || 'Could not create share link');
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(data.share_url);
      message.success('Shareable invoice link copied');
    } else window.prompt('Copy this shareable invoice link:', data.share_url);
  };

  if (loading) return <div className="page-shell"><Card><Skeleton active /></Card></div>;
  if (!invoice) return <div className="page-shell"><Empty description="Invoice not found" /></div>;

  const passengerRows = buildPassengerRows(invoice);
  const serviceRows = buildServiceRows(invoice);
  const detailedRows = buildDetailedLineRows(invoice);

  return (
    <div className="page-shell page-fade-up invoice-document">
      <Space className="invoice-screen-actions" style={{ marginBottom: 16 }}>
        {!shared && <Button icon={<ArrowLeftOutlined />} onClick={() => navigate('/invoices')}>Back</Button>}
        <Button icon={<PrinterOutlined />} onClick={() => window.print()}>Print</Button>
        <Button icon={<DownloadOutlined />} onClick={() => window.print()}>Download PDF</Button>
        {!shared && <Button type="primary" icon={<ShareAltOutlined />} onClick={share}>Copy share link</Button>}
      </Space>
      <Card className="border-beam-aurora">
        <Row justify="space-between" gutter={[24, 24]} align="top">
          <Col flex="1 1 360px">
            <Title level={2}>{invoice.company?.display_name || invoice.company?.legal_name || 'Invoice'}</Title>
            <Paragraph>{invoice.company?.address}</Paragraph>
            <Text>{[invoice.company?.email, invoice.company?.phone].filter(Boolean).join(' | ')}</Text>
          </Col>
          {invoice.company?.logo_url && (
            <Col>
              <img className="invoice-company-logo" src={invoice.company.logo_url} alt={`${invoice.company?.display_name || 'Company'} logo`} />
            </Col>
          )}
          <Col style={{ textAlign: 'right' }}><Title level={1}>INVOICE</Title><Title level={4}>{invoice.invoice_number}</Title><Tag>{String(invoice.status).replaceAll('_', ' ').toUpperCase()}</Tag></Col>
        </Row>
        <Divider />
        <Descriptions column={{ xs: 1, sm: 2, md: 4 }}>
          <Descriptions.Item label="Bill to">{invoice.customer?.name}</Descriptions.Item>
          <Descriptions.Item label="Invoice date">{String(invoice.invoice_date || '').slice(0, 10)}</Descriptions.Item>
          <Descriptions.Item label="Due date">{String(invoice.due_date || '').slice(0, 10)}</Descriptions.Item>
          <Descriptions.Item label="Order">{invoice.order?.order_number || '—'}</Descriptions.Item>
        </Descriptions>
        {detailed ? (
          <Table rowKey="id" pagination={false} dataSource={detailedRows} style={{ marginTop: 24 }} columns={[
            { title: 'Service', dataIndex: 'service_name' },
            { title: 'Qty', dataIndex: 'quantity', width: 90, align: 'right' },
            { title: 'Unit Price', dataIndex: 'unit_price', width: 150, align: 'right', render: (value) => `${invoice.currency_code} ${money(value)}` },
            { title: 'Total', dataIndex: 'total_price', width: 160, align: 'right', render: (value) => <Text strong>{invoice.currency_code} {money(value)}</Text> },
            { title: 'Breakup', dataIndex: 'breakup' },
          ]} />
        ) : (
          <>
            {passengerRows.length > 0 && (
              <>
                <Divider />
                <Title level={4}>Passenger</Title>
                <Table
                  rowKey="id"
                  pagination={false}
                  dataSource={passengerRows}
                  columns={[
                    { title: 'Passenger', dataIndex: 'name', render: (value) => value || '-' },
                  ]}
                />
              </>
            )}
            <Divider />
            <Title level={4}>Services</Title>
            <Table rowKey="key" pagination={false} dataSource={serviceRows} columns={[
              { title: 'Services', dataIndex: 'service' },
              { title: 'Qty', dataIndex: 'quantity', width: 90, align: 'right' },
            ]} />
          </>
        )}
        <Row justify="end" style={{ marginTop: 24 }}><Col xs={24} md={10} lg={7}><Descriptions column={1} bordered size="small">
          <Descriptions.Item label="Subtotal">{invoice.currency_code} {money(invoice.subtotal)}</Descriptions.Item>
          <Descriptions.Item label="Tax">{invoice.currency_code} {money(invoice.tax_amount)}</Descriptions.Item>
          <Descriptions.Item label="Invoice total"><Text strong>{invoice.currency_code} {money(invoice.total_amount)}</Text></Descriptions.Item>
          <Descriptions.Item label="Outstanding"><Text strong>{invoice.currency_code} {money(invoice.outstanding_amount)}</Text></Descriptions.Item>
        </Descriptions></Col></Row>
        {invoice.notes && <><Divider /><Text strong>Notes</Text><Paragraph>{invoice.notes}</Paragraph></>}
      </Card>
    </div>
  );
}
