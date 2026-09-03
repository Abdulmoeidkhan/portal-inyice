import React, { useState } from 'react';
import { Button, Empty, Input, List, Space, Typography, theme } from 'antd';
import { SendOutlined } from '@ant-design/icons';
import { message } from '../services/feedback';

const { Text, Title, Paragraph } = Typography;
const { TextArea } = Input;

const authHeaders = (json = false) => {
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  return {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
    ...(json ? { 'Content-Type': 'application/json' } : {}),
  };
};

const formatDateTime = (value) => {
  if (!value) {
    return '';
  }

  return new Date(value).toLocaleString();
};

export default function OrderInternalNotes({ order, onOrderChange }) {
  const notes = Array.isArray(order?.internal_notes) ? order.internal_notes : null;
  const [body, setBody] = useState('');
  const [saving, setSaving] = useState(false);
  const { token } = theme.useToken();

  if (!order?.uid || notes === null) {
    return null;
  }

  const addNote = async () => {
    const trimmedBody = body.trim();

    if (!trimmedBody) {
      message.error('Enter an internal note');
      return;
    }

    setSaving(true);
    try {
      const response = await fetch(`/api/v1/orders/${order.uid}/internal-notes`, {
        method: 'POST',
        headers: authHeaders(true),
        body: JSON.stringify({ body: trimmedBody }),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data?.message || 'Failed to add internal note');
      }

      const nextNotes = [...notes, data.note].filter(Boolean);
      onOrderChange?.({ ...order, internal_notes: nextNotes });
      setBody('');
      message.success('Internal note added');
    } catch (error) {
      message.error(error.message || 'Failed to add internal note');
    } finally {
      setSaving(false);
    }
  };

  const noteCountLabel = notes.length > 0
    ? `${notes.length} note${notes.length === 1 ? '' : 's'}`
    : 'No data to show';
  const canAddNote = body.trim().length > 0;

  return (
    <section
      className="internal-notes-panel"
      style={{
        '--internal-notes-bg': token.colorBgContainer,
        '--internal-notes-border': token.colorBorderSecondary,
        '--internal-notes-soft-bg': token.colorFillQuaternary,
        '--internal-notes-text': token.colorText,
        '--internal-notes-muted': token.colorTextSecondary,
      }}
    >
      <Space orientation="vertical" size={12} style={{ width: '100%' }}>
        <div className="internal-notes-header">
          <Title level={4} style={{ margin: 0 }}>Internal Notes</Title>
          <Text type="secondary">{noteCountLabel}</Text>
        </div>
        <List
          className="internal-notes-list"
          size="small"
          dataSource={notes}
          locale={{
            emptyText: (
              <Empty
                image={Empty.PRESENTED_IMAGE_SIMPLE}
                description={<Text type="secondary">No data to show</Text>}
              />
            ),
          }}
          renderItem={(note) => (
            <List.Item key={note.uid} className="internal-note-item">
              <div className="internal-note-content">
                <div className="internal-note-meta">
                  <Text strong>{note.user?.name || 'Staff'}</Text> &nbsp;
                  <Text type="secondary">{formatDateTime(note.created_at)}</Text>
                </div>
                <Paragraph className="internal-note-body">{note.body}</Paragraph>
              </div>
            </List.Item>
          )}
        />
        <div className="internal-notes-composer">
          <TextArea
            className="internal-notes-input"
            autoSize={{ minRows: 2, maxRows: 5 }}
            maxLength={5000}
            value={body}
            onChange={(event) => setBody(event.target.value)}
            placeholder="Add an internal note"
          />
          <br />
          <br />
          <Button
            className="internal-notes-submit"
            type="primary"
            icon={<SendOutlined />}
            loading={saving}
            disabled={!canAddNote}
            onClick={addNote}
          >
            Add Note
          </Button>
        </div>
      </Space>
    </section>
  );
}
