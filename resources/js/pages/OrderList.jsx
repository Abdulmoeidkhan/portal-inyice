import React, { useEffect, useState } from 'react';
import { Button, Card, DatePicker, Descriptions, Drawer, Form, Grid, Input, Modal, Select, Space, Spin, Tag, Typography } from 'antd';
import { ArrowsAltOutlined, CopyOutlined, EditOutlined, EyeOutlined, FileSearchOutlined, FileTextOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { useNavigate } from 'react-router-dom';
import { message } from '../services/feedback';
import { acquireEditLock, releaseEditLock } from '../services/editLocks';
import { openRoute } from '../services/navigation';
import VoucherSummaryCard from './sales-flow/VoucherSummaryCard';
import Table from '../components/CsvTable';

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
    cancel: 'error',
    invoice: 'purple',
    void: 'default',
    refund_request: 'error',
    refund: 'error',
    partial_refund: 'warning',
    paid: 'success',
    partial_paid: 'warning',
  };

  return colors[status] || 'default';
};

const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const orderFormDefaults = (order) => ({
  customer_id: order?.customer?.id || order?.customer_id,
  status: order?.status || 'order',
  currency_code: order?.currency_code || 'PKR',
  notes: order?.notes || '',
});

const formatStatus = (status) => String(status || 'order').replace(/_/g, ' ').toUpperCase();
const invoiceSectionStatuses = ['invoice', 'void', 'refund', 'partial_refund', 'paid', 'partial_paid'];
const statusFilterOptions = [
  { label: 'Quote', value: 'quote' },
  { label: 'Order', value: 'order' },
  { label: 'Refund Request', value: 'refund_request' },
];
const openOrderStatusOptions = [
  { label: 'Quote', value: 'quote' },
  { label: 'Order', value: 'order' },
  { label: 'Cancel', value: 'cancel' },
];
const orderStatusOptions = [
  {
    label: 'Order Section',
    options: openOrderStatusOptions,
  },
  {
    label: 'Invoice Section',
    options: [
      { label: 'Void', value: 'void' },
    ],
  },
];
const refundRequestStatusOptions = [
  { label: 'Refund Request', value: 'refund_request' },
  { label: 'Refund', value: 'refund' },
  { label: 'Cancel', value: 'cancel' },
];

const hasActiveInvoice = (order) => Boolean(order?.invoice || (Array.isArray(order?.invoices) && order.invoices.length));

const currentUserIsSales = () => {
  try {
    const user = JSON.parse(localStorage.getItem('user') || '{}');
    return user.role === 'sales';
  } catch {
    return false;
  }
};

const countRows = (rows, keys) => (Array.isArray(rows) ? rows.filter((row) => rowHasValue(row, keys)).length : 0);

