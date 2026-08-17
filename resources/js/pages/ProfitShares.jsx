import React, { useEffect, useMemo, useState } from 'react';
import { DeleteOutlined, EditOutlined, PlusOutlined, ReloadOutlined, SwapOutlined } from '@ant-design/icons';
import { Button, Card, Col, Form, Input, InputNumber, Modal, Row, Select, Space, Statistic, Tag, Typography } from 'antd';
import { message } from '../services/feedback';
import { dateOnly } from '../services/dateFormat';
import Table from '../components/CsvTable';

const { Title, Paragraph, Text } = Typography;

const dateString = (date) => new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const firstOfMonth = () => {
  const date = new Date();
  date.setDate(1);
  return dateString(date);
};
const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function ProfitShares() {
  const [form] = Form.useForm();
  const [data, setData] = useState([]);
  const [sourceProfits, setSourceProfits] = useState([]);
  const [summary, setSummary] = useState({ by_currency: [], by_user: [] });
  const [staff, setStaff] = useState([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [modalOpen, setModalOpen] = useState(false);
  const [editingShare, setEditingShare] = useState(null);
  const [baseCurrency, setBaseCurrency] = useState('GBP');
  const [filters, setFilters] = useState({
    from_date: firstOfMonth(),
    to_date: dateString(new Date()),
    user_id: null,
    currency_code: '',
    search: '',
  });
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  const staffOptions = useMemo(() => staff.map((user) => ({
    value: user.id,
    label: `${user.name}${user.email ? ` (${user.email})` : ''}`,
  })), [staff]);

  const loadCompany = async () => {
    try {
      const response = await fetch('/api/v1/company-profile', {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      const payload = await response.json();
      if (response.ok && payload.company?.base_currency_code) {
        setBaseCurrency(payload.company.base_currency_code);
      }
    } catch {
      // Keep the fallback currency.
    }
  };

  const fetchShares = async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      Object.entries(filters).forEach(([key, value]) => {
        if (value) params.set(key, value);
      });

      const response = await fetch(`/api/v1/profit-shares?${params}`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || payload.message || 'Could not load profit shares');
      setData(payload.data || []);
      setSourceProfits(payload.source_profits || []);
      setSummary(payload.summary || { by_currency: [], by_user: [] });
      setStaff(payload.staff || []);
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadCompany();
    fetchShares();
  }, []);

  useEffect(() => {
    const timeoutId = window.setTimeout(fetchShares, 250);

    return () => window.clearTimeout(timeoutId);
  }, [filters.from_date, filters.to_date, filters.user_id, filters.currency_code, filters.search]);

  const openCreate = () => {
    setEditingShare(null);
    form.resetFields();
    form.setFieldsValue({
      share_date: dateString(new Date()),
      currency_code: baseCurrency,
    });
    setModalOpen(true);
  };

  const openShareSource = (record) => {
    setEditingShare(null);
    form.resetFields();
    form.setFieldsValue({
      from_user_id: record.staff?.id,
      invoice_uid: record.invoice_uid,
      share_date: dateString(new Date()),
      currency_code: record.currency_code,
      amount: Math.max(0, Number(record.available_profit || record.profit || 0)),
    });
    setModalOpen(true);
  };

  const openEdit = (record) => {
    setEditingShare(record);
    form.setFieldsValue({
      from_user_id: record.from_user?.id,
      to_user_id: record.to_user?.id,
      invoice_uid: record.invoice?.uid,
      share_date: record.share_date,
      currency_code: record.currency_code,
      amount: record.amount,
      notes: record.notes,
    });
    setModalOpen(true);
  };

  const saveShare = async (values) => {
    setSaving(true);
    try {
      const endpoint = editingShare ? `/api/v1/profit-shares/${editingShare.uid}` : '/api/v1/profit-shares';
      const response = await fetch(endpoint, {
        method: editingShare ? 'PATCH' : 'POST',
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          ...values,
          currency_code: String(values.currency_code || '').toUpperCase(),
          invoice_uid: values.invoice_uid || null,
        }),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || payload.message || 'Could not save profit share');
      message.success(payload.message || 'Profit share saved');
      setModalOpen(false);
      fetchShares();
    } catch (error) {
      message.error(error.message);
    } finally {
      setSaving(false);
    }
  };

  const deleteShare = (record) => {
    Modal.confirm({
      title: 'Delete profit share?',
      content: `${record.currency_code} ${money(record.amount)} from ${record.from_user?.name || '-'} to ${record.to_user?.name || '-'}`,
      okText: 'Delete',
      cancelButtonProps: { danger: true },
      okButtonProps: { danger: true },
      onOk: async () => {
        const response = await fetch(`/api/v1/profit-shares/${record.uid}`, {
          method: 'DELETE',
          headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.error || payload.message || 'Could not delete profit share');
        message.success(payload.message || 'Profit share deleted');
        fetchShares();
      },
    });
  };

  const transferColumns = [
    { title: 'Date', dataIndex: 'share_date', width: 115, render: dateOnly },
    { title: 'From', dataIndex: ['from_user', 'name'], width: 180 },
    { title: 'To', dataIndex: ['to_user', 'name'], width: 180 },
    {
      title: 'Amount',
      dataIndex: 'amount',
      width: 150,
      align: 'right',
      render: (value, row) => <Text strong>{row.currency_code} {money(value)}</Text>,
    },
    {
      title: 'Invoice',
      dataIndex: ['invoice', 'invoice_number'],
      width: 150,
      render: (value) => value || '-',
    },
    { title: 'Notes', dataIndex: 'notes', ellipsis: true, render: (value) => value || '-' },
    {
      title: 'Actions',
      key: 'actions',
      fixed: 'right',
      width: 120,
      render: (_, record) => (
        <Space>
          <Button size="small" icon={<EditOutlined />} onClick={() => openEdit(record)} />
          <Button size="small" danger icon={<DeleteOutlined />} onClick={() => deleteShare(record)} />
        </Space>
      ),
    },
  ];

  const sourceColumns = [
    { title: 'Invoice Date', dataIndex: 'invoice_date', width: 120, render: dateOnly },
    { title: 'Invoice', dataIndex: 'invoice_number', width: 170 },
    { title: 'Order', dataIndex: 'order_number', width: 160 },
    { title: 'Customer', dataIndex: 'customer_name', width: 180, render: (value) => value || '-' },
    { title: 'Staff', dataIndex: ['staff', 'name'], width: 170, render: (value) => value || '-' },
    { title: 'Revenue', dataIndex: 'revenue', width: 140, align: 'right', render: (value, row) => `${row.currency_code} ${money(value)}` },
    { title: 'Cost', dataIndex: 'cost', width: 140, align: 'right', render: (value, row) => `${row.currency_code} ${money(value)}` },
    {
      title: 'Profit',
      dataIndex: 'profit',
      width: 140,
      align: 'right',
      render: (value, row) => <Text strong type={Number(value) < 0 ? 'danger' : 'success'}>{row.currency_code} {money(value)}</Text>,
    },
    { title: 'Shared', dataIndex: 'shared_out', width: 140, align: 'right', render: (value, row) => `${row.currency_code} ${money(value)}` },
    {
      title: 'Available',
      dataIndex: 'available_profit',
      width: 140,
      align: 'right',
      render: (value, row) => <Text strong>{row.currency_code} {money(value)}</Text>,
    },
    {
      title: 'Action',
      key: 'source_action',
      fixed: 'right',
      width: 110,
      render: (_, record) => (
        <Button size="small" type="primary" icon={<SwapOutlined />} disabled={!record.staff?.id || Number(record.available_profit) <= 0} onClick={() => openShareSource(record)}>
          Share
        </Button>
      ),
    },
  ];

  const userColumns = [
    { title: 'User', dataIndex: 'user_name', width: 220 },
    { title: 'Currency', dataIndex: 'currency_code', width: 100, render: (value) => <Tag>{value}</Tag> },
    { title: 'Shared Out', dataIndex: 'shared_out', width: 140, align: 'right', render: (value, row) => `${row.currency_code} ${money(value)}` },
    { title: 'Received', dataIndex: 'received', width: 140, align: 'right', render: (value, row) => `${row.currency_code} ${money(value)}` },
    {
      title: 'Net',
      dataIndex: 'net',
      width: 140,
      align: 'right',
      render: (value, row) => <Text strong type={Number(value) < 0 ? 'danger' : 'success'}>{row.currency_code} {money(value)}</Text>,
    },
  ];

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2}>Profit Shares</Title>
        <Paragraph>Move profit responsibility between company users without editing invoices, orders, receipts, or ledger entries.</Paragraph>

        <Row gutter={[12, 12]} align="bottom">
          <Col xs={12} md={4}>
            <Text strong>From</Text>
            <Input type="date" value={filters.from_date} onChange={(event) => setFilters((current) => ({ ...current, from_date: event.target.value }))} />
          </Col>
          <Col xs={12} md={4}>
            <Text strong>To</Text>
            <Input type="date" value={filters.to_date} onChange={(event) => setFilters((current) => ({ ...current, to_date: event.target.value }))} />
          </Col>
          <Col xs={24} md={6}>
            <Text strong>User</Text>
            <Select allowClear showSearch optionFilterProp="label" placeholder="Any user" value={filters.user_id || undefined} options={staffOptions} onChange={(value) => setFilters((current) => ({ ...current, user_id: value || null }))} style={{ width: '100%' }} />
          </Col>
          <Col xs={12} md={3}>
            <Text strong>Currency</Text>
            <Input value={filters.currency_code} maxLength={3} onChange={(event) => setFilters((current) => ({ ...current, currency_code: event.target.value.toUpperCase() }))} />
          </Col>
          <Col xs={24} md={5}>
            <Text strong>Search</Text>
            <Input allowClear value={filters.search} onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))} onPressEnter={fetchShares} />
          </Col>
          <Col xs={12} md={3}>
            <Button block icon={<ReloadOutlined />} loading={loading} onClick={fetchShares}>Run</Button>
          </Col>
          <Col xs={12} md={3}>
            <Button block type="primary" icon={<PlusOutlined />} onClick={openCreate}>Record</Button>
          </Col>
        </Row>
      </div>

      {summary.by_currency?.length > 0 && (
        <Card title="Shared profit by currency" style={{ marginBottom: 16 }}>
          <Space size="large" wrap>
            {summary.by_currency.map((item) => (
              <Statistic key={item.currency_code} title={item.currency_code} value={item.amount} precision={2} prefix={item.currency_code} suffix={<Text type="secondary"> {item.count} records</Text>} />
            ))}
          </Space>
        </Card>
      )}

      <Card title="Invoice profit available to share" style={{ marginBottom: 16 }}>
        <Table rowKey="key" loading={loading} columns={sourceColumns} dataSource={sourceProfits} scroll={{ x: 1610 }} pagination={{ pageSize: 10, showSizeChanger: true }} />
      </Card>

      <Card title="User totals" style={{ marginBottom: 16 }}>
        <Table rowKey="key" loading={loading} columns={userColumns} dataSource={summary.by_user || []} scroll={{ x: 740 }} pagination={false} />
      </Card>

      <Card title="Transfer history">
        <Table rowKey="uid" loading={loading} columns={transferColumns} dataSource={data} scroll={{ x: 1080 }} pagination={{ pageSize: 25, showSizeChanger: true }} />
      </Card>

      <Modal
        title={editingShare ? 'Edit Profit Share' : 'Record Profit Share'}
        open={modalOpen}
        onCancel={() => setModalOpen(false)}
        cancelButtonProps={{ danger: true }}
        onOk={() => form.submit()}
        confirmLoading={saving}
        okText={editingShare ? 'Save' : 'Record'}
        width={680}
        destroyOnClose
      >
        <Form form={form} layout="vertical" onFinish={saveShare}>
          <Row gutter={12}>
            <Col xs={24} md={12}>
              <Form.Item name="from_user_id" label="Share From" rules={[{ required: true, message: 'Choose who gives profit' }]}>
                <Select showSearch optionFilterProp="label" options={staffOptions} />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="to_user_id" label="Share To" rules={[{ required: true, message: 'Choose who receives profit' }]}>
                <Select showSearch optionFilterProp="label" options={staffOptions} />
              </Form.Item>
            </Col>
            <Col xs={12} md={8}>
              <Form.Item name="share_date" label="Date" rules={[{ required: true, message: 'Choose date' }]}>
                <Input type="date" />
              </Form.Item>
            </Col>
            <Col xs={12} md={8}>
              <Form.Item name="currency_code" label="Currency" rules={[{ required: true, message: 'Enter currency' }]}>
                <Input maxLength={3} />
              </Form.Item>
            </Col>
            <Col xs={24} md={8}>
              <Form.Item name="amount" label="Amount" rules={[{ required: true, message: 'Enter amount' }]}>
                <InputNumber min={0.0001} precision={4} style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Form.Item name="invoice_uid" hidden>
              <Input />
            </Form.Item>
            <Col xs={24}>
              <Form.Item name="notes" label="Notes">
                <Input.TextArea rows={3} maxLength={1000} />
              </Form.Item>
            </Col>
          </Row>
        </Form>
      </Modal>
    </div>
  );
}
