import React, { useMemo, useState } from 'react';
import { Card, Form, Steps, Tabs, Typography, message } from 'antd';
import { createInvoiceFromOrderApi, createOrderFromVoucherApi, parseGdsApi } from '../services/salesFlowApi';
import {
  buildVoucherFromParsed,
  createInitialVoucher,
} from './sales-flow/defaults';
import GdsParserCard from './sales-flow/GdsParserCard';
import VoucherHeaderCard from './sales-flow/VoucherHeaderCard';
import VoucherRowsSections from './sales-flow/VoucherRowsSections';
import { ConvertInvoiceCard, CreateOrderCard } from './sales-flow/OrderInvoiceCards';

const { Title, Paragraph } = Typography;

export default function SalesFlow() {
  const [gdsForm] = Form.useForm();
  const [orderForm] = Form.useForm();
  const [invoiceForm] = Form.useForm();

  const [voucher, setVoucher] = useState(createInitialVoucher());
  const [parseResult, setParseResult] = useState(null);
  const [createdOrder, setCreatedOrder] = useState(null);
  const [createdInvoice, setCreatedInvoice] = useState(null);

  const [loadingParse, setLoadingParse] = useState(false);
  const [loadingOrder, setLoadingOrder] = useState(false);
  const [loadingInvoice, setLoadingInvoice] = useState(false);

  const parsedHint = useMemo(() => {
    if (!parseResult) {
      return 'Parse Sabre/Galileo text to pre-fill booking reference, flights, and passengers.';
    }

    const passengerCount = parseResult.parsed?.passengers?.length || 0;
    const segmentCount = parseResult.parsed?.segments?.length || 0;
    return `Parsed ${passengerCount} passenger(s) and ${segmentCount} flight segment(s).`;
  }, [parseResult]);

  const setVoucherField = (field, value) => {
    setVoucher((prev) => ({ ...prev, [field]: value }));
  };

  const setContactField = (field, value) => {
    setVoucher((prev) => ({ ...prev, contact: { ...prev.contact, [field]: value } }));
  };

  const setRowField = (section, idx, field, value) => {
    setVoucher((prev) => ({
      ...prev,
      [section]: prev[section].map((row, rowIdx) => (rowIdx === idx ? { ...row, [field]: value } : row)),
    }));
  };

  const addRow = (section, factory) => {
    setVoucher((prev) => ({ ...prev, [section]: [...prev[section], factory()] }));
  };

  const removeRow = (section, idx) => {
    setVoucher((prev) => {
      if (prev[section].length <= 1) {
        return prev;
      }
      return { ...prev, [section]: prev[section].filter((_, rowIdx) => rowIdx !== idx) };
    });
  };

  const handleParse = async ({ gds_source, raw_text }) => {
    setLoadingParse(true);
    try {
      const data = await parseGdsApi({ gds_source, raw_text });
      setParseResult(data);
      setVoucher((prev) => {
        const nextVoucher = buildVoucherFromParsed(prev, gds_source, data.parsed);
        return {
          ...nextVoucher,
          gds_parsed_record_id: data.gds_record?.id || nextVoucher.gds_parsed_record_id,
        };
      });
      message.success('GDS parsed and voucher fields pre-filled.');
    } catch (error) {
      message.error(error.message || 'GDS parse failed');
    } finally {
      setLoadingParse(false);
    }
  };

  const handleCreateOrder = async (values) => {
    setLoadingOrder(true);
    setCreatedOrder(null);
    try {
      const data = await createOrderFromVoucherApi({
        company_id: values.company_id || undefined,
        customer_id: values.customer_id,
        vendor_id: values.vendor_id || undefined,
        currency_code: values.currency_code || undefined,
        status: values.status || 'quote',
        notes: values.notes || null,
        voucher,
      });

      setCreatedOrder(data.order);
      invoiceForm.setFieldsValue({ order_id: data.order.id });
      message.success('Order/quotation created from voucher data');
    } catch (error) {
      message.error(error.message || 'Order creation failed');
    } finally {
      setLoadingOrder(false);
    }
  };

  const handleCreateInvoice = async ({ order_id }) => {
    setLoadingInvoice(true);
    setCreatedInvoice(null);
    try {
      const data = await createInvoiceFromOrderApi({ order_id });
      setCreatedInvoice(data.invoice);
      message.success('Invoice created successfully');
    } catch (error) {
      message.error(error.message || 'Invoice creation failed');
    } finally {
      setLoadingInvoice(false);
    }
  };

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2} style={{ margin: 0 }}>Voucher to Order Workspace</Title>
        <Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>
          Follow this flow: Parse GDS, then create quotation/order, then convert to invoice.
        </Paragraph>
      </div>

      <Card className="border-beam-aurora" style={{ marginBottom: 16 }}>
        <Steps
          current={1}
          items={[
            { title: 'Parse GDS', description: 'Extract flights and passengers' },
            { title: 'Create Quotation/Order', description: 'Submit full voucher payload' },
            { title: 'Convert to Invoice', description: 'Generate invoice from order' },
          ]}
        />
      </Card>

      <Tabs
        defaultActiveKey="voucher"
        items={[
          {
            key: 'gds',
            label: 'GDS Parser',
            children: (
              <GdsParserCard
                form={gdsForm}
                loading={loadingParse}
                parsedHint={parsedHint}
                parseResult={parseResult}
                onParse={handleParse}
              />
            ),
          },
          {
            key: 'voucher',
            label: 'Voucher Fields',
            children: (
              <>
                <VoucherHeaderCard
                  voucher={voucher}
                  setVoucherField={setVoucherField}
                  setContactField={setContactField}
                />
                <VoucherRowsSections
                  voucher={voucher}
                  setRowField={setRowField}
                  addRow={addRow}
                  removeRow={removeRow}
                />
              </>
            ),
          },
          {
            key: 'order',
            label: 'Create Quotation/Order',
            children: (
              <CreateOrderCard
                form={orderForm}
                loading={loadingOrder}
                createdOrder={createdOrder}
                onCreateOrder={handleCreateOrder}
              />
            ),
          },
          {
            key: 'invoice',
            label: 'Convert Invoice',
            children: (
              <ConvertInvoiceCard
                form={invoiceForm}
                loading={loadingInvoice}
                createdInvoice={createdInvoice}
                onCreateInvoice={handleCreateInvoice}
                canConvert={Boolean(createdOrder?.id)}
              />
            ),
          },
        ]}
      />
    </div>
  );
}