const getVoucherItemCount = (order) => {
  const meta = order?.meta || {};
  const count =
    countRows(meta.flights, ['gds_pnr', 'pnr', 'flight_no', 'from', 'to', 'date']) +
    countRows(meta.visa, ['passenger_name', 'visa_type', 'visa_no', 'cost', 'profit', 'sales', 'amount']) +
    countRows(meta.hotels, ['hcn', 'city', 'hotel_name', 'check_in', 'check_out', 'cost', 'profit', 'sales', 'amount']) +
    countRows(meta.transfers, ['tn', 'service', 'from_city', 'to_city', 'cost', 'profit', 'sales', 'amount']) +
    countRows(meta.city_tours, ['city', 'title', 'date', 'cost', 'profit', 'sales', 'amount']) +
    countRows(meta.other_services, ['description', 'cost', 'profit', 'sales', 'amount']);

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
  const [duplicateLoadingKey, setDuplicateLoadingKey] = useState('');
  const [customers, setCustomers] = useState([]);
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [cancelConfirmOpen, setCancelConfirmOpen] = useState(false);
  const [pendingCancelValues, setPendingCancelValues] = useState(null);
  const [cancelPassword, setCancelPassword] = useState('');
  const [invoiceOrder, setInvoiceOrder] = useState(null);
  const [invoiceDate, setInvoiceDate] = useState(dayjs());
  const [invoiceLoadingKey, setInvoiceLoadingKey] = useState('');
  const screens = Grid.useBreakpoint();
  const compactActions = !screens.sm;
  const canChangeEditingStatus = !(currentUserIsSales() && invoiceSectionStatuses.includes(editingOrder?.status));
  const visibleStatusFilterOptions = statusFilterOptions;
  const editingStatusOptions = ['refund_request', 'refund'].includes(editingOrder?.status)
    ? refundRequestStatusOptions
    : hasActiveInvoice(editingOrder) || invoiceSectionStatuses.includes(editingOrder?.status)
      ? orderStatusOptions
      : openOrderStatusOptions;

  const showLockAlert = (error, fallback = 'This order is currently locked for editing.') => {
    Modal.warning({
      title: 'Order is being edited',
      content: error?.data?.message || error?.message || fallback,
    });
  };

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

  const fetchOrders = async (page = pagination.current, search = searchTerm, status = statusFilter) => {
    setLoading(true);
    try {
      const params = new URLSearchParams({
        page,
        per_page: pagination.pageSize,
      });

      if (search) {
        params.set('search', search);
      }

      if (status) {
        params.set('status', status);
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
    fetchOrders(1, search, statusFilter);
  };

  const handleStatusFilter = (value) => {
    const status = value || '';
    setStatusFilter(status);
    fetchOrders(1, searchTerm, status);
  };

  useEffect(() => {
    fetchOrders(1);
    loadCustomers();
  }, []);

  const openEditDrawer = async (order) => {
    setSaving(true);
    let acquiredLock = false;
    try {
      await acquireEditLock('order', order.uid);
      acquiredLock = true;

      const response = await fetch(`/api/v1/orders/${order.uid}`, {
        headers: authHeaders(),
      });

      if (!response.ok) {
        throw new Error('Failed to load order for editing');
      }

      const detail = await response.json();
      setEditingOrder(detail);
      form.resetFields();
      form.setFieldsValue(orderFormDefaults(detail));
    } catch (error) {
      if (acquiredLock) {
        releaseEditLock('order', order.uid).catch(() => {});
      }
      if (error.status === 423) {
        showLockAlert(error);
      } else {
        message.error(error.message || 'Failed to load order for editing');
      }
    } finally {
      setSaving(false);
    }
  };

  const closeEditDrawer = () => {
    if (editingOrder?.uid) {
      releaseEditLock('order', editingOrder.uid).catch(() => {});
    }
    setEditingOrder(null);
    setCancelConfirmOpen(false);
    setPendingCancelValues(null);
    setCancelPassword('');
    form.resetFields();
  };

  const saveEditedOrder = async (values, password = null) => {
    if (!editingOrder) {
      return;
    }

    setSaving(true);
    try {
      const payload = {
        ...orderFormDefaults(editingOrder),
        ...form.getFieldsValue(true),
        ...values,
      };
      if (payload.status === 'cancel' && editingOrder.status !== 'cancel') {
        payload.cancel_password = password;
      } else {
        delete payload.cancel_password;
      }

      const response = await fetch(`/api/v1/orders/${editingOrder.uid}`, {
        method: 'PATCH',
        headers: authHeaders(true),
        body: JSON.stringify(payload),
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

  const handleEdit = async () => {
    if (!editingOrder) {
      return;
    }

    try {
      const values = await form.validateFields();
      if (values.status === 'cancel' && editingOrder.status !== 'cancel') {
        setPendingCancelValues(values);
        setCancelPassword('');
        setCancelConfirmOpen(true);
        return;
      }

      saveEditedOrder(values);
    } catch (error) {
      if (!error?.errorFields) {
        message.error(error.message || 'Failed to update order');
      }
    }
  };

  const confirmCancelOrder = () => {
    if (!cancelPassword) {
      message.error('Enter your login password to cancel this order');
      return;
    }

    saveEditedOrder(pendingCancelValues, cancelPassword);
  };

  const duplicateOrder = (order) => {
    Modal.confirm({
      title: 'Duplicate this order?',
      content: `Create a new editable order copied from ${order.order_number}.`,
      okText: 'Duplicate',
      cancelButtonProps: { danger: true },
      onOk: async () => {
        setDuplicateLoadingKey(order.uid);
        try {
          const response = await fetch(`/api/v1/orders/${order.uid}/duplicate`, {
            method: 'POST',
            headers: authHeaders(),
          });
          const data = await response.json();

          if (!response.ok) {
            throw new Error(data?.message || data?.error || 'Failed to duplicate order');
          }

          message.success(data.message || 'Order duplicated');
          fetchOrders(pagination.current);
          if (data.order?.uid) {
            navigate(`/orders/${data.order.uid}/edit`);
          }
        } catch (error) {
          message.error(error.message || 'Failed to duplicate order');
        } finally {
          setDuplicateLoadingKey('');
        }
      },
    });
  };

  const canCreateInvoice = (order) => {
    if (!order) {
      return false;
    }

    return !order.invoice && ['quote', 'order', 'confirm', 'refund_request'].includes(order.status);
  };

  const openInvoiceModal = (order) => {
    setInvoiceOrder(order);
    setInvoiceDate(dayjs());
  };

  const closeInvoiceModal = () => {
    setInvoiceOrder(null);
    setInvoiceDate(dayjs());
  };

  const createInvoice = async () => {
    if (!invoiceOrder || !invoiceDate) {
      message.error('Choose an invoice date');
      return;
    }

    setInvoiceLoadingKey(invoiceOrder.uid);
    try {
      const response = await fetch('/api/v1/invoices/create-from-order', {
        method: 'POST',
        headers: authHeaders(true),
        body: JSON.stringify({
          order_id: invoiceOrder.id,
          invoice_date: invoiceDate.format('YYYY-MM-DD'),
        }),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data?.message || data?.error || 'Failed to create invoice');
      }

      message.success(`Invoice ${data.invoice?.invoice_number || ''} created`);
      closeInvoiceModal();
      fetchOrders(pagination.current);
      if (selectedOrder?.uid === invoiceOrder.uid) {
        fetchOrderDetail(invoiceOrder);
      }
    } catch (error) {
      message.error(error.message || 'Failed to create invoice');
    } finally {
      setInvoiceLoadingKey('');
    }
  };

  const renderOrderActions = (record, { showLabels = !compactActions } = {}) => {
    const isRefundRequestOrder = record?.status === 'refund_request';

    return (
      <Space className={showLabels ? 'mobile-detail-actions' : undefined} size={compactActions ? 6 : 8} wrap={false}>
        <Button
          size="small"
          type={isRefundRequestOrder ? 'primary' : 'default'}
          danger={isRefundRequestOrder}
          icon={<FileTextOutlined />}
          disabled={!canCreateInvoice(record)}
          loading={invoiceLoadingKey === record.uid}
          onClick={() => openInvoiceModal(record)}
        >
          {showLabels ? (isRefundRequestOrder ? 'Refund' : 'Invoice') : null}
        </Button>
      <Button
        size="small"
        icon={<EditOutlined />}
        onClick={() => openEditDrawer(record)}
      >
        {showLabels ? 'Edit' : null}
      </Button>
      <Button
        size="small"
        icon={<FileSearchOutlined />}
        onClick={(event) => openRoute(navigate, `/orders/${record.uid}/quotation`, event)}
      >
        {showLabels ? 'Quotation' : null}
      </Button>
      <Button
        size="small"
        icon={<EyeOutlined />}
        onClick={(event) => openRoute(navigate, `/orders/${record.uid}/voucher`, event)}
      >
        {showLabels ? 'Voucher' : null}
      </Button>
      <Button
        size="small"
        icon={<CopyOutlined />}
        loading={duplicateLoadingKey === record.uid}
        onClick={() => duplicateOrder(record)}
      >
        {showLabels ? 'Duplicate' : null}
      </Button>
      </Space>
    );
  };

  const columns = [
    {
      title: 'Order #',
      dataIndex: 'order_number',
      key: 'order_number',
      width: compactActions ? 170 : 240,
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
      render: (amount, record) => {
        const value = Number(amount || 0);
        return <Text type={value < 0 ? 'danger' : undefined}>{record.currency_code || ''} {money(value)}</Text>;
      },
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
      width: compactActions ? 180 : 360,
      align: compactActions ? 'center' : undefined,
      fixed: compactActions ? undefined : 'right',
      onCell: () => ({ onClick: (event) => event.stopPropagation() }),
      render: (_, record) => renderOrderActions(record),
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
          <Select
            className="responsive-control"
            allowClear
            placeholder="Filter by status"
            value={statusFilter || undefined}
            options={visibleStatusFilterOptions}
            onChange={handleStatusFilter}
            style={{ width: 220 }}
          />
        </div>
        <Spin spinning={loading}>
          <Table
            scroll={{ x: 'max-content' }}
            columns={columns}
            dataSource={orders}
            rowKey="id"
            onRow={(record) => compactActions ? {
              className: 'mobile-row-clickable',
              onClick: () => fetchOrderDetail(record),
            } : {}}
            pagination={{
              current: pagination.current,
              pageSize: pagination.pageSize,
              total: pagination.total,
              onChange: (page) => fetchOrders(page, searchTerm, statusFilter),
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
                <Descriptions.Item label="Passenger">{getFirstPassengerName(selectedOrder)}</Descriptions.Item>
                <Descriptions.Item label="Booking Ref">{selectedOrder.booking_reference || '-'}</Descriptions.Item>
                <Descriptions.Item label="Status">
                  <Tag color={getStatusColor(selectedOrder.status)}>{formatStatus(selectedOrder.status)}</Tag>
                </Descriptions.Item>
                <Descriptions.Item label="Total">
                  <Text type={Number(selectedOrder.total_amount || 0) < 0 ? 'danger' : undefined}>
                    {selectedOrder.currency_code || ''} {money(selectedOrder.total_amount)}
                  </Text>
                </Descriptions.Item>
                <Descriptions.Item label="Items">{getVoucherItemCount(selectedOrder)}</Descriptions.Item>
                <Descriptions.Item label="Created">{selectedOrder.created_at ? new Date(selectedOrder.created_at).toLocaleDateString() : '-'}</Descriptions.Item>
                <Descriptions.Item label="Notes">{selectedOrder.notes || '-'}</Descriptions.Item>
              </Descriptions>

              <div>
                <Title level={4}>Order Items</Title>
                <Space orientation="vertical" style={{ width: '100%' }}>
                  {(selectedOrder.items || []).map((item) => (
                    <Card key={item.id} size="small">
                      <Space orientation="vertical">
                        <Text>{item.description}</Text>
                        <Text type="secondary">{money(item.total_price)}</Text>
                      </Space>
                    </Card>
                  ))}
                </Space>
              </div>
              {compactActions && (
                <div>
                  <Title level={4}>Actions</Title>
                  {renderOrderActions(selectedOrder, { showLabels: true })}
                </div>
              )}
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
            <Button danger onClick={closeEditDrawer}>Cancel</Button>
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
                    disabled={!canChangeEditingStatus}
                    options={editingStatusOptions}
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

        {editingOrder?.meta && <VoucherSummaryCard voucher={editingOrder.meta} />}

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

      <Modal
        title="Confirm Order Cancellation"
        open={cancelConfirmOpen}
        okText="Cancel Order"
        cancelButtonProps={{ danger: true }}
        okButtonProps={{ danger: true, loading: saving }}
        confirmLoading={saving}
        onOk={confirmCancelOrder}
        onCancel={() => {
          setCancelConfirmOpen(false);
          setPendingCancelValues(null);
          setCancelPassword('');
        }}
      >
        <Space direction="vertical" size={8} style={{ width: '100%' }}>
          <Text type="secondary">Enter your login password to confirm this cancellation.</Text>
          <Input.Password
            autoComplete="current-password"
            value={cancelPassword}
            onChange={(event) => setCancelPassword(event.target.value)}
            onPressEnter={confirmCancelOrder}
          />
        </Space>
      </Modal>

      <Modal
        title={invoiceOrder ? `Create ${invoiceOrder.status === 'refund_request' ? 'refund' : 'invoice'} for ${invoiceOrder.order_number}` : 'Create Invoice'}
        open={Boolean(invoiceOrder)}
        okText={invoiceOrder?.status === 'refund_request' ? 'Create Refund' : 'Create Invoice'}
        cancelButtonProps={{ danger: true }}
        confirmLoading={Boolean(invoiceLoadingKey)}
        okButtonProps={{ disabled: !invoiceDate }}
        onOk={createInvoice}
        onCancel={closeInvoiceModal}
        destroyOnClose
      >
        <Space direction="vertical" size={8} style={{ width: '100%' }}>
          <Text type="secondary">Choose the {invoiceOrder?.status === 'refund_request' ? 'refund' : 'invoice'} date used for invoice registers and reports.</Text>
          <DatePicker
            autoFocus
            allowClear={false}
            value={invoiceDate}
            format="YYYY-MM-DD"
            onChange={(value) => setInvoiceDate(value)}
            style={{ width: '100%' }}
          />
        </Space>
      </Modal>
    </div>
  );
}
