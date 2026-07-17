import React, { useEffect, useState } from 'react';
import { Alert, Button, Card, Form, Input, InputNumber, Modal, Select, Space, Spin, Table, Tag, Typography } from 'antd';
import { ArrowLeftOutlined, EyeOutlined, ExclamationCircleOutlined, SaveOutlined } from '@ant-design/icons';
import { useNavigate, useParams } from 'react-router-dom';
import { message } from '../services/feedback';
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
  createInitialVoucher,
  normalizeCabin,
  normalizeFlightDate,
  syncPassengerNameFields,
  syncVisaNumberFields,
} from './sales-flow/defaults';

const { Title, Paragraph } = Typography;
const { TextArea } = Input;

const invoiceStatusColors = {
  draft: 'default',
  issued: 'blue',
  sent: 'cyan',
  partial_paid: 'gold',
  paid: 'success',
  overdue: 'red',
  void: 'default',
};

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
  other_services: ['vendor_id', 'vendor_name', 'description', 'cost', 'profit', 'sales', 'amount'],
};

const normalizeRows = (rows, factory) => (Array.isArray(rows) && rows.length ? rows : [factory()]);

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

export default function OrderEdit() {
  const { uid } = useParams();
  const navigate = useNavigate();
  const [form] = Form.useForm();
  const [order, setOrder] = useState(null);
  const [voucher, setVoucher] = useState(createInitialVoucher());
  const [customers, setCustomers] = useState([]);
  const [vendors, setVendors] = useState([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const customerOptions = customers.map((customer) => ({
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
      render: (value) => String(value || '').slice(0, 10),
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
      width: 88,
      render: (_, invoice) => (
        <Button size="small" icon={<EyeOutlined />} onClick={() => navigate(`/invoices/${invoice.uid}`)}>
          Open
        </Button>
      ),
    },
  ];

  const loadCustomers = async (search = '') => {
    try {
      const response = await fetch(`/api/v1/customers${search ? `?search=${encodeURIComponent(search)}` : ''}`, {
        headers: authHeaders(),
      });

      if (!response.ok) throw new Error('Unable to load customers');
      setCustomers(await response.json());
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
    const loadOrder = async () => {
      setLoading(true);
      try {
        const response = await fetch(`/api/v1/orders/${uid}`, {
          headers: authHeaders(),
        });

        if (!response.ok) throw new Error('Failed to load order');
        const detail = await response.json();
        setOrder(detail);
        setVoucher(voucherFromOrder(detail));
        form.setFieldsValue({
          customer_id: detail.customer?.id || detail.customer_id,
          status: detail.status || 'order',
          currency_code: detail.currency_code || 'PKR',
          total_amount: Number(detail.total_amount || 0),
          notes: detail.notes || '',
        });
      } catch (error) {
        message.error(error.message || 'Failed to load order');
      } finally {
        setLoading(false);
      }
    };

    loadOrder();
    loadCustomers();
    loadVendors();
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

  const submitOrder = async (confirmInvoiceRevision = false) => {
    setSaving(true);
    try {
      const values = await form.validateFields();
      const voucherPayload = sanitizeVoucherForSubmit(voucher);
      const response = await fetch(`/api/v1/orders/${uid}`, {
        method: 'PATCH',
        headers: authHeaders(true),
        body: JSON.stringify({
          ...values,
          booking_reference: voucherPayload.booking_reference || null,
          voucher: voucherPayload,
          confirm_invoice_revision: confirmInvoiceRevision,
        }),
      });
      const data = await response.json();

      if (response.status === 409 && data?.requires_invoice_revision) {
        Modal.confirm({
          title: 'Create replacement invoice?',
          icon: <ExclamationCircleOutlined />,
          content: (
            <Space direction="vertical" size={8}>
              <span>
                Invoice {data.invoice?.invoice_number || ''} will be canceled, its amount will become 0,
                and a new invoice will be generated from these order changes.
              </span>
              <span>This keeps invoice history clean instead of editing an issued invoice directly.</span>
            </Space>
          ),
          okText: 'Create Replacement',
          cancelText: 'Keep Current Invoice',
          okButtonProps: { danger: true },
          onOk: () => submitOrder(true),
        });
        return;
      }

      if (!response.ok) throw new Error(data?.message || data?.error || 'Failed to update order');
      if (data.invoice_revised && data.invoice?.invoice_number) {
        message.success(`Replacement invoice ${data.invoice.invoice_number} created`);
      } else {
        message.success('Order updated');
      }
      navigate('/orders');
    } catch (error) {
      if (!error?.errorFields) {
        message.error(error.message || 'Failed to update order');
      }
    } finally {
      setSaving(false);
    }
  };

  const handleSave = () => submitOrder(false);

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

      <Spin spinning={loading}>
        <Card className="border-beam-aurora" style={{ marginBottom: 16 }} title="Order Details">
          {order?.invoice && (
            <Alert
              type="warning"
              showIcon
              style={{ marginBottom: 16 }}
              message={`Invoice ${order.invoice.invoice_number} already exists`}
              description="Saving changes will ask for confirmation, then cancel the current invoice and create a new replacement invoice."
            />
          )}
          <Form form={form} layout="vertical">
            <Space className="edit-order-fields" wrap align="start" style={{ width: '100%' }}>
              <Form.Item name="customer_id" label="Customer" rules={[{ required: true, message: 'Customer required' }]} style={{ minWidth: 300 }}>
                <Select showSearch filterOption={false} onSearch={loadCustomers} options={customerOptions} />
              </Form.Item>
              <Form.Item name="status" label="Status" rules={[{ required: true, message: 'Status required' }]} style={{ minWidth: 200 }}>
                <Select
                  options={[
                    { label: 'Quote', value: 'quote' },
                    { label: 'Order', value: 'order' },
                    { label: 'Confirm', value: 'confirm' },
                    { label: 'Cancel', value: 'cancel' },
                    { label: 'Invoice', value: 'invoice' },
                    { label: 'Void', value: 'void' },
                    { label: 'Refund', value: 'refund' },
                    { label: 'Partial Refund', value: 'partial_refund' },
                    { label: 'Paid', value: 'paid' },
                    { label: 'Partial Paid', value: 'partial_paid' },
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
        </Card>

        <Card
          className="border-beam-aurora"
          style={{ marginBottom: 16 }}
          title="Invoice History"
        >
          <Table
            rowKey="uid"
            size="small"
            columns={invoiceHistoryColumns}
            dataSource={order?.invoices || []}
            pagination={false}
            scroll={{ x: 900 }}
            locale={{ emptyText: 'No invoices have been generated for this order yet' }}
          />
        </Card>

        <VoucherHeaderCard voucher={voucher} setVoucherField={setVoucherField} />
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
            <Button onClick={() => navigate('/orders')}>Cancel</Button>
            <Button type="primary" icon={<SaveOutlined />} loading={saving} onClick={handleSave}>
              Save Order
            </Button>
          </Space>
        </Card>
      </Spin>
    </div>
  );
}
