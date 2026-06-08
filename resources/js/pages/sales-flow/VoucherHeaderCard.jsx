import React from 'react';
import { Card, Col, Divider, Input, InputNumber, Row, Select, Typography } from 'antd';
import { optionalVoucherSections } from './defaults';

const { TextArea } = Input;
const { Text } = Typography;

export default function VoucherHeaderCard({ voucher, setVoucherField, setContactField }) {
  return (
    <>
      <Card className="border-beam-aurora" style={{ marginBottom: 12 }} title="Basic Voucher Information">
        <Row gutter={12}>
          <Col xs={24} md={6}>
            <Text>Voucher No</Text>
            <Input value={voucher.voucher_no} onChange={(e) => setVoucherField('voucher_no', e.target.value)} />
          </Col>
          <Col xs={24} md={6}>
            <Text>Issue Date</Text>
            <Input type="date" value={voucher.issue_date} onChange={(e) => setVoucherField('issue_date', e.target.value)} />
          </Col>
          <Col xs={24} md={6}>
            <Text>Travel Type</Text>
            <Input value={voucher.travel_type} onChange={(e) => setVoucherField('travel_type', e.target.value)} />
          </Col>
          <Col xs={24} md={6}>
            <Text>Package Type</Text>
            <Input value={voucher.package_type} onChange={(e) => setVoucherField('package_type', e.target.value)} />
          </Col>
        </Row>
        <Row gutter={12} style={{ marginTop: 12 }}>
          <Col xs={24} md={6}>
            <Text>Booking Reference</Text>
            <Input value={voucher.booking_reference} onChange={(e) => setVoucherField('booking_reference', e.target.value)} />
          </Col>
          <Col xs={24} md={6}>
            <Text>GDS Source</Text>
            <Select
              allowClear
              style={{ width: '100%' }}
              value={voucher.gds_source}
              onChange={(value) => setVoucherField('gds_source', value || null)}
              options={[
                { value: 'sabre', label: 'Sabre' },
                { value: 'galileo', label: 'Galileo' },
                { value: 'amadeus', label: 'Amadeus' },
                { value: 'other', label: 'Other (Manual)' },
              ]}
            />
          </Col>
          <Col xs={24} md={6}>
            <Text>Parsed GDS Record ID</Text>
            <InputNumber
              style={{ width: '100%' }}
              value={voucher.gds_parsed_record_id}
              onChange={(value) => setVoucherField('gds_parsed_record_id', value || null)}
            />
          </Col>
          <Col xs={24} md={6}>
            <Text>Optional Sections</Text>
            <Select
              mode="multiple"
              style={{ width: '100%' }}
              value={voucher.active_sections}
              options={optionalVoucherSections}
              onChange={(value) => setVoucherField('active_sections', value)}
            />
          </Col>
        </Row>
      </Card>

      <Card className="border-beam-aurora" size="small" title="Company Contact" style={{ marginBottom: 12 }}>
        <Row gutter={12}>
          <Col xs={24} md={8}><Text>Company Name</Text><Input value={voucher.contact.company_name} onChange={(e) => setContactField('company_name', e.target.value)} /></Col>
          <Col xs={24} md={8}><Text>Executive Name</Text><Input value={voucher.contact.executive_name} onChange={(e) => setContactField('executive_name', e.target.value)} /></Col>
          <Col xs={24} md={8}><Text>Email</Text><Input value={voucher.contact.email} onChange={(e) => setContactField('email', e.target.value)} /></Col>
        </Row>
        <Row gutter={12} style={{ marginTop: 12 }}>
          <Col xs={24} md={8}><Text>Phone</Text><Input value={voucher.contact.phone} onChange={(e) => setContactField('phone', e.target.value)} /></Col>
          <Col xs={24} md={16}><Text>Address</Text><Input value={voucher.contact.address} onChange={(e) => setContactField('address', e.target.value)} /></Col>
        </Row>
        <Divider style={{ marginTop: 12, marginBottom: 12 }} />
        <Text>Emergency Contact / Notes</Text>
        <TextArea rows={3} value={voucher.emergency_contact} onChange={(e) => setVoucherField('emergency_contact', e.target.value)} />
      </Card>
    </>
  );
}
