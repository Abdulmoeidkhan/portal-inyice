import React, { useEffect, useState } from 'react';
import { ArrowLeftOutlined, DownloadOutlined, PrinterOutlined, ShareAltOutlined } from '@ant-design/icons';
import { Alert, Button, Card, Col, Divider, Empty, Row, Skeleton, Space, Tag, Typography, Watermark } from 'antd';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { message } from '../services/feedback';
import { dateOnly } from '../services/dateFormat';
import { printDocument } from '../services/printDocument';
import { acquireEditLock, heartbeatEditLock, releaseEditLock } from '../services/editLocks';
import { backToRoute } from '../services/navigation';
import Table from '../components/CsvTable';

const { Title, Text, Paragraph } = Typography;
const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const firstFilled = (...values) => values.find((value) => value !== null && value !== undefined && String(value).trim() !== '') || '';
const toArray = (value) => (Array.isArray(value) ? value : []);
const toNumber = (value) => {
  const parsed = Number(value || 0);
  return Number.isFinite(parsed) ? parsed : 0;
};
const isDiscountLine = (line) => toNumber(line?.total_price) < 0 || /^discount\b/i.test(String(line?.description || '').trim());
const formatDate = dateOnly;
const documentForSettlement = (settlement) => settlement.reference_document || settlement.referenceDocument || null;
const stripVendorDetails = (value) => String(value || '')
  .replace(/\s+Vendor:\s+.+$/i, '')
  .replace(/\s+\((?:vendor|supplier)[^)]+\)/gi, '')
  .replace(/\s{2,}/g, ' ')
  .trim();
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
  other_services: stripVendorDetails(line?.description) || 'Other Services',
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

