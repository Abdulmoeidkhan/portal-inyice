import React, { useEffect, useMemo, useState } from 'react';
import { ArrowLeftOutlined, DownloadOutlined, PrinterOutlined, ShareAltOutlined } from '@ant-design/icons';
import { Button, Card, Col, Divider, Empty, Row, Skeleton, Space, Table, Typography, Watermark } from 'antd';
import { useNavigate, useParams } from 'react-router-dom';
import { message } from '../services/feedback';
import { dateOnly } from '../services/dateFormat';
import { printDocument } from '../services/printDocument';

const { Title, Text, Paragraph } = Typography;

const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const toArray = (value) => (Array.isArray(value) ? value : []);
const toNumber = (value) => {
  const parsed = Number(value || 0);
  return Number.isFinite(parsed) ? parsed : 0;
};
const firstFilled = (...values) => values.find((value) => value !== null && value !== undefined && String(value).trim() !== '') || '';
const formatDate = dateOnly;
const stripVendorDetails = (value) => String(value || '')
  .replace(/\s+Vendor:\s+.+$/i, '')
  .replace(/\s+\((?:vendor|supplier)[^)]+\)/gi, '')
  .replace(/\s{2,}/g, ' ')
  .trim();
const classifyLine = (line) => {
  const description = String(line?.description || '').trim();

  if (/^(Flight|Flight Fare|Package Total)\b/i.test(description)) return 'flights';
  if (/^Hotel\b/i.test(description)) return 'hotels';
  if (/^Transfer\b/i.test(description)) return 'transfers';
  if (/^City Tour\b/i.test(description)) return 'city_tours';
  if (/^Visa\b/i.test(description)) return 'visa';

  return 'other_services';
};
const serviceLabels = {
  flights: 'Flights',
  hotels: 'Hotels',
  transfers: 'Transfers',
  city_tours: 'City Tours/Ziarat',
  visa: 'Visa',
  other_services: 'Other Services',
};

const authHeaders = () => {
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  return {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
  };
};

const copyLink = async (url) => {
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(url);
    return;
  }

  window.prompt('Copy this quotation link:', url);
};

const passengerRows = (order) => {
  const meta = order?.meta || {};
  const seen = new Set();

  return [
    ...toArray(meta.passengers).map((row) => row.name),
    ...toArray(meta.pricing).map((row) => row.pax_name),
    ...toArray(meta.visa).map((row) => row.passenger_name),
  ]
    .map((value) => String(value || '').trim())
    .filter(Boolean)
    .filter((name) => {
      const key = name.toUpperCase();
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    })
    .map((name, index) => ({ id: `passenger-${index}`, name }));
};

const quotationRows = (order) => {
  const grouped = new Map();

  toArray(order?.items)
    .filter((item) => toNumber(item.total_price) > 0)
    .forEach((item) => {
      const serviceKey = classifyLine(item);
      const description = serviceKey === 'other_services'
        ? (stripVendorDetails(item.description) || 'Other Service')
        : serviceLabels[serviceKey] || stripVendorDetails(item.description) || 'Service';
      const key = serviceKey === 'other_services' ? `${serviceKey}:${description.toUpperCase()}` : serviceKey;
      const current = grouped.get(key) || {
        id: key,
        description,
        quantity: 0,
        amount: 0,
      };

      current.quantity += toNumber(item.quantity) || 1;
      current.amount += toNumber(item.total_price);
      grouped.set(key, current);
    });

  const rows = [...grouped.values()].map((row) => ({
    ...row,
    quantity: row.quantity || 1,
  }));

  if (rows.length) {
    return rows;
  }

  const total = toNumber(order?.total_amount);
  return total > 0 ? [{
    id: 'quotation-total',
    description: firstFilled(order?.package_type, order?.meta?.package_type, 'Travel services'),
    quantity: 1,
    amount: total,
  }] : [];
};

