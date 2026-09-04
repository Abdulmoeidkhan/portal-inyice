import React, { useState } from 'react';
import { Form, Input, Button, Card, Typography, Alert, Space } from 'antd';
import { ArrowLeftOutlined, MailOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { message } from '../services/feedback';

const { Title, Paragraph } = Typography;

export default function ForgotPassword() {
  const [loading, setLoading] = useState(false);
  const [verificationLoading, setVerificationLoading] = useState(false);
  const [sentMessage, setSentMessage] = useState('');
  const [form] = Form.useForm();
  const navigate = useNavigate();

  const onFinish = async (values) => {
    setLoading(true);
    try {
      const response = await fetch('/api/v1/auth/forgot-password', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify(values),
      });
      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        throw new Error(data.error || data.message || 'Unable to send reset link');
      }

      setSentMessage(data.message || 'Password reset email sent.');
    } catch (error) {
      message.error(error.message || 'Unable to send reset link');
    } finally {
      setLoading(false);
    }
  };

  const resendVerificationEmail = async () => {
    try {
      const { email } = await form.validateFields(['email']);

      setVerificationLoading(true);
      const response = await fetch('/api/v1/auth/email/verification-notification', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({ email }),
      });
      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        throw new Error(data.error || data.message || 'Unable to send verification email');
      }

      setSentMessage(data.message || 'Verification email sent.');
    } catch (error) {
      if (error?.errorFields) {
        return;
      }

      message.error(error.message || 'Unable to send verification email');
    } finally {
      setVerificationLoading(false);
    }
  };

  return (
    <div className="auth-wrap page-fade-up">
      <Card className="auth-panel auth-form border-beam-aurora" style={{ width: '100%', maxWidth: 460 }}>
        <Title level={3} style={{ marginBottom: 6 }}>Reset Password</Title>
        <Paragraph type="secondary" style={{ marginTop: 0 }}>
          Enter your account email to receive a reset link.
        </Paragraph>

        {sentMessage && <Alert type="success" title={sentMessage} showIcon style={{ marginBottom: 18 }} />}

        <Form form={form} layout="vertical" onFinish={onFinish} autoComplete="off">
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

          <Button type="primary" htmlType="submit" size="large" block icon={<MailOutlined />} loading={loading}>
            Send Reset Link
          </Button>

          <Button
            size="large"
            block
            icon={<MailOutlined />}
            loading={verificationLoading}
            onClick={resendVerificationEmail}
            style={{ marginTop: 12 }}
          >
            Resend Verification Email
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
