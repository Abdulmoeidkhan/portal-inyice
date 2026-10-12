import React, { useState } from 'react';
import { Alert, Button, Card, Form, Input, Typography } from 'antd';
import { accountingApi, localDate } from './api';
import { dialog, message } from '../../services/feedback';

export default function PeriodTab({ book, isOwner, onChange }) {
  const [saving, setSaving] = useState(false);
  return <Card title="Close an accounting period">
    <Typography.Paragraph>Currently locked through: <strong>{book.locked_through || 'No period closed'}</strong>. Closing a period prevents new or edited accounting entries dated on or before that day. Resolve pending drafts first.</Typography.Paragraph>
    <Alert type="warning" showIcon title="Period locks apply to this accounting ledger only" description="Existing operational screens keep their current behavior. Closed periods cannot be reopened here; correct posted entries with reversals dated in an open period." style={{ marginBottom: 20 }} />
    {isOwner ? <Form layout="vertical" style={{ maxWidth: 640 }} onFinish={(values) => dialog.confirm({
      title: `Close accounting through ${values.locked_through}?`,
      content: 'This permanently advances the accounting lock date. Review reports and opening balances before continuing.',
      onOk: async () => {
        setSaving(true);
        try { await accountingApi('/period-lock', 'POST', values); onChange(); message.success('Accounting period closed.'); }
        catch (e) { message.error(e.message); throw e; } finally { setSaving(false); }
      },
    })}>
      <Form.Item name="locked_through" label="Lock through" rules={[{ required: true }]}><Input type="date" min={book.start_date} max={localDate()} /></Form.Item>
      <Form.Item name="reason" label="Reason / closing reference" rules={[{ required: true, whitespace: true }]}><Input.TextArea maxLength={2000} /></Form.Item>
      <Button type="primary" htmlType="submit" loading={saving}>Review and close period</Button>
    </Form> : <Typography.Text>Only the company owner can close a period.</Typography.Text>}
  </Card>;
}

