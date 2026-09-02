import React, { useEffect, useState } from 'react';
import { Affix, Checkbox, Col, Grid, Input, InputNumber, Row, Select, Tabs, Typography } from 'antd';
import { getAirportLabel } from './airportLookup';
import RowGroupCard from './RowGroupCard';
import { stripUtcMidnightSuffix } from '../../services/dateFormat';
import {
  blankCityTour,
  blankFlight,
  blankHotel,
  blankOtherService,
  blankPassenger,
  blankPricing,
  blankTransfer,
  blankVisa,
} from './defaults';
import { refundInputValue, refundSignedFields, signedRefundValue } from './refundValues';

const { Text } = Typography;
const { useBreakpoint } = Grid;

const visaTypeOptions = [
  { value: 'visit', label: 'Visit' },
  { value: 'umrah', label: 'Umrah' },
  { value: 'hajj', label: 'Hajj' },
  { value: 'tourist', label: 'Tourist' },
  { value: 'other', label: 'Other' },
];

const cabinOptions = [
  { value: 'economy', label: 'Eco' },
  { value: 'economy+', label: 'Eco+' },
  { value: 'business', label: 'BUSNS' },
  { value: 'business+', label: 'BUSNS+' },
  { value: 'first_class', label: 'FRST' },
  { value: 'first_class+', label: 'FRST+' },
];

const rbdOptions = Array.from({ length: 26 }, (_, index) => {
  const letter = String.fromCharCode(65 + index);
  return { value: letter, label: letter };
});

const baggageOptions = [
  '0PC', '1PC', '2PC', '4PC',
  '0KG', '10KG', '15KG', '20KG', '23KG', '25KG', '30KG', '35KG', '40KG',
].map((value) => ({ value, label: value }));

const roomTypeOptions = [
  {
    label: 'Common',
    options: [
      'Standard Room',
      'Superior Room',
      'Deluxe Room',
      'Executive Room',
      'Premium Room',
      'Club Room',
    ].map((value) => ({ value, label: value })),
  },
  {
    label: 'Bed Setup',
    options: [
      'Single Room',
      'Double Room',
      'Twin Room',
      'Triple Room',
      'Quad Room',
      'Queen Room',
      'King Room',
      'Double Queen Room',
    ].map((value) => ({ value, label: value })),
  },
  {
    label: 'Suites',
    options: [
      'Junior Suite',
      'Suite',
      'Executive Suite',
      'Family Suite',
      'Presidential Suite',
    ].map((value) => ({ value, label: value })),
  },
  {
    label: 'Family / Access',
    options: [
      'Family Room',
      'Connecting Room',
      'Adjoining Room',
      'Accessible Room',
      'Studio Room',
      'Apartment',
    ].map((value) => ({ value, label: value })),
  },
];

const hasSection = (voucher, section) => voucher.active_sections.includes(section);

const flightPricingFields = [
  ['Ticket Number', 'flight_ticket_no', { min: 0, precision: 0 }],
  ['Base', 'flight_cost_base', { min: 0, precision: 2 }],
  ['ROE', 'flight_cost_roe', { min: 0, precision: 6 }],
  ['Equv/Cost', 'flight_cost', { min: 0, precision: 2 }],
  ['Profit', 'flight_profit', { precision: 2 }],
  ['Amount / Sales', 'flight_sales', { min: 0, precision: 2 }],
];

const serviceMoneyFields = [
  ['Base', 'cost_base', { min: 0, precision: 2 }],
  ['ROE', 'cost_roe', { min: 0, precision: 6 }],
  ['Equv/Cost', 'cost', { min: 0, precision: 2 }],
  ['Profit', 'profit', { precision: 2 }],
  ['Amount / Sales', 'sales', { min: 0, precision: 2 }],
];

const flightCostExchangeFields = {
  base: 'flight_cost_base',
  roe: 'flight_cost_roe',
  equv: 'flight_cost',
};

const serviceCostExchangeFields = {
  base: 'cost_base',
  roe: 'cost_roe',
  equv: 'cost',
};

