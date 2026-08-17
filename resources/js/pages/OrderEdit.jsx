import React, { useEffect, useMemo, useState } from 'react';
import { Affix, Alert, Button, Card, Collapse, Form, Input, InputNumber, Modal, Select, Space, Spin, Tag, Typography } from 'antd';
import { ArrowLeftOutlined, EyeOutlined, ExclamationCircleOutlined, FileTextOutlined, SaveOutlined } from '@ant-design/icons';
import { useNavigate, useParams } from 'react-router-dom';
import { message } from '../services/feedback';
import { dateOnly } from '../services/dateFormat';
import { acquireEditLock, heartbeatEditLock, releaseEditLock } from '../services/editLocks';
import VoucherHeaderCard from './sales-flow/VoucherHeaderCard';
import VoucherRowsSections from './sales-flow/VoucherRowsSections';
import VoucherSummaryCard from './sales-flow/VoucherSummaryCard';
import {
  blankCityTour,
  blankFlight,
  blankHotel,
  blankOtherService,
  blankPassenger,
  blankPricing,
  blankTransfer,
  blankVisa,
  buildVoucherFromParsed,
  createInitialVoucher,
  normalizeCabin,
  normalizeFlightDate,
  syncPassengerNameFields,
  syncVisaNumberFields,
} from './sales-flow/defaults';
import { parseGdsData } from './sales-flow/gdsParser';
import GdsParserCard from './sales-flow/GdsParserCard';
import Table from '../components/CsvTable';

const { Title, Paragraph, Text } = Typography;
const { TextArea } = Input;

const invoiceStatusColors = {
  draft: 'default',
  issued: 'blue',
  sent: 'cyan',
  partial_paid: 'gold',
  paid: 'success',
  overdue: 'red',
  void: 'default',
  refund_request: 'red',
};
const invoiceSectionStatuses = ['invoice', 'void', 'refund', 'partial_refund', 'paid', 'partial_paid'];

const money = (value, currency = '') => `${currency ? `${currency} ` : ''}${Number(value || 0).toLocaleString()}`;

const rowFactories = {
  flights: blankFlight,
  passengers: blankPassenger,
  pricing: blankPricing,
  hotels: blankHotel,
  transfers: blankTransfer,
  city_tours: blankCityTour,
  visa: blankVisa,
  other_services: blankOtherService,
};

const serviceSections = ['flights', 'hotels', 'transfers', 'city_tours', 'visa', 'other_services'];

const sectionValueKeys = {
  flights: ['gds_pnr', 'pnr', 'flight_no', 'from', 'to', 'date', 'departure', 'arrival'],
  hotels: ['vendor_id', 'vendor_name', 'hcn', 'city', 'hotel_name', 'room_type', 'check_in', 'check_out', 'lead_passenger', 'notes', 'cost', 'profit', 'sales', 'amount'],
  transfers: ['vendor_id', 'vendor_name', 'tn', 'service', 'from_city', 'to_city', 'vehicle', 'contact_person', 'notes', 'cost', 'profit', 'sales', 'amount'],
  city_tours: ['vendor_id', 'vendor_name', 'city', 'title', 'attractions', 'date', 'notes', 'cost', 'profit', 'sales', 'amount'],
  visa: ['passenger_name', 'validity', 'visa_no', 'visa_publisher', 'vendor_id', 'visa_vendor', 'notes', 'cost', 'profit', 'sales', 'amount'],
  other_services: ['vendor_id', 'vendor_name', 'description', 'quantity', 'cost', 'profit', 'sales', 'amount'],
};

const normalizeRows = (rows, factory) => (Array.isArray(rows) && rows.length ? rows : [factory()]);
const mergeById = (...lists) => {
  const rows = new Map();

  lists.flat().filter((item) => item?.id).forEach((item) => {
    rows.set(Number(item.id), item);
  });

  return Array.from(rows.values());
};

