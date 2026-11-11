import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Alert, Button, Card, Col, Input, Row, Select, Space, Statistic, Typography } from 'antd';
import Table from '../components/CsvTable';
import { message } from '../services/feedback';
import { formatMoney as money } from '../services/numberFormat';

export default function PartyBalances() {
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [search, setSearch] = useState('');
  const [type, setType] = useState('all');
  const [direction, setDirection] = useState('all');
  const [currency, setCurrency] = useState('');
  const refresh = async () => {
    setLoading(true);
    try {
      const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
      const response = await fetch('/api/v1/reports/party-balances', {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      if (!response.ok) throw new Error('Could not load party balances');
      const data = await response.json();
      setReport(data);
      setCurrency(current => current || data.base_currency);
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => { refresh(); }, []);
  const currencies = [...new Set([report?.base_currency, ...(report?.totals || []).map(item => item.currency)])].filter(Boolean);
  const rows = (report?.rows || []).filter(row => row.currency === currency
    && (type === 'all' || row.type === type)
    && (direction === 'all' || Number(row[direction]) > 0)
    && `${row.name} ${row.phone || ''}`.toLowerCase().includes(search.trim().toLowerCase()));
  const totals = rows.reduce((result, row) => ({
    receivable: result.receivable + Number(row.receivable), payable: result.payable + Number(row.payable),
  }), { receivable: 0, payable: 0 });
  const columns = [
    { title: 'Party', dataIndex: 'name' },
    { title: 'Type', dataIndex: 'type', render: value => value === 'customer' ? 'Customer' : 'Vendor' },
    { title: 'Phone', dataIndex: 'phone', render: value => value || '-' },
    { title: 'Currency', dataIndex: 'currency' },
    { title: 'Receivable', dataIndex: 'receivable', align: 'right', render: money },
    { title: 'Payable', dataIndex: 'payable', align: 'right', render: money },
    { title: 'Action', key: 'action', render: (_, row) => <Link to={`/statements/${row.type === 'customer' ? 'customers' : 'vendors'}?party_id=${row.party_id}`}>View statement</Link> },
  ];
  return (
    <div className="page-shell page-fade-up">
      <Typography.Title level={2}>Payables &amp; Receivables</Typography.Title>
      <Typography.Paragraph type="secondary">Current outstanding balances by party. Totals reflect the filters below; each currency is shown separately.</Typography.Paragraph>
      <Card className="border-beam-aurora" style={{ marginBottom: 16 }}>
        <Space wrap>
          <Input aria-label="Search party" placeholder="Search party or phone" allowClear value={search} onChange={event => setSearch(event.target.value)} style={{ width: 240 }} />
          <Select aria-label="Party type" value={type} onChange={setType} style={{ width: 150 }} options={[{ value: 'all', label: 'All parties' }, { value: 'customer', label: 'Customers' }, { value: 'vendor', label: 'Vendors' }]} />
          <Select aria-label="Balance type" value={direction} onChange={setDirection} style={{ width: 160 }} options={[{ value: 'all', label: 'All balances' }, { value: 'receivable', label: 'Receivable' }, { value: 'payable', label: 'Payable' }]} />
          <Select aria-label="Currency" value={currency || undefined} onChange={setCurrency} style={{ width: 110 }} options={currencies.map(value => ({ value, label: value }))} />
          <Button onClick={refresh} loading={loading}>Refresh</Button>
        </Space>
      </Card>
      <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
        <Col xs={24} md={8}><Card><Statistic title="Total receivable" value={totals.receivable} precision={2} prefix={currency} /></Card></Col>
        <Col xs={24} md={8}><Card><Statistic title="Total payable" value={totals.payable} precision={2} prefix={currency} /></Card></Col>
        <Col xs={24} md={8}><Card><Statistic title="Parties with balances" value={rows.length} /></Card></Col>
      </Row>
      <Alert type="info" showIcon style={{ marginBottom: 16 }} title="Customer credits appear as payable; vendor advances appear as receivable. Fully settled parties are omitted." />
      <Card className="border-beam-aurora" title="Party-wise balances">
        <Table columns={columns} dataSource={rows} rowKey="key" loading={loading} scroll={{ x: 'max-content' }}
          csvFileName={`Party-balances-${currency}`} pagination={{ pageSize: 25, showSizeChanger: true }}
          summary={() => <Table.Summary.Row>
            <Table.Summary.Cell index={0} colSpan={4}><Typography.Text strong>Filtered total ({currency})</Typography.Text></Table.Summary.Cell>
            <Table.Summary.Cell index={4} align="right"><Typography.Text strong>{money(totals.receivable)}</Typography.Text></Table.Summary.Cell>
            <Table.Summary.Cell index={5} align="right"><Typography.Text strong>{money(totals.payable)}</Typography.Text></Table.Summary.Cell>
            <Table.Summary.Cell index={6} />
          </Table.Summary.Row>} />
      </Card>
    </div>
  );
}
