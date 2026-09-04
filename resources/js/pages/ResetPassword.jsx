import React, { useMemo, useState } from 'react';
import { Form, Input, Button, Card, Typography, Alert, Space } from 'antd';
import { ArrowLeftOutlined, LockOutlined, MailOutlined } from '@ant-design/icons';
import { useLocation, useNavigate } from 'react-router-dom';
import { message } from '../services/feedback';

const { Title, Paragraph } = Typography;

export default function ResetPassword() {
  const [loading, setLoading] = useState(false);
  const [completed, setCompleted] = useState(false);
  const [form] = Form.useForm();
  const location = useLocation();
  const navigate = useNavigate();
  const params = useMemo(() => new URLSearchParams(location.search), [location.search]);
  const token = params.get('token') || '';
  const email = params.get('email') || '';

  const onFinish = async (values) => {
    setLoading(true);
    try {
      const response = await fetch('/api/v1/auth/reset-password', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          ...values,
          token,
        }),
      });
      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        const validationError = data?.errors ? Object.values(data.errors).flat().join(' ') : null;
        throw new Error(validationError || data.error || data.message || 'Unable to reset password');
      }

      setCompleted(true);
      message.success(data.message || 'Password reset successfully');
    } catch (error) {
      message.error(error.message || 'Unable to reset password');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="auth-wrap page-fade-up">
      <Card className="auth-panel auth-form border-beam-aurora" style={{ width: '100%', maxWidth: 480 }}>
        <Title level={3} style={{ marginBottom: 6 }}>Choose New Password</Title>
        <Paragraph type="secondary" style={{ marginTop: 0 }}>
          Set a new password for your InYice OS account.
        </Paragraph>

        {!token && (
          <Alert
            type="error"
            message="This reset link is missing a token."
            showIcon
            style={{ marginBottom: 18 }}
          />
        )}

        {completed && (
          <Alert
            type="success"
            message="Password reset successfully. You can sign in now."
            showIcon
            style={{ marginBottom: 18 }}
          />
        )}

        <Form
          form={form}
          layout="vertical"
          onFinish={onFinish}
          autoComplete="off"
          initialValues={{ email }}
          disabled={!token || completed}
        >
          <Form.Item
            label="Email"
            name="email"
            rules={[
              { required: true, message: 'Please enter your email' },
              { type: 'email', message: 'Invalid email format' },
            ]}
          >
            <Input prefix={<MailOutlined />} placeholder="admin@agency.com" size="large" autoComplete="email" />
          </Form.Item>

          <Form.Item
            label="New Password"
            name="password"
            rules={[
              { required: true, message: 'Please enter a new password' },
              { min: 8, message: 'Use at least 8 characters' },
            ]}
          >
            <Input.Password prefix={<LockOutlined />} placeholder="At least 8 characters" size="large" autoComplete="new-password" />
          </Form.Item>

          <Form.Item
            label="Confirm Password"
            name="password_confirmation"
            dependencies={['password']}
            rules={[
              { required: true, message: 'Please confirm your new password' },
              ({ getFieldValue }) => ({
                validator(_, value) {
                  if (!value || getFieldValue('password') === value) {
                    return Promise.resolve();
                  }
                  return Promise.reject(new Error('Passwords do not match'));
                },
              }),
            ]}
          >
            <Input.Password prefix={<LockOutlined />} placeholder="Repeat new password" size="large" autoComplete="new-password" />
          </Form.Item>

          <Button type="primary" htmlType="submit" size="large" block icon={<LockOutlined />} loading={loading}>
            Reset Password
          </Button>
        </Form>

        <Space style={{ marginTop: 18 }}>
          <Button type="link" icon={<ArrowLeftOutlined />} onClick={() => navigate('/login')}>
            Back to sign in
          </Button>
        </Space>
      </Card>
    </div>
  );
}
