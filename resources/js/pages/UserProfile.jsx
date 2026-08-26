import React, { useState } from 'react';
import { Button, Card, Descriptions, Form, Input, Tag, Typography } from 'antd';
import { CopyOutlined, SaveOutlined } from '@ant-design/icons';
import { message } from '../services/feedback';

const { Title, Paragraph } = Typography;
const USER_UPDATED_EVENT = 'inyice:user-updated';

const authHeaders = () => {
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  };
};

export default function UserProfile() {
  const [form] = Form.useForm();
  const [user, setUser] = useState(() => JSON.parse(localStorage.getItem('user') || '{}'));
  const [saving, setSaving] = useState(false);

  const copyEmail = async () => {
    try {
      await navigator.clipboard.writeText(user.email || '');
      message.success('Email copied');
    } catch {
      message.error('Could not copy email');
    }
  };

  const updateProfile = async (values) => {
    const name = values.name?.trim();
    setSaving(true);

    try {
      const response = await fetch('/api/v1/user', {
        method: 'PATCH',
        headers: authHeaders(),
        body: JSON.stringify({ name }),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || data.errors?.name?.[0] || 'Could not update profile');
      }

      const nextUser = { ...user, ...data.user };
      localStorage.setItem('user', JSON.stringify(nextUser));
      window.dispatchEvent(new CustomEvent(USER_UPDATED_EVENT, { detail: nextUser }));
      setUser(nextUser);
      form.setFieldsValue({ name: nextUser.name || '' });
      message.success('Profile updated');
    } catch (error) {
      message.error(error.message || 'Could not update profile');
    } finally {
      setSaving(false);
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

      <Card
        className="border-beam-aurora"
        title="Account Details"
        extra={<Button icon={<CopyOutlined />} onClick={copyEmail}>Copy Email</Button>}
      >
        <Form
          form={form}
          layout="vertical"
          initialValues={{ name: user.name || '' }}
          onFinish={updateProfile}
          style={{ maxWidth: 420, marginBottom: 16 }}
        >
          <Form.Item
            name="name"
            label="Name"
            rules={[
              { required: true, whitespace: true, message: 'Name required' },
              { max: 200, message: 'Name must be 200 characters or less' },
            ]}
          >
            <Input maxLength={200} />
          </Form.Item>
          <Button type="primary" htmlType="submit" icon={<SaveOutlined />} loading={saving}>
            Save Name
          </Button>
        </Form>

        <Descriptions column={1} bordered size="middle">
          <Descriptions.Item label="Email">{user.email || 'N/A'}</Descriptions.Item>
          <Descriptions.Item label="Role">{user.role_name || user.role || 'N/A'}</Descriptions.Item>
          <Descriptions.Item label="Status">
            <Tag color="processing">Signed In</Tag>
          </Descriptions.Item>
        </Descriptions>
      </Card>
    </div>
  );
}
