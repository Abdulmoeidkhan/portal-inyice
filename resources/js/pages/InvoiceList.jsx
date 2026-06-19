import React, { useEffect, useState } from 'react';
import { Button, Card, Input, Select, Space, Spin, Table, Tag, Typography } from 'antd';
import { message } from '../services/feedback';
import { useNavigate } from 'react-router-dom';

const { Title, Paragraph } = Typography;

export default function InvoiceList() {
  const navigate = useNavigate();
  const [invoices, setInvoices] = useState([]);
  const [loading, setLoading] = useState(false);
  const [pagination, setPagination] = useState({ current: 1, pageSize: 20, total: 0 });
  const [statusFilter, setStatusFilter] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
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
        <Button
          type="primary"
          size="small"
          disabled={Number(invoice.outstanding_amount || 0) <= 0 || invoice.status === 'void'}
          onClick={() => navigate(`/payments?invoice=${invoice.uid}`)}
        >
          Record Payment
        </Button>
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
    </div>
  );
}
