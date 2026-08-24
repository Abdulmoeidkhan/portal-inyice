import React, { useEffect, useMemo, useState } from 'react';
import { DeleteOutlined, EditOutlined, PrinterOutlined, ReloadOutlined, SaveOutlined } from '@ant-design/icons';
import { Button, Card, Col, Divider, Form, Grid, Input, InputNumber, Modal, Popconfirm, Row, Select, Space, Tag, Typography, Watermark } from 'antd';
import { message } from '../services/feedback';
import { printDocument } from '../services/printDocument';
import Table from '../components/CsvTable';

const { Title, Paragraph, Text } = Typography;

const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const statusOptions = [
  { value: 'received', label: 'Received' },
  { value: 'clear', label: 'Clear' },
];
const statusMeta = {
  received: { label: 'Received', color: 'processing' },
  clear: { label: 'Clear', color: 'success' },
};

const dateTime = (value) => {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '-';

  return date.toLocaleString();
};

const getStoredUser = () => {
  try {
    return JSON.parse(localStorage.getItem('user') || '{}');
  } catch {
    return {};
  }
};

export default function Receivings() {
  const [form] = Form.useForm();
  const [editForm] = Form.useForm();
  const [receivings, setReceivings] = useState([]);
  const [customers, setCustomers] = useState([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [search, setSearch] = useState('');
  const [editing, setEditing] = useState(null);
  const [printableReceiving, setPrintableReceiving] = useState(null);
  const [printingReceivingUid, setPrintingReceivingUid] = useState(null);
  const screens = Grid.useBreakpoint();
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
  const user = getStoredUser();
  const canEdit = ['owner', 'admin', 'accounts'].includes(user.role);
  const canDelete = ['owner', 'admin'].includes(user.role);
  const compactActions = !screens.sm;

  const customerOptions = customers.map((customer) => ({
    value: customer.id,
    label: `${customer.name}${customer.phone ? ` - ${customer.phone}` : ''}`,
  }));

  const loadData = async (nextSearch = search) => {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      if (nextSearch.trim()) params.set('search', nextSearch.trim());

      const [receivingResponse, customerResponse] = await Promise.all([
        fetch(`/api/v1/receivings${params.toString() ? `?${params}` : ''}`, { headers }),
        fetch('/api/v1/customers', { headers }),
      ]);
      const [receivingData, customerData] = await Promise.all([receivingResponse.json(), customerResponse.json()]);
      if (!receivingResponse.ok) throw new Error(receivingData.message || receivingData.error || 'Could not load receivings');
      if (!customerResponse.ok) throw new Error(customerData.message || customerData.error || 'Could not load customers');

      setReceivings(receivingData.data || []);
      setCustomers(customerData.data || customerData || []);
    } catch (error) {
      message.error(error.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { loadData(''); }, []);

  useEffect(() => {
    if (!editing) return;

    editForm.setFieldsValue({
      amount: Number(editing.amount || 0),
      status: editing.status || 'received',
      paid_by: editing.paid_by,
      reference_customer_id: editing.reference_customer_id || undefined,
      notes: editing.notes || '',
    });
  }, [editing, editForm]);

  useEffect(() => {
    if (!printableReceiving) return undefined;

    const frame = window.requestAnimationFrame(() => {
      printDocument('.receiving-print-host .receipt-slip', `Receiving ${printableReceiving.receiving_number || ''}`);
    });

    return () => window.cancelAnimationFrame(frame);
  }, [printableReceiving]);

  const totalActive = useMemo(
    () => receivings.filter((item) => !item.is_deleted).reduce((sum, item) => sum + Number(item.amount || 0), 0),
    [receivings],
  );

  const submit = async () => {
    setSaving(true);
    try {
      const values = await form.validateFields();
      const response = await fetch('/api/v1/receivings', {
        method: 'POST',
        headers: { ...headers, 'Content-Type': 'application/json' },
        body: JSON.stringify({
          amount: values.amount,
          paid_by: values.paid_by,
          reference_customer_id: values.reference_customer_id || null,
          notes: values.notes || null,
        }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || data.error || 'Could not save receiving');

      message.success('Receiving saved');
      form.resetFields();
      await loadData();
    } catch (error) {
      if (error?.errorFields) return;
      message.error(error.message);
    } finally {
      setSaving(false);
    }
  };

  const saveEdit = async () => {
    if (!editing) return;
    setSaving(true);
    try {
      const values = await editForm.validateFields();
      const response = await fetch(`/api/v1/receivings/${editing.uid}`, {
        method: 'PATCH',
        headers: { ...headers, 'Content-Type': 'application/json' },
        body: JSON.stringify({
          amount: values.amount,
          status: values.status || 'received',
          paid_by: values.paid_by,
          reference_customer_id: values.reference_customer_id || null,
          notes: values.notes || null,
        }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || data.error || 'Could not update receiving');

      message.success('Receiving updated');
      setEditing(null);
      await loadData();
    } catch (error) {
      if (error?.errorFields) return;
      message.error(error.message);
    } finally {
      setSaving(false);
    }
  };

  const deleteReceiving = async (row) => {
    setSaving(true);
    try {
      const response = await fetch(`/api/v1/receivings/${row.uid}`, { method: 'DELETE', headers });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || data.error || 'Could not delete receiving');

      message.success('Receiving deleted');
      await loadData();
    } catch (error) {
      message.error(error.message);
    } finally {
      setSaving(false);
    }
  };

  const printReceiving = async (row) => {
    setPrintingReceivingUid(row.uid);
    try {
      const response = await fetch(`/api/v1/receivings/${row.uid}`, { headers });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || data.error || 'Could not load receiving receipt');

      setPrintableReceiving(data);
    } catch (error) {
      message.error(error.message);
    } finally {
      setPrintingReceivingUid(null);
    }
  };

  const columns = [
    { title: 'Receiving #', dataIndex: 'receiving_number', width: 140, render: (value) => <Text strong>{value}</Text> },
    { title: 'Created', dataIndex: 'created_at', width: 190, render: dateTime },
    { title: 'Amount', dataIndex: 'amount', width: 140, align: 'right', render: (value) => <Text strong>{money(value)}</Text> },
    { title: 'Paid by', dataIndex: 'paid_by', width: 190, ellipsis: true },
    { title: 'Received by', dataIndex: ['received_by', 'name'], width: 180, render: (value) => value || '-' },
    {
      title: 'Status',
      dataIndex: 'status',
      width: 120,
      render: (value, row) => {
        const meta = row.is_deleted ? { label: 'Deleted', color: 'default' } : statusMeta[value] || statusMeta.received;
        return <Tag color={meta.color}>{meta.label}</Tag>;
      },
    },
    { title: 'Updated', dataIndex: 'updated_at', width: 190, render: (value, row) => (row.updated_by_user_id ? dateTime(value) : '-') },
    { title: 'Updated by', dataIndex: ['updated_by', 'name'], width: 180, render: (value) => value || '-' },
    { title: 'Reference customer', dataIndex: ['reference_customer', 'name'], width: 210, render: (value) => value || '-' },
    { title: 'Notes', dataIndex: 'notes', width: 260, ellipsis: true, render: (value) => value || '-' },
    {
      title: 'Actions',
      key: 'actions',
      fixed: 'right',
      width: compactActions ? 116 : 170,
      render: (_, row) => (
        <Space>
          <Button size="small" icon={<PrinterOutlined />} loading={printingReceivingUid === row.uid} onClick={() => printReceiving(row)} title="Print receiving receipt" aria-label="Print receiving receipt" />
          {canEdit && !row.is_deleted && <Button size="small" icon={<EditOutlined />} onClick={() => setEditing(row)} title="Edit receiving" aria-label="Edit receiving" />}
          {canDelete && !row.is_deleted && (
            <Popconfirm title="Delete this receiving?" description="The row will remain in history." okText="Delete" okButtonProps={{ danger: true }} onConfirm={() => deleteReceiving(row)}>
              <Button danger size="small" icon={<DeleteOutlined />} title="Delete receiving" aria-label="Delete receiving" />
            </Popconfirm>
          )}
        </Space>
      ),
    },
  ];

  const receiptCompany = printableReceiving?.company || {};
  const receiptCustomer = printableReceiving?.reference_customer || {};
  const receiptCompanyName = receiptCompany.display_name || receiptCompany.legal_name || user.company_name || 'Company';
  const receiptCompanyContact = [receiptCompany.address, receiptCompany.phone, receiptCompany.email].filter(Boolean);
  const receiptCustomerContact = [receiptCustomer.name, receiptCustomer.phone, receiptCustomer.email, receiptCustomer.address].filter(Boolean);

  return (
    <div className="page-shell page-fade-up receivings-page financial-entry-page">
      <div className="receivings-header">
        <div className="receivings-title">
          <Title level={2}>Receivings</Title>
          <Paragraph>Record simple incoming payments without opening customer receipts.</Paragraph>
        </div>
        <div className="receivings-stats">
          <div className="receivings-stat">
            <span>Active receivings</span>
            <strong>{receivings.filter((item) => !item.is_deleted).length}</strong>
          </div>
          <div className="receivings-stat">
            <span>Active total</span>
            <strong>{money(totalActive)}</strong>
          </div>
        </div>
      </div>

      <Card className="receivings-entry-card financial-entry-card">
        <Form form={form} layout="vertical">
          <Row gutter={[16, 12]} align="bottom">
            <Col xs={24} md={8} lg={4}>
              <Form.Item name="amount" label="Amount" rules={[{ required: true, message: 'Amount required' }]}>
                <InputNumber min={0.01} precision={2} style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={24} md={8} lg={5}>
              <Form.Item name="paid_by" label="Paid by" rules={[{ required: true, message: 'Paid by required' }]}>
                <Input maxLength={190} />
              </Form.Item>
            </Col>
            <Col xs={24} md={12} lg={5}>
              <Form.Item name="reference_customer_id" label="Reference customer">
                <Select allowClear showSearch optionFilterProp="label" options={customerOptions} />
              </Form.Item>
            </Col>
            <Col xs={24} md={12} lg={6}>
              <Form.Item name="notes" label="Notes">
                <Input maxLength={2000} />
              </Form.Item>
            </Col>
            <Col xs={24} lg={4}>
              <Form.Item label=" ">
                <Button type="primary" icon={<SaveOutlined />} loading={saving} onClick={submit}>
                  {compactActions ? null : 'Save receiving'}
                </Button>
              </Form.Item>
            </Col>
          </Row>
        </Form>
      </Card>

      <Card
        className="financial-history-card"
        title="Receiving history"
        extra={(
          <Space wrap>
            <Input.Search
              allowClear
              placeholder="Search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              onSearch={(value) => loadData(value)}
              style={{ width: compactActions ? 180 : 260 }}
            />
            <Button icon={<ReloadOutlined />} onClick={() => loadData()}>
              {compactActions ? null : 'Refresh'}
            </Button>
          </Space>
        )}
      >
        <Table
          title="Receivings"
          rowKey="uid"
          loading={loading}
          columns={columns}
          dataSource={receivings}
          csvFileName="receivings.csv"
          scroll={{ x: 1820 }}
          locale={{ emptyText: 'No receivings found' }}
        />
      </Card>
      {printableReceiving && (
        <div className="receipt-print-host receiving-print-host" aria-hidden="true">
          <div className="receipt-slip">
            <Watermark
              content={receiptCompany && receiptCompany.is_paid === false ? 'InYice' : undefined}
              rotate={-24}
              gap={[120, 100]}
              font={{ color: 'rgba(0, 0, 0, 0.12)', fontSize: 24, fontWeight: 700 }}
            >
              <div className="receipt-slip-header">
                <div className="receipt-slip-brand">
                  {receiptCompany.logo_url && <img className="receipt-slip-logo" src={receiptCompany.logo_url} alt={`${receiptCompanyName} logo`} />}
                  <div>
                    <Title level={3}>{receiptCompanyName}</Title>
                    {receiptCompanyContact.map((item) => <Text key={item}>{item}<br /></Text>)}
                  </div>
                </div>
                <div className="receipt-slip-heading">
                  <Title level={1}>RECEIVING</Title>
                  <Text strong># {printableReceiving.receiving_number}</Text>
                  <Text type="secondary">Created</Text>
                  <Title level={5}>{dateTime(printableReceiving.created_at || printableReceiving.received_at)}</Title>
                </div>
              </div>

              <div className="receipt-slip-meta">
                <div>
                  <Text type="secondary">Received From</Text>
                  <div className="receipt-slip-party">
                    <Text strong>{printableReceiving.paid_by || '-'}</Text><br />
                    {receiptCustomerContact.map((item, index) => <Text key={`${item}-${index}`}>{item}<br /></Text>)}
                  </div>
                </div>
                <div />
              </div>

              <div className="receipt-slip-amount">
                <Text>Amount Received</Text>
                <Title level={2}>{money(printableReceiving.amount)}</Title>
              </div>

              <Divider />
              <div className="receipt-slip-notes">
                <Text strong>Notes</Text>
                <Paragraph>{printableReceiving.notes || 'Amount received and recorded.'}</Paragraph>
              </div>
              <div className="receipt-slip-signatures">
                <div><span /> <Text>Received by</Text></div>
                <div><span /> <Text>Checked by accounts/admin</Text></div>
              </div>
            </Watermark>
          </div>
        </div>
      )}

      <Modal
        title={editing?.receiving_number ? `Edit receiving ${editing.receiving_number}` : 'Edit receiving'}
        open={!!editing}
        onCancel={() => setEditing(null)}
        cancelButtonProps={{ danger: true }}
        onOk={saveEdit}
        confirmLoading={saving}
        okText="Save changes"
      >
        <Form form={editForm} layout="vertical">
          <Form.Item name="amount" label="Amount" rules={[{ required: true, message: 'Amount required' }]}>
            <InputNumber min={0.01} precision={2} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="status" label="Status">
            <Select options={statusOptions} />
          </Form.Item>
          <Form.Item name="paid_by" label="Paid by" rules={[{ required: true, message: 'Paid by required' }]}>
            <Input maxLength={190} />
          </Form.Item>
          <Form.Item name="reference_customer_id" label="Reference customer">
            <Select allowClear showSearch optionFilterProp="label" options={customerOptions} />
          </Form.Item>
          <Form.Item name="notes" label="Notes">
            <Input maxLength={2000} />
          </Form.Item>
          <Form.Item label="Received by">
            <Input readOnly value={editing?.received_by?.name || '-'} />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  );
}
