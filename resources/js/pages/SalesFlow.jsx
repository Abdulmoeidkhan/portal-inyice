import React, { useMemo, useState } from 'react';
import { Card, Steps, Typography, Form, InputNumber, Button, message, Alert, Tabs, Input, Space, Descriptions, Tag, Divider } from 'antd';

const { Title, Paragraph } = Typography;
const { TextArea } = Input;

function parseSabreText(text) {
  const bookingReference = text.match(/PN\s+([A-Z0-9]{6})/i)?.[1] || null;
  const passengers = Array.from(text.matchAll(/\d+\.\s+([A-Z\s\/]+)\s+([A-Z]+)(?:\s+(\d+))?/g)).map((match) => ({
    name: match[1].trim(),
    ptc: match[2] || 'PAX',
    age: match[3] ? Number(match[3]) : null,
  }));
  const segments = Array.from(text.matchAll(/\d+\s+([A-Z]{2})\s*(\d+)\s+([A-Z]{3})\s+([A-Z]{3})\s+(\d{2}[A-Z]{3})/g)).map((match) => ({
    airline_code: match[1],
    flight_number: match[2],
    departure_airport: match[3],
    arrival_airport: match[4],
    departure_date: match[5],
  }));

  return { booking_reference: bookingReference, passengers, segments, ticket_info: [] };
}

function parseGalileoText(text) {
  const bookingReference = text.match(/\(([A-Z0-9]{6})\)/i)?.[1] || null;
  const passengers = Array.from(text.matchAll(/([A-Z\s]+)\/([A-Z]+)/g)).map((match) => ({
    name: match[1].trim(),
    ptc: match[2].trim(),
  }));
  const segments = Array.from(text.matchAll(/([A-Z]{2})\s+(\d{3,4})\s+([A-Z]{3})\s+([A-Z]{3})\s+(\d{1,2}[A-Z]{3})/g)).map((match) => ({
    airline_code: match[1],
    flight_number: match[2],
    departure_airport: match[3],
    arrival_airport: match[4],
    departure_date: match[5],
  }));

  return { booking_reference: bookingReference, passengers, segments, ticket_info: [] };
}

