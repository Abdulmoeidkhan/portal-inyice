import React, { useEffect, useState } from 'react';
import { Button, Card, Form, Grid, Input, Modal, Popconfirm, Select, Space, Table, Typography } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { message } from '../services/feedback';
import { createCustomerApi, deleteCustomerApi, listCustomersApi, updateCustomerApi } from '../services/salesFlowApi';

const { Title, Paragraph } = Typography;

export default function CustomerList() {
  const [customers, setCustomers] = useState([]);
  const [loading, setLoading] = useState(false);
  const [modalOpen, setModalOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [search, setSearch] = useState('');
  const [editing, setEditing] = useState(null);
  const [form] = Form.useForm();
  const screens = Grid.useBreakpoint();
  const compactActions = !screens.sm;

  const loadCustomers = async (value = search) => {
    setLoading(true);
    try {
      setCustomers(await listCustomersApi(value));
    } catch (error) {
      message.error(error.message || 'Unable to load customers');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadCustomers('');
  }, []);

  const openCreate = () => {
    setEditing(null);
    form.resetFields();
    form.setFieldsValue({ type: 'B2C' });
    setModalOpen(true);
  };

  const openEdit = (customer) => {
    setEditing(customer);
    form.setFieldsValue({
      name: customer.name,
      type: customer.type || 'B2C',
      email: customer.email,
      phone: customer.phone,
      address: customer.address,
      city: customer.city,
      country_code: customer.country_code,
      currency_code: customer.currency_code,
    });
    setModalOpen(true);
  };

  const handleSave = async () => {
    setSaving(true);
    try {
      const values = await form.validateFields();
      if (editing) {
        await updateCustomerApi(editing.uid, values);
      } else {
        await createCustomerApi(values);
      }
      form.resetFields();
      setEditing(null);
      setModalOpen(false);
      message.success(`Customer ${editing ? 'updated' : 'created'}`);
      loadCustomers();
    } catch (error) {
      if (error?.errorFields) return;
      message.error(error.message || `Customer ${editing ? 'update' : 'creation'} failed`);
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (customer) => {
    setSaving(true);
    try {
      await deleteCustomerApi(customer.uid);
      message.success('Customer deleted');
      loadCustomers();
    } catch (error) {
      message.error(error.message || 'Customer delete failed');
    } finally {
      setSaving(false);
    }
  };

  const columns = [
    { title: 'Name', dataIndex: 'name', key: 'name' },
    { title: 'Email', dataIndex: 'email', key: 'email' },
    { title: 'Phone', dataIndex: 'phone', key: 'phone' },
    { title: 'Currency', dataIndex: 'currency_code', key: 'currency_code', width: 110 },
    {
      title: 'Actions',
      key: 'actions',
      width: 132,
      fixed: 'right',
      render: (_, customer) => (
        <Space>
          <Button size="small" icon={<EditOutlined />} disabled={!customer.can_manage} onClick={() => openEdit(customer)}>
            {compactActions ? null : 'Edit'}
          </Button>
          <Popconfirm
            title="Delete this customer?"
            description="Only customers without financial activity can be deleted."
            okText="Delete"
            okButtonProps={{ danger: true }}
            disabled={!customer.can_manage}
            onConfirm={() => handleDelete(customer)}
          >
            <Button danger size="small" icon={<DeleteOutlined />} disabled={!customer.can_manage}>
              {compactActions ? null : 'Delete'}
            </Button>
          </Popconfirm>
        </Space>
      ),
    },
  ];

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2} style={{ margin: 0 }}>Customers</Title>
        <Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>
          Create and maintain customers for quotations, orders, invoices, and statements.
        </Paragraph>
      </div>

      <Card className="border-beam-aurora">
        <Space className="responsive-toolbar" style={{ marginBottom: 12, width: '100%', justifyContent: 'space-between' }}>
          <Input.Search
            allowClear
            placeholder="Search customers"
            className="responsive-search"
            style={{ width: 320 }}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            onSearch={loadCustomers}
          />
          <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>{compactActions ? null : 'Add Customer'}</Button>
        </Space>

        <Table rowKey="id" loading={loading} columns={columns} dataSource={customers} pagination={{ pageSize: 20 }} scroll={{ x: 'max-content' }} />
      </Card>

      <Modal
        title={editing ? 'Edit Customer' : 'Add Customer'}
        open={modalOpen}
        onOk={handleSave}
        onCancel={() => { setModalOpen(false); setEditing(null); form.resetFields(); }}
        confirmLoading={saving}
        okText={editing ? 'Save changes' : 'Create customer'}
      >
        <Form layout="vertical" form={form} initialValues={{ type: 'B2C' }}>
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
        </Form>
      </Modal>
    </div>
  );
}
