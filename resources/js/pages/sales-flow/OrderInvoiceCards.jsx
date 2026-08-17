import React, { useEffect, useState } from 'react';
import { Alert, Button, Card, Col, Form, Input, InputNumber, Modal, Row, Select, Space, Typography } from 'antd';
import { message } from '../../services/feedback';
import { createCustomerApi, listCustomersApi } from '../../services/salesFlowApi';

const { Text } = Typography;
const { TextArea } = Input;

export function CreateOrderCard({ form, loading, createdOrder, onCreateOrder, showSubmit = true }) {
  const [customers, setCustomers] = useState([]);
  const [customerModalOpen, setCustomerModalOpen] = useState(false);
  const [savingCustomer, setSavingCustomer] = useState(false);
  const [customerForm] = Form.useForm();

  const customerOptions = customers.map((customer) => ({
    value: customer.id,
    label: `${customer.name}${customer.phone ? ` - ${customer.phone}` : ''}`,
  }));
  const createdDocumentLabel = createdOrder?.status === 'quote' ? 'Quotation' : 'Order';

  const loadCustomers = async (search = '') => {
    try {
      setCustomers(await listCustomersApi(search));
    } catch (error) {
      message.error(error.message || 'Unable to load customers');
    }
  };

  useEffect(() => {
    loadCustomers();
  }, []);

  const handleCreateCustomer = async () => {
    setSavingCustomer(true);
    try {
      const values = await customerForm.validateFields();
      const data = await createCustomerApi(values);
      await loadCustomers();
      form.setFieldValue('customer_id', data.customer.id);
      customerForm.resetFields();
      setCustomerModalOpen(false);
      message.success('Customer created');
    } catch (error) {
      if (error?.errorFields) return;
      message.error(error.message || 'Customer creation failed');
    } finally {
      setSavingCustomer(false);
    }
  };

  return (
    <Card className="border-beam-aurora" title="Order / Quotation Details">
      <Form layout="vertical" form={form} onFinish={onCreateOrder} initialValues={{ currency_code: 'PKR' }}>
        <Row gutter={12}>
          <Col xs={24} md={8}>
            <Form.Item name="customer_id" label="Customer" rules={[{ required: true, message: 'Customer required' }]}>
              <Select
                showSearch
                placeholder="Select customer"
                filterOption={false}
                onSearch={loadCustomers}
                options={customerOptions}
                dropdownRender={(menu) => (
                  <>
                    {menu}
                    <Button type="link" block onClick={() => setCustomerModalOpen(true)}>+ Add Customer</Button>
                  </>
                )}
              />
            </Form.Item>
          </Col>
          <Col xs={24} md={4}>
            <Form.Item name="company_id" label="Company ID (optional)">
              <InputNumber min={1} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={4}>
            <Form.Item name="status" label="Document Type" initialValue="order">
              <Select
                options={[
                  {
                    label: 'Create as',
                    options: [
                      { value: 'quote', label: 'Quotation' },
                      { value: 'order', label: 'Order' },
                    ],
                  },
                ]}
              />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="currency_code" label="Currency Code">
              <Input placeholder="PKR" />
            </Form.Item>
          </Col>
        </Row>

        <Row gutter={12}>
          <Col xs={24}>
            <Form.Item name="notes" label="Notes">
              <TextArea rows={3} placeholder="Internal notes" />
            </Form.Item>
          </Col>
        </Row>

        {showSubmit && <Button type="primary" htmlType="submit" loading={loading}>Create Order / Quotation</Button>}
      </Form>

      <Modal
        title="Add Customer"
        open={customerModalOpen}
        onOk={handleCreateCustomer}
        onCancel={() => setCustomerModalOpen(false)}
        cancelButtonProps={{ danger: true }}
        confirmLoading={savingCustomer}
      >
        <Form layout="vertical" form={customerForm} initialValues={{ type: 'B2C' }}>
          <Form.Item name="name" label="Name" rules={[{ required: true, message: 'Customer name required' }]}>
            <Input />
          </Form.Item>
          <Form.Item name="type" label="Type">
            <Select options={[{ value: 'B2C', label: 'B2C' }, { value: 'B2B', label: 'B2B' }]} />
          </Form.Item>
          <Form.Item name="email" label="Email">
            <Input />
          </Form.Item>
          <Form.Item name="phone" label="Phone">
            <Input />
          </Form.Item>
          <Form.Item name="currency_code" label="Currency Code">
            <Input placeholder="PKR" maxLength={3} />
          </Form.Item>
        </Form>
      </Modal>

      {createdOrder && (
        <Alert
          style={{ marginTop: 16 }}
          type="success"
          showIcon
          message={`${createdDocumentLabel} ${createdOrder.order_number} created`}
          description={
            <Space orientation="vertical">
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
