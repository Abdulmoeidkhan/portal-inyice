import React, { useEffect, useState } from 'react';
import { Button, Card, Dropdown, Grid, Input, InputNumber, Modal, Select, Space, Spin, Table, Tag, Typography } from 'antd';
import { DownOutlined, EditOutlined, EyeOutlined, FileSearchOutlined, FileTextOutlined, PercentageOutlined, RollbackOutlined, ShareAltOutlined } from '@ant-design/icons';
import { message } from '../services/feedback';
import { useNavigate } from 'react-router-dom';
import { dateOnly } from '../services/dateFormat';

const { Title, Paragraph } = Typography;
const money = (value) => Number(value || 0).toFixed(2);
const canEditInvoiceOrder = () => {
  try {
    const user = JSON.parse(localStorage.getItem('user') || '{}');
    return ['admin', 'owner', 'accounts'].includes(user.role);
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
  const screens = Grid.useBreakpoint();
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
  const canEditInvoices = canEditInvoiceOrder();
  const compactActions = !screens.sm;

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
      title: 'Amount',
      dataIndex: 'total_amount',
      key: 'total_amount',
      align: 'right',
      render: (amount, record) => `${record.currency_code || ''} ${Number(amount || 0).toFixed(2)}`,
    },
    {
      title: 'Outstanding',
      dataIndex: 'outstanding_amount',
      key: 'outstanding_amount',
      align: 'right',
      render: (amount, record) => `${record.currency_code || ''} ${Number(amount || 0).toFixed(2)}`,
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
      render: (_, invoice) => (
        <Space size={compactActions ? 6 : 8} wrap={false}>
          {invoice.is_refund_order ? (
            <Button size="small" type="primary" icon={<EyeOutlined />} onClick={() => navigate(`/orders/${invoice.order.uid}/edit`)}>
              {compactActions ? null : 'Open Order'}
            </Button>
          ) : (
            <>
              <Button size="small" type="primary" icon={<FileTextOutlined />} onClick={() => navigate(`/invoices/${invoice.uid}`)}>
                {compactActions ? null : 'Invoice'}
              </Button>
              <Button size="small" icon={<EyeOutlined />} onClick={() => navigate(`/invoices/${invoice.uid}?view=detailed`)}>
                {compactActions ? null : 'Detailed'}
              </Button>
            </>
          )}
          {invoice.order?.uid && (
            <Button size="small" icon={<FileSearchOutlined />} onClick={() => navigate(`/orders/${invoice.order.uid}/voucher`)}>
              {compactActions ? null : 'Voucher'}
            </Button>
          )}
          {!invoice.is_refund_order && <Dropdown menu={{ items: [
            { key: 'pay', label: 'Record payment', disabled: Number(invoice.outstanding_amount || 0) <= 0 || invoice.status === 'void', onClick: () => navigate(`/payments?invoice=${invoice.uid}`) },
            { key: 'discount', icon: <PercentageOutlined />, label: 'Add discount', disabled: Number(invoice.outstanding_amount || 0) <= 0 || ['paid', 'void', 'cancel'].includes(invoice.status), onClick: () => openDiscount(invoice) },
            { key: 'refund-request', icon: <RollbackOutlined />, label: 'Create refund request', disabled: !invoice.order?.uid || ['void', 'cancel'].includes(invoice.status), onClick: () => createRefundRequest(invoice) },
            { key: 'partial-refund', label: 'Partial refund', disabled: Number(invoice.total_amount) - Number(invoice.outstanding_amount) <= 0 || invoice.status === 'void', onClick: () => { setRefundInvoice(invoice); setRefundAmount(0); } },
            { key: 'full-refund', label: 'Full refund', disabled: Number(invoice.total_amount) - Number(invoice.outstanding_amount) <= 0 || invoice.status === 'void', onClick: () => Modal.confirm({ title: 'Refund all paid amount?', content: `${invoice.currency_code} ${money(Number(invoice.total_amount) - Number(invoice.outstanding_amount))}`, okText: 'Refund', okButtonProps: { danger: true }, onOk: () => refund(invoice, Number(invoice.total_amount) - Number(invoice.outstanding_amount)) }) },
            { key: 'share', icon: <ShareAltOutlined />, label: 'Copy share link', onClick: () => shareInvoice(invoice) },
            ...(canEditInvoices ? [
              { type: 'divider' },
              { key: 'edit', icon: <EditOutlined />, label: 'Edit order', disabled: !invoice.order?.uid, onClick: () => navigate(`/orders/${invoice.order.uid}/edit`) },
              { key: 'delete', danger: true, label: 'Delete invoice', onClick: () => openDeleteInvoice(invoice) },
            ] : []),
            { type: 'divider' },
            { key: 'void', danger: true, label: 'Void invoice', disabled: !['draft', 'issued', 'sent'].includes(invoice.status), onClick: () => Modal.confirm({ title: 'Void this invoice?', content: 'This action marks the invoice as void.', okText: 'Void', okButtonProps: { danger: true }, onOk: () => voidInvoice(invoice) }) },
          ] }}><Button size="small" icon={<DownOutlined />} loading={actionLoadingKey.startsWith(`${invoice.uid}:`)}>{compactActions ? null : 'Actions'}</Button></Dropdown>}
          {invoice.is_refund_order && canEditInvoices && (
            <Button size="small" icon={<EditOutlined />} onClick={() => navigate(`/orders/${invoice.order.uid}/edit`)}>
              {compactActions ? null : 'Edit'}
            </Button>
          )}
        </Space>
      ),
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
            pagination={{
              current: pagination.current,
              pageSize: pagination.pageSize,
              total: pagination.total,
              onChange: (page) => fetchInvoices(page),
            }}
          />
        </Spin>
      </Card>
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