const buildDetailedLineRows = (invoice) => toArray(invoice.lines).filter((line) => !isDiscountLine(line) && toNumber(line.total_price) > 0).map((line, index) => ({
  ...line,
  id: line.id || `line-${index}`,
  public_description: stripVendorDetails(line.description),
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
  const lines = toArray(invoice.lines).filter((line) => !isDiscountLine(line));

  return serviceDefinitions
    .map((service) => {
      const serviceLines = lines.filter((line) => classifyLine(line) === service.key);
      const serviceTotal = serviceLines.reduce((sum, line) => sum + toNumber(line.total_price), 0);
      const metaRows = toArray(meta[service.metaKey]).filter((row) => hasRowValue(row, service.fields));
      const pricedLines = serviceLines.filter((line) => toNumber(line.total_price) > 0);

      if (service.key === 'other_services') {
        const otherRows = metaRows.length ? metaRows : serviceLines;

        return otherRows
          .map((row, index) => {
            const total = toNumber(firstFilled(row.sales, row.amount, row.total_price));
            const quantity = Math.max(1, Math.round(toNumber(firstFilled(row.quantity, 1))));
            const description = stripVendorDetails(firstFilled(row.description)) || 'Other Service';

            if (total <= 0 && !description) {
              return null;
            }

            return {
              key: `${service.key}-${index}`,
              service: description,
              quantity,
              unit_price: total / quantity,
              total_price: total,
            };
          })
          .filter(Boolean);
      }

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
    .flat()
    .filter(Boolean);
};

export default function InvoiceDetail({ shared = false }) {
  const { uid, token: shareToken } = useParams();
  const navigate = useNavigate();
  const location = useLocation();
  const [invoice, setInvoice] = useState(null);
  const [loading, setLoading] = useState(true);
  const [lockConflict, setLockConflict] = useState(null);
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
  const detailed = new URLSearchParams(location.search).get('view') === 'detailed';
  const backToInvoices = () => backToRoute(navigate, location, '/invoices');

  useEffect(() => {
    let acquiredLock = false;
    let heartbeatTimer = null;
    let cancelled = false;
    let lockUid = uid;

    const loadInvoice = async () => {
      setLoading(true);
      setLockConflict(null);

      try {
        const endpoint = shared ? `/api/v1/shared-invoices/${shareToken}` : `/api/v1/invoices/${uid}`;
        const response = await fetch(endpoint, { headers: shared ? { Accept: 'application/json' } : { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Invoice is unavailable');
        if (cancelled) return;

        if (!shared && !data.is_virtual_invoice) {
          lockUid = data.uid || uid;
          await acquireEditLock('invoice', lockUid);
          acquiredLock = true;
          heartbeatTimer = setInterval(() => {
            heartbeatEditLock('invoice', lockUid).catch(() => {});
          }, 30000);
        }

        setInvoice(data);
      } catch (error) {
        if (cancelled) return;
        if (error.status === 423) {
          setLockConflict(error.data || { message: error.message });
        } else {
          message.error(error.message);
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    };

    loadInvoice();

    return () => {
      cancelled = true;
      if (heartbeatTimer) {
        clearInterval(heartbeatTimer);
      }
      if (acquiredLock) {
        releaseEditLock('invoice', lockUid).catch(() => {});
      }
    };
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
  if (lockConflict) {
    const userName = lockConflict.locked_by?.name || 'Another user';

    return (
      <div className="page-shell page-fade-up">
        <Alert
          type="warning"
          showIcon
          message={`${userName} is working on this invoice`}
          description="This invoice is temporarily locked for editing. Try again after they finish or the lock expires."
          action={(
            <Button icon={<ArrowLeftOutlined />} onClick={backToInvoices}>
              Back to Invoices
            </Button>
          )}
        />
      </div>
    );
  }
  if (!invoice) return <div className="page-shell"><Empty description="Invoice not found" /></div>;

  const passengerRows = buildPassengerRows(invoice);
  const serviceRows = buildServiceRows(invoice);
  const detailedRows = buildDetailedLineRows(invoice);
  const discountTotal = Math.abs(toArray(invoice.lines).filter(isDiscountLine).reduce((sum, line) => sum + toNumber(line.total_price), 0));
  const settlementRows = toArray(invoice.settlements).filter((settlement) => (
    toNumber(settlement.amount_received) > 0 || toNumber(settlement.amount_refunded) > 0 || toNumber(settlement.amount_to_advance) > 0
  ));
  const paymentMade = settlementRows.reduce((sum, settlement) => sum + toNumber(settlement.amount_received), 0);
  const refunded = settlementRows.reduce((sum, settlement) => sum + toNumber(settlement.amount_refunded), 0);
  const hasReceiptRows = settlementRows.length > 0;
  const companyName = invoice.company?.display_name || invoice.company?.legal_name || 'Company';
  const companyContact = [
    invoice.company?.address,
    invoice.company?.phone,
    invoice.company?.email,
  ].filter(Boolean);
  const billTo = [
    invoice.customer?.name,
    invoice.customer?.phone,
    invoice.customer?.email,
    invoice.customer?.address,
  ].filter(Boolean);
  const showInyiceWatermark = invoice.company && invoice.company.is_paid === false;

  return (
    <div className="page-shell page-fade-up invoice-document">
      <Space className="invoice-screen-actions" style={{ marginBottom: 16 }}>
        {!shared && <Button icon={<ArrowLeftOutlined />} onClick={backToInvoices}>Back</Button>}
        <Button icon={<PrinterOutlined />} onClick={() => printDocument('.invoice-document .invoice-paper', 'Invoice')}>Print</Button>
        <Button icon={<DownloadOutlined />} onClick={() => printDocument('.invoice-document .invoice-paper', 'Invoice')}>Download PDF</Button>
        {!shared && !invoice.is_virtual_invoice && <Button type="primary" icon={<ShareAltOutlined />} onClick={share}>Copy share link</Button>}
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
              {invoice.company?.logo_url && (
                <img className="invoice-company-logo" src={invoice.company.logo_url} alt={`${companyName} logo`} />
              )}
              <div>
                <Title level={2}>{companyName}</Title>
                {companyContact.map((item) => <Text key={item}>{item}<br /></Text>)}
              </div>
            </div>
            <div className="invoice-heading">
              <Title level={1}>INVOICE</Title>
              <Text strong># {invoice.invoice_number}</Text>
              <Text className="invoice-balance-label">Balance Due</Text>
              <Title level={4}>{invoice.currency_code} {money(invoice.outstanding_amount)}</Title>
              <Tag>{String(invoice.status).replaceAll('_', ' ').toUpperCase()}</Tag>
            </div>
          </div>
          <div className="invoice-meta-grid">
            <div>
              <Text type="secondary">Bill To</Text>
              <div className="invoice-party">
                {billTo.length ? billTo.map((item, index) => <Text key={`${item}-${index}`} strong={index === 0}>{item}<br /></Text>) : <Text>-</Text>}
              </div>
            </div>
            <div className="invoice-dates">
              <div><Text>Invoice Date :</Text><Text>{formatDate(invoice.invoice_date)}</Text></div>
              <div><Text>Terms :</Text><Text>Due on Receipt</Text></div>
              <div><Text>Due Date :</Text><Text>{formatDate(invoice.due_date)}</Text></div>
              {invoice.order?.order_number && <div><Text>Order :</Text><Text>{invoice.order.order_number}</Text></div>}
            </div>
          </div>
          {detailed ? (
            <Table className="invoice-lines-table" rowKey="id" pagination={false} csvDownload={false} sortable={false} dataSource={detailedRows} columns={[
              { title: '#', width: 54, render: (_, __, index) => index + 1 },
              { title: 'Item & Description', dataIndex: 'public_description', render: (value, row) => <><Text strong>{row.service_name}</Text><br /><Text type="secondary">{value || '-'}</Text><br /><Text type="secondary">{row.breakup}</Text></> },
              { title: 'Qty', dataIndex: 'quantity', width: 90, align: 'right', render: (value) => money(value).replace(/\.00$/, '') },
              { title: 'Rate', dataIndex: 'unit_price', width: 130, align: 'right', render: (value) => money(value) },
              { title: 'Amount', dataIndex: 'total_price', width: 140, align: 'right', render: (value) => money(value) },

            ]} />
          ) : (
            <>
              {passengerRows.length > 0 && (
                <div className="invoice-mini-section">
                  <Title level={4}>Passenger</Title>
                  <Table
                    className="invoice-lines-table"
                    rowKey="id"
                    pagination={false}
                    csvDownload={false}
                    sortable={false}
                    dataSource={passengerRows}
                    columns={[
                      { title: 'Passenger', dataIndex: 'name', render: (value) => value || '-' },
                    ]}
                  />
                </div>
              )}
              <Table className="invoice-lines-table" rowKey="key" pagination={false} csvDownload={false} sortable={false} dataSource={serviceRows} columns={[
                { title: '#', width: 54, render: (_, __, index) => index + 1 },
                { title: 'Item & Description', dataIndex: 'service', render: (value) => <Text strong>{value}</Text> },
                { title: 'Qty', dataIndex: 'quantity', width: 90, align: 'right', render: (value) => money(value).replace(/\.00$/, '') },
              ]} />
            </>
          )}
          <Divider />
          <Row justify="end"><Col xs={24} md={11} lg={8}>
            <div className="invoice-totals">
              <div><Text>Sub Total</Text><Text>{money(invoice.subtotal)}</Text></div>
              {discountTotal > 0 && <div><Text>Discount</Text><Text>(-) {money(discountTotal)}</Text></div>}
              {toNumber(invoice.tax_amount) > 0 && <div><Text>Tax</Text><Text>{money(invoice.tax_amount)}</Text></div>}
              <div className="invoice-total-row"><Text strong>Total</Text><Text strong>{invoice.currency_code} {money(invoice.total_amount)}</Text></div>
              {paymentMade > 0 && <div className="invoice-paid-row"><Text>Payment Made</Text><Text>(-) {money(paymentMade)}</Text></div>}
              {refunded > 0 && <div><Text>Refunded</Text><Text>{money(refunded)}</Text></div>}
              <div className="invoice-balance-row"><Text strong>Balance Due</Text><Text strong>{invoice.currency_code} {money(invoice.outstanding_amount)}</Text></div>
            </div>
          </Col></Row>
          {hasReceiptRows && (
            <div className="invoice-receipts">
              <Title level={4}>Receipts & Payments</Title>
              <Table rowKey="id" pagination={false} csvDownload={false} sortable={false} dataSource={settlementRows} columns={[
                { title: 'Date', dataIndex: 'settlement_date', width: 115, render: formatDate },
                {
                  title: 'Receipt / Reference', render: (_, row) => {
                    const document = documentForSettlement(row);
                    return document?.receipt_number || document?.payment_number || row.notes || 'Applied balance';
                  }
                },
                { title: 'Method', width: 130, render: (_, row) => documentForSettlement(row)?.payment_method || row.settlement_type },
                { title: 'Amount', width: 140, align: 'right', render: (_, row) => money(toNumber(row.amount_received) || toNumber(row.amount_to_advance) || toNumber(row.amount_refunded)) },
              ]} />
            </div>
          )}
          <div className="invoice-notes">
            <Text strong>Notes</Text>
            <Paragraph>{invoice.notes || 'Thanks for your business.'}</Paragraph>
          </div>
        </Watermark>
      </Card>
    </div>
  );
}
