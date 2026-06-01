import React, { useState, useEffect } from 'react';
import { Card, Table, Button, Space, Select, Spin, message, DatePicker } from 'antd';
import { DownloadOutlined, PrinterOutlined } from '@ant-design/icons';

export default function CustomerStatement() {
  const [customers, setCustomers] = useState([]);
  const [selectedCustomer, setSelectedCustomer] = useState(null);
  const [statement, setStatement] = useState(null);
  const [loading, setLoading] = useState(false);
  const [fromDate, setFromDate] = useState(null);
  const [toDate, setToDate] = useState(null);

  useEffect(() => {
    // TODO: Fetch customers from API
  }, []);

  const fetchStatement = async () => {
    if (!selectedCustomer) {
      message.error('Please select a customer');
      return;
    }

    setLoading(true);
    try {
      const params = new URLSearchParams();
      if (fromDate) params.append('from_date', fromDate);
      if (toDate) params.append('to_date', toDate);

      const response = await fetch(
        `/api/v1/statements/customer/${selectedCustomer}?${params}`,
        {
          headers: {
            Authorization: `Bearer ${localStorage.getItem('token')}`,
            Accept: 'application/json',
          },
        }
      );

      if (!response.ok) throw new Error('Failed to fetch statement');
      const data = await response.json();
      setStatement(data);
    } catch (error) {
      message.error('Failed to load statement: ' + error.message);
    } finally {
      setLoading(false);
    }
  };

  const columns = [
    {
      title: 'Invoice #',
      dataIndex: 'invoice_number',
      key: 'invoice_number',
    },
    {
      title: 'Date',
      dataIndex: 'invoice_date',
      key: 'invoice_date',
    },
    {
      title: 'Amount',
      dataIndex: 'amount_in_client_currency',
      render: (amount) => amount.toFixed(2),
      align: 'right',
    },
    {
      title: 'Status',
      dataIndex: 'status',
      key: 'status',
    },
    {
      title: 'Outstanding',
      dataIndex: 'outstanding',
      render: (outstanding) => outstanding.toFixed(2),
      align: 'right',
    },
  ];

  return (
    <div style={{ padding: '20px' }}>
      <h1>Customer Statement</h1>

      <Card style={{ marginBottom: '20px' }}>
        <Space direction="vertical" style={{ width: '100%' }}>
          <Select
            placeholder="Select Customer"
            style={{ width: '100%' }}
            onChange={setSelectedCustomer}
            // TODO: Load customers from API
          />
          <Space>
            <DatePicker
              placeholder="From Date"
              onChange={(date) => setFromDate(date?.format('YYYY-MM-DD'))}
            />
            <DatePicker
              placeholder="To Date"
              onChange={(date) => setToDate(date?.format('YYYY-MM-DD'))}
            />
            <Button type="primary" onClick={fetchStatement} loading={loading}>
              Generate Statement
            </Button>
          </Space>
        </Space>
      </Card>

      {statement && (
        <>
          <Card style={{ marginBottom: '20px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between' }}>
              <div>
                <h3>{statement.customer.name}</h3>
                <p>{statement.customer.email}</p>
                <p>{statement.customer.phone}</p>
              </div>
              <div style={{ textAlign: 'right' }}>
                <p><strong>Statement Date:</strong> {statement.statement_date}</p>
                <p><strong>Currency:</strong> {statement.customer_currency}</p>
              </div>
            </div>
          </Card>

          <Card title="Invoices" style={{ marginBottom: '20px' }}>
            <Spin spinning={loading}>
              <Table
                columns={columns}
                dataSource={statement.customer_currency_invoices}
                rowKey="invoice_uid"
                pagination={false}
              />
            </Spin>
          </Card>

          <Card title="Summary" style={{ marginBottom: '20px' }}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '20px' }}>
              <div>
                <p><strong>Total Invoices:</strong></p>
                <p style={{ fontSize: '18px', fontWeight: 'bold' }}>
                  {statement.summary.total_invoices}
                </p>
              </div>
              <div>
                <p><strong>Total Outstanding:</strong></p>
                <p style={{ fontSize: '18px', fontWeight: 'bold' }}>
                  {statement.summary.total_outstanding.toFixed(2)}
                </p>
              </div>
              <div>
                <p><strong>Total Paid:</strong></p>
                <p style={{ fontSize: '18px', fontWeight: 'bold' }}>
                  {statement.summary.total_paid.toFixed(2)}
                </p>
              </div>
              <div>
                <p><strong>Total Amount:</strong></p>
                <p style={{ fontSize: '18px', fontWeight: 'bold' }}>
                  {statement.summary.total_amount.toFixed(2)}
                </p>
              </div>
            </div>
          </Card>

          <Card>
            <Space>
              <Button
                type="primary"
                icon={<DownloadOutlined />}
                onClick={() => message.info('PDF export coming soon')}
              >
                Export PDF
              </Button>
              <Button icon={<PrinterOutlined />} onClick={() => window.print()}>
                Print
              </Button>
            </Space>
          </Card>
        </>
      )}
    </div>
  );
}
