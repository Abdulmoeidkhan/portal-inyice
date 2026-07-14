import React, { useMemo, useState } from 'react';
import { Button, Card, Form, Space, Steps, Tabs, Typography } from 'antd';
import { message } from '../services/feedback';
import { createOrderFromVoucherApi, listVendorsApi } from '../services/salesFlowApi';
import {
  blankPricing,
  blankVisa,
  buildVoucherFromParsed,
  createInitialVoucher,
  syncPassengerNameFields,
  syncVisaNumberFields,
} from './sales-flow/defaults';
import { parseGdsData } from './sales-flow/gdsParser';
import GdsParserCard from './sales-flow/GdsParserCard';
import VoucherHeaderCard from './sales-flow/VoucherHeaderCard';
import VoucherRowsSections from './sales-flow/VoucherRowsSections';
import VoucherSummaryCard from './sales-flow/VoucherSummaryCard';
import { CreateOrderCard } from './sales-flow/OrderInvoiceCards';

const { Title, Paragraph } = Typography;

const getProfileContact = () => {
  try {
    const user = JSON.parse(localStorage.getItem('user') || '{}');
    return {
      company_name: user.company_name || user.tenant_name || '',
      executive_name: user.name || '',
      email: user.company_email || user.email || '',
      phone: user.company_phone || user.phone || '',
      address: user.company_address || user.billing_address || user.address || '',
    };
  } catch {
    return {};
  }
};

const createVoucherWithProfileContact = () => {
  const initialVoucher = createInitialVoucher();
  return {
    ...initialVoucher,
    contact: {
      ...initialVoucher.contact,
      ...getProfileContact(),
    },
  };
};

