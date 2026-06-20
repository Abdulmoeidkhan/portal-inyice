import React, { useEffect, useState } from 'react';
import { Button, Card, Dropdown, Input, InputNumber, Modal, Popconfirm, Select, Space, Spin, Table, Tag, Typography } from 'antd';
import { DownOutlined, EyeOutlined, ShareAltOutlined } from '@ant-design/icons';
import { message } from '../services/feedback';
import { useNavigate } from 'react-router-dom';

const { Title, Paragraph } = Typography;
const money = (value) => Number(value || 0).toFixed(2);

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
  const [actionLoading, setActionLoading] = useState(false);
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

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
    };

    return colors[status] || 'default';
  };

  const request = async (endpoint, method = 'POST', body) => {
    setActionLoading(true);
    try {
      const response = await fetch(endpoint, { method, headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) }, body: body ? JSON.stringify(body) : undefined });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || data.message || 'Action failed');
      return data;
    } catch (error) { message.error(error.message); return null; } finally { setActionLoading(false); }
  };

  const refund = async (invoice, amount) => {
    const data = await request('/api/v1/payments/customer/refund', 'POST', { invoice_uid: invoice.uid, amount, reason: refundReason || undefined });
    if (data) { message.success('Refund recorded'); setRefundInvoice(null); setRefundReason(''); fetchInvoices(); }
  };

  const voidInvoice = async (invoice) => {
    const data = await request(`/api/v1/invoices/${invoice.uid}/void`, 'PATCH');
    if (data) { message.success('Invoice voided'); fetchInvoices(); }
  };

  const shareInvoice = async (invoice) => {
    const data = await request(`/api/v1/invoices/${invoice.uid}/share`);
    if (data && navigator.clipboard?.writeText) { await navigator.clipboard.writeText(data.share_url); message.success('Shareable invoice link copied'); }
    else if (data) window.prompt('Copy this shareable invoice link:', data.share_url);
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
    },
    {
      title: 'Due Date',
      dataIndex: 'due_date',
      key: 'due_date',
      width: 130,
      render: (value) => value || '-',
    },
    {
      title: 'Action',
      key: 'action',
      fixed: 'right',
      render: (_, invoice) => (
        <Space>
          <Button size="small" icon={<EyeOutlined />} onClick={() => navigate(`/invoices/${invoice.uid}`)}>Open</Button>
          <Dropdown menu={{ items: [
            { key: 'pay', label: 'Record payment', disabled: Number(invoice.outstanding_amount || 0) <= 0 || invoice.status === 'void', onClick: () => navigate(`/payments?invoice=${invoice.uid}`) },
            { key: 'partial-refund', label: 'Partial refund', disabled: Number(invoice.total_amount) - Number(invoice.outstanding_amount) <= 0 || invoice.status === 'void', onClick: () => { setRefundInvoice(invoice); setRefundAmount(0); } },
            { key: 'full-refund', label: 'Full refund', disabled: Number(invoice.total_amount) - Number(invoice.outstanding_amount) <= 0 || invoice.status === 'void', onClick: () => Modal.confirm({ title: 'Refund all paid amount?', content: `${invoice.currency_code} ${money(Number(invoice.total_amount) - Number(invoice.outstanding_amount))}`, okText: 'Refund', okButtonProps: { danger: true }, onOk: () => refund(invoice, Number(invoice.total_amount) - Number(invoice.outstanding_amount)) }) },
            { key: 'share', icon: <ShareAltOutlined />, label: 'Copy share link', onClick: () => shareInvoice(invoice) },
            { type: 'divider' },
            { key: 'void', danger: true, label: 'Void invoice', disabled: !['draft', 'issued', 'sent'].includes(invoice.status), onClick: () => Modal.confirm({ title: 'Void this invoice?', content: 'This action marks the invoice as void.', okText: 'Void', okButtonProps: { danger: true }, onOk: () => voidInvoice(invoice) }) },
          ] }}><Button size="small" loading={actionLoading}>Actions <DownOutlined /></Button></Dropdown>
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
      <Modal title={`Partial refund · ${refundInvoice?.invoice_number || ''}`} open={!!refundInvoice} onCancel={() => setRefundInvoice(null)} onOk={() => refund(refundInvoice, refundAmount)} okText="Record refund" confirmLoading={actionLoading} okButtonProps={{ danger: true, disabled: refundAmount <= 0 }}>
        <Typography.Paragraph>Refundable: {refundInvoice?.currency_code} {money(Number(refundInvoice?.total_amount || 0) - Number(refundInvoice?.outstanding_amount || 0))}</Typography.Paragraph>
        <Typography.Text strong>Amount</Typography.Text>
        <InputNumber style={{ width: '100%', marginBottom: 12 }} min={0.01} max={Math.max(0, Number(refundInvoice?.total_amount || 0) - Number(refundInvoice?.outstanding_amount || 0))} precision={2} value={refundAmount} onChange={(value) => setRefundAmount(Number(value || 0))} />
        <Typography.Text strong>Reason</Typography.Text>
        <Input.TextArea maxLength={500} value={refundReason} onChange={(event) => setRefundReason(event.target.value)} />
      </Modal>
    </div>
  );
}
