import React, { useState, useEffect } from 'react';
import { Table, Button, Space, Tag, Spin, message, Modal, InputNumber, Select, Input } from 'antd';
import { DeleteOutlined, EditOutlined, FileTextOutlined } from '@ant-design/icons';

export default function InvoiceList() {
  const [invoices, setInvoices] = useState([]);
  const [loading, setLoading] = useState(false);
  const [pagination, setPagination] = useState({ current: 1, pageSize: 20, total: 0 });
  const [statusFilter, setStatusFilter] = useState('');
  const [paymentModalVisible, setPaymentModalVisible] = useState(false);
  const [selectedInvoice, setSelectedInvoice] = useState(null);
  const [paymentData, setPaymentData] = useState({ amount: 0, payment_method: 'cash' });
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  useEffect(() => {
    fetchInvoices();
  }, [pagination.current, statusFilter]);

  const fetchInvoices = async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams({
        page: pagination.current,
        per_page: pagination.pageSize,
      });
      if (statusFilter) params.append('status', statusFilter);

      const response = await fetch(`/api/v1/invoices?${params}`, {
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
        },
      });

      if (!response.ok) throw new Error('Failed to fetch invoices');
      const data = await response.json();
      setInvoices(data.data);
      setPagination(prev => ({
        ...prev,
        total: data.total,
      }));
    } catch (error) {
      message.error('Failed to load invoices: ' + error.message);
    } finally {
      setLoading(false);
    }
  };

  const handlePayment = async () => {
    if (!selectedInvoice || paymentData.amount <= 0) {
      message.error('Please select an invoice and enter a valid amount');
      return;
    }

    try {
      const response = await fetch('/api/v1/payments/record', {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          invoice_uid: selectedInvoice.uid,
          amount: paymentData.amount,
          payment_method: paymentData.payment_method,
        }),
      });

      if (!response.ok) throw new Error('Failed to record payment');
      message.success('Payment recorded successfully');
      setPaymentModalVisible(false);
      fetchInvoices();
    } catch (error) {
      message.error('Failed to record payment: ' + error.message);
    }
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
      width: 130,
    },
    {
      title: 'Customer',
      dataIndex: ['customer', 'name'],
      key: 'customer',
      width: 200,
    },
    {
      title: 'Amount',
      dataIndex: 'total_amount',
      key: 'total_amount',
      render: (amount) => `${amount.toFixed(2)}`,
      align: 'right',
    },
    {
      title: 'Outstanding',
      dataIndex: 'outstanding_amount',
      key: 'outstanding_amount',
      render: (amount) => `${amount.toFixed(2)}`,
      align: 'right',
    },
    {
      title: 'Status',
      dataIndex: 'status',
      key: 'status',
      render: (status) => <Tag color={getStatusColor(status)}>{status}</Tag>,
    },
    {
      title: 'Date',
      dataIndex: 'invoice_date',
      key: 'invoice_date',
      width: 110,
    },
    {
      title: 'Actions',
      key: 'actions',
      render: (_, record) => (
        <Space>
          <Button
            type="primary"
            size="small"
            onClick={() => {
              setSelectedInvoice(record);
              setPaymentModalVisible(true);
            }}
          >
            Pay
          </Button>
          <Button size="small" icon={<FileTextOutlined />} />
        </Space>
      ),
    },
  ];

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card" style={{ marginBottom: 16 }}>
      <h1>Invoices</h1>
      
      <div style={{ marginBottom: '20px' }}>
        <Select
          style={{ width: 200 }}
          placeholder="Filter by status"
          allowClear
          onChange={setStatusFilter}
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
      </div>

      <Spin spinning={loading}>
        <Table
          columns={columns}
          dataSource={invoices}
          rowKey="id"
          pagination={{
            current: pagination.current,
            pageSize: pagination.pageSize,
            total: pagination.total,
            onChange: (page) => setPagination(prev => ({ ...prev, current: page })),
          }}
        />
      </Spin>
      </div>

      <Modal
        title="Record Payment"
        open={paymentModalVisible}
        onOk={handlePayment}
        onCancel={() => setPaymentModalVisible(false)}
      >
        {selectedInvoice && (
          <div>
            <p><strong>Invoice:</strong> {selectedInvoice.invoice_number}</p>
            <p><strong>Outstanding:</strong> {selectedInvoice.outstanding_amount.toFixed(2)}</p>
            
            <InputNumber
              label="Amount"
              value={paymentData.amount}
              onChange={(value) => setPaymentData(prev => ({ ...prev, amount: value }))}
              style={{ width: '100%', marginBottom: '10px' }}
              min={0}
              step={0.01}
            />
            
            <Select
              label="Payment Method"
              value={paymentData.payment_method}
              onChange={(value) => setPaymentData(prev => ({ ...prev, payment_method: value }))}
              options={[
                { label: 'Cash', value: 'cash' },
                { label: 'Bank Transfer', value: 'bank_transfer' },
                { label: 'Check', value: 'check' },
                { label: 'Card', value: 'card' },
              ]}
              style={{ width: '100%' }}
            />
          </div>
        )}
      </Modal>
    </div>
  );
}
