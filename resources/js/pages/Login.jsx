import React, { useState } from 'react';
import { App as AntdApp, Form, Input, Button, Card, Checkbox, Divider, Typography, Space } from 'antd';
import { UserOutlined, LockOutlined } from '@ant-design/icons';
import { useNavigate, Link } from 'react-router-dom';

const { Title, Paragraph } = Typography;

export default function Login({ onLoginSuccess }) {
  const [loading, setLoading] = useState(false);
  const [form] = Form.useForm();
  const navigate = useNavigate();
  const { message } = AntdApp.useApp();

  const onFinish = async (values) => {
    setLoading(true);
    try {
      const response = await fetch('/api/v1/auth/login', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          email: values.email,
          password: values.password,
        }),
      });

      if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        const validationError = error?.errors
          ? Object.values(error.errors).flat().join(' ')
          : null;

        throw new Error(validationError || error.error || 'Invalid credentials');
      }

      const data = await response.json();
      localStorage.setItem('auth_token', data.token);
      localStorage.setItem('token', data.token);
      localStorage.setItem('user', JSON.stringify(data.user));
      onLoginSuccess?.(data.user);

      message.success('Welcome back!');
      navigate(data.user?.is_system_user ? '/internal' : '/', { replace: true });
    } catch (error) {
      message.error(error.message || 'Login failed');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="auth-wrap page-fade-up">
      <div className="auth-grid">
        <Card className="auth-panel auth-hero border-beam-aurora stagger-1">
          <img className="auth-logo" src="/images/icons/icon-512x512.png" alt="InYice OS" />
          <Title level={2}>Welcome to InYice OS</Title>
          <Paragraph>
            One focused workspace for sales, invoicing, payments, and reporting. Designed for fast operations and clear financial visibility.
          </Paragraph>
          <Space orientation="vertical" size={10} style={{ width: '100%' }}>
            <TextRow label="Operational speed" value="Quote to payment flow" />
            <TextRow label="Finance control" value="Aging, revenue, settlements" />
            <TextRow label="Security" value="Tenant-isolated + role based" />
          </Space>
        </Card>

        <Card className="auth-panel auth-form border-beam-aurora stagger-2">
          <Title level={3} style={{ marginBottom: 6 }}>Sign In</Title>
          <Paragraph type="secondary" style={{ marginTop: 0 }}>
            Continue to your agency workspace.
          </Paragraph>

          <Form form={form} layout="vertical" onFinish={onFinish} autoComplete="off">
              <Form.Item
                label="Email"
                name="email"
                rules={[
                  { required: true, message: 'Please enter your email' },
                  { type: 'email', message: 'Invalid email format' },
                ]}
              >
                <Input
                  prefix={<UserOutlined />}
                  placeholder="admin@agency.com"
                  size="large"
                  autoComplete="email"
                />
              </Form.Item>

              <Form.Item
                label="Password"
                name="password"
                rules={[
                  { required: true, message: 'Please enter your password' },
                ]}
              >
                <Input.Password
                  prefix={<LockOutlined />}
                  placeholder="Enter password"
                  size="large"
                  autoComplete="current-password"
                />
              </Form.Item>

              <Form.Item name="remember" valuePropName="checked">
                <Checkbox>Remember me</Checkbox>
              </Form.Item>

              <Button
                type="primary"
                htmlType="submit"
                size="large"
                block
                loading={loading}
                style={{ marginBottom: 14 }}
              >
                Sign In
              </Button>
            </Form>

            <Divider>OR</Divider>

            <div style={{ textAlign: 'center' }} className="stagger-3">
              <Paragraph>New agency?</Paragraph>
              <Button
                type="default"
                size="large"
                block
                onClick={() => navigate('/register')}
              >
                Register Your Agency
              </Button>
            </div>

            <Divider style={{ margin: '24px 0 12px' }} />
            <Paragraph type="secondary" style={{ marginBottom: 0, fontSize: 12 }}>
              Secure login with automatic session token rotation.
            </Paragraph>
          </Card>
        </div>
    </div>
  );
}

function TextRow({ label, value }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12 }}>
      <span style={{ color: 'var(--app-muted)' }}>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}