const rowHasValue = (row, keys) => keys.some((key) => {
  const value = row?.[key];
  return value !== null && value !== undefined && String(value).trim() !== '';
});

const inferActiveSections = (order, meta, fallbackSections) => {
  if (Array.isArray(order?.active_sections) && order.active_sections.length) {
    return order.active_sections;
  }

  if (Array.isArray(meta.active_sections) && meta.active_sections.length) {
    return meta.active_sections;
  }

  const inferred = serviceSections.filter((section) => (
    Array.isArray(meta?.[section]) && meta[section].some((row) => rowHasValue(row, sectionValueKeys[section] || []))
  ));

  return inferred.length ? inferred : fallbackSections;
};

const sanitizeVoucherForSubmit = (voucher) => {
  const activeSections = Array.isArray(voucher.active_sections) ? voucher.active_sections : [];
  const isActive = (section) => activeSections.includes(section);

  return {
    ...voucher,
    active_sections: activeSections,
    flights: isActive('flights') ? voucher.flights : [],
    pricing: isActive('flights') ? voucher.pricing : [],
    hotels: isActive('hotels') ? voucher.hotels : [],
    transfers: isActive('transfers') ? voucher.transfers : [],
    city_tours: isActive('city_tours') ? voucher.city_tours : [],
    visa: isActive('visa') ? voucher.visa : [],
    other_services: isActive('other_services') ? voucher.other_services : [],
  };
};

const voucherFromOrder = (order) => {
  const initialVoucher = createInitialVoucher();
  const meta = order?.meta || {};
  const storedFlights = normalizeRows(meta.flights, blankFlight);
  const legacyFlightVendor = storedFlights.find((flight) => flight.vendor_id || flight.vendor_name) || {};

  return {
    ...initialVoucher,
    voucher_no: order?.voucher_no || meta.voucher_no || '',
    issue_date: order?.issue_date || meta.issue_date || '',
    package_type: order?.package_type || meta.package_type || '',
    booking_reference: order?.booking_reference || meta.booking_reference || '',
    gds_source: order?.gds_source || meta.gds_source || null,
    gds_parsed_record_id: order?.gds_parsed_record_id || meta.gds_parsed_record_id || null,
    emergency_contact: order?.emergency_contact || meta.emergency_contact || '',
    contact: {
      ...initialVoucher.contact,
      ...(meta.contact || {}),
    },
    active_sections: inferActiveSections(order, meta, initialVoucher.active_sections),
    flights: storedFlights.map((flight) => {
      const cleanFlight = {
        ...flight,
        cabin: normalizeCabin(flight.cabin),
        date: normalizeFlightDate(flight.date),
      };
      delete cleanFlight.vendor_id;
      delete cleanFlight.vendor_name;
      return cleanFlight;
    }),
    passengers: normalizeRows(meta.passengers, blankPassenger),
    pricing: normalizeRows(meta.pricing, blankPricing).map((row) => ({
      ...row,
      vendor_id: row.vendor_id ?? legacyFlightVendor.vendor_id ?? null,
      vendor_name: row.vendor_name || legacyFlightVendor.vendor_name || '',
    })),
    hotels: normalizeRows(meta.hotels, blankHotel),
    transfers: normalizeRows(meta.transfers, blankTransfer),
    city_tours: normalizeRows(meta.city_tours, blankCityTour),
    visa: normalizeRows(meta.visa, blankVisa),
    other_services: normalizeRows(meta.other_services, blankOtherService),
  };
};

const authHeaders = (json = false) => {
  const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
  return {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
    ...(json ? { 'Content-Type': 'application/json' } : {}),
  };
};

const currentUserCanViewCostProfit = () => {
  try {
    const user = JSON.parse(localStorage.getItem('user') || '{}');
    return user.role !== 'sales' || user.company_sales_can_edit_cost === true;
  } catch {
    return true;
  }
};

const currentUserIsSales = () => {
  try {
    const user = JSON.parse(localStorage.getItem('user') || '{}');
    return user.role === 'sales';
  } catch {
    return false;
  }
};