export default function SalesFlow() {
  const [gdsForm] = Form.useForm();
  const [orderForm] = Form.useForm();

  const [activeTab, setActiveTab] = useState('order');
  const [voucher, setVoucher] = useState(createVoucherWithProfileContact());
  const [parseResult, setParseResult] = useState(null);
  const [createdOrder, setCreatedOrder] = useState(null);

  const [loadingParse, setLoadingParse] = useState(false);
  const [loadingOrder, setLoadingOrder] = useState(false);
  const [vendors, setVendors] = useState([]);

  const parsedHint = useMemo(() => {
    if (!parseResult) {
      return 'Parse GDS text locally to pre-fill booking reference, flights, and passengers.';
    }

    const passengerCount = parseResult.parsed?.passengers?.length || 0;
    const segmentCount = parseResult.parsed?.flights?.length || parseResult.parsed?.segments?.length || 0;
    return `Parsed ${passengerCount} passenger(s) and ${segmentCount} flight segment(s).`;
  }, [parseResult]);

  const setVoucherField = (field, value) => {
    setVoucher((prev) => ({ ...prev, [field]: value }));
  };

  const loadVendors = async (search = '') => {
    try {
      setVendors(await listVendorsApi(search));
    } catch (error) {
      message.error(error.message || 'Unable to load vendors');
    }
  };

  React.useEffect(() => {
    loadVendors();
  }, []);

  const setRowField = (section, idx, field, value) => {
    setVoucher((prev) => {
      const previousRow = prev[section][idx] || {};
      let nextVoucher = {
        ...prev,
        [section]: prev[section].map((row, rowIdx) => (rowIdx === idx ? { ...row, [field]: value } : row)),
      };

      nextVoucher = syncPassengerNameFields(prev, nextVoucher, section, idx, field, value);
      nextVoucher = syncVisaNumberFields(prev, nextVoucher, section, idx, field, value);

      if (section === 'passengers' && field === 'ticket_no') {
        nextVoucher.pricing = prev.pricing.map((row, rowIdx) => {
          if (rowIdx !== idx || (row.flight_ticket_no && row.flight_ticket_no !== previousRow.ticket_no)) {
            return row;
          }

          return { ...row, flight_ticket_no: value };
        });
      }

      return nextVoucher;
    });
  };

  const addRow = (section, factory) => {
    setVoucher((prev) => {
      const nextVoucher = { ...prev, [section]: [...prev[section], factory()] };

      if (section === 'passengers') {
        nextVoucher.pricing = [...prev.pricing, blankPricing()];
      }

      return nextVoucher;
    });
  };

  const removeRow = (section, idx) => {
    setVoucher((prev) => {
      if (prev[section].length <= 1) {
        return prev;
      }

      const nextVoucher = { ...prev, [section]: prev[section].filter((_, rowIdx) => rowIdx !== idx) };

      if (section === 'passengers' && prev.pricing.length > 1) {
        nextVoucher.pricing = prev.pricing.filter((_, rowIdx) => rowIdx !== idx);
      }

      return nextVoucher;
    });
  };

  const useFlightPassengersForVisa = () => {
    setVoucher((prev) => {
      const passengerNames = (prev.pricing || [])
        .map((row, idx) => (row.pax_name || prev.passengers?.[idx]?.name || '').trim())
        .filter(Boolean);

      if (passengerNames.length === 0) {
        return prev;
      }

      const visaRows = [...prev.visa];
      while (visaRows.length < passengerNames.length) {
        visaRows.push(blankVisa());
      }

      return {
        ...prev,
        visa: visaRows.map((row, idx) => (
          passengerNames[idx] ? { ...row, passenger_name: passengerNames[idx] } : row
        )),
      };
    });
  };

  const setHotelLeadPassenger = (name) => {
    setVoucher((prev) => ({
      ...prev,
      hotels: prev.hotels.map((row) => ({ ...row, lead_passenger: name })),
    }));
  };

  const handleParse = ({ raw_text }) => {
    setLoadingParse(true);
    try {
      const parsed = parseGdsData(raw_text);
      const detectedSource = parsed.gds_source || 'other';
      const data = {
        success: true,
        gds_record: {
          id: null,
          uid: 'local',
          booking_reference: parsed.booking_reference,
          gds_source: detectedSource,
        },
        parsed,
      };

      if (!parsed.flights.length && !parsed.passengers.length) {
        throw new Error('No valid flights or passengers detected. Check the pasted GDS text format.');
      }

      setParseResult(data);
      setVoucher((prev) => {
        const nextVoucher = buildVoucherFromParsed(prev, detectedSource, data.parsed);
        return {
          ...nextVoucher,
          gds_parsed_record_id: null,
        };
      });
      setActiveTab('order');
      message.success('GDS parsed locally and voucher fields pre-filled.');
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
      const voucherPayload = {
        ...voucher,
        contact: {
          ...getProfileContact(),
          ...voucher.contact,
        },
      };

      const data = await createOrderFromVoucherApi({
        company_id: values.company_id || undefined,
        customer_id: values.customer_id,
        currency_code: values.currency_code || undefined,
        status: values.status || 'order',
        notes: values.notes || null,
        voucher: voucherPayload,
      });

      setCreatedOrder(data.order);
      message.success('Order created from voucher data');
    } catch (error) {
      message.error(error.message || 'Order creation failed');
    } finally {
      setLoadingOrder(false);
    }
  };

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Title level={2} style={{ margin: 0 }}>Create Order</Title>
        <Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>
          Parse GDS when available, complete the voucher fields, then create an order.
        </Paragraph>
      </div>

      <Card className="border-beam-aurora" style={{ marginBottom: 16 }}>
        <Steps
          current={1}
          items={[
            { title: 'Parse GDS', content: 'Extract flights and passengers' },
            { title: 'Complete Order', content: 'Confirm order, passenger, and service details' },
            { title: 'Create Order', content: 'Submit the completed order' },
          ]}
        />
      </Card>

      <Tabs
        activeKey={activeTab}
        onChange={setActiveTab}
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
            key: 'order',
            label: 'Order',
            children: (
              <>
                <div style={{ marginBottom: 16 }}>
                  <CreateOrderCard
                    form={orderForm}
                    loading={loadingOrder}
                    createdOrder={createdOrder}
                    onCreateOrder={handleCreateOrder}
                    showSubmit={false}
                  />
                </div>
                <VoucherHeaderCard
                  voucher={voucher}
                  setVoucherField={setVoucherField}
                />
                <VoucherRowsSections
                  voucher={voucher}
                  vendors={vendors}
                  onSearchVendors={loadVendors}
                  setRowField={setRowField}
                  addRow={addRow}
                  removeRow={removeRow}
                  onUseFlightPassengersForVisa={useFlightPassengersForVisa}
                  onSetHotelLeadPassenger={setHotelLeadPassenger}
                />

                <VoucherSummaryCard voucher={voucher} />

                <Card className="border-beam-aurora" style={{ marginTop: 16 }}>
                  <Space>
                    <Button type="primary" loading={loadingOrder} onClick={() => orderForm.submit()}>
                      Create Order
                    </Button>
                  </Space>
                </Card>
              </>
            ),
          },
        ]}
      />
    </div>
  );
}
