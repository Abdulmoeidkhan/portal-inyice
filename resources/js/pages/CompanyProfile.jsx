import React from 'react';
import { Card, Descriptions, Typography, Tag } from 'antd';

const { Title, Paragraph } = Typography;

export default function CompanyProfile() {
  const user = JSON.parse(localStorage.getItem('user') || '{}');

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2} style={{ margin: 0 }}>Company Profile</Title>
        <Paragraph type="secondary" style={{ marginTop: 8 }}>
          Company account details and onboarding metadata.
        </Paragraph>
      </div>

      <Card className="border-beam-aurora" title="Organization Details">
        <Descriptions column={1} bordered size="middle">
          <Descriptions.Item label="Tenant Name">{user.tenant_name || 'N/A'}</Descriptions.Item>
          <Descriptions.Item label="Company Name">{user.company_name || 'N/A'}</Descriptions.Item>
          <Descriptions.Item label="Tenant ID">{user.tenant_id || 'N/A'}</Descriptions.Item>
          <Descriptions.Item label="Company ID">{user.company_id || 'N/A'}</Descriptions.Item>
          <Descriptions.Item label="Status">
            <Tag color="success">Active</Tag>
          </Descriptions.Item>
        </Descriptions>
      </Card>
    </div>
  );
}
