import React from 'react';
import { Card, Col, Input, Row, Select, Typography } from 'antd';
import { optionalVoucherSections } from './defaults';

const { TextArea } = Input;
const { Text } = Typography;

const packageTypeOptions = [
  'Ticket Only',
  'Visa Only',
  'Hotel Only',
  'Transfer Only',
  'Partial Package',
  'Full Package',
  'Holiday Package',
  'Umrah Package',
].map((value) => ({ value, label: value }));

export default function VoucherHeaderCard({ voucher, setVoucherField, embedded = false }) {
  const content = (
    <>
        <Row gutter={[12, 12]}>
          <Col xs={24} sm={12} md={8} lg={4}>
            <Text>Voucher No</Text>
            <Input value={voucher.voucher_no} onChange={(e) => setVoucherField('voucher_no', e.target.value)} />
          </Col>
          <Col xs={24} sm={12} md={8} lg={4}>
            <Text>Issue Date</Text>
            <Input type="date" value={voucher.issue_date} onChange={(e) => setVoucherField('issue_date', e.target.value)} />
          </Col>
          <Col xs={24} sm={12} md={8} lg={4}>
            <Text>Type</Text>
            <Select
              allowClear
              placeholder="Select type"
              style={{ width: '100%' }}
              value={voucher.package_type || undefined}
              options={packageTypeOptions}
              onChange={(value) => setVoucherField('package_type', value || '')}
            />
          </Col>
          <Col xs={24} sm={12} md={8} lg={4}>
            <Text>Booking Reference</Text>
            <Input value={voucher.booking_reference} onChange={(e) => setVoucherField('booking_reference', e.target.value)} />
          </Col>
          <Col xs={24} sm={12} md={8} lg={4}>
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
          <Col xs={24} sm={12} md={8} lg={4}>
            <Text>Optional Services</Text>
            <Select
              mode="multiple"
              style={{ width: '100%' }}
              value={voucher.active_sections}
              options={optionalVoucherSections}
              onChange={(value) => setVoucherField('active_sections', value)}
            />
          </Col>
        </Row>
        <Row gutter={12} style={{ marginTop: 12 }}>
          <Col xs={24}>
            <Text>Emergency Contact / Notes</Text>
            <TextArea rows={3} value={voucher.emergency_contact} onChange={(e) => setVoucherField('emergency_contact', e.target.value)} />
          </Col>
        </Row>
    </>
  );

  if (embedded) {
    return content;
  }

  return (
    <Card className="border-beam-aurora" style={{ marginBottom: 12 }} title="Basic Voucher Information">
      {content}
    </Card>
  );
}
