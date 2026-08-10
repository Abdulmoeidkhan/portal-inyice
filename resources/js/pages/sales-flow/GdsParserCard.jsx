import React from 'react';
import { Alert, Button, Card, Form, Input, Space, Tag } from 'antd';

const { TextArea } = Input;

export default function GdsParserCard({ form, loading, parsedHint, parseResult, onParse, embedded = false }) {
  const content = (
    <>
      <Alert type="info" showIcon style={{ marginBottom: 12 }} message={parsedHint} />
      <Form
        layout="vertical"
        form={form}
        onFinish={onParse}
      >
        <Form.Item name="raw_text" label="Raw GDS Text" rules={[{ required: true, message: 'Paste GDS text' }]}>
          <TextArea rows={10} placeholder="Paste complete GDS/PNR text" />
        </Form.Item>
        <Button type="primary" htmlType="submit" loading={loading}>Parse GDS</Button>
      </Form>

      {parseResult && (
        <Space wrap style={{ marginTop: 14 }}>
          <Tag color="processing">{parseResult.gds_record?.gds_source || '-'}</Tag>
          <Tag color="blue">Booking: {parseResult.gds_record?.booking_reference || 'N/A'}</Tag>
          <Tag color="green">Local parser</Tag>
        </Space>
      )}
    </>
  );

  if (embedded) {
    return content;
  }

  return (
    <Card className="border-beam-aurora" title="Parse GDS (Sabre / Galileo / Amadeus / Other)">
      {content}
    </Card>
  );
}
