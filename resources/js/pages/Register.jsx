import React, { useState, useEffect } from 'react';
import { Steps, Form, Input, Select, Button, Card, message, Alert, Typography, Space } from 'antd';
import { UserOutlined, BuildOutlined, LockOutlined, CheckCircleOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';

const { Title, Paragraph, Text } = Typography;

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
  const [agencyCodeAvailable, setAgencyCodeAvailable] = useState(null);
  const [registered, setRegistered] = useState(null);
  const [form] = Form.useForm();
  const navigate = useNavigate();

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
    } catch (error) {
      console.error('Failed to load options:', error);
    }
  };

  const checkAgencyCode = async (value) => {
    if (!value) return;

    try {
      const response = await fetch(`/api/v1/registration/check-code?code=${value}`);
      const data = await response.json();
      setAgencyCodeAvailable(data.available);
    } catch (error) {
      console.error('Error checking code:', error);
    }
  };

  const handleNext = async () => {
    try {
      const values = await form.validateFields();
      setFormData({ ...formData, ...values });

      if (current === 2) {
        // Submit registration
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
        },
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        const error = await response.json();
        throw new Error(error.error || 'Registration failed');
      }

      const result = await response.json();
      setRegistered(result);
      setCurrent(3);

      // Auto-redirect after 3 seconds
      setTimeout(() => {
        localStorage.setItem('auth_token', result.token);
        localStorage.setItem('token', result.token);
        localStorage.setItem('user', JSON.stringify(result.user));
        onRegistered?.();
        navigate('/');
      }, 3000);
    } catch (error) {
      message.error('Registration failed: ' + error.message);
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
              label="Agency Code"
              name="agency_code"
              rules={[
                { required: true, message: 'Please enter agency code' },
                { pattern: /^[a-z0-9]+$/, message: 'Code must be lowercase alphanumeric' },
                { min: 3, message: 'Code must be at least 3 characters' },
              ]}
              onChange={(e) => checkAgencyCode(e.target.value)}
            >
              <Input placeholder="e.g., myagency, travel123" />
            </Form.Item>
            {agencyCodeAvailable !== null && (
              <Alert
                type={agencyCodeAvailable ? 'success' : 'error'}
                message={agencyCodeAvailable ? 'Code available!' : 'Code already taken'}
                style={{ marginBottom: 15 }}
              />
            )}

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
            <CheckCircleOutlined style={{ fontSize: 60, color: '#22c55e', marginBottom: 20, display: 'block' }} />
            <Title level={3}>Registration Successful!</Title>
            <Paragraph style={{ fontSize: 16, marginBottom: 10 }}>
              Welcome to <strong>{registered.user.company_name}</strong>
            </Paragraph>
            <Paragraph type="secondary" style={{ marginBottom: 30 }}>
              Redirecting to dashboard in 3 seconds...
            </Paragraph>

            <div className="elevated-card" style={{ textAlign: 'left', marginBottom: 20 }}>
              <Space orientation="vertical" size={4}>
                  <Text><strong>Admin Email:</strong> {registered.user.email}</Text>
                <Text><strong>Agency:</strong> {registered.user.tenant_name}</Text>
                <Text><strong>Company:</strong> {registered.user.company_name}</Text>
              </Space>
            </div>

            <Button type="primary" size="large" onClick={() => navigate('/')}>
              Go to Dashboard Now
            </Button>
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
              disabled={current === 0 && !agencyCodeAvailable}
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
