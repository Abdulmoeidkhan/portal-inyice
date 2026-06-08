import React from 'react';
import { Button, Card, Space } from 'antd';

export default function RowGroupCard({ title, rows, addLabel, onAdd, onRemove, children }) {
  return (
    <Card className="border-beam-aurora" size="small" title={title} style={{ marginBottom: 12 }}>
      <Space direction="vertical" style={{ width: '100%' }}>
        {rows.map((row, idx) => (
          <Card
            key={`${title}-${idx}`}
            size="small"
            extra={
              <Button danger onClick={() => onRemove(idx)} disabled={rows.length <= 1}>
                Remove
              </Button>
            }
          >
            {children(row, idx)}
          </Card>
        ))}
        <Button onClick={onAdd}>{addLabel}</Button>
      </Space>
    </Card>
  );
}
