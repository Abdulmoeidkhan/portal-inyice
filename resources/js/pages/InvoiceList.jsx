import React, { useEffect, useState } from 'react';
import { Button, Card, Descriptions, Drawer, Dropdown, Grid, Input, InputNumber, Modal, Select, Space, Spin, Tag, Typography } from 'antd';
import { CopyOutlined, DownOutlined, EditOutlined, EyeOutlined, FileSearchOutlined, FileTextOutlined, PercentageOutlined, RollbackOutlined, ShareAltOutlined } from '@ant-design/icons';
import { message } from '../services/feedback';
import { useNavigate } from 'react-router-dom';
import { dateOnly } from '../services/dateFormat';
import { acquireEditLock } from '../services/editLocks';
import Table from '../components/CsvTable';

const { Title, Paragraph, Text } = Typography;
const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const canEditInvoiceOrder = () => {
  try {
    const user = JSON.parse(localStorage.getItem('user') || '{}');
    return ['admin', 'owner', 'accounts'].includes(user.role);
  } catch {
    return false;
  }
};
const canChangeBookedBy = () => {
  try {
    const user = JSON.parse(localStorage.getItem('user') || '{}');
    return ['admin', 'owner'].includes(user.role);
  } catch {
    return false;
  }
};

export default function InvoiceList() {
  const navigate = useNavigate();
  const [invoices, setInvoices] = useState([]);
  const [loading, setLoading] = useState(false);
  const [pagination, setPagination] = useState({ current: 1, pageSize: 20, total: 0 });
  const [statusFilter, setStatusFilter] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const [refundInvoice, setRefundInvoice] = useState(null);
  const [refundAmount, setRefundAmount] = useState(0);
  const [refundReason, setRefundReason] = useState('');
  const [discountInvoice, setDiscountInvoice] = useState(null);
  const [discountAmount, setDiscountAmount] = useState(0);
  const [discountReason, setDiscountReason] = useState('');
  const [deleteInvoice, setDeleteInvoice] = useState(null);
  const [deletePassword, setDeletePassword] = useState('');
  const [deleteInvoiceNumber, setDeleteInvoiceNumber] = useState('');
  const [actionLoadingKey, setActionLoadingKey] = useState('');
  const [selectedInvoice, setSelectedInvoice] = useState(null);
  const [staff, setStaff] = useState([]);
  const [bookedByInvoice, setBookedByInvoice] = useState(null);
  const [bookedByUserId, setBookedByUserId] = useState(null);
  const screens = Grid.useBreakpoint();
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
  const canEditInvoices = canEditInvoiceOrder();
  const canUpdateBookedBy = canChangeBookedBy();
  const compactActions = !screens.sm;
  const staffOptions = staff.map((user) => ({
    value: user.id,
    label: `${user.name}${user.email ? ` (${user.email})` : ''}`,
  }));

  const fetchInvoices = async (page = pagination.current, search = searchTerm, status = statusFilter) => {
    setLoading(true);
    try {
      const params = new URLSearchParams({
        page,
        per_page: pagination.pageSize,
      });

      if (status) {
        params.set('status', status);
      }

      if (search) {
        params.set('search', search);
      }

      const response = await fetch(`/api/v1/invoices?${params}`, {
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
        },
      });

      if (!response.ok) {
        throw new Error('Failed to fetch invoices');
      }

      const data = await response.json();
      setInvoices(data.data || []);
      setPagination((prev) => ({
        ...prev,
        current: page,
        total: data.total || 0,
      }));
    } catch (error) {
      message.error('Failed to load invoices: ' + error.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchInvoices(1, searchTerm, statusFilter);
  }, [statusFilter]);

  useEffect(() => {
    if (!canUpdateBookedBy) return;

    const fetchStaff = async () => {
      try {
        const response = await fetch('/api/v1/staff', {
          headers: {
            Authorization: `Bearer ${token}`,
            Accept: 'application/json',
          },
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data?.message || 'Could not load staff');
        setStaff(data || []);
      } catch (error) {
        message.error(error.message || 'Could not load staff');
      }
    };

    fetchStaff();
  }, [canUpdateBookedBy, token]);

  const handleSearch = (value) => {
    const search = value.trim();
    setSearchTerm(search);
    fetchInvoices(1, search, statusFilter);
  };

  const getStatusColor = (status) => {
    const colors = {
      draft: 'default',
      issued: 'processing',
      sent: 'processing',
      partial_paid: 'warning',
      paid: 'success',
      overdue: 'error',
      void: 'default',
      cancel: 'default',
      refund: 'error',
      partial_refund: 'warning',
    };

    return colors[status] || 'default';
  };

  const request = async (endpoint, method = 'POST', body, loadingKey = 'action') => {
    setActionLoadingKey(loadingKey);
    try {
      const response = await fetch(endpoint, { method, headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) }, body: body ? JSON.stringify(body) : undefined });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || data.message || 'Action failed');
      return data;
    } catch (error) { message.error(error.message); return null; } finally { setActionLoadingKey(''); }
  };

  const openOrderEdit = async (invoice) => {
    if (!invoice.order?.uid) return;

    setActionLoadingKey(`${invoice.uid}:edit`);
    try {
      await acquireEditLock('order', invoice.order.uid);
      navigate(`/orders/${invoice.order.uid}/edit`);
    } catch (error) {
      if (error.status === 423) {
        Modal.warning({
          title: 'Order is being edited',
          content: error?.data?.message || error.message || 'This order is currently locked for editing.',
        });
      } else {
        message.error(error.message || 'Unable to open order for editing');
      }
    } finally {
      setActionLoadingKey('');
    }
  };

  const refund = async (invoice, amount) => {
    const data = await request('/api/v1/payments/customer/refund', 'POST', { invoice_uid: invoice.uid, amount, reason: refundReason || undefined }, `${invoice.uid}:refund`);
    if (data) { message.success('Refund recorded'); setRefundInvoice(null); setRefundReason(''); fetchInvoices(); }
  };

  const openDiscount = (invoice) => {
    setDiscountInvoice(invoice);
    setDiscountAmount(0);
    setDiscountReason('');
  };

  const applyDiscount = async () => {
    if (!discountInvoice) return;

    const data = await request(`/api/v1/invoices/${discountInvoice.uid}/discount`, 'PATCH', {
      amount: discountAmount,
      reason: discountReason || undefined,
    }, `${discountInvoice.uid}:discount`);

    if (data) {
      message.success('Discount added');
      setDiscountInvoice(null);
      setDiscountAmount(0);
      setDiscountReason('');
      fetchInvoices();
    }
  };

  const voidInvoice = async (invoice) => {
    const data = await request(`/api/v1/invoices/${invoice.uid}/void`, 'PATCH', undefined, `${invoice.uid}:void`);
    if (data) { message.success('Invoice voided'); fetchInvoices(); }
  };

  const shareInvoice = async (invoice) => {
    const data = await request(`/api/v1/invoices/${invoice.uid}/share`, 'POST', undefined, `${invoice.uid}:share`);
    if (data && navigator.clipboard?.writeText) { await navigator.clipboard.writeText(data.share_url); message.success('Shareable invoice link copied'); }
    else if (data) window.prompt('Copy this shareable invoice link:', data.share_url);
  };

  const createRefundRequest = async (invoice) => {
    const data = await request(`/api/v1/orders/${invoice.order.uid}/refund-request`, 'POST', undefined, `${invoice.uid}:refund-request`);
    if (data) {
      message.success(`Refund request ${data.order.order_number} created`);
      fetchInvoices();
    }
  };

  const duplicateInvoiceOrder = async (invoice) => {
    if (!invoice.order?.uid) return;

    const data = await request(`/api/v1/orders/${invoice.order.uid}/duplicate`, 'POST', undefined, `${invoice.uid}:duplicate`);
    if (data) {
      message.success(data.message || 'Order duplicated');
      fetchInvoices();
      if (data.order?.uid) {
        navigate(`/orders/${data.order.uid}/edit`);
      }
    }
  };

  const openBookedByModal = (invoice) => {
    setBookedByInvoice(invoice);
    setBookedByUserId(invoice.order?.created_by?.id || invoice.order?.created_by_user_id || null);
  };

  const updateBookedBy = async () => {
    if (!bookedByInvoice?.order?.uid || !bookedByUserId) return;

    const data = await request(`/api/v1/orders/${bookedByInvoice.order.uid}/booked-by`, 'PATCH', {
      user_id: bookedByUserId,
    }, `${bookedByInvoice.uid}:booked-by`);

    if (data) {
      message.success(data.message || 'Booked by updated');
      setBookedByInvoice(null);
      setBookedByUserId(null);
      fetchInvoices();
    }
  };

  const openDeleteInvoice = (invoice) => {
    setDeleteInvoice(invoice);
    setDeletePassword('');
    setDeleteInvoiceNumber('');
  };

  const cancelInvoice = async () => {
    if (!deleteInvoice) return;

    const data = await request(`/api/v1/invoices/${deleteInvoice.uid}/cancel`, 'PATCH', {
      password: deletePassword,
      invoice_number: deleteInvoiceNumber,
    }, `${deleteInvoice.uid}:delete`);

    if (data) {
      message.success('Invoice deleted');
      setDeleteInvoice(null);
      setDeletePassword('');
      setDeleteInvoiceNumber('');
      fetchInvoices();
    }
  };

  const renderInvoiceActions = (invoice, { showLabels = !compactActions } = {}) => (
    <Space className={showLabels ? 'mobile-detail-actions' : undefined} size={compactActions ? 6 : 8} wrap={showLabels}>
      {invoice.is_refund_order ? (
        <>
          <Button size="small" type="primary" icon={<EyeOutlined />} loading={actionLoadingKey === `${invoice.uid}:edit`} onClick={() => openOrderEdit(invoice)}>
            {showLabels ? 'Open Order' : null}
          </Button>
          <Button size="small" icon={<CopyOutlined />} loading={actionLoadingKey === `${invoice.uid}:duplicate`} onClick={() => duplicateInvoiceOrder(invoice)}>
            {showLabels ? 'Duplicate' : null}
          </Button>
          {canUpdateBookedBy && (
            <Button size="small" icon={<EditOutlined />} onClick={() => openBookedByModal(invoice)}>
              {showLabels ? 'Booked By' : null}
            </Button>
          )}
        </>
      ) : (
        <>
          <Button size="small" type="primary" icon={<FileTextOutlined />} onClick={() => navigate(`/invoices/${invoice.uid}`)}>
            {showLabels ? 'Invoice' : null}
          </Button>
          <Button size="small" icon={<EyeOutlined />} onClick={() => navigate(`/invoices/${invoice.uid}?view=detailed`)}>
            {showLabels ? 'Detailed' : null}
          </Button>
        </>
      )}
      {invoice.order?.uid && (
        <Button size="small" icon={<FileSearchOutlined />} onClick={() => navigate(`/orders/${invoice.order.uid}/voucher`)}>
          {showLabels ? 'Voucher' : null}
        </Button>
      )}
      {!invoice.is_refund_order && (
        <Dropdown menu={{ items: [
          ...(canEditInvoices ? [
            { key: 'pay', label: 'Record payment', disabled: Number(invoice.outstanding_amount || 0) <= 0 || ['void', 'cancel'].includes(invoice.status), onClick: () => navigate(`/payments?invoice=${invoice.uid}`) },
            { key: 'discount', icon: <PercentageOutlined />, label: 'Add discount', disabled: Number(invoice.outstanding_amount || 0) <= 0 || ['paid', 'void', 'cancel'].includes(invoice.status), onClick: () => openDiscount(invoice) },
          ] : []),
          { key: 'refund-request', icon: <RollbackOutlined />, label: 'Create refund request', disabled: !invoice.order?.uid || ['void', 'cancel'].includes(invoice.status), onClick: () => createRefundRequest(invoice) },
          { key: 'duplicate', icon: <CopyOutlined />, label: 'Duplicate order', disabled: !invoice.order?.uid, onClick: () => duplicateInvoiceOrder(invoice) },
          ...(canUpdateBookedBy ? [
            { key: 'booked-by', icon: <EditOutlined />, label: 'Change booked by', disabled: !invoice.order?.uid, onClick: () => openBookedByModal(invoice) },
          ] : []),
          ...(canEditInvoices ? [
            { key: 'partial-refund', label: 'Partial refund', disabled: Number(invoice.total_amount) - Number(invoice.outstanding_amount) <= 0 || ['void', 'cancel'].includes(invoice.status), onClick: () => { setRefundInvoice(invoice); setRefundAmount(0); } },
            { key: 'full-refund', label: 'Full refund', disabled: Number(invoice.total_amount) - Number(invoice.outstanding_amount) <= 0 || ['void', 'cancel'].includes(invoice.status), onClick: () => Modal.confirm({ title: 'Refund all paid amount?', content: `${invoice.currency_code} ${money(Number(invoice.total_amount) - Number(invoice.outstanding_amount))}`, okText: 'Refund', okButtonProps: { danger: true }, onOk: () => refund(invoice, Number(invoice.total_amount) - Number(invoice.outstanding_amount)) }) },
          ] : []),
          { key: 'share', icon: <ShareAltOutlined />, label: 'Copy share link', onClick: () => shareInvoice(invoice) },
          ...(canEditInvoices ? [
            { type: 'divider' },
            { key: 'edit', icon: <EditOutlined />, label: 'Edit order', disabled: !invoice.order?.uid, onClick: () => openOrderEdit(invoice) },
            { key: 'delete', danger: true, label: 'Delete invoice', onClick: () => openDeleteInvoice(invoice) },
            { key: 'void', danger: true, label: 'Void invoice', disabled: !['draft', 'issued', 'sent'].includes(invoice.status), onClick: () => Modal.confirm({ title: 'Void this invoice?', content: 'This action marks the invoice as void.', okText: 'Void', okButtonProps: { danger: true }, onOk: () => voidInvoice(invoice) }) },
          ] : []),
        ] }}>
          <Button size="small" icon={<DownOutlined />} loading={actionLoadingKey.startsWith(`${invoice.uid}:`)}>
            {showLabels ? 'Actions' : null}
          </Button>
        </Dropdown>
      )}
      {invoice.is_refund_order && canEditInvoices && (
        <Button size="small" icon={<EditOutlined />} loading={actionLoadingKey === `${invoice.uid}:edit`} onClick={() => openOrderEdit(invoice)}>
          {showLabels ? 'Edit' : null}
        </Button>
      )}
    </Space>
  );

  const columns = [
    {
      title: 'Invoice #',
      dataIndex: 'invoice_number',
      key: 'invoice_number',
      width: 150,
    },
    {
      title: 'Customer',
      dataIndex: ['customer', 'name'],
      key: 'customer',
      width: 220,
      render: (value) => value || '-',
    },
    {
      title: 'Order #',
      dataIndex: ['order', 'order_number'],
      key: 'order_number',
      width: 160,
      render: (value) => value || '-',
    },
    {
      title: 'Booked By',
      key: 'booked_by',
      width: 170,
      render: (_, invoice) => invoice.order?.created_by?.name || '-',
    },
    {
      title: 'Amount',
      dataIndex: 'total_amount',
      key: 'total_amount',
      align: 'right',
      render: (amount, record) => `${record.currency_code || ''} ${money(amount)}`,
    },
    {
      title: 'Outstanding',
      dataIndex: 'outstanding_amount',
      key: 'outstanding_amount',
      align: 'right',
      render: (amount, record) => `${record.currency_code || ''} ${money(amount)}`,
    },
    {
      title: 'Status',
      dataIndex: 'status',
      key: 'status',
      width: 130,
      render: (status) => <Tag color={getStatusColor(status)}>{String(status || '-').toUpperCase()}</Tag>,
    },
    {
      title: 'Invoice Date',
      dataIndex: 'invoice_date',
      key: 'invoice_date',
      width: 130,
      render: dateOnly,
    },
    {
      title: 'Due Date',
      dataIndex: 'due_date',
      key: 'due_date',
      width: 130,
      render: dateOnly,
    },
    {
      title: 'Action',
      key: 'action',
      width: compactActions ? 172 : 330,
      align: compactActions ? 'center' : undefined,
      fixed: compactActions ? undefined : 'right',
      onCell: () => ({ onClick: (event) => event.stopPropagation() }),
      render: (_, invoice) => renderInvoiceActions(invoice),
    },
  ];

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2} style={{ margin: 0 }}>Invoices</Title>
        <Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>
          Read-only invoice register generated from orders.
        </Paragraph>
      </div>

      <Card className="border-beam-aurora">
        <Space className="list-toolbar" wrap>
          <Input.Search
            className="responsive-search"
            allowClear
            enterButton="Search"
            placeholder="Search invoice, order, or customer"
            onSearch={handleSearch}
            style={{ width: 380 }}
          />
          <Select
            className="responsive-control"
            style={{ width: 220 }}
            placeholder="Filter by status"
            allowClear
            onChange={(value) => setStatusFilter(value || '')}
            options={[
              { label: 'Draft', value: 'draft' },
              { label: 'Issued', value: 'issued' },
              { label: 'Sent', value: 'sent' },
              { label: 'Partial Paid', value: 'partial_paid' },
              { label: 'Paid', value: 'paid' },
              { label: 'Overdue', value: 'overdue' },
              { label: 'Void', value: 'void' },
              { label: 'Partial Refund', value: 'partial_refund' },
              { label: 'Refund', value: 'refund' },
            ]}
          />
        </Space>

        <Spin spinning={loading}>
          <Table
            scroll={{ x: 'max-content' }}
            columns={columns}
            dataSource={invoices}
            rowKey="id"
            onRow={(invoice) => compactActions ? {
              className: 'mobile-row-clickable',
              onClick: () => setSelectedInvoice(invoice),
            } : {}}
            pagination={{
              current: pagination.current,
              pageSize: pagination.pageSize,
              total: pagination.total,
              onChange: (page) => fetchInvoices(page),
            }}
          />
        </Spin>
      </Card>
      <Drawer
        title={selectedInvoice?.invoice_number || 'Invoice Detail'}
        open={Boolean(selectedInvoice)}
        onClose={() => setSelectedInvoice(null)}
        size="large"
      >
        {selectedInvoice && (
          <Space direction="vertical" size="large" style={{ width: '100%' }}>
            <Descriptions bordered column={1} size="small">
              <Descriptions.Item label="Invoice #">{selectedInvoice.invoice_number || '-'}</Descriptions.Item>
              <Descriptions.Item label="Customer">{selectedInvoice.customer?.name || '-'}</Descriptions.Item>
              <Descriptions.Item label="Order #">{selectedInvoice.order?.order_number || '-'}</Descriptions.Item>
              <Descriptions.Item label="Booked By">{selectedInvoice.order?.created_by?.name || '-'}</Descriptions.Item>
              <Descriptions.Item label="Status">
                <Tag color={getStatusColor(selectedInvoice.status)}>{String(selectedInvoice.status || '-').toUpperCase()}</Tag>
              </Descriptions.Item>
              <Descriptions.Item label="Total">
                <Text>{selectedInvoice.currency_code || ''} {money(selectedInvoice.total_amount)}</Text>
              </Descriptions.Item>
              <Descriptions.Item label="Outstanding">
                <Text>{selectedInvoice.currency_code || ''} {money(selectedInvoice.outstanding_amount)}</Text>
              </Descriptions.Item>
              <Descriptions.Item label="Invoice Date">{dateOnly(selectedInvoice.invoice_date)}</Descriptions.Item>
              <Descriptions.Item label="Due Date">{dateOnly(selectedInvoice.due_date)}</Descriptions.Item>
            </Descriptions>
            <div>
              <Title level={4}>Actions</Title>
              {renderInvoiceActions(selectedInvoice, { showLabels: true })}
            </div>
          </Space>
        )}
      </Drawer>
      <Modal title={`Partial refund · ${refundInvoice?.invoice_number || ''}`} open={!!refundInvoice} onCancel={() => setRefundInvoice(null)} onOk={() => refund(refundInvoice, refundAmount)} okText="Record refund" confirmLoading={actionLoadingKey === `${refundInvoice?.uid}:refund`} okButtonProps={{ danger: true, disabled: refundAmount <= 0 }}>
        <Typography.Paragraph>Refundable: {refundInvoice?.currency_code} {money(Number(refundInvoice?.total_amount || 0) - Number(refundInvoice?.outstanding_amount || 0))}</Typography.Paragraph>
        <Typography.Text strong>Amount</Typography.Text>
        <InputNumber style={{ width: '100%', marginBottom: 12 }} min={0.01} max={Math.max(0, Number(refundInvoice?.total_amount || 0) - Number(refundInvoice?.outstanding_amount || 0))} precision={2} value={refundAmount} onChange={(value) => setRefundAmount(Number(value || 0))} />
        <Typography.Text strong>Reason</Typography.Text>
        <Input.TextArea maxLength={500} value={refundReason} onChange={(event) => setRefundReason(event.target.value)} />
      </Modal>
      <Modal
        title={`Add discount · ${discountInvoice?.invoice_number || ''}`}
        open={!!discountInvoice}
        onCancel={() => setDiscountInvoice(null)}
        onOk={applyDiscount}
        okText="Add discount"
        confirmLoading={actionLoadingKey === `${discountInvoice?.uid}:discount`}
        okButtonProps={{ disabled: discountAmount <= 0 || discountAmount > Number(discountInvoice?.outstanding_amount || 0) }}
      >
        <Typography.Paragraph>Available balance: {discountInvoice?.currency_code} {money(discountInvoice?.outstanding_amount)}</Typography.Paragraph>
        <Typography.Text strong>Discount amount</Typography.Text>
        <InputNumber
          style={{ width: '100%', marginBottom: 12 }}
          min={0.01}
          max={Math.max(0, Number(discountInvoice?.outstanding_amount || 0))}
          precision={2}
          value={discountAmount}
          onChange={(value) => setDiscountAmount(Number(value || 0))}
        />
        <Typography.Text strong>Reason</Typography.Text>
        <Input.TextArea maxLength={500} value={discountReason} onChange={(event) => setDiscountReason(event.target.value)} />
      </Modal>
      <Modal
        title={`Change booked by · ${bookedByInvoice?.invoice_number || ''}`}
        open={!!bookedByInvoice}
        onCancel={() => {
          setBookedByInvoice(null);
          setBookedByUserId(null);
        }}
        onOk={updateBookedBy}
        okText="Save"
        confirmLoading={actionLoadingKey === `${bookedByInvoice?.uid}:booked-by`}
        okButtonProps={{ disabled: !bookedByUserId }}
      >
        <Typography.Text strong>Booked By</Typography.Text>
        <Select
          showSearch
          optionFilterProp="label"
          value={bookedByUserId || undefined}
          options={staffOptions}
          placeholder="Select staff"
          onChange={setBookedByUserId}
          style={{ width: '100%', marginTop: 8 }}
        />
      </Modal>
      <Modal
        title={`Delete invoice · ${deleteInvoice?.invoice_number || ''}`}
        open={!!deleteInvoice}
        onCancel={() => setDeleteInvoice(null)}
        onOk={cancelInvoice}
        okText="Delete"
        confirmLoading={actionLoadingKey === `${deleteInvoice?.uid}:delete`}
        okButtonProps={{
          danger: true,
          disabled: !deletePassword || deleteInvoiceNumber !== deleteInvoice?.invoice_number,
        }}
      >
        <Typography.Paragraph type="secondary">
          This will mark the invoice as cancelled and remove it from the invoices table.
        </Typography.Paragraph>
        <Typography.Text strong>Invoice Number</Typography.Text>
        <Input
          style={{ marginBottom: 12 }}
          value={deleteInvoiceNumber}
          onChange={(event) => setDeleteInvoiceNumber(event.target.value)}
        />
        <Typography.Text strong>Password</Typography.Text>
        <Input.Password
          value={deletePassword}
          onChange={(event) => setDeletePassword(event.target.value)}
        />
      </Modal>
    </div>
  );
}
