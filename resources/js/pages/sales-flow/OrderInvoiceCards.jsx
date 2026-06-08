import React from 'react';
import { Alert, Button, Card, Col, Form, Input, InputNumber, Row, Select, Space, Typography } from 'antd';

const { Text } = Typography;
const { TextArea } = Input;

export function CreateOrderCard({ form, loading, createdOrder, onCreateOrder }) {
  return (
    <Card className="border-beam-aurora" title="Create Order / Quotation">
      <Form layout="vertical" form={form} onFinish={onCreateOrder} initialValues={{ status: 'quote', currency_code: 'PKR' }}>
        <Row gutter={12}>
          <Col xs={24} md={6}>
            <Form.Item name="customer_id" label="Customer ID" rules={[{ required: true, message: 'Customer ID required' }]}>
              <InputNumber min={1} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={6}>
            <Form.Item name="company_id" label="Company ID (optional)">
              <InputNumber min={1} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={6}>
            <Form.Item name="vendor_id" label="Vendor ID (optional)">
              <InputNumber min={1} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={6}>
            <Form.Item name="status" label="Type">
              <Select options={[{ value: 'quote', label: 'Quotation' }, { value: 'order', label: 'Order' }]} />
            </Form.Item>
          </Col>
        </Row>

        <Row gutter={12}>
          <Col xs={24} md={6}>
            <Form.Item name="currency_code" label="Currency Code">
              <Input placeholder="PKR" />
            </Form.Item>
          </Col>
          <Col xs={24} md={18}>
            <Form.Item name="notes" label="Order Notes">
              <TextArea rows={3} placeholder="Internal notes" />
            </Form.Item>
          </Col>
        </Row>

        <Button type="primary" htmlType="submit" loading={loading}>Create Order / Quotation</Button>
      </Form>

      {createdOrder && (
        <Alert
          style={{ marginTop: 16 }}
          type="success"
          showIcon
          message={`${createdOrder.status?.toUpperCase() || 'ORDER'} ${createdOrder.order_number} created`}
          description={
            <Space direction="vertical">
              <Text>Order ID: {createdOrder.id}</Text>
              <Text>Order UID: {createdOrder.uid}</Text>
              <Text>Total Amount: {createdOrder.total_amount}</Text>
              <Text>Items: {createdOrder.items?.length || 0}</Text>
            </Space>
          }
        />
      )}
    </Card>
  );
}

export function ConvertInvoiceCard({ form, loading, createdInvoice, onCreateInvoice, canConvert }) {
  return (
    <Card className="border-beam-aurora" title="Convert to Invoice">
      <Form layout="inline" form={form} onFinish={onCreateInvoice}>
        <Form.Item name="order_id" rules={[{ required: true, message: 'Order ID required' }]}>
          <InputNumber min={1} style={{ width: 220 }} placeholder="Order ID" />
        </Form.Item>
        <Form.Item>
          <Button type="primary" htmlType="submit" loading={loading} disabled={!canConvert}>Convert to Invoice</Button>
        </Form.Item>
      </Form>

      {!canConvert && (
        <Alert
          style={{ marginTop: 12 }}
          type="info"
          showIcon
          message="Create an order/quotation first, then convert it to invoice."
        />
      )}

      {createdInvoice && (
        <Alert
          style={{ marginTop: 16 }}
          type="success"
          showIcon
          message={`Invoice ${createdInvoice.invoice_number} created`}
          description={`Invoice UID: ${createdInvoice.uid}`}
        />
      )}
    </Card>
  );
}
