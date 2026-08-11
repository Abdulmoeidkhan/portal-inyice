import React, { useEffect, useState } from 'react';
import { Button, Card, Col, Input, Row, Select, Space, Spin, Statistic, Tag, Typography } from 'antd';
import { PrinterOutlined } from '@ant-design/icons';
import { message } from '../services/feedback';
import { dateOnly } from '../services/dateFormat';
import Table from '../components/CsvTable';

const { Title, Paragraph, Text } = Typography;
const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const statementTotals = (rows = []) => ({
  debit: rows.reduce((sum, row) => sum + Number(row.debit || 0), 0),
  credit: rows.reduce((sum, row) => sum + Number(row.credit || 0), 0),
  balance: Number(rows.at(-1)?.balance || 0),
});
const invoiceTotals = (rows = []) => ({
  amount: rows.reduce((sum, row) => sum + Number(row.amount_in_client_currency ?? row.amount ?? 0), 0),
  paid: rows.reduce((sum, row) => sum + (Number(row.amount || 0) - Number(row.outstanding || 0)), 0),
  outstanding: rows.reduce((sum, row) => sum + Number(row.outstanding || 0), 0),
});

export default function CustomerStatement() {
  const [customers, setCustomers] = useState([]);
  const [selectedCustomer, setSelectedCustomer] = useState('all');
  const [statement, setStatement] = useState(null);
  const [loading, setLoading] = useState(false);
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  useEffect(() => {
    fetch('/api/v1/customers', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } })
      .then((response) => response.ok ? response.json() : Promise.reject(new Error('Could not load customers')))
      .then(setCustomers)
      .catch((error) => message.error(error.message));
  }, []);

  const fetchStatement = async () => {
    if (!selectedCustomer) {
      message.error('Select a customer');
      return;
    }

    setLoading(true);
    try {
      const params = new URLSearchParams();
      if (fromDate) params.set('from_date', fromDate);
      if (toDate) params.set('to_date', toDate);
      const path = selectedCustomer === 'all'
        ? '/api/v1/statements/customers'
        : `/api/v1/statements/customer/${selectedCustomer}`;
      const response = await fetch(`${path}?${params}`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Could not load customer statement');
      setStatement(data);
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };

  const isAllCustomers = Boolean(statement?.customer?.is_all);
  const columns = [
    ...(isAllCustomers ? [{ title: 'Customer', dataIndex: 'customer_name', key: 'customer_name' }] : []),
    { title: 'Invoice', dataIndex: 'invoice_number', key: 'invoice_number' },
    { title: 'Date', dataIndex: 'invoice_date', key: 'invoice_date', render: dateOnly },
    { title: 'Due Date', dataIndex: 'due_date', key: 'due_date', render: dateOnly },
    { title: 'Amount', dataIndex: 'amount_in_client_currency', align: 'right', render: (value, row) => money(value ?? row.amount) },
    { title: 'Paid', key: 'paid', align: 'right', render: (_, row) => money(Number(row.amount || 0) - Number(row.outstanding || 0)) },
    { title: 'Outstanding', dataIndex: 'outstanding', align: 'right', render: money },
    { title: 'Status', dataIndex: 'status', render: (value) => <Tag>{String(value || '').toUpperCase()}</Tag> },
  ];
  const transactionColumns = [
    { title: 'Date', dataIndex: 'date', render: dateOnly }, { title: 'Type', dataIndex: 'type', render: (value) => <Tag>{String(value).replace(/_/g, ' ').toUpperCase()}</Tag> },
    ...(isAllCustomers ? [{ title: 'Customer', dataIndex: 'customer_name' }] : []),
    { title: 'Reference', dataIndex: 'reference' }, { title: 'Description', dataIndex: 'description' },
    { title: 'Sales', dataIndex: 'sales', align: 'right', render: money },
    { title: 'Refunds', dataIndex: 'refunds', align: 'right', render: money },
    { title: 'Receipts', dataIndex: 'customer_receipts', align: 'right', render: money },
    { title: 'Payments', dataIndex: 'customer_payments', align: 'right', render: money },
    { title: 'Debit', dataIndex: 'debit', align: 'right', render: money },
    { title: 'Credit', dataIndex: 'credit', align: 'right', render: money },
    { title: 'Balance', dataIndex: 'balance', align: 'right', render: money },
  ];
  const totals = statementTotals(statement?.transactions || []);
  const invoiceSummary = invoiceTotals(statement?.customer_currency_invoices || []);
  const summaryStats = statement ? [
    { title: 'Invoices', value: statement.summary.total_invoices },
    { title: 'Total', value: Number(statement.summary.total_amount), money: true },
    { title: 'Receipt', value: Number(statement.summary.total_receipts ?? statement.summary.total_paid ?? 0), money: true },
    { title: 'Payment', value: Number(statement.summary.total_payments || 0), money: true },
    { title: 'Outstanding', value: Number(statement.summary.total_outstanding), money: true },
  ] : [];

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2} style={{ margin: 0 }}>Customer Statement</Title>
        <Paragraph type="secondary" style={{ margin: '8px 0 0' }}>Review customer invoices, receipts, and outstanding receivables.</Paragraph>
      </div>

      <Card className="border-beam-aurora" style={{ marginBottom: 16 }}>
        <Space wrap className="responsive-toolbar">
          <Select
            showSearch
            optionFilterProp="label"
            placeholder="Select customer"
            value={selectedCustomer}
            onChange={(value) => { setSelectedCustomer(value); setStatement(null); }}
            options={[
              { value: 'all', label: 'All Customers' },
              ...customers.map((customer) => ({ value: customer.id, label: customer.name })),
            ]}
            style={{ width: 300 }}
            className="responsive-control"
          />
          <Input type="date" value={fromDate} onChange={(event) => setFromDate(event.target.value)} style={{ width: 165 }} />
          <Input type="date" value={toDate} onChange={(event) => setToDate(event.target.value)} style={{ width: 165 }} />
          <Button type="primary" onClick={fetchStatement} loading={loading}>Generate Statement</Button>
        </Space>
      </Card>

      <Spin spinning={loading}>
        {statement && (
          <>
            <Card className="border-beam-aurora" style={{ marginBottom: 16 }}>
              <Title level={4} style={{ marginTop: 0 }}>{statement.customer.name}</Title>
              {!isAllCustomers && <Paragraph type="secondary">{statement.customer.email || '-'} · {statement.customer.phone || '-'}</Paragraph>}
              <Row gutter={[16, 16]} wrap={false} style={{ overflowX: 'auto' }}>
                {summaryStats.map((item) => (
                  <Col key={item.title} flex="1 0 160px">
                    <Statistic title={item.title} value={item.value} precision={item.money ? 2 : 0} prefix={item.money ? statement.customer_currency : undefined} />
                  </Col>
                ))}
              </Row>
            </Card>
            <Card className="border-beam-aurora" title="Invoice Activity" extra={<Button icon={<PrinterOutlined />} onClick={() => window.print()}>Print</Button>}>
              <Table
                scroll={{ x: 'max-content' }}
                columns={columns}
                dataSource={statement.customer_currency_invoices}
                rowKey="invoice_uid"
                pagination={false}
                summary={() => (
                  <Table.Summary.Row>
                    <Table.Summary.Cell index={0} colSpan={isAllCustomers ? 4 : 3}><Text strong>Total</Text></Table.Summary.Cell>
                    <Table.Summary.Cell index={isAllCustomers ? 4 : 3} align="right"><Text strong>{money(invoiceSummary.amount)}</Text></Table.Summary.Cell>
                    <Table.Summary.Cell index={isAllCustomers ? 5 : 4} align="right"><Text strong>{money(invoiceSummary.paid)}</Text></Table.Summary.Cell>
                    <Table.Summary.Cell index={isAllCustomers ? 6 : 5} align="right"><Text strong>{money(invoiceSummary.outstanding)}</Text></Table.Summary.Cell>
                    <Table.Summary.Cell index={isAllCustomers ? 7 : 6} />
                  </Table.Summary.Row>
                )}
              />
            </Card>
            <Card className="border-beam-aurora" title="Receipts and Payments" style={{ marginTop: 16 }}>
              <Table
                scroll={{ x: 'max-content' }}
                columns={transactionColumns}
                dataSource={statement.transactions}
                rowKey="id"
                pagination={false}
                summary={() => (
                  <Table.Summary.Row>
                    <Table.Summary.Cell index={0} colSpan={isAllCustomers ? 9 : 8}><Text strong>Total</Text></Table.Summary.Cell>
                    <Table.Summary.Cell index={isAllCustomers ? 9 : 8} align="right"><Text strong>{money(totals.debit)}</Text></Table.Summary.Cell>
                    <Table.Summary.Cell index={isAllCustomers ? 10 : 9} align="right"><Text strong>{money(totals.credit)}</Text></Table.Summary.Cell>
                    <Table.Summary.Cell index={isAllCustomers ? 11 : 10} align="right"><Text strong>{money(totals.balance)}</Text></Table.Summary.Cell>
                  </Table.Summary.Row>
                )}
              />
            </Card>
          </>
        )}
      </Spin>
    </div>
  );
}
