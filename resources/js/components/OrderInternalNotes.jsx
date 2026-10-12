import React, { useId, useState } from 'react';
import { Button, Input, List, Typography, theme } from 'antd';
import { MessageOutlined, SendOutlined } from '@ant-design/icons';
import { message } from '../services/feedback';
import './OrderInternalNotes.css';

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
  const inputId = useId();

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
    : '0 notes';
  const canAddNote = body.trim().length > 0;

  return (
    <section
      className="internal-notes-panel"
      aria-labelledby={`${inputId}-title`}
      style={{
        '--internal-notes-bg': token.colorBgContainer,
        '--internal-notes-border': token.colorBorderSecondary,
        '--internal-notes-soft-bg': token.colorFillQuaternary,
        '--internal-notes-text': token.colorText,
        '--internal-notes-muted': token.colorTextSecondary,
        '--internal-notes-accent': token.colorPrimary,
        '--internal-notes-accent-bg': token.colorPrimaryBg,
      }}
    >
        <div className="internal-notes-header">
          <div className="internal-notes-heading">
            <span className="internal-notes-icon" aria-hidden="true"><MessageOutlined /></span>
            <div>
              <Title id={`${inputId}-title`} level={4} style={{ margin: 0, fontSize: 16 }}>Internal Notes</Title>
              <Text type="secondary">Keep your team up to date.</Text>
            </div>
          </div>
          <span className="internal-notes-count">{noteCountLabel}</span>
        </div>
        <List
          className="internal-notes-list"
          size="small"
          dataSource={notes}
          locale={{
            emptyText: (
              <div className="internal-notes-empty">
                <Text strong>No notes yet</Text>
                <Text type="secondary">Add a reminder or an update about this order.</Text>
              </div>
            ),
          }}
          renderItem={(note) => (
            <List.Item key={note.uid} className="internal-note-item">
              <div className="internal-note-content">
                <div className="internal-note-meta">
                  <Text strong>{note.user?.name || 'Staff'}</Text>
                  <Text type="secondary">{formatDateTime(note.created_at)}</Text>
                </div>
                <Paragraph className="internal-note-body">{note.body}</Paragraph>
              </div>
            </List.Item>
          )}
        />
        <div className="internal-notes-composer">
          <label className="internal-notes-label" htmlFor={inputId}>New note</label>
          <TextArea
            id={inputId}
            className="internal-notes-input"
            autoSize={{ minRows: 3, maxRows: 8 }}
            maxLength={5000}
            value={body}
            disabled={saving}
            onChange={(event) => setBody(event.target.value)}
            placeholder="Write an update for your team…"
          />
          <div className="internal-notes-actions">
            <Text type="secondary" className="internal-notes-counter">{body.length.toLocaleString()} / 5,000</Text>
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
        </div>
    </section>
  );
}