const flightCostProfitFields = new Set(['flight_cost_base', 'flight_cost_roe', 'flight_cost', 'flight_profit']);
const blankableFlightPricingFields = new Set(['flight_ticket_no', 'flight_cost_base', 'flight_cost_roe']);
const blankableServiceMoneyFields = new Set(['cost_base', 'cost_roe']);
const serviceMoneyColProps = { xs: 24, sm: 12, md: 8, lg: 4, xl: 3 };
const flightPricingColProps = { xs: 24, sm: 12, md: 8, lg: 4, xl: 3 };

const toNumber = (value) => {
  if (value === null || value === undefined || value === '') {
    return 0;
  }

  const normalized = String(value).replace(/[^0-9.-]/g, '');
  const parsed = Number(normalized);

  return Number.isFinite(parsed) ? parsed : null;
};

const dateOnlyMs = (value) => {
  const match = stripUtcMidnightSuffix(value, '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!match) {
    return null;
  }

  return Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
};

const calculateNights = (checkIn, checkOut) => {
  const start = dateOnlyMs(checkIn);
  const end = dateOnlyMs(checkOut);

  if (start === null || end === null || end <= start) {
    return '';
  }

  return String(Math.round((end - start) / (24 * 60 * 60 * 1000)));
};

const moneyValue = (value) => {
  const rounded = Math.round((value + Number.EPSILON) * 100) / 100;
  return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(2);
};

const enteredNumber = (value) => {
  if (value === null || value === undefined || String(value).trim() === '') {
    return null;
  }

  const normalized = String(value).replace(/[^0-9.-]/g, '');
  const parsed = Number(normalized);

  return Number.isFinite(parsed) ? parsed : null;
};

const calculateCostExchangeUpdates = (row, fields, changedField, value) => {
  const nextRow = { ...row, [changedField]: value };
  const base = enteredNumber(nextRow[fields.base]);
  const roe = enteredNumber(nextRow[fields.roe]);
  const equv = enteredNumber(nextRow[fields.equv]);

  if ((changedField === fields.base || changedField === fields.roe) && base !== null && roe !== null) {
    return { [fields.equv]: moneyValue(base * roe) };
  }

  if (changedField === fields.equv && equv !== null) {
    return {
      [fields.base]: moneyValue(equv),
      [fields.roe]: '1',
    };
  }

  return {};
};

const AirportInput = ({ label, value, onChange }) => {
  const airportLabel = getAirportLabel(value);

  return (
    <>
      <Text>{label}</Text>
      <Input value={value} onChange={(e) => onChange(e.target.value.toUpperCase())} />
      {airportLabel && (
        <Text type="secondary" style={{ display: 'block', fontSize: 12, lineHeight: 1.25, marginTop: 4 }}>
          {airportLabel}
        </Text>
      )}
    </>
  );
};