export default function OrderEdit() {
  const { uid } = useParams();
  const navigate = useNavigate();
  const [form] = Form.useForm();
  const [gdsForm] = Form.useForm();
  const [order, setOrder] = useState(null);
  const [voucher, setVoucher] = useState(createInitialVoucher());
  const [parseResult, setParseResult] = useState(null);
  const [customers, setCustomers] = useState([]);
  const [vendors, setVendors] = useState([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [loadingParse, setLoadingParse] = useState(false);
  const [cancelConfirmOpen, setCancelConfirmOpen] = useState(false);
  const [pendingCancelSubmit, setPendingCancelSubmit] = useState(null);
  const [cancelPassword, setCancelPassword] = useState('');
  const [lockConflict, setLockConflict] = useState(null);
  const canViewCostProfit = currentUserCanViewCostProfit();
  const canChangeStatus = !(currentUserIsSales() && invoiceSectionStatuses.includes(order?.status));
  const affixTarget = () => document.querySelector('.app-content');

  const parsedHint = useMemo(() => {
    if (!parseResult) {
      return 'Parse GDS text locally to update booking reference, flights, and passengers.';
    }

    const passengerCount = parseResult.parsed?.passengers?.length || 0;
    const segmentCount = parseResult.parsed?.flights?.length || parseResult.parsed?.segments?.length || 0;
    return `Parsed ${passengerCount} passenger(s) and ${segmentCount} flight segment(s).`;
  }, [parseResult]);

  const customerOptions = mergeById(customers, order?.customer ? [order.customer] : []).map((customer) => ({
    value: customer.id,
    label: `${customer.name}${customer.phone ? ` - ${customer.phone}` : ''}`,
  }));

  const invoiceHistoryColumns = [
    {
      title: 'Invoice',
      dataIndex: 'invoice_number',
      render: (value, invoice) => (
        <Space direction="vertical" size={0}>
          <Typography.Text strong>{value}</Typography.Text>
          <Typography.Text type="secondary">{String(invoice.created_at || '').slice(0, 16).replace('T', ' ')}</Typography.Text>
        </Space>
      ),
    },
    {
      title: 'Date',
      dataIndex: 'invoice_date',
      width: 120,
      render: dateOnly,
    },
    {
      title: 'Status',
      dataIndex: 'status',
      width: 130,
      render: (value) => <Tag color={invoiceStatusColors[value] || 'default'}>{String(value || '').replaceAll('_', ' ').toUpperCase()}</Tag>,
    },
    {
      title: 'Total',
      width: 140,
      align: 'right',
      render: (_, invoice) => money(invoice.total_amount, invoice.currency_code),
    },
    {
      title: 'Outstanding',
      width: 140,
      align: 'right',
      render: (_, invoice) => money(invoice.outstanding_amount, invoice.currency_code),
    },
    {
      title: 'Notes',
      dataIndex: 'notes',
      ellipsis: true,
      render: (value) => value || '-',
    },
    {
      title: '',
      width: 180,
      render: (_, invoice) => (
        <Space>
          <Button size="small" icon={<FileTextOutlined />} onClick={() => navigate(`/invoices/${invoice.uid}`)}>
            Invoice
          </Button>
          <Button size="small" icon={<EyeOutlined />} onClick={() => navigate(`/invoices/${invoice.uid}?view=detailed`)}>
            Detailed
          </Button>
        </Space>
      ),
    },
  ];

  const loadCustomers = async (search = '') => {
    try {
      const response = await fetch(`/api/v1/customers${search ? `?search=${encodeURIComponent(search)}` : ''}`, {
        headers: authHeaders(),
      });

      if (!response.ok) throw new Error('Unable to load customers');
      const data = await response.json();
      const selectedCustomerId = Number(form.getFieldValue('customer_id'));
      setCustomers((current) => mergeById(data, current.filter((customer) => Number(customer.id) === selectedCustomerId)));
    } catch (error) {
      message.error(error.message || 'Unable to load customers');
    }
  };

  const loadVendors = async (search = '') => {
    try {
      const response = await fetch(`/api/v1/vendors${search ? `?search=${encodeURIComponent(search)}` : ''}`, {
        headers: authHeaders(),
      });

      if (!response.ok) throw new Error('Unable to load vendors');
      setVendors(await response.json());
    } catch (error) {
      message.error(error.message || 'Unable to load vendors');
    }
  };

  useEffect(() => {
    let acquiredLock = false;
    let heartbeatTimer = null;
    let cancelled = false;

    const loadOrder = async () => {
      setLoading(true);
      try {
        setLockConflict(null);
        await acquireEditLock('order', uid);
        acquiredLock = true;
        heartbeatTimer = setInterval(() => {
          heartbeatEditLock('order', uid).catch(() => {});
        }, 30000);

        const response = await fetch(`/api/v1/orders/${uid}`, {
          headers: authHeaders(),
        });

        if (!response.ok) throw new Error('Failed to load order');
        const detail = await response.json();
        if (cancelled) return;
        setOrder(detail);
        if (detail.customer?.id) {
          setCustomers((current) => mergeById([detail.customer], current));
        }
        setVoucher(voucherFromOrder(detail));
        form.setFieldsValue({
          customer_id: detail.customer?.id || detail.customer_id,
          status: detail.status || 'order',
          currency_code: detail.currency_code || 'PKR',
          total_amount: Number(detail.total_amount || 0),
          notes: detail.notes || '',
        });
      } catch (error) {
        if (cancelled) return;
        if (error.status === 423) {
          setLockConflict(error.data || { message: error.message });
        } else {
          message.error(error.message || 'Failed to load order');
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    };

    loadOrder();
    loadCustomers();
    loadVendors();

    return () => {
      cancelled = true;
      if (heartbeatTimer) {
        clearInterval(heartbeatTimer);
      }
      if (acquiredLock) {
        releaseEditLock('order', uid).catch(() => {});
      }
    };
  }, [uid]);

  const setVoucherField = (field, value) => {
    setVoucher((prev) => ({ ...prev, [field]: value }));
  };

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
          if (rowIdx !== idx || (row.flight_ticket_no && row.flight_ticket_no !== previousRow.ticket_no)) return row;
          return { ...row, flight_ticket_no: value };
        });
      }

      return nextVoucher;
    });
  };

  const addRow = (section, factory = rowFactories[section]) => {
    if (!factory) return;

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
      if (prev[section].length <= 1) return prev;

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
      setVoucher((prev) => ({
        ...buildVoucherFromParsed(prev, detectedSource, data.parsed),
        gds_parsed_record_id: null,
      }));
      message.success('GDS parsed locally and voucher fields updated.');
    } catch (error) {
      message.error(error.message || 'GDS parse failed');
    } finally {
      setLoadingParse(false);
    }
  };

  const submitOrder = async (confirmInvoiceRevision = false, password = null) => {
    setSaving(true);
    try {
      const values = await form.validateFields();
      const voucherPayload = sanitizeVoucherForSubmit(voucher);
      const isCancellingOrder = values.status === 'cancel' && order?.status !== 'cancel';

      if (isCancellingOrder && !password) {
        setPendingCancelSubmit({ confirmInvoiceRevision });
        setCancelPassword('');
        setCancelConfirmOpen(true);
        return;
      }

      const payload = { ...values };
      if (isCancellingOrder) {
        payload.cancel_password = password;
      } else {
        delete payload.cancel_password;
      }

      const response = await fetch(`/api/v1/orders/${uid}`, {
        method: 'PATCH',
        headers: authHeaders(true),
        body: JSON.stringify({
          ...payload,
          booking_reference: voucherPayload.booking_reference || null,
          voucher: voucherPayload,
          confirm_invoice_revision: confirmInvoiceRevision,
        }),
      });
      const data = await response.json();

      if (response.status === 409 && data?.requires_invoice_revision) {
        Modal.confirm({
          title: 'Cancel invoice and create new order?',
          icon: <ExclamationCircleOutlined />,
          content: (
            <Space direction="vertical" size={8}>
              <span>
                Invoice {data.invoice?.invoice_number || ''} will be cancelled and its amount will become 0.
              </span>
              <span>A new order will be created from these changes so you can create the invoice manually.</span>
            </Space>
          ),
          okText: 'Create New Order',
          cancelText: 'Keep Current Invoice',
          okButtonProps: { danger: true },
          onOk: () => submitOrder(true),
        });
        return;
      }

      if (!response.ok) throw new Error(data?.message || data?.error || 'Failed to update order');
      if (data.new_order_created && data.order?.order_number) {
        message.success(`New order ${data.order.order_number} created. You can create its invoice from Orders.`);
      } else if (data.invoice?.invoice_number) {
        message.success(`Invoice ${data.invoice.invoice_number} is ready`);
      } else {
        message.success('Order updated');
      }
      setCancelConfirmOpen(false);
      setPendingCancelSubmit(null);
      setCancelPassword('');
      navigate('/orders');
    } catch (error) {
      if (!error?.errorFields) {
        message.error(error.message || 'Failed to update order');
      }
    } finally {
      setSaving(false);
    }
  };

  const confirmCancelOrder = () => {
    if (!cancelPassword) {
      message.error('Enter your login password to cancel this order');
      return;
    }

    submitOrder(pendingCancelSubmit?.confirmInvoiceRevision || false, cancelPassword);
  };

  const handleSave = () => submitOrder(false);

  if (lockConflict) {
    const userName = lockConflict.locked_by?.name || 'Another user';

    return (
      <div className="page-shell page-fade-up">
        <Alert
          type="warning"
          showIcon
          message={`${userName} is working on this order`}
          description="This order is temporarily locked for editing. Try again after they finish or the lock expires."
          action={(
            <Button icon={<ArrowLeftOutlined />} onClick={() => navigate('/orders')}>
              Back to Orders
            </Button>
          )}
        />
      </div>
    );
  }

  return (
    <div className="page-shell page-fade-up">
      <div className="elevated-card border-beam-aurora" style={{ marginBottom: 16 }}>
        <Space direction="vertical" size={4} style={{ width: '100%' }}>
          <Button icon={<ArrowLeftOutlined />} onClick={() => navigate('/orders')} style={{ width: 'fit-content' }}>
            Back to Orders
          </Button>
          <Title level={2} style={{ margin: 0 }}>
            Edit {order?.order_number || 'Order'}
          </Title>
          <Paragraph type="secondary" style={{ margin: 0 }}>
            Update order details, voucher header, passengers, and service rows in the full order form.
          </Paragraph>
        </Space>
      </div>

      <Affix className="edit-order-action-affix" offsetTop={10} target={affixTarget}>
        <Card className="edit-order-action-card border-beam-aurora" style={{ marginBottom: 16 }}>
          <Space className="edit-order-actions">
            <Button onClick={() => navigate('/orders')}>Cancel</Button>
            <Button type="primary" icon={<SaveOutlined />} loading={saving} onClick={handleSave}>
              Save Order
            </Button>
          </Space>
        </Card>
      </Affix>

      <Spin spinning={loading}>
        <Collapse
          className="edit-order-collapse border-beam-aurora"
          items={[
            {
              key: 'order-details',
              label: 'Order Details',
              children: (
                <>
                  {order?.invoice && (
                    <Alert
                      type="warning"
                      showIcon
                      style={{ marginBottom: 16 }}
                      message={`Invoice ${order.invoice.invoice_number} already exists`}
                      description="Saving changes will ask for confirmation, then cancel the current invoice and create a new order for manual invoicing."
                    />
                  )}
                  <Form form={form} layout="vertical">
                    <Space className="edit-order-fields" wrap align="start" style={{ width: '100%' }}>
                      <Form.Item name="customer_id" label="Customer" rules={[{ required: true, message: 'Customer required' }]} style={{ minWidth: 300 }}>
                        <Select showSearch filterOption={false} onSearch={loadCustomers} options={customerOptions} />
                      </Form.Item>
                      <Form.Item name="status" label="Status" rules={[{ required: true, message: 'Status required' }]} style={{ minWidth: 200 }}>
                        <Select
                          disabled={!canChangeStatus}
                          options={[
                            { label: 'Quote', value: 'quote' },
                            { label: 'Order', value: 'order' },
                            { label: 'Cancel', value: 'cancel' },
                            { label: 'Invoice', value: 'invoice' },
                            { label: 'Void', value: 'void' },
                            { label: 'Refund Request', value: 'refund_request' },
                            { label: 'Refund', value: 'refund' },
                            { label: 'Partial Refund', value: 'partial_refund' },
                          ]}
                        />
                      </Form.Item>
                      <Form.Item
                        name="currency_code"
                        label="Currency Code"
                        normalize={(value) => value?.toUpperCase()}
                        rules={[{ required: true, message: 'Currency required' }]}
                        style={{ minWidth: 160 }}
                      >
                        <Input maxLength={3} />
                      </Form.Item>
                      <Form.Item
                        name="total_amount"
                        label="Total Amount"
                        rules={[{ required: true, message: 'Total amount required' }]}
                        style={{ minWidth: 180 }}
                      >
                        <InputNumber min={0} precision={2} controls={false} style={{ width: '100%' }} />
                      </Form.Item>
                    </Space>
                    <Form.Item name="notes" label="Order Notes">
                      <TextArea rows={3} />
                    </Form.Item>
                  </Form>
                </>
              ),
            },
            {
              key: 'parse-gds',
              label: 'Parse GDS (Sabre / Galileo / Amadeus / Other)',
              children: (
                <GdsParserCard
                  form={gdsForm}
                  loading={loadingParse}
                  parsedHint={parsedHint}
                  parseResult={parseResult}
                  onParse={handleParse}
                  embedded
                />
              ),
            },
            {
              key: 'invoice-history',
              label: 'Invoice History',
              children: (
                <Table
                  rowKey="uid"
                  size="small"
                  columns={invoiceHistoryColumns}
                  dataSource={order?.invoices || []}
                  pagination={false}
                  scroll={{ x: 900 }}
                  locale={{ emptyText: 'No invoices have been generated for this order yet' }}
                />
              ),
            },
            {
              key: 'basic-voucher-information',
              label: 'Basic Voucher Information',
              children: <VoucherHeaderCard voucher={voucher} setVoucherField={setVoucherField} embedded />,
            },
          ]}
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
          canViewCostProfit={canViewCostProfit}
        />

        <Collapse
          className="edit-order-collapse border-beam-aurora"
          items={[
            {
              key: 'order-summary',
              label: 'Order Summary',
              children: <VoucherSummaryCard voucher={voucher} canViewCostProfit={canViewCostProfit} embedded />,
            },
          ]}
        />
      </Spin>

      <Modal
        title="Confirm Order Cancellation"
        open={cancelConfirmOpen}
        okText="Cancel Order"
        okButtonProps={{ danger: true, loading: saving }}
        confirmLoading={saving}
        onOk={confirmCancelOrder}
        onCancel={() => {
          setCancelConfirmOpen(false);
          setPendingCancelSubmit(null);
          setCancelPassword('');
        }}
      >
        <Space direction="vertical" size={8} style={{ width: '100%' }}>
          <Text type="secondary">Enter your login password to confirm this cancellation.</Text>
          <Input.Password
            autoComplete="current-password"
            value={cancelPassword}
            onChange={(event) => setCancelPassword(event.target.value)}
            onPressEnter={confirmCancelOrder}
          />
        </Space>
      </Modal>
    </div>
  );
}
