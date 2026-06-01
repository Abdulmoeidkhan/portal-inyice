import React from 'react';
import { Card, Descriptions, Typography, Tag, Button, message } from 'antd';

const { Title, Paragraph } = Typography;

export default function UserProfile() {
  const user = JSON.parse(localStorage.getItem('user') || '{}');

  const copyEmail = async () => {
    try {
      await navigator.clipboard.writeText(user.email || '');
      message.success('Email copied');
    } catch {
      message.error('Could not copy email');
    }
  };

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2} style={{ margin: 0 }}>User Profile</Title>
        <Paragraph type="secondary" style={{ marginTop: 8 }}>
          Personal account and role access information.
        </Paragraph>
      </div>

      <Card className="border-beam-aurora" title="Account Details" extra={<Button onClick={copyEmail}>Copy Email</Button>}>
        <Descriptions column={1} bordered size="middle">
          <Descriptions.Item label="Name">{user.name || 'N/A'}</Descriptions.Item>
          <Descriptions.Item label="Email">{user.email || 'N/A'}</Descriptions.Item>
          <Descriptions.Item label="Role">{user.role || 'N/A'}</Descriptions.Item>
          <Descriptions.Item label="User ID">{user.id || 'N/A'}</Descriptions.Item>
          <Descriptions.Item label="Status">
            <Tag color="processing">Signed In</Tag>
          </Descriptions.Item>
        </Descriptions>
      </Card>
    </div>
  );
}
