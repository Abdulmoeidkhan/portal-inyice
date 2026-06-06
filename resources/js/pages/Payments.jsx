import React, { useEffect, useState } from 'react';
import { Card, Table, Space, Button, Modal, InputNumber, Select, message, Typography } from 'antd';

const { Title, Paragraph } = Typography;

export default function Payments() {
  const [invoices, setInvoices] = useState([]);
  const [loading, setLoading] = useState(false);
  const [paymentModal, setPaymentModal] = useState(false);
  const [selectedInvoice, setSelectedInvoice] = useState(null);
  const [paymentData, setPaymentData] = useState({ amount: 0, payment_method: 'cash' });

  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  const fetchInvoices = async () => {
    setLoading(true);
    try {
      const response = await fetch('/api/v1/invoices', {
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
        },
      });

      if (!response.ok) throw new Error('Could not load invoices');
      const data = await response.json();
      setInvoices(data.data || []);
    } catch (error) {
      message.error(error.message || 'Could not load invoices');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchInvoices();
  }, []);

  const recordPayment = async () => {
    if (!selectedInvoice || paymentData.amount <= 0) {
      message.error('Enter valid amount');
      return;
    }

    try {
      const response = await fetch('/api/v1/payments/record', {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${token}`,
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          invoice_uid: selectedInvoice.uid,
          amount: paymentData.amount,
          payment_method: paymentData.payment_method,
        }),
      });

      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Payment failed');

      message.success('Payment recorded');
      setPaymentModal(false);
      setSelectedInvoice(null);
      setPaymentData({ amount: 0, payment_method: 'cash' });
      fetchInvoices();
    } catch (error) {
      message.error(error.message || 'Payment failed');
    }
  };

  const columns = [
    { title: 'Invoice', dataIndex: 'invoice_number', key: 'invoice_number' },
    { title: 'Customer', dataIndex: ['customer', 'name'], key: 'customer' },
    { title: 'Total', dataIndex: 'total_amount', key: 'total_amount' },
    { title: 'Outstanding', dataIndex: 'outstanding_amount', key: 'outstanding_amount' },
    {
      title: 'Action',
      key: 'action',
      render: (_, row) => (
        <Button
          type="primary"
          onClick={() => {
            setSelectedInvoice(row);
            setPaymentModal(true);
          }}
        >
          Record Payment
        </Button>
      ),
    },
  ];

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2} style={{ margin: 0 }}>Payments</Title>
        <Paragraph type="secondary" style={{ marginTop: 8 }}>
          Dedicated tab for cash/bank receipts and invoice settlements.
        </Paragraph>
      </div>

      <Card className="border-beam-aurora">
        <Table rowKey="id" loading={loading} columns={columns} dataSource={invoices} />
      </Card>

      <Modal
        title="Record Payment"
        open={paymentModal}
        onOk={recordPayment}
        onCancel={() => setPaymentModal(false)}
      >
        <Space orientation="vertical" style={{ width: '100%' }}>
          <InputNumber
            style={{ width: '100%' }}
            min={0}
            step={0.01}
            placeholder="Amount"
            value={paymentData.amount}
            onChange={(value) => setPaymentData((prev) => ({ ...prev, amount: value || 0 }))}
          />
          <Select
            style={{ width: '100%' }}
            value={paymentData.payment_method}
            options={[
              { label: 'Cash', value: 'cash' },
              { label: 'Bank Transfer', value: 'bank_transfer' },
              { label: 'Check', value: 'check' },
            ]}
            onChange={(value) => setPaymentData((prev) => ({ ...prev, payment_method: value }))}
          />
        </Space>
      </Modal>
    </div>
  );
}
