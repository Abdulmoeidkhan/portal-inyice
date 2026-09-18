import React, { useState, useEffect } from 'react';
import { Alert, Steps, Form, Input, Select, Button, Card, Typography, Space, theme } from 'antd';
import { message } from '../services/feedback';
import { ArrowLeftOutlined, UserOutlined, BuildOutlined, LockOutlined, CheckCircleOutlined, MailOutlined } from '@ant-design/icons';
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
    throw new Error('Registration is temporarily unavailable. Please refresh the page and try again.');
  }

  throw new Error(fallbackMessage);
}

const steps = [
  {
    title: 'Company Details',
    icon: <BuildOutlined />,
  },
  {
    title: 'Admin Account',
    icon: <UserOutlined />,
  },
];

export default function Register({ onRegistered }) {
  const [current, setCurrent] = useState(0);
  const [loading, setLoading] = useState(false);
  const [currencies, setCurrencies] = useState([]);
  const [timezones, setTimezones] = useState([]);
  const [formData, setFormData] = useState({});
  const [registered, setRegistered] = useState(null);
  const [csrfToken, setCsrfToken] = useState('');
  const [sessionError, setSessionError] = useState('');
  const [website, setWebsite] = useState('');
  const [form] = Form.useForm();
  const navigate = useNavigate();
  const { token: themeToken } = theme.useToken();

  useEffect(() => {
    fetchCurrenciesAndTimezones();
    initializeSession();
  }, []);

  const initializeSession = async () => {
    setCsrfToken('');
    setSessionError('');
    try {
      const response = await fetch('/registration/session', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      });
      const result = await readJsonResponse(response, 'Could not start registration.');
      if (!response.ok || !result.csrf_token) {
        throw new Error(result.message || 'Could not start registration.');
      }
      setCsrfToken(result.csrf_token);
    } catch (error) {
      setSessionError(error.message);
    }
  };

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
    if (loading) return;
    if (current === 1 && !csrfToken) return;
    try {
      const values = await form.validateFields();
      setFormData({ ...formData, ...values });

      if (current === 1) {
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
      const response = await fetch('/registration/register', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({ ...data, website }),
      });

      const result = await readJsonResponse(response, 'Registration failed');

      if (!response.ok) {
        if (response.status === 419) {
          setCsrfToken('');
          setSessionError('Your registration session has expired. Retry to continue with your entered details.');
        }
        const validationError = result?.errors ? Object.values(result.errors).flat().join(' ') : null;
        throw new Error(validationError || result.error || result.message || 'Registration failed');
      }

      setRegistered(result);
      setCurrent(2);

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
    if (current === 0) {
      navigate('/login');
      return;
    }
    setFormData({ ...formData, ...form.getFieldsValue(true) });
    setCurrent(current - 1);
  };

  return (
    <div className="auth-wrap page-fade-up">
      <Card className="auth-panel border-beam-aurora" style={{ width: '100%', maxWidth: 760 }}>
        <div className="auth-form">
          <Title level={3} style={{ textAlign: 'center', marginBottom: 6 }}>Register Your Agency</Title>
          <Paragraph type="secondary" style={{ textAlign: 'center', marginBottom: 26 }}>
            Create your workspace in two simple steps.
          </Paragraph>

          <Steps current={current} items={steps} style={{ marginBottom: 30 }} />

        {sessionError && !registered && (
          <Alert
            type="error"
            title={sessionError}
            action={<Button size="small" onClick={initializeSession}>Retry</Button>}
            style={{ marginBottom: 20 }}
          />
        )}

        {current === 0 && (
          <Form layout="vertical" form={form} initialValues={formData} onFinish={handleNext} className="stagger-1">
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

        {current === 1 && (
          <Form layout="vertical" form={form} initialValues={formData} onFinish={handleNext} className="stagger-1">
            <Title level={4}>Admin Account</Title>
            <Paragraph type="secondary">
              Please check your details before registering. Your network can submit two registration requests every 24 hours.
            </Paragraph>

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
              dependencies={['admin_password']}
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
            <div aria-hidden="true" style={{ position: 'absolute', left: -10000, width: 1, height: 1, overflow: 'hidden' }}>
              <label htmlFor="registration-website">Website</label>
              <input id="registration-website" name="website" tabIndex={-1} autoComplete="off" value={website} onChange={(event) => setWebsite(event.target.value)} />
            </div>
          </Form>
        )}

        {current === 2 && registered && (
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

        {current < 2 && (
          <div style={{ marginTop: 30, display: 'flex', justifyContent: 'space-between' }}>
            <Button icon={<ArrowLeftOutlined />} onClick={handlePrev} disabled={loading}>
              {current === 0 ? 'Back to login' : 'Back'}
            </Button>

            <Button
              type="primary"
              loading={loading}
              disabled={current === 1 && !csrfToken}
              onClick={handleNext}
            >
              {current === 1 ? 'Register' : 'Next'}
            </Button>
          </div>
        )}
        </div>
      </Card>
    </div>
  );
}