export default function SalesFlow() {
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState(null);
  const [parserForm] = Form.useForm();
  const [quoteForm] = Form.useForm();
  const [form] = Form.useForm();
  const [parsedResult, setParsedResult] = useState(null);
  const [quoteDraft, setQuoteDraft] = useState(() => {
    try {
      return JSON.parse(localStorage.getItem('quote_draft') || 'null');
    } catch {
      return null;
    }
  });

  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  const quoteHint = useMemo(() => {
    if (!parsedResult) {
      return 'Paste Sabre or Galileo text in the GDS parser box, or create a manual quote draft below.';
    }

    return `Parsed ${parsedResult.passengers.length || 0} passenger(s) and ${parsedResult.segments.length || 0} segment(s). Start quote from this extracted travel data.`;
  }, [parsedResult]);

  const parseGds = async ({ gds_source, raw_text }) => {
    try {
      const parsed = gds_source === 'sabre' ? parseSabreText(raw_text) : parseGalileoText(raw_text);
      setParsedResult({ ...parsed, gds_source, raw_text });
      message.success('GDS text parsed on frontend');
    } catch {
      message.error('Could not parse GDS text');
    }
  };

  const startQuoteDraft = async (values) => {
    const draft = {
      ...values,
      gds_source: parsedResult?.gds_source || null,
      booking_reference: parsedResult?.booking_reference || null,
      passengers: parsedResult?.passengers || [],
      segments: parsedResult?.segments || [],
      created_at: new Date().toISOString(),
    };

    localStorage.setItem('quote_draft', JSON.stringify(draft));
    setQuoteDraft(draft);
    message.success('Quote draft started on frontend');
  };

  const createInvoiceFromOrder = async ({ order_id }) => {
    setLoading(true);
    setResult(null);

    try {
      const response = await fetch('/api/v1/invoices/create-from-order', {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${token}`,
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({ order_id }),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Could not create invoice from order');
      }

      setResult(data.invoice);
      message.success('Invoice created from order');
    } catch (error) {
      message.error(error.message || 'Action failed');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2} style={{ margin: 0 }}>Quotes Workspace</Title>
        <Paragraph type="secondary" style={{ marginTop: 8 }}>
          This is where you start making a quote. The GDS parser box is below on the frontend.
        </Paragraph>
      </div>

      <Card className="border-beam-aurora" style={{ marginBottom: 16 }}>
        <Steps
          current={2}
          items={[
            { title: 'Quote', description: 'Start quote draft' },
            { title: 'Order', description: 'Confirm booking order' },
            { title: 'Invoice', description: 'Generate invoice from order' },
          ]}
        />
      </Card>

      <Card className="border-beam-aurora" style={{ marginBottom: 16 }}>
        <Alert showIcon type="info" message="Quote Entry Point" description={quoteHint} />
      </Card>

      <Tabs
        defaultActiveKey="quote"
        items={[
          {
            key: 'quote',
            label: 'Start Quote',
            children: (
              <Card className="border-beam-aurora" title="Manual Quote Draft">
                <Form layout="vertical" form={quoteForm} onFinish={startQuoteDraft}>
                  <Form.Item name="customer_name" label="Customer Name" rules={[{ required: true, message: 'Customer name required' }]}>
                    <Input placeholder="Enter customer or agency name" />
                  </Form.Item>
                  <Form.Item name="trip_summary" label="Trip Summary" rules={[{ required: true, message: 'Trip summary required' }]}>
                    <TextArea rows={4} placeholder="Karachi to Dubai return, 2 pax, hotel + transfer" />
                  </Form.Item>
                  <Space wrap>
                    <Form.Item name="quoted_amount" label="Quoted Amount">
                      <InputNumber min={0} step={0.01} style={{ width: 220 }} placeholder="0.00" />
                    </Form.Item>
                    <Form.Item name="currency_code" label="Currency">
                      <Input style={{ width: 120 }} placeholder="PKR" />
                    </Form.Item>
                  </Space>
                  <div>
                    <Button type="primary" htmlType="submit">Start Quote</Button>
                  </div>
                </Form>

                {quoteDraft && (
                  <>
                    <Divider />
                    <Descriptions column={1} bordered size="small" title="Current Frontend Quote Draft">
                      <Descriptions.Item label="Customer">{quoteDraft.customer_name}</Descriptions.Item>
                      <Descriptions.Item label="Trip Summary">{quoteDraft.trip_summary}</Descriptions.Item>
                      <Descriptions.Item label="Quoted Amount">{quoteDraft.quoted_amount || 'N/A'} {quoteDraft.currency_code || ''}</Descriptions.Item>
                      <Descriptions.Item label="Booking Reference">{quoteDraft.booking_reference || 'N/A'}</Descriptions.Item>
                    </Descriptions>
                  </>
                )}
              </Card>
            ),
          },
          {
            key: 'gds',
            label: 'GDS Parser Box',
            children: (
              <Card className="border-beam-aurora" title="Frontend GDS Parser Box">
                <Form layout="vertical" form={parserForm} onFinish={parseGds} initialValues={{ gds_source: 'sabre' }}>
                  <Form.Item name="gds_source" label="GDS Source" rules={[{ required: true }]}>
                    <Input placeholder="Use sabre or galileo" />
                  </Form.Item>
                  <Form.Item name="raw_text" label="Paste PNR / GDS Text" rules={[{ required: true, message: 'Paste raw GDS text' }]}>
                    <TextArea rows={10} placeholder="Paste Sabre or Galileo text here" />
                  </Form.Item>
                  <Button type="primary" htmlType="submit">Parse on Frontend</Button>
                </Form>

                {parsedResult && (
                  <>
                    <Divider />
                    <Descriptions bordered column={1} size="small" title="Parsed Output">
                      <Descriptions.Item label="GDS Source">
                        <Tag color="processing">{parsedResult.gds_source}</Tag>
                      </Descriptions.Item>
                      <Descriptions.Item label="Booking Reference">{parsedResult.booking_reference || 'Not found'}</Descriptions.Item>
                      <Descriptions.Item label="Passengers">{parsedResult.passengers.length}</Descriptions.Item>
                      <Descriptions.Item label="Segments">{parsedResult.segments.length}</Descriptions.Item>
                    </Descriptions>
                  </>
                )}
              </Card>
            ),
          },
          {
            key: 'invoice',
            label: 'Create Invoice',
            children: (
              <Card className="border-beam-aurora" title="Create Invoice from Existing Order">
                <Paragraph type="secondary">
                  Once an order exists, enter its order ID here to generate the invoice.
                </Paragraph>
                <Form layout="inline" form={form} onFinish={createInvoiceFromOrder}>
                  <Form.Item
                    name="order_id"
                    rules={[{ required: true, message: 'Order ID required' }]}
                  >
                    <InputNumber placeholder="Order ID" min={1} style={{ width: 200 }} />
                  </Form.Item>
                  <Form.Item>
                    <Button type="primary" htmlType="submit" loading={loading}>Create Invoice</Button>
                  </Form.Item>
                </Form>

                {result && (
                  <Alert
                    style={{ marginTop: 16 }}
                    type="success"
                    message={`Invoice ${result.invoice_number} created`}
                    description={`Invoice UID: ${result.uid}`}
                    showIcon
                  />
                )}
              </Card>
            ),
          },
        ]}
      />
    </div>
  );
}