export default function VoucherRowsSections({
  voucher,
  vendors = [],
  onSearchVendors,
  setRowField,
  addRow,
  removeRow,
  onUseFlightPassengersForVisa,
  onSetHotelLeadPassenger,
  canViewCostProfit = true,
  refundMode = false,
}) {
  const screens = useBreakpoint();
  const useMobileSectionSelect = !screens.md;
  const [activeTabKey, setActiveTabKey] = useState('passengers');
  const vendorOptions = vendors.map((vendor) => ({
    value: vendor.id,
    label: `${vendor.name}${vendor.phone ? ` - ${vendor.phone}` : ''}`,
    vendor,
  }));
  const flightPassengerNames = (voucher.pricing || [])
    .map((row, idx) => (row.pax_name || voucher.passengers?.[idx]?.name || '').trim())
    .filter(Boolean);
  const visaNamesMatchFlights = flightPassengerNames.length > 0 && flightPassengerNames.every(
    (name, idx) => (voucher.visa?.[idx]?.passenger_name || '').trim() === name
  );
  const hotelLeadPassenger = (voucher.hotels || []).find((row) => (row.lead_passenger || '').trim())?.lead_passenger || '';

  const setVendorFields = (section, idx, idField, nameField, vendorId) => {
    const vendor = vendors.find((item) => item.id === vendorId);
    setRowField(section, idx, idField, vendorId || null);
    setRowField(section, idx, nameField, vendor?.name || '');
  };

  const setPricingField = (idx, field, value) => {
    const row = voucher.pricing[idx] || {};
    const fallbackValue = blankableFlightPricingFields.has(field) ? '' : '0';
    const numericValue = refundMode && refundSignedFields.has(field)
      ? signedRefundValue(value, fallbackValue)
      : value ?? fallbackValue;
    const updates = {
      [field]: numericValue,
      ...(canViewCostProfit ? calculateCostExchangeUpdates(row, flightCostExchangeFields, field, numericValue) : {}),
    };
    const nextRow = { ...row, ...updates };
    const cost = toNumber(nextRow.flight_cost);
    const profit = toNumber(nextRow.flight_profit);
    const sales = toNumber(nextRow.flight_sales);
    const costChanged = field === 'flight_cost' || Object.prototype.hasOwnProperty.call(updates, 'flight_cost');

    const applyUpdates = () => {
      Object.entries(updates).forEach(([updateField, updateValue]) => {
        setRowField('pricing', idx, updateField, updateValue);
      });
    };

    if (!canViewCostProfit) {
      applyUpdates();
      return;
    }

    if (costChanged) {
      if (cost !== null && profit !== null) {
        updates.flight_sales = moneyValue(cost + profit);
      } else if (cost !== null && sales !== null) {
        updates.flight_profit = moneyValue(sales - cost);
      }
    }

    if (field === 'flight_profit' && cost !== null && profit !== null) {
      updates.flight_sales = moneyValue(cost + profit);
    }

    if (field === 'flight_sales' && cost !== null && sales !== null) {
      if (cost === 0 && profit === 0 && sales !== 0) {
        updates.flight_cost = moneyValue(sales);
      } else {
        updates.flight_profit = moneyValue(sales - cost);
      }
    }

    applyUpdates();
  };

  const setServiceMoneyField = (section, idx, field, value) => {
    const row = voucher[section]?.[idx] || {};
    const fallbackValue = blankableServiceMoneyFields.has(field) ? '' : '0';
    const numericValue = refundMode && refundSignedFields.has(field)
      ? signedRefundValue(value, fallbackValue)
      : value ?? fallbackValue;
    const updates = {
      [field]: numericValue,
      ...(canViewCostProfit ? calculateCostExchangeUpdates(row, serviceCostExchangeFields, field, numericValue) : {}),
    };
    const nextRow = { ...row, ...updates };
    const cost = toNumber(nextRow.cost);
    const profit = toNumber(nextRow.profit);
    const sales = toNumber(nextRow.sales);
    const costChanged = field === 'cost' || Object.prototype.hasOwnProperty.call(updates, 'cost');

    const applyUpdates = () => {
      Object.entries(updates).forEach(([updateField, updateValue]) => {
        setRowField(section, idx, updateField, updateValue);
      });
    };

    if (!canViewCostProfit) {
      if (field === 'sales') {
        updates.amount = moneyValue(sales || 0);
      }
      applyUpdates();
      return;
    }

    if (costChanged) {
      if (cost !== null && profit !== null) {
        const nextSales = moneyValue(cost + profit);
        updates.sales = nextSales;
        updates.amount = nextSales;
      } else if (cost !== null && sales !== null) {
        updates.profit = moneyValue(sales - cost);
      }
    }

    if (field === 'profit' && cost !== null && profit !== null) {
      const nextSales = moneyValue(cost + profit);
      updates.sales = nextSales;
      updates.amount = nextSales;
    }

    if (field === 'sales' && cost !== null && sales !== null) {
      updates.amount = moneyValue(sales);
      if (cost === 0 && profit === 0 && sales !== 0) {
        updates.cost = moneyValue(sales);
      } else {
        updates.profit = moneyValue(sales - cost);
      }
    }

    applyUpdates();
  };

  const serviceVendorSelect = (section, idx, row, label = 'Vendor', nameField = 'vendor_name') => (
    <Col xs={24} sm={12} md={8} lg={4} xl={4}>
      <Text>{label}</Text>
      <Select
        allowClear
        showSearch
        value={row.vendor_id}
        placeholder="Select vendor"
        filterOption={false}
        onSearch={onSearchVendors}
        options={vendorOptions}
        onChange={(value) => setVendorFields(section, idx, 'vendor_id', nameField, value)}
        style={{ width: '100%' }}
      />
    </Col>
  );

  const visibleServiceMoneyFields = canViewCostProfit
    ? serviceMoneyFields
    : serviceMoneyFields.filter(([, field]) => field === 'sales');
  const visibleFlightPricingFields = canViewCostProfit
    ? flightPricingFields
    : flightPricingFields.filter(([, field]) => !flightCostProfitFields.has(field));

  const serviceMoneyInputs = (section, idx, row) => visibleServiceMoneyFields.map(([label, field, inputProps]) => (
    <Col {...serviceMoneyColProps} key={field}>
      <Text>{label}</Text>
      <InputNumber
        {...inputProps}
        stringMode
        controls={false}
        status={refundMode && refundSignedFields.has(field) ? 'error' : undefined}
        value={refundMode && refundSignedFields.has(field)
          ? refundInputValue(row[field])
          : row[field] === '' || row[field] === null || row[field] === undefined ? null : row[field]}
        onChange={(value) => setServiceMoneyField(section, idx, field, value)}
        style={{ width: '100%' }}
      />
    </Col>
  ));

  const tabItems = [
    {
      key: 'passengers',
      label: 'Passengers',
      children: (
        <RowGroupCard title="Passengers" rows={voucher.passengers} addLabel="+ Add Passenger" onAdd={() => addRow('passengers', blankPassenger)} onRemove={(idx) => removeRow('passengers', idx)}>
          {(row, idx) => (
            <Row gutter={8}>
              {(() => {
                const passengerName = (row.name || '').trim();
                const visaNumber = (voucher.visa?.[idx]?.visa_no || '').trim();
                const passengerVisaNumber = (row.visa_no || '').trim();
                return (
                  <>
                    <Col xs={24} sm={12} md={8} lg={8} xl={4}><Text>Name</Text><Input value={row.name} onChange={(e) => setRowField('passengers', idx, 'name', e.target.value)} /></Col>
                    <Col xs={24} sm={12} md={8} lg={2} xl={1}>
                      <Text>Lead</Text>
                      <Checkbox
                        checked={passengerName !== '' && hotelLeadPassenger === passengerName}
                        disabled={passengerName === ''}
                        onChange={(e) => onSetHotelLeadPassenger?.(e.target.checked ? passengerName : '')}
                        style={{ display: 'block', marginTop: 8 }}
                      />
                    </Col>
                    <Col xs={24} sm={12} md={8} lg={8} xl={4}><Text>Ticket No</Text><Input value={row.ticket_no} onChange={(e) => setRowField('passengers', idx, 'ticket_no', e.target.value)} /></Col>
                    <Col xs={24} sm={12} md={8} lg={8} xl={4}><Text>Passport No</Text><Input value={row.passport_no} onChange={(e) => setRowField('passengers', idx, 'passport_no', e.target.value)} /></Col>
                    <Col xs={24} sm={12} md={8} lg={8} xl={4}>
                      <Text>Visa No</Text>
                      <Input value={row.visa_no} onChange={(e) => setRowField('passengers', idx, 'visa_no', e.target.value)} />
                    </Col>
                    <Col xs={24} sm={12} md={8} lg={3} xl={1}>
                      <Text>
                        Use inserted
                      </Text>
                      <Checkbox
                        checked={visaNumber !== '' && passengerVisaNumber === visaNumber}
                        disabled={visaNumber === ''}
                        onChange={(e) => {
                          if (e.target.checked) {
                            setRowField('passengers', idx, 'visa_no', visaNumber);
                          }
                        }}
                        style={{ display: 'block', marginTop: 8 }}
                      />
                    </Col>
                    <Col xs={24} sm={12} md={8} lg={16} xl={5}><Text>Notes</Text><Input value={row.notes} onChange={(e) => setRowField('passengers', idx, 'notes', e.target.value)} /></Col>
                  </>
                );
              })()}
            </Row>
          )}
        </RowGroupCard>
      ),
    },
    hasSection(voucher, 'flights') && {
      key: 'flights',
      label: 'Flights',
      children: (
        <>
          <RowGroupCard title="Flights" rows={voucher.flights} addLabel="+ Add Flight" onAdd={() => addRow('flights', blankFlight)} onRemove={(idx) => removeRow('flights', idx)}>
            {(row, idx) => (
              <Row gutter={8}>
                <Col xs={24} sm={12} md={8} lg={4} xl={2}><Text>Flight No</Text><Input value={row.flight_no} onChange={(e) => setRowField('flights', idx, 'flight_no', e.target.value)} /></Col>
                <Col xs={24} sm={12} md={8} lg={4} xl={2}><Text>Date</Text><Input type="date" value={row.date} onChange={(e) => setRowField('flights', idx, 'date', e.target.value)} /></Col>
                <Col xs={24} sm={12} md={10} lg={6} xl={3}>
                  <AirportInput label="From" value={row.from} onChange={(value) => setRowField('flights', idx, 'from', value)} />
                </Col>
                <Col xs={24} sm={12} md={10} lg={6} xl={3}>
                  <AirportInput label="To" value={row.to} onChange={(value) => setRowField('flights', idx, 'to', value)} />
                </Col>
                <Col xs={24} sm={12} md={8} lg={4} xl={2}><Text>Departure</Text><Input type="time" value={row.departure} onChange={(e) => setRowField('flights', idx, 'departure', e.target.value)} /></Col>
                <Col xs={24} sm={12} md={8} lg={4} xl={2}><Text>Arrival</Text><Input type="time" value={row.arrival} onChange={(e) => setRowField('flights', idx, 'arrival', e.target.value)} /></Col>
                <Col xs={24} sm={12} md={8} lg={4} xl={2}><Text>Cabin</Text><Select value={row.cabin} options={cabinOptions} onChange={(value) => setRowField('flights', idx, 'cabin', value)} style={{ width: '100%' }} /></Col>
                <Col xs={24} sm={12} md={8} lg={4} xl={2}><Text>Bag</Text><Select value={row.baggage} options={baggageOptions} onChange={(value) => setRowField('flights', idx, 'baggage', value)} style={{ width: '100%' }} /></Col>
                <Col xs={24} sm={12} md={8} lg={4} xl={2}><Text>RBD</Text><Select showSearch value={row.booking_class} options={rbdOptions} onChange={(value) => setRowField('flights', idx, 'booking_class', value)} style={{ width: '100%' }} /></Col>
                <Col xs={24} sm={12} md={8} lg={4} xl={2}><Text>GDS PNR</Text><Input value={row.gds_pnr} onChange={(e) => setRowField('flights', idx, 'gds_pnr', e.target.value)} /></Col>
                <Col xs={24} sm={12} md={8} lg={4} xl={2}><Text>Airline PNR</Text><Input value={row.pnr} onChange={(e) => setRowField('flights', idx, 'pnr', e.target.value)} /></Col>
              </Row>
            )}
          </RowGroupCard>
          <RowGroupCard title="Flight Amounts (Per Passenger)" rows={voucher.pricing} addLabel="+ Add Flight Amount" onAdd={() => addRow('pricing', blankPricing)} onRemove={(idx) => removeRow('pricing', idx)}>
            {(row, idx) => (
              <Row gutter={8}>
                <Col {...flightPricingColProps}>
                  <Text>Flight Vendor</Text>
                  <Select
                    allowClear
                    showSearch
                    value={row.vendor_id}
                    placeholder="Select vendor"
                    filterOption={false}
                    onSearch={onSearchVendors}
                    options={vendorOptions}
                    onChange={(value) => setVendorFields('pricing', idx, 'vendor_id', 'vendor_name', value)}
                    style={{ width: '100%' }}
                  />
                </Col>
                <Col {...flightPricingColProps}><Text>Passenger</Text><Input value={row.pax_name || voucher.passengers[idx]?.name || ''} onChange={(e) => setRowField('pricing', idx, 'pax_name', e.target.value)} /></Col>
                {visibleFlightPricingFields.map(([label, field, inputProps]) => (
                  <Col {...flightPricingColProps} key={field}>
                    <Text>{label}</Text>
                    <InputNumber
                      {...inputProps}
                      stringMode
                      controls={false}
                      status={refundMode && refundSignedFields.has(field) ? 'error' : undefined}
                      value={refundMode && refundSignedFields.has(field)
                        ? (refundInputValue(row[field]) ?? (field === 'flight_ticket_no' ? null : '0'))
                        : row[field] === '' || row[field] === null || row[field] === undefined
                          ? (field === 'flight_ticket_no' ? null : '0')
                          : row[field]}
                      onChange={(value) => setPricingField(idx, field, value)}
                      style={{ width: '100%' }}
                    />
                  </Col>
                ))}
              </Row>
            )}
          </RowGroupCard>
        </>
      ),
    },
    hasSection(voucher, 'visa') && {
      key: 'visa',
      label: 'Visa',
      children: (
        <>
          <div style={{ marginBottom: 8 }}>
            <Checkbox
              checked={visaNamesMatchFlights}
              disabled={flightPassengerNames.length === 0}
              onChange={(e) => {
                if (e.target.checked) {
                  onUseFlightPassengersForVisa?.();
                }
              }}
            >
              Use flight passenger names
            </Checkbox>
          </div>
          <RowGroupCard title="Visa" rows={voucher.visa} addLabel="+ Add Visa" onAdd={() => addRow('visa', blankVisa)} onRemove={(idx) => removeRow('visa', idx)}>
            {(row, idx) => (
              <Row gutter={8}>
                <Col xs={24} sm={12} md={8} lg={4} xl={6}><Text>Passenger Name</Text><Input value={row.passenger_name} onChange={(e) => setRowField('visa', idx, 'passenger_name', e.target.value)} /></Col>
                <Col xs={24} sm={12} md={8} lg={4} xl={6}><Text>Visa Type</Text><Select value={row.visa_type} options={visaTypeOptions} onChange={(value) => setRowField('visa', idx, 'visa_type', value)} style={{ width: '100%' }} /></Col>
                <Col xs={24} sm={12} md={8} lg={4} xl={6}><Text>Validity</Text><Input value={row.validity} onChange={(e) => setRowField('visa', idx, 'validity', e.target.value)} placeholder="30 days / 1 year" /></Col>
                <Col xs={24} sm={12} md={8} lg={4} xl={6}><Text>Visa Number</Text><Input value={row.visa_no} onChange={(e) => setRowField('visa', idx, 'visa_no', e.target.value)} /></Col>
                <Col xs={24} sm={12} md={8} lg={4} xl={6}><Text>Visa Publisher</Text><Input value={row.visa_publisher || voucher.passengers?.[idx]?.visa_publisher || ''} onChange={(e) => setRowField('visa', idx, 'visa_publisher', e.target.value)} /></Col>
                {serviceVendorSelect('visa', idx, row, 'Visa Vendor', 'visa_vendor')}
                {serviceMoneyInputs('visa', idx, row)}
                <Col xs={24} sm={12} md={8} lg={4} xl={24}><Text>Notes</Text><Input value={row.notes} onChange={(e) => setRowField('visa', idx, 'notes', e.target.value)} /></Col>
              </Row>
            )}
          </RowGroupCard>
        </>
      ),
    },
    hasSection(voucher, 'transfers') && {
      key: 'transfers',
      label: 'Transfer',
      children: (
        <RowGroupCard title="Transfers" rows={voucher.transfers} addLabel="+ Add Transfer" onAdd={() => addRow('transfers', blankTransfer)} onRemove={(idx) => removeRow('transfers', idx)}>
          {(row, idx) => (
            <Row gutter={8}>
              <Col xs={24} sm={12} md={8} lg={4} xl={6}><Text>TN</Text><Input value={row.tn} onChange={(e) => setRowField('transfers', idx, 'tn', e.target.value)} /></Col>
              <Col xs={24} sm={12} md={8} lg={4} xl={6}><Text>Service</Text><Input value={row.service} onChange={(e) => setRowField('transfers', idx, 'service', e.target.value)} /></Col>
              <Col xs={24} sm={12} md={8} lg={4} xl={6}><Text>From</Text><Input value={row.from_city} onChange={(e) => setRowField('transfers', idx, 'from_city', e.target.value)} /></Col>
              <Col xs={24} sm={12} md={8} lg={4} xl={6}><Text>To</Text><Input value={row.to_city} onChange={(e) => setRowField('transfers', idx, 'to_city', e.target.value)} /></Col>
              <Col xs={24} sm={12} md={8} lg={4} xl={6}><Text>Vehicle</Text><Input value={row.vehicle} onChange={(e) => setRowField('transfers', idx, 'vehicle', e.target.value)} /></Col>
              <Col xs={24} sm={12} md={8} lg={4} xl={6}><Text>Contact Person</Text><Input value={row.contact_person} onChange={(e) => setRowField('transfers', idx, 'contact_person', e.target.value)} /></Col>
              {serviceVendorSelect('transfers', idx, row)}
              {serviceMoneyInputs('transfers', idx, row)}
              <Col xs={24} sm={12} md={8} lg={4} xl={24}><Text>Notes</Text><Input value={row.notes} onChange={(e) => setRowField('transfers', idx, 'notes', e.target.value)} /></Col>
            </Row>
          )}
        </RowGroupCard>
      ),
    },
    hasSection(voucher, 'city_tours') && {
      key: 'city_tours',
      label: 'Ziarat',
      children: (
        <RowGroupCard title="City Tours / Ziarat" rows={voucher.city_tours} addLabel="+ Add City Tour" onAdd={() => addRow('city_tours', blankCityTour)} onRemove={(idx) => removeRow('city_tours', idx)}>
          {(row, idx) => (
            <Row gutter={8}>
              <Col xs={24} sm={12} md={8} lg={4} xl={6}><Text>City</Text><Input value={row.city} onChange={(e) => setRowField('city_tours', idx, 'city', e.target.value)} /></Col>
              <Col xs={24} sm={12} md={8} lg={4} xl={6}><Text>Tour Title</Text><Input value={row.title} onChange={(e) => setRowField('city_tours', idx, 'title', e.target.value)} /></Col>
              <Col xs={24} sm={12} md={8} lg={4} xl={6}><Text>Attractions</Text><Input value={row.attractions} onChange={(e) => setRowField('city_tours', idx, 'attractions', e.target.value)} /></Col>
              <Col xs={24} sm={12} md={8} lg={4} xl={6}><Text>Date</Text><Input type="date" value={row.date} onChange={(e) => setRowField('city_tours', idx, 'date', e.target.value)} /></Col>
              {serviceVendorSelect('city_tours', idx, row)}
              {serviceMoneyInputs('city_tours', idx, row)}
              <Col xs={24} sm={12} md={8} lg={4} xl={24}><Text>Notes</Text><Input value={row.notes} onChange={(e) => setRowField('city_tours', idx, 'notes', e.target.value)} /></Col>
            </Row>
          )}
        </RowGroupCard>
      ),
    },
    hasSection(voucher, 'hotels') && {
      key: 'hotels',
      label: 'Hotels',
      children: (
        <RowGroupCard title="Hotels" rows={voucher.hotels} addLabel="+ Add Hotel" onAdd={() => addRow('hotels', blankHotel)} onRemove={(idx) => removeRow('hotels', idx)}>
          {(row, idx) => (
            <Row gutter={8}>
              <Col xs={24} sm={12} md={8} lg={4} xl={4}><Text>HCN</Text><Input value={row.hcn} onChange={(e) => setRowField('hotels', idx, 'hcn', e.target.value)} /></Col>
              <Col xs={24} sm={12} md={8} lg={4} xl={4}><Text>City</Text><Input value={row.city} onChange={(e) => setRowField('hotels', idx, 'city', e.target.value)} /></Col>
              <Col xs={24} sm={12} md={8} lg={4} xl={4}>
                <Text>Room Type</Text>
                <Select
                  allowClear
                  showSearch
                  mode="tags"
                  maxCount={1}
                  value={row.room_type ? [row.room_type] : []}
                  options={roomTypeOptions}
                  placeholder="Select room type"
                  onChange={(value) => setRowField('hotels', idx, 'room_type', value[0] || '')}
                  style={{ width: '100%' }}
                />
              </Col>
              <Col xs={24} sm={12} md={8} lg={4} xl={4}><Text>Check In</Text><Input type="date" value={row.check_in} onChange={(e) => setRowField('hotels', idx, 'check_in', e.target.value)} /></Col>
              <Col xs={24} sm={12} md={8} lg={4} xl={4}><Text>Check Out</Text><Input type="date" value={row.check_out} onChange={(e) => setRowField('hotels', idx, 'check_out', e.target.value)} /></Col>
              <Col xs={24} sm={12} md={8} lg={4} xl={4}><Text>Nights</Text><Input value={calculateNights(row.check_in, row.check_out)} readOnly /></Col>
              <Col xs={24} sm={12} md={8} lg={4} xl={8}><Text>Hotel Name</Text><Input value={row.hotel_name} onChange={(e) => setRowField('hotels', idx, 'hotel_name', e.target.value)} /></Col>
              {serviceVendorSelect('hotels', idx, row, 'Hotel Vendor')}
              {serviceMoneyInputs('hotels', idx, row)}
              <Col xs={24} sm={12} md={8} lg={4} xl={8}><Text>Notes</Text><Input value={row.notes} onChange={(e) => setRowField('hotels', idx, 'notes', e.target.value)} /></Col>
            </Row>
          )}
        </RowGroupCard>
      ),
    },
    hasSection(voucher, 'other_services') && {
      key: 'other_services',
      label: 'Services',
      children: (
        <RowGroupCard title="Other Services" rows={voucher.other_services} addLabel="+ Add Service" onAdd={() => addRow('other_services', blankOtherService)} onRemove={(idx) => removeRow('other_services', idx)}>
          {(row, idx) => (
            <Row gutter={8}>
              <Col xs={24} sm={12} md={8} lg={4} xl={12}><Text>Description</Text><Input value={row.description} onChange={(e) => setRowField('other_services', idx, 'description', e.target.value)} /></Col>
              <Col xs={24} sm={12} md={8} lg={4} xl={6}>
                <Text>Qty</Text>
                <InputNumber
                  min={1}
                  precision={0}
                  stringMode
                  controls={false}
                  value={row.quantity === '' || row.quantity === null || row.quantity === undefined ? '1' : row.quantity}
                  onChange={(value) => setRowField('other_services', idx, 'quantity', value ?? '1')}
                  style={{ width: '100%' }}
                />
              </Col>
              {serviceVendorSelect('other_services', idx, row)}
              {serviceMoneyInputs('other_services', idx, row)}
            </Row>
          )}
        </RowGroupCard>
      ),
    },
  ].filter(Boolean);
  const activeTab = tabItems.find((item) => item.key === activeTabKey) || tabItems[0];
  const sectionOptions = tabItems.map(({ key, label }) => ({ value: key, label }));

  useEffect(() => {
    if (tabItems.length && !tabItems.some((item) => item.key === activeTabKey)) {
      setActiveTabKey(tabItems[0].key);
    }
  }, [activeTabKey, tabItems]);

  return (
    <>
      {useMobileSectionSelect ? (
        <>
          <br />
          <Affix offsetTop={10} target={() => document.querySelector('.app-content')}>
            <div className="voucher-section-mobile-affix">
              <Select
                className="voucher-section-mobile-select"
                value={activeTab?.key}
                options={sectionOptions}
                onChange={setActiveTabKey}
                size="large"
                style={{ width: '100%' }}
              />
            </div>
          </Affix>
          <br />
          {activeTab?.children}
        </>
      ) : (
        <Tabs
          activeKey={activeTab?.key}
          size="small"
          type="card"
          items={tabItems}
          destroyOnHidden={false}
          onChange={setActiveTabKey}
        />
      )}
    </>
  );
}
