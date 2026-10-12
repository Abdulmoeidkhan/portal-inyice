import React, { useEffect, useState } from 'react';
import { Alert, Button, Card, Select, Space, Typography } from 'antd';
import { ReloadOutlined } from '@ant-design/icons';
import { Link, useSearchParams } from 'react-router-dom';
import Table from '../components/CsvTable';
import { dateOnly } from '../services/dateFormat';
import { formatMoney } from '../services/numberFormat';

export default function PaymentHistory({ direction, partyType }) {
  const [searchParams] = useSearchParams();
  const [partyId, setPartyId] = useState(searchParams.get(`${partyType}_id`) || undefined);
  const [parties, setParties] = useState([]);
  const [accounts, setAccounts] = useState({ cash: [], bank: [] });
  const [data, setData] = useState({ data: [], total: 0 });
  const [page, setPage] = useState(1);
  const [refresh, setRefresh] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const title = `${partyType === 'customer' ? 'Customer' : 'Vendor'} ${direction === 'receipt' ? 'Receipts' : 'Payments'}`;
  const hasAdvance = (partyType === 'customer' && direction === 'receipt') || (partyType === 'vendor' && direction === 'payment');
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  useEffect(() => {
    const controller = new AbortController();
    const read = async (path) => {
      const response = await fetch(`/api/v1/${path}`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        signal: controller.signal,
      });
      if (!response.ok) throw new Error('Could not load payment history. Please try again.');
      return response.json();
    };
    setLoading(true);
    setError('');
    setData({ data: [], total: 0 });
    const query = new URLSearchParams({ page: String(page) });
    if (partyId) query.set(`${partyType}_id`, partyId);
    Promise.all([
      read(`${direction}s/${partyType}?${query}`),
      read(`${partyType}s`), read('accounts/cash'), read('accounts/bank'),
    ]).then(([history, partiesData, cash, bank]) => {
      if (controller.signal.aborted) return;
      setData(history);
      setParties(partiesData.data || partiesData);
      setAccounts({ cash, bank });
    }).catch((failure) => {
      if (!controller.signal.aborted) setError(failure.message);
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false);
    });
    return () => controller.abort();
  }, [direction, partyType, partyId, page, refresh, token]);

  const allocations = (row) => {
    const rows = row.settlements || row.allocations || row.refund_allocations || [];
    if (!rows.length) return hasAdvance ? 'On account' : 'No invoice allocation';
    return <Space direction="vertical" size={0}>{rows.map((allocation) => {
      const document = allocation.invoice || allocation.order;
      const label = document?.invoice_number || document?.order_number || 'Allocation';
      return <span key={allocation.id}>
        {document?.uid && allocation.invoice ? <Link to={`/invoices/${document.uid}`}>{label}</Link> : label}
        {' — '}{formatMoney(allocation.amount_received ?? allocation.amount)} {row.currency_code}
      </span>;
    })}</Space>;
  };
  const columns = [
    { title: 'Date', key: 'date', render: (_, row) => dateOnly(row[`${direction}_date`]) },
    { title: 'Number', dataIndex: `${direction}_number` },
    { title: partyType === 'customer' ? 'Customer' : 'Vendor', key: 'party', render: (_, row) => row[partyType]?.name || '—' },
    { title: 'Amount', key: 'amount', render: (_, row) => `${formatMoney(row.amount)} ${row.currency_code}` },
    { title: 'Method', dataIndex: 'payment_method', render: (value) => String(value || '—').replaceAll('_', ' ') },
    { title: 'Account', key: 'account', render: (_, row) => {
      if (!row.account_id) return '—';
      const account = (accounts[row.account_type] || []).find((item) => Number(item.id) === Number(row.account_id));
      return account?.account_name || (account?.bank_name ? `${account.bank_name} ? ${account.account_number}` : `${row.account_type || 'Account'} #${row.account_id}`);
    } },
    { title: 'Reference', dataIndex: 'reference_number' },
    { title: 'Invoice / order allocations', key: 'allocations', render: (_, row) => allocations(row) },
    ...(hasAdvance ? [{ title: 'Unallocated', key: 'remaining', render: (_, row) => `${formatMoney(row.remaining_amount)} ${row.currency_code}` }] : []),
    { title: 'Description', dataIndex: 'description' },
  ];

  return <div className="page-shell page-fade-up">
    <Typography.Title level={2}>{title}</Typography.Title>
    <Typography.Paragraph type="secondary">View recorded payments, receipts, and allocations.</Typography.Paragraph>
    <Card>
      <Space wrap style={{ marginBottom: 16 }}>
        <Select allowClear showSearch optionFilterProp="label" style={{ width: 300 }}
          placeholder={`All ${partyType}s`} value={partyId}
          options={parties.map((party) => ({ value: String(party.id), label: party.name }))}
          onChange={(value) => { setPartyId(value); setPage(1); }} />
        <Button icon={<ReloadOutlined />} onClick={() => setRefresh((value) => value + 1)}>Refresh</Button>
      </Space>
      {error && <Alert type="error" showIcon message={error} style={{ marginBottom: 16 }} />}
      <Table rowKey="id" columns={columns} dataSource={data.data} loading={loading} scroll={{ x: 1400 }}
        pagination={{ current: page, pageSize: 50, total: data.total, showSizeChanger: false, onChange: setPage }} />
    </Card>
  </div>;
}
