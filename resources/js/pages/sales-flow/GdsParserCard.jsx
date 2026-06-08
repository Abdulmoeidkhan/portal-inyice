import React from 'react';
import { Alert, Button, Card, Col, Form, Input, Row, Select, Space, Tag } from 'antd';

const { TextArea } = Input;

export default function GdsParserCard({ form, loading, parsedHint, parseResult, onParse }) {
  return (
    <Card className="border-beam-aurora" title="Parse GDS (Sabre / Galileo / Amadeus)">
      <Alert type="info" showIcon style={{ marginBottom: 12 }} message={parsedHint} />
      <Form
        layout="vertical"
        form={form}
        onFinish={onParse}
        initialValues={{ gds_source: 'sabre' }}
      >
        <Row gutter={12}>
          <Col xs={24} md={8}>
            <Form.Item name="gds_source" label="GDS Source" rules={[{ required: true }]}>
              <Select
                style={{ width: '100%' }}
                options={[
                  { value: 'sabre', label: 'Sabre' },
                  { value: 'galileo', label: 'Galileo' },
                  { value: 'amadeus', label: 'Amadeus' },
                ]}
              />
            </Form.Item>
          </Col>
        </Row>
        <Form.Item name="raw_text" label="Raw GDS Text" rules={[{ required: true, message: 'Paste GDS text' }]}>
          <TextArea rows={10} placeholder="Paste complete GDS/PNR text" />
        </Form.Item>
        <Button type="primary" htmlType="submit" loading={loading}>Parse GDS</Button>
      </Form>

      {parseResult && (
        <Space wrap style={{ marginTop: 14 }}>
          <Tag color="processing">{parseResult.gds_record?.gds_source || '-'}</Tag>
          <Tag color="blue">Booking: {parseResult.gds_record?.booking_reference || 'N/A'}</Tag>
          <Tag color="green">Record UID: {parseResult.gds_record?.uid || '-'}</Tag>
        </Space>
      )}
    </Card>
  );
}
