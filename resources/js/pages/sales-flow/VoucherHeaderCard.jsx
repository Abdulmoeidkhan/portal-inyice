import React from 'react';
import { Card, Col, Input, InputNumber, Row, Select, Typography } from 'antd';
import { optionalVoucherSections } from './defaults';

const { TextArea } = Input;
const { Text } = Typography;

export default function VoucherHeaderCard({ voucher, setVoucherField }) {
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
        <Row gutter={12} style={{ marginTop: 12 }}>
          <Col xs={24}>
            <Text>Emergency Contact / Notes</Text>
            <TextArea rows={3} value={voucher.emergency_contact} onChange={(e) => setVoucherField('emergency_contact', e.target.value)} />
          </Col>
        </Row>
      </Card>
    </>
  );
}
