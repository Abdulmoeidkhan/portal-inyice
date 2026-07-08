import React, { useEffect, useState } from 'react';
import { Button, Card, Descriptions, Drawer, Form, Input, Popconfirm, Select, Space, Spin, Table, Tag, Typography } from 'antd';
import { ArrowsAltOutlined, DeleteOutlined, EditOutlined, FileDoneOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { message } from '../services/feedback';

const { Title, Paragraph, Text } = Typography;
const { TextArea } = Input;

const rowHasValue = (row, keys) => keys.some((key) => {
  const value = row?.[key];
  return value !== null && value !== undefined && String(value).trim() !== '';
});

const authHeaders = (json = false) => {
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
  return {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
    ...(json ? { 'Content-Type': 'application/json' } : {}),
  };
};

const getStatusColor = (status) => {
  const colors = {
    quote: 'default',
    order: 'processing',
    confirm: 'success',
    cancel: 'error',
    invoice: 'purple',
    void: 'default',
    refund: 'error',
    partial_refund: 'warning',
    paid: 'success',
    partial_paid: 'warning',
  };

  return colors[status] || 'default';
};

const formatStatus = (status) => String(status || 'order').replace(/_/g, ' ').toUpperCase();

const countRows = (rows, keys) => (Array.isArray(rows) ? rows.filter((row) => rowHasValue(row, keys)).length : 0);

const getVoucherItemCount = (order) => {
  const meta = order?.meta || {};
  const count =
    countRows(meta.flights, ['gds_pnr', 'pnr', 'flight_no', 'from', 'to', 'date']) +
    countRows(meta.visa, ['passenger_name', 'visa_type', 'visa_no', 'amount']) +
    countRows(meta.hotels, ['hcn', 'city', 'hotel_name', 'check_in', 'check_out', 'amount']) +
    countRows(meta.transfers, ['tn', 'service', 'from_city', 'to_city', 'amount']) +
    countRows(meta.city_tours, ['city', 'title', 'date', 'amount']) +
    countRows(meta.other_services, ['description', 'amount']);

  return count || order?.items?.length || 0;
};

const getFirstPassengerName = (order) => {
  const passengers = order?.meta?.passengers;
  const firstPassenger = Array.isArray(passengers)
    ? passengers.find((passenger) => rowHasValue(passenger, ['name']))
    : null;

  return firstPassenger?.name || '-';
};

export default function OrderList() {
  const [form] = Form.useForm();
  const navigate = useNavigate();
  const [orders, setOrders] = useState([]);
  const [loading, setLoading] = useState(false);
  const [pagination, setPagination] = useState({ current: 1, pageSize: 20, total: 0 });
  const [selectedOrder, setSelectedOrder] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [editingOrder, setEditingOrder] = useState(null);
  const [saving, setSaving] = useState(false);
  const [customers, setCustomers] = useState([]);
  const [searchTerm, setSearchTerm] = useState('');
  const [invoicingOrderId, setInvoicingOrderId] = useState(null);

  const customerOptions = customers.map((customer) => ({
    value: customer.id,
    label: `${customer.name}${customer.phone ? ` - ${customer.phone}` : ''}`,
  }));

  const loadCustomers = async (search = '') => {
    try {
      const response = await fetch(`/api/v1/customers${search ? `?search=${encodeURIComponent(search)}` : ''}`, {
        headers: authHeaders(),
      });

      if (!response.ok) {
        throw new Error('Unable to load customers');
      }

      setCustomers(await response.json());
    } catch (error) {
      message.error(error.message || 'Unable to load customers');
    }
  };

  const fetchOrders = async (page = pagination.current, search = searchTerm) => {
    setLoading(true);
    try {
      const params = new URLSearchParams({
        page,
        per_page: pagination.pageSize,
      });

      if (search) {
        params.set('search', search);
      }

      const response = await fetch(`/api/v1/orders?${params}`, {
        headers: authHeaders(),
      });

      if (!response.ok) {
        throw new Error('Failed to fetch orders');
      }

      const data = await response.json();
      setOrders(data.data || []);
      setPagination((prev) => ({
        ...prev,
        current: page,
        total: data.total || 0,
      }));
    } catch (error) {
      message.error(error.message || 'Failed to load orders');
    } finally {
      setLoading(false);
    }
  };

  const fetchOrderDetail = async (order) => {
    setSelectedOrder(order);
    setDetailLoading(true);
    try {
      const response = await fetch(`/api/v1/orders/${order.uid}`, {
        headers: authHeaders(),
      });

      if (!response.ok) {
        throw new Error('Failed to fetch order detail');
      }

      setSelectedOrder(await response.json());
    } catch (error) {
      message.error(error.message || 'Failed to load order detail');
    } finally {
      setDetailLoading(false);
    }
  };

  const handleSearch = (value) => {
    const search = value.trim();
    setSearchTerm(search);
    fetchOrders(1, search);
  };

  const createInvoice = async (order) => {
    setInvoicingOrderId(order.id);
    try {
      const response = await fetch('/api/v1/invoices/create-from-order', {
        method: 'POST',
        headers: authHeaders(true),
        body: JSON.stringify({ order_id: order.id }),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data?.message || data?.error || 'Failed to create invoice');
      }

      message.success(`Invoice ${data.invoice.invoice_number} is ready for payment`);
      fetchOrders(pagination.current);
    } catch (error) {
      message.error(error.message || 'Failed to create invoice');
    } finally {
      setInvoicingOrderId(null);
    }
  };

  useEffect(() => {
    fetchOrders(1);
    loadCustomers();
  }, []);

  const openEditDrawer = async (order) => {
    setSaving(true);
    try {
      const response = await fetch(`/api/v1/orders/${order.uid}`, {
        headers: authHeaders(),
      });

      if (!response.ok) {
        throw new Error('Failed to load order for editing');
      }

      const detail = await response.json();
      setEditingOrder(detail);
      form.resetFields();
      form.setFieldsValue({
        customer_id: detail.customer?.id || detail.customer_id,
        status: detail.status || 'order',
        currency_code: detail.currency_code || 'PKR',
        notes: detail.notes || '',
      });
    } catch (error) {
      message.error(error.message || 'Failed to load order for editing');
    } finally {
      setSaving(false);
    }
  };

  const closeEditDrawer = () => {
    setEditingOrder(null);
    form.resetFields();
  };

  const handleEdit = async () => {
    if (!editingOrder) {
      return;
    }

    setSaving(true);
    try {
      const values = await form.validateFields();

      const response = await fetch(`/api/v1/orders/${editingOrder.uid}`, {
        method: 'PATCH',
        headers: authHeaders(true),
        body: JSON.stringify(values),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data?.message || data?.error || 'Failed to update order');
      }

      message.success('Order updated');
      closeEditDrawer();
      fetchOrders(pagination.current);
      if (selectedOrder?.uid === editingOrder.uid) {
        setSelectedOrder(data.order);
      }
    } catch (error) {
      if (!error?.errorFields) {
        message.error(error.message || 'Failed to update order');
      }
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (order) => {
    try {
      const response = await fetch(`/api/v1/orders/${order.uid}`, {
        method: 'DELETE',
        headers: authHeaders(),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data?.message || data?.error || 'Failed to delete order');
      }

      message.success('Order deleted');
      if (selectedOrder?.uid === order.uid) {
        setSelectedOrder(null);
      }
      fetchOrders(pagination.current);
    } catch (error) {
      message.error(error.message || 'Failed to delete order');
    }
  };

  const columns = [
    {
      title: 'Order #',
      dataIndex: 'order_number',
      key: 'order_number',
      width: 240,
      render: (value, record) => (
        <a onClick={() => fetchOrderDetail(record)}>{value}</a>
      ),
    },
    {
      title: 'Customer',
      dataIndex: ['customer', 'name'],
      key: 'customer',
      width: 220,
      render: (value) => value || '-',
    },
    {
      title: 'Passenger',
      key: 'passenger',
      width: 220,
      render: (_, record) => getFirstPassengerName(record),
    },
    {
      title: 'Booking Ref',
      dataIndex: 'booking_reference',
      key: 'booking_reference',
      width: 150,
      render: (value) => value || '-',
    },
    {
      title: 'Status',
      dataIndex: 'status',
      key: 'status',
      width: 120,
      render: (status) => <Tag color={getStatusColor(status)}>{formatStatus(status)}</Tag>,
    },
    {
      title: 'Amount',
      dataIndex: 'total_amount',
      key: 'total_amount',
      align: 'right',
      render: (amount, record) => `${record.currency_code || ''} ${Number(amount || 0).toFixed(2)}`,
    },
    {
      title: 'Items',
      key: 'items',
      width: 90,
      align: 'right',
      render: (_, record) => getVoucherItemCount(record),
    },
    {
      title: 'Created',
      dataIndex: 'created_at',
      key: 'created_at',
      width: 150,
      render: (value) => value ? new Date(value).toLocaleDateString() : '-',
    },
    {
      title: 'Actions',
      key: 'actions',
      width: 140,
      render: (_, record) => (
        <Space>
          {record.status === 'invoice' && !record.invoice && (
            <Button
              type="primary"
              size="small"
              icon={<FileDoneOutlined />}
              loading={invoicingOrderId === record.id}
              onClick={() => createInvoice(record)}
            >
              Create Invoice
            </Button>
          )}
          <Button
            size="small"
            icon={<EditOutlined />}
            onClick={() => openEditDrawer(record)}
          >
            Edit
          </Button>
          <Popconfirm
            title="Delete order?"
            description="This removes the order if no invoice exists for it."
            okText="Delete"
            okButtonProps={{ danger: true }}
            onConfirm={() => handleDelete(record)}
          >
            <Button danger size="small" icon={<DeleteOutlined />}>
              Delete
            </Button>
          </Popconfirm>
        </Space>
      ),
    },
  ];

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2} style={{ margin: 0 }}>Orders</Title>
        <Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>
          Orders created from voucher and GDS data.
        </Paragraph>
      </div>

      <Card className="border-beam-aurora">
        <div className="list-toolbar">
          <Input.Search
            className="responsive-search"
            allowClear
            enterButton="Search"
            placeholder="Search voucher, booking reference, type, GDS, service, or customer"
            onSearch={handleSearch}
            style={{ width: 420 }}
          />
        </div>
        <Spin spinning={loading}>
          <Table
            scroll={{ x: 'max-content' }}
            columns={columns}
            dataSource={orders}
            rowKey="id"
            pagination={{
              current: pagination.current,
              pageSize: pagination.pageSize,
              total: pagination.total,
              onChange: (page) => fetchOrders(page),
            }}
          />
        </Spin>
      </Card>

      <Drawer
        title={selectedOrder?.order_number || 'Order Detail'}
        open={Boolean(selectedOrder)}
        onClose={() => setSelectedOrder(null)}
        size="large"
      >
        <Spin spinning={detailLoading}>
          {selectedOrder && (
            <Space orientation="vertical" size="large" style={{ width: '100%' }}>
              <Descriptions bordered column={1} size="small">
                <Descriptions.Item label="Customer">{selectedOrder.customer?.name || '-'}</Descriptions.Item>
                <Descriptions.Item label="Vendor">{selectedOrder.vendor?.name || '-'}</Descriptions.Item>
                <Descriptions.Item label="Booking Ref">{selectedOrder.booking_reference || '-'}</Descriptions.Item>
                <Descriptions.Item label="Status">
                  <Tag color={getStatusColor(selectedOrder.status)}>{formatStatus(selectedOrder.status)}</Tag>
                </Descriptions.Item>
                <Descriptions.Item label="Total">
                  {selectedOrder.currency_code || ''} {Number(selectedOrder.total_amount || 0).toFixed(2)}
                </Descriptions.Item>
                <Descriptions.Item label="Notes">{selectedOrder.notes || '-'}</Descriptions.Item>
              </Descriptions>

              <div>
                <Title level={4}>Order Items</Title>
                <Space orientation="vertical" style={{ width: '100%' }}>
                  {(selectedOrder.items || []).map((item) => (
                    <Card key={item.id} size="small">
                      <Space orientation="vertical">
                        <Text>{item.description}</Text>
                        <Text type="secondary">{Number(item.total_price || 0).toFixed(2)}</Text>
                      </Space>
                    </Card>
                  ))}
                </Space>
              </div>
            </Space>
          )}
        </Spin>
      </Drawer>

      <Drawer
        title={editingOrder ? `Edit ${editingOrder.order_number}` : 'Edit Order'}
        open={Boolean(editingOrder)}
        onClose={closeEditDrawer}
        size="large"
        extra={
          <Space>
            <Button
              icon={<ArrowsAltOutlined />}
              onClick={() => editingOrder && navigate(`/orders/${editingOrder.uid}/edit`)}
            >
              Open Full Form
            </Button>
            <Button onClick={closeEditDrawer}>Cancel</Button>
            <Button type="primary" loading={saving} onClick={handleEdit}>Save Order</Button>
          </Space>
        }
      >
        <Form form={form} layout="vertical">
          <Card className="border-beam-aurora" style={{ marginBottom: 12 }} title="Order Details">
            <Space orientation="vertical" style={{ width: '100%' }} size={0}>
              <Space className="edit-order-fields" wrap align="start" style={{ width: '100%' }}>
                <Form.Item name="customer_id" label="Customer" rules={[{ required: true, message: 'Customer required' }]} style={{ minWidth: 260 }}>
                  <Select
                    showSearch
                    filterOption={false}
                    onSearch={loadCustomers}
                    options={customerOptions}
                  />
                </Form.Item>
                <Form.Item name="status" label="Status" rules={[{ required: true, message: 'Status required' }]} style={{ minWidth: 180 }}>
                  <Select
                    options={[
                      {
                        label: 'Order Section',
                        options: [
                          { label: 'Quote', value: 'quote' },
                          { label: 'Order', value: 'order' },
                          { label: 'Confirm', value: 'confirm' },
                          { label: 'Cancel', value: 'cancel' },
                        ],
                      },
                      {
                        label: 'Invoice Section',
                        options: [
                          { label: 'Invoice', value: 'invoice' },
                          { label: 'Void', value: 'void' },
                          { label: 'Refund', value: 'refund' },
                          { label: 'Partial Refund', value: 'partial_refund' },
                        ],
                      },
                      {
                        label: 'Payment Section',
                        options: [
                          { label: 'Paid', value: 'paid' },
                          { label: 'Partial Paid', value: 'partial_paid' },
                        ],
                      },
                    ]}
                  />
                </Form.Item>
                <Form.Item
                  name="currency_code"
                  label="Currency Code"
                  normalize={(value) => value?.toUpperCase()}
                  rules={[{ required: true, message: 'Currency required' }]}
                  style={{ minWidth: 140 }}
                >
                  <Input maxLength={3} />
                </Form.Item>
              </Space>
              <Form.Item name="notes" label="Order Notes">
                <TextArea rows={2} />
              </Form.Item>
            </Space>
          </Card>
        </Form>

        <Card className="border-beam-aurora" style={{ marginBottom: 12 }}>
          <Space direction="vertical" size={8} style={{ width: '100%' }}>
            <Text type="secondary">
              Use the full form to edit voucher header, passengers, flights, visa, hotel, transfer, tour, and service rows.
            </Text>
            <Button
              block
              icon={<ArrowsAltOutlined />}
              onClick={() => editingOrder && navigate(`/orders/${editingOrder.uid}/edit`)}
            >
              Open Full Form
            </Button>
          </Space>
        </Card>
      </Drawer>
    </div>
  );
}
