import React, { useMemo, useState } from 'react';
import { Button, Card, Col, Divider, Input, InputNumber, Popconfirm, Row, Segmented, Space, Typography } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import Table from '../../components/CsvTable';
import { blankDiscount } from './defaults';
import { calculateVoucherTotals } from './voucherTotals';

const { Text } = Typography;

const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const discountFieldStyle = { display: 'flex', flexDirection: 'column', gap: 8, width: '100%' };
const discountControlStyle = { width: '100%' };

const normalizedForm = (discount) => ({
  ...blankDiscount(),
  ...discount,
  discount_type: discount?.discount_type === 'percentage' ? 'percentage' : 'amount',
});

export default function VoucherDiscountsCard({ voucher, onChangeDiscounts, currencyCode = '' }) {
  const [editingIndex, setEditingIndex] = useState(null);
  const [form, setForm] = useState(blankDiscount());
  const discounts = Array.isArray(voucher.discounts) ? voucher.discounts : [];
  const totals = useMemo(() => calculateVoucherTotals(voucher), [voucher]);
  const computedByKey = new Map(totals.discounts.map((discount) => [discount.key, discount]));
  const inputLimit = form.discount_type === 'percentage' ? 100 : Math.max(0, totals.grossTotal);
  const inputValue = form.discount_type === 'percentage' ? form.percentage : form.amount;

  const resetForm = () => {
    setEditingIndex(null);
    setForm(blankDiscount());
  };

  const saveDiscount = () => {
    const value = Number(inputValue || 0);
    if (value <= 0 || value > inputLimit) {
      return;
    }

    const nextDiscount = {
      ...normalizedForm(form),
      key: form.key || form.uid || `discount-${Date.now()}`,
      amount: form.discount_type === 'amount' ? String(value) : '',
      percentage: form.discount_type === 'percentage' ? String(value) : '',
      reason: String(form.reason || '').trim(),
    };
    const nextDiscounts = [...discounts];

    if (editingIndex === null) {
      nextDiscounts.push(nextDiscount);
    } else {
      nextDiscounts[editingIndex] = nextDiscount;
    }

    onChangeDiscounts(nextDiscounts);
    resetForm();
  };

  const editDiscount = (discount, index) => {
    setEditingIndex(index);
    setForm(normalizedForm(discount));
  };

  const deleteDiscount = (index) => {
    onChangeDiscounts(discounts.filter((_, rowIndex) => rowIndex !== index));
    if (editingIndex === index) {
      resetForm();
    }
  };

  return (
    <Card className="border-beam-aurora" style={{ marginTop: 16 }} title="Discounts">
      <Space direction="vertical" size={12} style={{ width: '100%' }}>
        <Row gutter={[12, 12]}>
          <Col xs={24} md={8}>
            <Text type="secondary">Gross total</Text>
            <div><Text strong>{currencyCode} {money(totals.grossTotal)}</Text></div>
          </Col>
          <Col xs={24} md={8}>
            <Text type="secondary">Discount</Text>
            <div><Text strong type={totals.discountTotal > 0 ? 'danger' : undefined}>{currencyCode} {money(totals.discountTotal)}</Text></div>
          </Col>
          <Col xs={24} md={8}>
            <Text type="secondary">Net total</Text>
            <div><Text strong>{currencyCode} {money(totals.netTotal)}</Text></div>
          </Col>
        </Row>

        <Table
          size="small"
          rowKey={(row, index) => row.key || row.uid || `discount-${index}`}
          pagination={false}
          dataSource={discounts}
          locale={{ emptyText: 'No discounts added' }}
          columns={[
            { title: 'Reason', dataIndex: 'reason', render: (value) => value || 'Discount' },
            { title: 'Type', dataIndex: 'discount_type', width: 120, render: (value) => String(value || 'amount').toUpperCase() },
            {
              title: 'Value',
              width: 120,
              render: (_, row) => row.discount_type === 'percentage' ? `${money(row.percentage).replace(/\.00$/, '')}%` : `${currencyCode} ${money(row.amount)}`,
            },
            {
              title: 'Amount',
              width: 140,
              align: 'right',
              render: (_, row, index) => {
                const key = row.key || row.uid || `discount-${index}`;
                return `${currencyCode} ${money(computedByKey.get(key)?.computed_amount || 0)}`;
              },
            },
            {
              title: 'Action',
              key: 'action',
              width: 120,
              render: (_, row, index) => (
                <Space>
                  <Button size="small" icon={<EditOutlined />} onClick={() => editDiscount(row, index)} />
                  <Popconfirm title="Delete this discount?" okText="Delete" okButtonProps={{ danger: true }} onConfirm={() => deleteDiscount(index)}>
                    <Button danger size="small" icon={<DeleteOutlined />} />
                  </Popconfirm>
                </Space>
              ),
            },
          ]}
        />

        <Divider style={{ margin: '4px 0' }} />
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
          <Text strong>{editingIndex === null ? 'New discount' : 'Edit discount'}</Text>
          {editingIndex !== null && <Button size="small" icon={<PlusOutlined />} onClick={resetForm}>New discount</Button>}
        </div>

        <Row className="voucher-discount-editor-grid" gutter={[12, 12]} align="bottom">
          <Col xs={24} md={8}>
            <div className="voucher-discount-field" style={discountFieldStyle}>
              <Text strong>Discount type</Text>
              <Segmented
                block
                value={form.discount_type}
                options={[
                  { label: 'Amount', value: 'amount' },
                  { label: 'Percentage', value: 'percentage' },
                ]}
                onChange={(value) => setForm((current) => ({ ...current, discount_type: value, amount: '', percentage: '' }))}
                style={discountControlStyle}
              />
            </div>
          </Col>
          <Col xs={24} md={8}>
            <div className="voucher-discount-field" style={discountFieldStyle}>
              <Text strong>{form.discount_type === 'percentage' ? 'Discount percentage' : 'Discount amount'}</Text>
              <InputNumber
                className="voucher-discount-value-input"
                min={0.01}
                max={inputLimit}
                precision={2}
                controls={false}
                addonAfter={form.discount_type === 'percentage' ? '%' : currencyCode}
                value={inputValue === '' || inputValue === null || inputValue === undefined ? null : inputValue}
                onChange={(value) => setForm((current) => ({
                  ...current,
                  [current.discount_type === 'percentage' ? 'percentage' : 'amount']: value ?? '',
                }))}
                style={discountControlStyle}
              />
            </div>
          </Col>
          <Col xs={24} md={8}>
            <div className="voucher-discount-field" style={discountFieldStyle}>
              <Text strong>Reason</Text>
              <Input value={form.reason} onChange={(event) => setForm((current) => ({ ...current, reason: event.target.value }))} style={discountControlStyle} />
            </div>
          </Col>
          <Col xs={24}>
            <Button className="voucher-discount-submit" type="primary" icon={<PlusOutlined />} disabled={Number(inputValue || 0) <= 0 || Number(inputValue || 0) > inputLimit} onClick={saveDiscount}>
              {editingIndex === null ? 'Add discount' : 'Update discount'}
            </Button>
          </Col>
        </Row>
      </Space>
    </Card>
  );
}
