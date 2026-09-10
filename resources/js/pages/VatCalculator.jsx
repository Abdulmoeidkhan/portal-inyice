import React, { useEffect, useRef, useState } from 'react';
import { Alert, Button, Card, Col, Form, Input, InputNumber, Row, Select, Space, Table, Typography } from 'antd';
import { DeleteOutlined, PlusOutlined, ReloadOutlined } from '@ant-design/icons';
import { calculateVat, formatVatMoney } from '../services/vatCalculator';

const { Title, Paragraph, Text } = Typography;
const newRow = (id) => ({ id, description: '', amount: null, rate: null });

export default function VatCalculator() {
  const [rows, setRows] = useState(() => [newRow(1)]);
  const nextId = useRef(2);
  const [mode, setMode] = useState('exclusive');
  const [currency, setCurrency] = useState('');
  const [precision, setPrecision] = useState(2);
  const [profileError, setProfileError] = useState(false);
  const currencyEdited = useRef(false);

  useEffect(() => {
    const controller = new AbortController();
    const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
    fetch('/api/v1/company-profile', {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      signal: controller.signal,
    }).then(async (response) => {
      if (!response.ok) throw new Error('Company profile unavailable');
      const data = await response.json();
      if (!currencyEdited.current && data.company?.base_currency_code) {
        const code = data.company.base_currency_code;
        setCurrency(code);
        setPrecision(new Intl.NumberFormat('en', { style: 'currency', currency: code }).resolvedOptions().maximumFractionDigits);
      }
    }).catch((error) => { if (error.name !== 'AbortError') setProfileError(true); });
    return () => controller.abort();
  }, []);

  const update = (id, field, value) => setRows((current) => current.map((row) => row.id === id ? { ...row, [field]: value } : row));
  const result = calculateVat(rows, mode, precision);
  const money = (value) => `${currency ? `${currency} ` : ''}${formatVatMoney(value, precision)}`;
  const totalColumns = [
    { title: 'VAT rate', dataIndex: 'rate', render: (value) => `${value}%` },
    ...['net', 'vat', 'gross'].map((field) => ({ title: field === 'vat' ? 'VAT' : field === 'net' ? 'Net amount' : 'Gross total', dataIndex: field, align: 'right', render: money })),
  ];

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2}>VAT Calculator</Title>
        <Paragraph>Add VAT or extract included VAT using a separate percentage for each item.</Paragraph>
        {profileError && <Alert type="info" title="Company currency could not be loaded. Enter your currency below." style={{ marginBottom: 16 }} />}
        <Form layout="vertical">
          <Row gutter={[16, 0]}>
            <Col xs={24} md={12}><Form.Item label="Calculation mode"><Select aria-label="Calculation mode" value={mode} onChange={setMode} options={[{ value: 'exclusive', label: 'Add VAT to net amounts' }, { value: 'inclusive', label: 'Extract VAT from gross amounts' }]} /></Form.Item></Col>
            <Col xs={12} md={6}><Form.Item label="Currency"><Input aria-label="Currency" value={currency} placeholder="e.g. PKR" maxLength={3} onChange={(event) => { currencyEdited.current = true; const code = event.target.value.toUpperCase().replace(/[^A-Z]/g, ''); setCurrency(code); if (code.length === 3) setPrecision(new Intl.NumberFormat('en', { style: 'currency', currency: code }).resolvedOptions().maximumFractionDigits); }} /></Form.Item></Col>
            <Col xs={12} md={6}><Form.Item label="Decimal places"><Select aria-label="Decimal places" value={precision} onChange={(value) => { currencyEdited.current = true; setPrecision(value); }} options={[0, 1, 2, 3, 4].map((value) => ({ value, label: String(value) }))} /></Form.Item></Col>
          </Row>
        </Form>
        <Text type="secondary">Currency labels amounts; no exchange conversion is applied. Amounts and VAT are rounded per row, half up, to {precision} decimal places.</Text>
      </div>
      <Space orientation="vertical" size={16} style={{ width: '100%' }}>
        {result.lines.map((row, index) => (
          <Card key={row.id} title={`Item ${index + 1}`} extra={<Button aria-label={`Remove item ${index + 1}`} icon={<DeleteOutlined />} disabled={rows.length === 1} onClick={() => setRows((current) => current.filter((item) => item.id !== row.id))} />}>
            <Form layout="vertical">
              <Row gutter={[16, 0]}>
                <Col xs={24} md={12}><Form.Item label="Description (optional)"><Input aria-label={`Item ${index + 1} description`} value={row.description} onChange={(event) => update(row.id, 'description', event.target.value)} /></Form.Item></Col>
                <Col xs={12} md={6}><Form.Item label={mode === 'exclusive' ? 'Net amount' : 'VAT-inclusive amount'}><InputNumber aria-label={`Item ${index + 1} amount`} stringMode min="0" value={row.amount} placeholder="Enter amount" style={{ width: '100%' }} onChange={(value) => update(row.id, 'amount', value)} /></Form.Item></Col>
                <Col xs={12} md={6}><Form.Item label="VAT rate (%)"><InputNumber aria-label={`Item ${index + 1} VAT rate`} stringMode min="0" value={row.rate} placeholder="Enter rate" style={{ width: '100%' }} onChange={(value) => update(row.id, 'rate', value)} /></Form.Item></Col>
              </Row>
            </Form>
            {row.error ? <Text type="secondary" role="status">{row.error}</Text> : <Space wrap size="large"><Text>Net: <Text strong>{money(row.net)}</Text></Text><Text>VAT: <Text strong>{money(row.vat)}</Text></Text><Text>Gross: <Text strong>{money(row.gross)}</Text></Text></Space>}
          </Card>
        ))}
        <Space wrap>
          <Button icon={<PlusOutlined />} onClick={() => { const id = nextId.current++; setRows((current) => [...current, newRow(id)]); }}>Add item</Button>
          <Button icon={<ReloadOutlined />} onClick={() => { setRows([newRow(nextId.current++)]); setMode('exclusive'); }}>Reset calculation</Button>
        </Space>
        {result.valid ? <>
          <Row gutter={[16, 16]} aria-live="polite">
            {['net', 'vat', 'gross'].map((field) => <Col xs={24} md={8} key={field}><Card><Text type="secondary">{field === 'vat' ? 'Total VAT' : field === 'net' ? 'Total net amount' : 'Total gross amount'}</Text><Title level={3} style={{ margin: '8px 0', overflowWrap: 'anywhere' }}>{money(result.totals[field])}</Title></Card></Col>)}
          </Row>
          <Card title="Breakdown by VAT rate"><Table rowKey="rate" columns={totalColumns} dataSource={result.groups} pagination={false} scroll={{ x: 560 }} /></Card>
        </> : <Alert type="info" title="Enter a valid amount and VAT rate for every item to see totals. Use 0 for a zero VAT rate." />}
      </Space>
    </div>
  );
}
