import React, { useEffect, useState } from 'react';
import { ArrowLeftOutlined, PrinterOutlined, ShareAltOutlined } from '@ant-design/icons';
import { Button, Card, Col, Descriptions, Divider, Empty, Row, Skeleton, Space, Table, Tag, Typography } from 'antd';
import { useNavigate, useParams } from 'react-router-dom';
import { message } from '../services/feedback';

const { Title, Text, Paragraph } = Typography;
const money = (value) => Number(value || 0).toFixed(2);

export default function InvoiceDetail({ shared = false }) {
  const { uid, token: shareToken } = useParams();
  const navigate = useNavigate();
  const [invoice, setInvoice] = useState(null);
  const [loading, setLoading] = useState(true);
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

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

  return (
    <div className="page-shell page-fade-up invoice-document">
      <Space className="invoice-screen-actions" style={{ marginBottom: 16 }}>
        {!shared && <Button icon={<ArrowLeftOutlined />} onClick={() => navigate('/invoices')}>Back</Button>}
        <Button icon={<PrinterOutlined />} onClick={() => window.print()}>Print / PDF</Button>
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
        <Table rowKey="id" pagination={false} dataSource={invoice.lines || []} style={{ marginTop: 24 }} columns={[
          { title: 'Description', dataIndex: 'description' },
          { title: 'Qty', dataIndex: 'quantity', width: 90, align: 'right' },
          { title: 'Unit price', dataIndex: 'unit_price', width: 150, align: 'right', render: (value) => `${invoice.currency_code} ${money(value)}` },
          { title: 'Total', dataIndex: 'total_price', width: 160, align: 'right', render: (value) => <Text strong>{invoice.currency_code} {money(value)}</Text> },
        ]} />
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
