import React, { useEffect, useState } from 'react';
import { Button, Card, Form, Input, Modal, Select, Space, Table, Typography } from 'antd';
import { message } from '../services/feedback';
import { createVendorApi, listVendorsApi } from '../services/salesFlowApi';

const { Title, Paragraph } = Typography;

export default function VendorList() {
  const [vendors, setVendors] = useState([]);
  const [loading, setLoading] = useState(false);
  const [modalOpen, setModalOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [search, setSearch] = useState('');
  const [form] = Form.useForm();

  const loadVendors = async (value = search) => {
    setLoading(true);
    try {
      setVendors(await listVendorsApi(value));
    } catch (error) {
      message.error(error.message || 'Unable to load vendors');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadVendors('');
  }, []);

  const handleCreate = async () => {
    setSaving(true);
    try {
      const values = await form.validateFields();
      await createVendorApi(values);
      form.resetFields();
      setModalOpen(false);
      message.success('Vendor created');
      loadVendors();
    } catch (error) {
      if (error?.errorFields) return;
      message.error(error.message || 'Vendor creation failed');
    } finally {
      setSaving(false);
    }
  };

  const columns = [
    { title: 'Name', dataIndex: 'name', key: 'name' },
    { title: 'Email', dataIndex: 'email', key: 'email' },
    { title: 'Phone', dataIndex: 'phone', key: 'phone' },
    { title: 'Currency', dataIndex: 'currency_code', key: 'currency_code', width: 110 },
    { title: 'Payment Terms', dataIndex: 'payment_terms', key: 'payment_terms' },
  ];

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2} style={{ margin: 0 }}>Vendors</Title>
        <Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>
          Create vendors once, then select them for flights, visa, payments, and upcoming service modules.
        </Paragraph>
      </div>

      <Card className="border-beam-aurora">
        <Space className="responsive-toolbar" style={{ marginBottom: 12, width: '100%', justifyContent: 'space-between' }}>
          <Input.Search
            allowClear
            placeholder="Search vendors"
            className="responsive-search"
            style={{ width: 320 }}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            onSearch={loadVendors}
          />
          <Button type="primary" onClick={() => setModalOpen(true)}>Add Vendor</Button>
        </Space>

        <Table rowKey="id" loading={loading} columns={columns} dataSource={vendors} pagination={{ pageSize: 20 }} scroll={{ x: 'max-content' }} />
      </Card>

      <Modal
        title="Add Vendor"
        open={modalOpen}
        onOk={handleCreate}
        onCancel={() => setModalOpen(false)}
        confirmLoading={saving}
      >
        <Form layout="vertical" form={form} initialValues={{ type: 'B2C' }}>
          <Form.Item name="name" label="Name" rules={[{ required: true, message: 'Vendor name required' }]}>
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
          <Form.Item name="address" label="Address">
            <Input.TextArea rows={2} />
          </Form.Item>
          <Form.Item name="city" label="City">
            <Input />
          </Form.Item>
          <Form.Item name="country_code" label="Country Code">
            <Input maxLength={2} placeholder="PK" />
          </Form.Item>
          <Form.Item name="currency_code" label="Currency Code">
            <Input maxLength={3} placeholder="PKR" />
          </Form.Item>
          <Form.Item name="payment_terms" label="Payment Terms">
            <Input />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  );
}
