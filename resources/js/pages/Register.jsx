import React, { useState, useEffect } from 'react';
import { Steps, Form, Input, Select, Button, Card, Typography, Space, theme } from 'antd';
import { message } from '../services/feedback';
import { UserOutlined, BuildOutlined, LockOutlined, CheckCircleOutlined, MailOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';

const { Title, Paragraph, Text } = Typography;

async function readJsonResponse(response, fallbackMessage) {
  const contentType = response.headers.get('content-type') || '';

  if (contentType.includes('application/json')) {
    return response.json();
  }

  const text = await response.text().catch(() => '');
  const returnedHtml = text.trim().startsWith('<!DOCTYPE') || text.trim().startsWith('<html');

  if (returnedHtml) {
    throw new Error('Server returned an HTML page instead of JSON. Make sure the Laravel app is serving /api or the Vite API proxy is running.');
  }

  throw new Error(fallbackMessage);
}

const steps = [
  {
    title: 'Agency Info',
    icon: <BuildOutlined />,
  },
  {
    title: 'Company Details',
    icon: <BuildOutlined />,
  },
  {
    title: 'Admin Account',
    icon: <UserOutlined />,
  },
  {
    title: 'Complete',
    icon: <CheckCircleOutlined />,
  },
];

export default function Register({ onRegistered }) {
  const [current, setCurrent] = useState(0);
  const [loading, setLoading] = useState(false);
  const [currencies, setCurrencies] = useState([]);
  const [timezones, setTimezones] = useState([]);
  const [formData, setFormData] = useState({});
  const [registered, setRegistered] = useState(null);
  const [form] = Form.useForm();
  const navigate = useNavigate();
  const { token: themeToken } = theme.useToken();

  useEffect(() => {
    fetchCurrenciesAndTimezones();
  }, []);

  const fetchCurrenciesAndTimezones = async () => {
    try {
      const [currRes, tzRes] = await Promise.all([
        fetch('/api/v1/registration/currencies'),
        fetch('/api/v1/registration/timezones'),
      ]);

      if (currRes.ok) {
        const currData = await currRes.json();
        setCurrencies(currData);
      }

      if (tzRes.ok) {
        const tzData = await tzRes.json();
        setTimezones(tzData);
      }
    } catch {
      message.warning('Could not load registration options. Please refresh and try again.');
    }
  };

  const handleNext = async () => {
    try {
      const values = await form.validateFields();
      setFormData({ ...formData, ...values });

      if (current === 2) {
        await submitRegistration({ ...formData, ...values });
      } else {
        setCurrent(current + 1);
      }
    } catch (error) {
      message.error('Please fill all required fields');
    }
  };

  const submitRegistration = async (data) => {
    setLoading(true);
    try {
      const response = await fetch('/api/v1/registration/register', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify(data),
      });

      const result = await readJsonResponse(response, 'Registration failed');

      if (!response.ok) {
        const validationError = result?.errors ? Object.values(result.errors).flat().join(' ') : null;
        throw new Error(validationError || result.error || result.message || 'Registration failed');
      }

      setRegistered(result);
      setCurrent(3);

      setTimeout(() => {
        finishRegistration(result);
      }, 3000);
    } catch (error) {
      message.error('Registration failed: ' + error.message);
    } finally {
      setLoading(false);
    }
  };

  const finishRegistration = (result = registered) => {
    if (!result?.token) return;

    localStorage.setItem('auth_token', result.token);
    localStorage.setItem('token', result.token);
    localStorage.setItem('user', JSON.stringify(result.user));
    onRegistered?.(result.user);
    navigate('/');
  };

  const resendVerificationEmail = async () => {
    if (!registered?.user?.email) return;

    setLoading(true);
    try {
      const response = await fetch('/api/v1/auth/email/verification-notification', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({ email: registered.user.email }),
      });
      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        throw new Error(data.error || data.message || 'Unable to resend verification email');
      }

      message.success(data.message || 'Verification email sent');
    } catch (error) {
      message.error(error.message || 'Unable to resend verification email');
    } finally {
      setLoading(false);
    }
  };

  const handlePrev = () => {
    setCurrent(current - 1);
  };

  return (
    <div className="auth-wrap page-fade-up">
      <Card className="auth-panel border-beam-aurora" style={{ width: '100%', maxWidth: 760 }}>
        <div className="auth-form">
          <Title level={3} style={{ textAlign: 'center', marginBottom: 6 }}>Register Your Agency</Title>
          <Paragraph type="secondary" style={{ textAlign: 'center', marginBottom: 26 }}>
            Create your workspace in a few guided steps.
          </Paragraph>

          <Steps current={current} items={steps} style={{ marginBottom: 30 }} />

        {current === 0 && (
          <Form layout="vertical" form={form} className="stagger-1">
            <Title level={4}>Agency Information</Title>
            <Form.Item
              label="Agency Name"
              name="agency_name"
              rules={[{ required: true, message: 'Please enter agency name' }]}
            >
              <Input placeholder="Your Travel Agency Name" />
            </Form.Item>
          </Form>
        )}

        {current === 1 && (
          <Form layout="vertical" form={form} className="stagger-1">
            <Title level={4}>Company Details</Title>

            <Form.Item
              label="Legal Company Name"
              name="company_legal_name"
              rules={[{ required: true, message: 'Please enter company name' }]}
            >
              <Input placeholder="Official company name" />
            </Form.Item>

            <Form.Item
              label="Email"
              name="company_email"
              rules={[
                { required: true, message: 'Please enter email' },
                { type: 'email', message: 'Invalid email' },
              ]}
            >
              <Input placeholder="company@example.com" />
            </Form.Item>

            <Form.Item
              label="Phone"
              name="company_phone"
              rules={[{ required: true, message: 'Please enter phone' }]}
            >
              <Input placeholder="+92-XXX-XXXXXXX" />
            </Form.Item>

            <Form.Item
              label="Billing Address"
              name="billing_address"
              rules={[{ required: true, message: 'Please enter address' }]}
            >
              <Input.TextArea rows={3} placeholder="Full address" />
            </Form.Item>

            <Form.Item
              label="Base Currency"
              name="base_currency_code"
              rules={[{ required: true, message: 'Please select currency' }]}
            >
              <Select
                placeholder="Select currency"
                options={currencies.map((c) => ({ label: `${c.code} - ${c.name}`, value: c.code }))}
              />
            </Form.Item>

            <Form.Item
              label="Timezone"
              name="timezone"
              rules={[{ required: true, message: 'Please select timezone' }]}
            >
              <Select
                placeholder="Select timezone"
                showSearch
                options={timezones.map((tz) => ({ label: tz, value: tz }))}
              />
            </Form.Item>
          </Form>
        )}

        {current === 2 && (
          <Form layout="vertical" form={form} className="stagger-1">
            <Title level={4}>Admin Account</Title>

            <Form.Item
              label="Admin Name"
              name="admin_name"
              rules={[{ required: true, message: 'Please enter your name' }]}
            >
              <Input placeholder="Your full name" />
            </Form.Item>

            <Form.Item
              label="Admin Email"
              name="admin_email"
              rules={[
                { required: true, message: 'Please enter email' },
                { type: 'email', message: 'Invalid email' },
              ]}
            >
              <Input placeholder="your@email.com" />
            </Form.Item>

            <Form.Item
              label="Password"
              name="admin_password"
              rules={[
                { required: true, message: 'Please enter password' },
                { min: 8, message: 'Password must be at least 8 characters' },
              ]}
            >
              <Input.Password placeholder="At least 8 characters" prefix={<LockOutlined />} />
            </Form.Item>

            <Form.Item
              label="Confirm Password"
              name="admin_password_confirmation"
              rules={[
                { required: true, message: 'Please confirm password' },
                ({ getFieldValue }) => ({
                  validator(_, value) {
                    if (!value || getFieldValue('admin_password') === value) {
                      return Promise.resolve();
                    }
                    return Promise.reject(new Error('Passwords do not match'));
                  },
                }),
              ]}
            >
              <Input.Password placeholder="Confirm password" prefix={<LockOutlined />} />
            </Form.Item>
          </Form>
        )}

        {current === 3 && registered && (
          <div style={{ textAlign: 'center', padding: '40px 0' }} className="stagger-2">
            <CheckCircleOutlined style={{ fontSize: 60, color: themeToken.colorSuccess, marginBottom: 20, display: 'block' }} />
            <Title level={3}>Registration Successful!</Title>
            <Paragraph style={{ fontSize: 16, marginBottom: 10 }}>
              Welcome to <strong>{registered.user.company_name}</strong>
            </Paragraph>
            <Paragraph type="secondary" style={{ marginBottom: 30 }}>
              We sent a welcome email with a verification link. Redirecting to your workspace in 3 seconds.
            </Paragraph>

            <div className="elevated-card" style={{ textAlign: 'left', marginBottom: 20 }}>
              <Space orientation="vertical" size={4}>
                  <Text><strong>Admin Email:</strong> {registered.user.email}</Text>
                <Text><strong>Agency:</strong> {registered.user.tenant_name}</Text>
                <Text><strong>Company:</strong> {registered.user.company_name}</Text>
              </Space>
            </div>

            <Space wrap style={{ justifyContent: 'center' }}>
              <Button type="primary" size="large" icon={<UserOutlined />} onClick={() => finishRegistration()}>
                Go to Dashboard Now
              </Button>
              <Button size="large" icon={<MailOutlined />} loading={loading} onClick={resendVerificationEmail}>
                Resend Email
              </Button>
            </Space>
          </div>
        )}

        <div style={{ marginTop: 30, display: 'flex', justifyContent: 'space-between' }}>
          <Button onClick={handlePrev} disabled={current === 0}>
            Previous
          </Button>

          {current < 3 && (
            <Button
              type="primary"
              loading={loading}
              onClick={handleNext}
            >
              {current === 2 ? 'Register' : 'Next'}
            </Button>
          )}
        </div>
        </div>
      </Card>
    </div>
  );
}
