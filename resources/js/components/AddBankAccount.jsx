import React, { useRef, useState } from 'react';
import { PlusOutlined } from '@ant-design/icons';
import { Alert, Button, Form, Input, InputNumber, Modal, Select } from 'antd';
import { message } from '../services/feedback';

export default function AddBankAccount({ currency, onCreated }) {
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [currencies, setCurrencies] = useState([]);
  const [error, setError] = useState('');
  const submitting = useRef(false);
  const [form] = Form.useForm();

  const show = async () => {
    setLoading(true);
    try {
      const response = await fetch('/api/v1/registration/currencies', { headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error('Could not load currencies. Please try again.');
      const data = await response.json();
      if (!Array.isArray(data) || !data.length) throw new Error('No currencies are available. Please try again.');
      setCurrencies(data);
      setError('');
      setOpen(true);
    } catch (e) { message.error(e.message); } finally { setLoading(false); }
  };

  const save = async (values) => {
    if (submitting.current) return;
    submitting.current = true;
    setSaving(true);
    setError('');
    try {
      const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
      const response = await fetch('/api/v1/accounts/bank', {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...values, opening_balance: values.opening_balance ?? 0 }),
      });
      const data = await response.json();
      if (!response.ok) {
        if (data.errors) form.setFields(Object.entries(data.errors).map(([name, errors]) => ({ name, errors })));
        throw new Error(data.message || 'Could not add bank account.');
      }
      onCreated(data.account);
      setOpen(false);
      message.success('Bank account added. Select it in the cash / bank account field.');
    } catch (e) { setError(e.message); } finally { submitting.current = false; setSaving(false); }
  };

  return <>
    <Button icon={<PlusOutlined />} loading={loading} onClick={show}>Add bank account</Button>
    <Modal title="Add bank account" open={open} footer={null} destroyOnHidden
      closable={!saving} mask={{ closable: !saving }} keyboard={!saving} onCancel={() => { if (!saving) setOpen(false); }}>
      <Form form={form} layout="vertical" preserve={false} disabled={saving} onFinish={save}
        initialValues={{ currency_code: currencies.some(item => item.code === currency) ? currency : undefined, opening_balance: 0 }}>
        {error && <Alert type="error" showIcon title={error} style={{ marginBottom: 16 }} />}
        <Form.Item name="bank_name" label="Bank name" rules={[{ required: true, whitespace: true }]} normalize={value => value.trimStart()}><Input maxLength={200} /></Form.Item>
        <Form.Item name="account_number" label="Account number" rules={[{ required: true, whitespace: true }]} normalize={value => value.trim()}><Input maxLength={50} /></Form.Item>
        <Form.Item name="account_holder" label="Account holder" rules={[{ required: true, whitespace: true }]} normalize={value => value.trimStart()}><Input maxLength={200} /></Form.Item>
        <Form.Item name="currency_code" label="Currency" rules={[{ required: true }]}><Select showSearch optionFilterProp="label" options={currencies.map(item => ({ value: item.code, label: `${item.code} — ${item.name}` }))} /></Form.Item>
        <Form.Item name="opening_balance" label="Opening balance" extra="Enter the balance before transactions recorded in this portal."><InputNumber min={0} precision={4} style={{ width: '100%' }} /></Form.Item>
        <Button type="primary" htmlType="submit" loading={saving}>Save bank account</Button>
      </Form>
    </Modal>
  </>;
}
