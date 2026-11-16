import React, { useEffect, useState } from 'react';
import { Button, Card, Col, Descriptions, Form, Input, Row, Switch, Checkbox, InputNumber, Select, Tag, Typography, Upload } from 'antd';
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
  const [taxCategories, setTaxCategories] = useState({});
  const taxEnabled = Form.useWatch(['tax_settings', 'enabled'], form);
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
      if (data.tax_categories) setTaxCategories(data.tax_categories);
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
        if (key === 'tax_settings') {
          payload.append(key, JSON.stringify(value));
          return;
        }
        payload.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : (value ?? ''));
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
        throw new Error(Object.values(data.errors || {}).flat()[0] || data.error || 'Unable to update company profile');
      }

      const storedUser = JSON.parse(localStorage.getItem('user') || '{}');
      localStorage.setItem('user', JSON.stringify({
        ...storedUser,
        company_name: data.company.display_name,
        company_is_paid: data.company.is_paid === true,
        company_sales_can_edit_cost: data.company.sales_can_edit_cost === true,
      }));

      setCompany(data.company);
      if (data.tax_categories) setTaxCategories(data.tax_categories);
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

  const allowedImageTypes = 'image/jpeg,image/png,image/webp';
  const allowedImageExtensions = '.jpg,.jpeg,.png,.webp';

  const uploadProps = (setter) => ({
    maxCount: 1,
    accept: allowedImageExtensions,
    beforeUpload: (file) => {
      if (!allowedImageTypes.split(',').includes(file.type)) {
        message.error('Only JPG, PNG, and WEBP images are allowed');
        return Upload.LIST_IGNORE;
      }

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
              <Descriptions.Item label="Invoice Tax">
                {company?.tax_settings?.enabled
                  ? `${company.tax_settings.name} ${company.tax_settings.rate}% (${company.tax_settings.pricing})`
                  : 'Handled externally'}
              </Descriptions.Item>
              <Descriptions.Item label="Sales Cost Access">
                <Tag color={company?.sales_can_edit_cost ? 'success' : 'default'}>{company?.sales_can_edit_cost ? 'Allowed' : 'Sales only'}</Tag>
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
                    <Form.Item name="sales_can_edit_cost" label="Sales Cost Access" valuePropName="checked">
                      <Switch checkedChildren="Allowed" unCheckedChildren="Sales only" />
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={12}>
                    <Form.Item label="Header Logo">
                      <Upload {...uploadProps(setLogoFile)}>
                        <Button icon={<UploadOutlined />}>Choose Logo</Button>
                      </Upload>
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={12}>
                    <Form.Item label="Voucher Footer Logo / QR">
                      <Upload {...uploadProps(setFooterLogoFile)}>
                        <Button icon={<UploadOutlined />}>Choose Footer Asset</Button>
                      </Upload>
                    </Form.Item>
                  </Col>
                </Row>
                <Card size="small" title="Voucher notes" style={{ marginBottom: 16 }}>
                  <Form.Item name="voucher_notes" extra="Used when a voucher has no notes of its own. Printed after emergency contacts, or alone when no emergency contact is provided." rules={[{ max: 10000 }]}>
                    <Input.TextArea aria-label="Voucher notes" autoSize={{ minRows: 4, maxRows: 12 }} maxLength={10000} showCount />
                  </Form.Item>
                </Card>
                <Card size="small" title="Itinerary notes" style={{ marginBottom: 16 }}>
                  <Form.Item name="itinerary_notes" extra="Printed after the itinerary details with a separator, including PDF and image downloads." rules={[{ max: 10000 }]}>
                    <Input.TextArea aria-label="Itinerary notes" autoSize={{ minRows: 4, maxRows: 12 }} maxLength={10000} showCount />
                  </Form.Item>
                </Card>
                <Card size="small" title="Invoice tax" style={{ marginBottom: 16 }}>
                  <Paragraph type="secondary">Choose whether inYice calculates tax or you handle it externally. Changes apply to new invoices; saved invoices keep their tax settings.</Paragraph>
                  <Form.Item name={['tax_settings', 'enabled']} label="Calculate tax in inYice" valuePropName="checked">
                    <Switch />
                  </Form.Item>
                  <Row gutter={12}>
                    <Col xs={24} sm={12}>
                      <Form.Item name={['tax_settings', 'name']} label="Tax label" rules={[{ required: true, max: 30 }]}>
                        <Input disabled={!taxEnabled} maxLength={30} placeholder="VAT" />
                      </Form.Item>
                    </Col>
                    <Col xs={24} sm={12}>
                      <Form.Item name={['tax_settings', 'registration_number']} label="Tax registration number">
                        <Input disabled={!taxEnabled} maxLength={100} />
                      </Form.Item>
                    </Col>
                    <Col xs={24} sm={12}>
                      <Form.Item name={['tax_settings', 'rate']} label="Fixed rate (%)" rules={[{ required: true }]}>
                        <InputNumber disabled={!taxEnabled} min={0} max={100} precision={4} style={{ width: '100%' }} />
                      </Form.Item>
                    </Col>
                    <Col xs={24} sm={12}>
                      <Form.Item name={['tax_settings', 'pricing']} label="Service prices" rules={[{ required: true }]}>
                        <Select disabled={!taxEnabled} options={[{ value: 'exclusive', label: 'Exclude tax (add on top)' }, { value: 'inclusive', label: 'Include tax (extract tax)' }]} />
                      </Form.Item>
                    </Col>
                  </Row>
                  <Form.Item name={['tax_settings', 'categories']} label="Taxable categories" rules={[{ validator: (_, value) => taxEnabled && !value?.length ? Promise.reject(new Error('Choose at least one category')) : Promise.resolve() }]}>
                    <Checkbox.Group disabled={!taxEnabled} options={Object.entries(taxCategories).map(([value, label]) => ({ value, label }))} />
                  </Form.Item>
                  <Text type="secondary">The fixed rate applies to selected services in every currency. Invoice users cannot override it.</Text>
                </Card>
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