export default function QuotationDetail({ shared = false }) {
  const navigate = useNavigate();
  const { uid, token } = useParams();
  const [order, setOrder] = useState(null);
  const [loading, setLoading] = useState(true);
  const [sharing, setSharing] = useState(false);

  useEffect(() => {
    const endpoint = shared ? `/api/v1/shared-vouchers/${token}` : `/api/v1/orders/${uid}`;
    setLoading(true);

    fetch(endpoint, {
      headers: shared ? { Accept: 'application/json' } : authHeaders(),
    })
      .then(async (response) => {
        const data = await response.json();
        if (!response.ok) throw new Error(data?.message || 'Quotation is unavailable');
        setOrder(data);
      })
      .catch((error) => message.error(error.message || 'Quotation is unavailable'))
      .finally(() => setLoading(false));
  }, [shared, token, uid]);

  const shareQuotation = async () => {
    if (shared) {
      await copyLink(window.location.href);
      message.success('Quotation link copied');
      return;
    }

    setSharing(true);
    try {
      const response = await fetch(`/api/v1/orders/${uid}/share`, {
        method: 'POST',
        headers: authHeaders(),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data?.message || 'Could not create share link');

      const quotationUrl = String(data.share_url || '').replace('/shared/vouchers/', '/shared/quotations/');
      await copyLink(quotationUrl || `${window.location.origin}/shared/quotations/${data.share_token}`);
      message.success('Shareable quotation link copied');
    } catch (error) {
      message.error(error.message || 'Could not create share link');
    } finally {
      setSharing(false);
    }
  };

  const rows = useMemo(() => quotationRows(order), [order]);
  const passengers = useMemo(() => passengerRows(order), [order]);

  if (loading) return <div className="page-shell invoice-document"><Card><Skeleton active /></Card></div>;
  if (!order) return <div className="page-shell invoice-document"><Empty description="Quotation not found" /></div>;

  const company = order.company || {};
  const customer = order.customer || {};
  const companyName = firstFilled(company.display_name, company.legal_name, order.meta?.contact?.company_name, 'Company');
  const companyContact = [
    firstFilled(company.address, order.meta?.contact?.address),
    firstFilled(company.phone, order.meta?.contact?.phone),
    firstFilled(company.email, order.meta?.contact?.email),
  ].filter(Boolean);
  const billTo = [customer.name, customer.phone, customer.email, customer.address].filter(Boolean);
  const subtotal = rows.reduce((sum, row) => sum + row.amount, 0);
  const total = subtotal || toNumber(order.total_amount);
  const quotationNumber = firstFilled(order.voucher_no, order.order_number);
  const showInyiceWatermark = company && company.is_paid === false;

  return (
    <div className="page-shell page-fade-up invoice-document quotation-document">
      <Space className="invoice-screen-actions" style={{ marginBottom: 16 }}>
        {!shared && <Button icon={<ArrowLeftOutlined />} onClick={() => navigate('/orders')}>Back</Button>}
        <Button icon={<PrinterOutlined />} onClick={() => printDocument('.quotation-document .invoice-paper', 'Quotation')}>Print</Button>
        <Button icon={<DownloadOutlined />} onClick={() => printDocument('.quotation-document .invoice-paper', 'Quotation')}>Download PDF</Button>
        <Button type="primary" icon={<ShareAltOutlined />} loading={sharing} onClick={shareQuotation}>Copy share link</Button>
      </Space>
      <Card className="invoice-paper">
        <Watermark
          content={showInyiceWatermark ? 'InYice' : undefined}
          rotate={-24}
          gap={[140, 120]}
          font={{ color: 'rgba(0, 0, 0, 0.15)', fontSize: 28, fontWeight: 700 }}
        >
          <div className="invoice-header">
            <div className="invoice-brand">
              {company.logo_url && (
                <img className="invoice-company-logo" src={company.logo_url} alt={`${companyName} logo`} />
              )}
              <div>
                <Title level={2}>{companyName}</Title>
                {companyContact.map((item) => <Text key={item}>{item}<br /></Text>)}
              </div>
            </div>
            <div className="invoice-heading">
              <Title level={1}>QUOTATION</Title>
              <Text strong># {quotationNumber}</Text>
              <Text className="invoice-balance-label">Estimated Total</Text>
              <Title level={4}>{order.currency_code} {money(total)}</Title>
            </div>
          </div>

          <div className="invoice-meta-grid">
            <div>
              <Text type="secondary">Quotation For</Text>
              <div className="invoice-party">
                {billTo.length ? billTo.map((item, index) => <Text key={`${item}-${index}`} strong={index === 0}>{item}<br /></Text>) : <Text>-</Text>}
              </div>
            </div>
            <div className="invoice-dates">
              <div><Text>Quotation Date :</Text><Text>{formatDate(firstFilled(order.issue_date, order.created_at))}</Text></div>
              <div><Text>Order :</Text><Text>{order.order_number}</Text></div>
              {order.booking_reference && <div><Text>Booking Ref :</Text><Text>{order.booking_reference}</Text></div>}
              {firstFilled(order.package_type, order.meta?.package_type) && <div><Text>Package :</Text><Text>{firstFilled(order.package_type, order.meta?.package_type)}</Text></div>}
            </div>
          </div>

          {passengers.length > 0 && (
            <div className="invoice-mini-section">
              <Title level={4}>Passenger</Title>
              <Table
                className="invoice-lines-table"
                rowKey="id"
                pagination={false}
                dataSource={passengers}
                columns={[
                  { title: 'Passenger', dataIndex: 'name', render: (value) => value || '-' },
                ]}
              />
            </div>
          )}

          <Table
            className="invoice-lines-table"
            rowKey="id"
            pagination={false}
            dataSource={rows}
            columns={[
              { title: '#', width: 54, render: (_, __, index) => index + 1 },
              { title: 'Item & Description', dataIndex: 'description', render: (value) => <Text strong>{value}</Text> },
              { title: 'Qty', dataIndex: 'quantity', width: 90, align: 'right', render: (value) => money(value).replace(/\.00$/, '') },
            ]}
          />

          <Divider />
          <Row justify="end"><Col xs={24} md={11} lg={8}>
            <div className="invoice-totals">
              <div><Text>Sub Total</Text><Text>{money(total)}</Text></div>
              <div className="invoice-total-row"><Text strong>Total</Text><Text strong>{order.currency_code} {money(total)}</Text></div>
            </div>
          </Col></Row>

          <div className="invoice-notes">
            <Text strong>Notes</Text>
            <Paragraph>{order.notes || 'This quotation is prepared for your review and confirmation.'}</Paragraph>
          </div>
        </Watermark>
      </Card>
    </div>
  );
}
