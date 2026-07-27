import React, { useEffect, useState } from 'react';
import { Button, Card, Col, Descriptions, Form, Input, Row, Tag, Typography, Upload } from 'antd';
import { SaveOutlined, UploadOutlined } from '@ant-design/icons';
import { message } from '../services/feedback';

const { Title, Paragraph, Text } = Typography;

const authHeaders = () => {
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');

  return {
    Accept: 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  };
};

export default function CompanyProfile() {
  const [form] = Form.useForm();
  const [company, setCompany] = useState(null);
  const [canUpdate, setCanUpdate] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [logoFile, setLogoFile] = useState(null);
  const [footerLogoFile, setFooterLogoFile] = useState(null);

  const fetchProfile = async () => {
    setLoading(true);

    try {
      const response = await fetch('/api/v1/company-profile', { headers: authHeaders() });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Unable to load company profile');
      }

      setCompany(data.company);
      setCanUpdate(Boolean(data.can_update));
      form.setFieldsValue(data.company);
    } catch (error) {
      message.error(error.message || 'Unable to load company profile');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchProfile();
  }, []);

  const updateProfile = async (values) => {
    setSaving(true);

    try {
      const payload = new FormData();
      Object.entries(values).forEach(([key, value]) => {
        payload.append(key, value ?? '');
      });

      if (logoFile) {
        payload.append('logo', logoFile);
      }

      if (footerLogoFile) {
        payload.append('footer_logo', footerLogoFile);
      }

      const response = await fetch('/api/v1/company-profile', {
        method: 'POST',
        headers: authHeaders(),
        body: payload,
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Unable to update company profile');
      }

      const storedUser = JSON.parse(localStorage.getItem('user') || '{}');
      localStorage.setItem('user', JSON.stringify({
        ...storedUser,
        company_name: data.company.display_name,
        company_is_paid: data.company.is_paid === true,
      }));

      setCompany(data.company);
      form.setFieldsValue(data.company);
      setLogoFile(null);
      setFooterLogoFile(null);
      message.success('Company profile updated');
    } catch (error) {
      message.error(error.message || 'Unable to update company profile');
    } finally {
      setSaving(false);
    }
  };

  const uploadProps = (setter) => ({
    maxCount: 1,
    beforeUpload: (file) => {
      setter(file);
      return false;
    },
    onRemove: () => setter(null),
  });

  return (
    <div className="page-shell page-fade-up company-profile-page">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2} style={{ margin: 0 }}>Company Profile</Title>
        <Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>
          Company details, document logo, and voucher footer QR/logo.
        </Paragraph>
      </div>

      <Row gutter={[16, 16]}>
        <Col xs={24} lg={canUpdate ? 10 : 24}>
          <Card className="border-beam-aurora" title="Organization Details" loading={loading}>
            <Descriptions column={1} bordered size="middle">
              <Descriptions.Item label="Tenant Name">{company?.tenant?.name || 'N/A'}</Descriptions.Item>
              <Descriptions.Item label="Company Name">{company?.display_name || 'N/A'}</Descriptions.Item>
              <Descriptions.Item label="Email">{company?.email || 'N/A'}</Descriptions.Item>
              <Descriptions.Item label="Phone">{company?.phone || 'N/A'}</Descriptions.Item>
              <Descriptions.Item label="Currency">{company?.base_currency_code || 'N/A'}</Descriptions.Item>
              <Descriptions.Item label="Limits">
                {company ? `Unlimited invoices/month, unlimited orders, ${company.user_limit} users` : 'N/A'}
              </Descriptions.Item>
              <Descriptions.Item label="Status">
                <Tag color={company?.is_active ? 'success' : 'default'}>{company?.is_active ? 'Active' : 'Inactive'}</Tag>
              </Descriptions.Item>
            </Descriptions>

            <div className="company-profile-assets">
              <div>
                <Text strong>Header Logo</Text>
                {company?.logo_url ? <img src={company.logo_url} alt="Company logo" /> : <Text type="secondary">No logo uploaded</Text>}
              </div>
              <div>
                <Text strong>Voucher Footer Logo / QR</Text>
                {company?.footer_logo_url ? <img src={company.footer_logo_url} alt="Voucher footer logo or QR" /> : <Text type="secondary">No footer asset uploaded</Text>}
              </div>
            </div>
          </Card>
        </Col>

        {canUpdate && (
          <Col xs={24} lg={14}>
            <Card className="border-beam-aurora" title="Update Profile" loading={loading}>
              <Form form={form} layout="vertical" onFinish={updateProfile}>
                <Row gutter={[12, 0]}>
                  <Col xs={24} md={12}>
                    <Form.Item name="legal_name" label="Legal Name" rules={[{ required: true }]}>
                      <Input />
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={12}>
                    <Form.Item name="display_name" label="Display Name" rules={[{ required: true }]}>
                      <Input />
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={12}>
                    <Form.Item name="email" label="Email" rules={[{ type: 'email' }]}>
                      <Input />
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={12}>
                    <Form.Item name="phone" label="Phone">
                      <Input />
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={12}>
                    <Form.Item name="country_code" label="Country Code">
                      <Input maxLength={2} />
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={12}>
                    <Form.Item name="default_timezone" label="Timezone" rules={[{ required: true }]}>
                      <Input />
                    </Form.Item>
                  </Col>
                  <Col xs={24}>
                    <Form.Item name="address" label="Address">
                      <Input.TextArea rows={3} />
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={12}>
                    <Form.Item label="Header Logo">
                      <Upload {...uploadProps(setLogoFile)} accept="image/*">
                        <Button icon={<UploadOutlined />}>Choose Logo</Button>
                      </Upload>
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={12}>
                    <Form.Item label="Voucher Footer Logo / QR">
                      <Upload {...uploadProps(setFooterLogoFile)} accept="image/*">
                        <Button icon={<UploadOutlined />}>Choose Footer Asset</Button>
                      </Upload>
                    </Form.Item>
                  </Col>
                </Row>
                <Button type="primary" htmlType="submit" icon={<SaveOutlined />} loading={saving}>
                  Save Profile
                </Button>
              </Form>
            </Card>
          </Col>
        )}
      </Row>
    </div>
  );
}
