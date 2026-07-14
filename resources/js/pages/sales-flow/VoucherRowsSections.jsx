import React from 'react';
import { Checkbox, Col, Input, InputNumber, Row, Select, Tabs, Typography } from 'antd';
import { getAirportLabel } from './airportLookup';
import RowGroupCard from './RowGroupCard';
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

const { Text } = Typography;

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
  ['Cost', 'flight_cost', { min: 0, precision: 2 }],
  ['Profit', 'flight_profit', { precision: 2 }],
  ['Amount / Sales', 'flight_sales', { min: 0, precision: 2 }],
];

const serviceMoneyFields = [
  ['Cost', 'cost', { min: 0, precision: 2 }],
  ['Profit', 'profit', { precision: 2 }],
  ['Amount / Sales', 'sales', { min: 0, precision: 2 }],
];

const toNumber = (value) => {
  if (value === null || value === undefined || value === '') {
    return 0;
  }

  const normalized = String(value).replace(/[^0-9.-]/g, '');
  const parsed = Number(normalized);

  return Number.isFinite(parsed) ? parsed : null;
};

const moneyValue = (value) => {
  const rounded = Math.round((value + Number.EPSILON) * 100) / 100;
  return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(2);
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
}) {
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
    const numericValue = value ?? (field === 'flight_ticket_no' ? '' : '0');
    const nextRow = { ...row, [field]: numericValue };
    const cost = toNumber(nextRow.flight_cost);
    const profit = toNumber(nextRow.flight_profit);
    const sales = toNumber(nextRow.flight_sales);

    setRowField('pricing', idx, field, numericValue);

    if (field === 'flight_cost') {
      if (cost !== null && profit !== null) {
        setRowField('pricing', idx, 'flight_sales', moneyValue(cost + profit));
      } else if (cost !== null && sales !== null) {
        setRowField('pricing', idx, 'flight_profit', moneyValue(sales - cost));
      }
    }

    if (field === 'flight_profit' && cost !== null && profit !== null) {
      setRowField('pricing', idx, 'flight_sales', moneyValue(cost + profit));
    }

    if (field === 'flight_sales' && cost !== null && sales !== null) {
      if (cost === 0 && profit === 0 && sales !== 0) {
        setRowField('pricing', idx, 'flight_cost', moneyValue(sales));
      } else {
        setRowField('pricing', idx, 'flight_profit', moneyValue(sales - cost));
      }
    }
  };

  const setServiceMoneyField = (section, idx, field, value) => {
    const row = voucher[section]?.[idx] || {};
    const numericValue = value ?? '0';
    const nextRow = { ...row, [field]: numericValue };
    const cost = toNumber(nextRow.cost);
    const profit = toNumber(nextRow.profit);
    const sales = toNumber(nextRow.sales);

    setRowField(section, idx, field, numericValue);

    if (field === 'cost') {
      if (cost !== null && profit !== null) {
        const nextSales = moneyValue(cost + profit);
        setRowField(section, idx, 'sales', nextSales);
        setRowField(section, idx, 'amount', nextSales);
      } else if (cost !== null && sales !== null) {
        setRowField(section, idx, 'profit', moneyValue(sales - cost));
      }
    }

    if (field === 'profit' && cost !== null && profit !== null) {
      const nextSales = moneyValue(cost + profit);
      setRowField(section, idx, 'sales', nextSales);
      setRowField(section, idx, 'amount', nextSales);
    }

    if (field === 'sales' && cost !== null && sales !== null) {
      setRowField(section, idx, 'amount', moneyValue(sales));
      if (cost === 0 && profit === 0 && sales !== 0) {
        setRowField(section, idx, 'cost', moneyValue(sales));
      } else {
        setRowField(section, idx, 'profit', moneyValue(sales - cost));
      }
    }
  };

  const serviceVendorSelect = (section, idx, row, label = 'Vendor', nameField = 'vendor_name') => (
    <Col xs={24} sm={12} md={8} lg={4} xl={6}>
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

  const serviceMoneyInputs = (section, idx, row) => serviceMoneyFields.map(([label, field, inputProps]) => (
    <Col xs={24} sm={12} md={8} lg={4} xl={6} key={field}>
      <Text>{label}</Text>
      <InputNumber
        {...inputProps}
        stringMode
        controls={false}
        value={row[field] === '' || row[field] === null || row[field] === undefined ? null : row[field]}
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
                <Col xs={24} sm={12} md={8} lg={4} xl={4}>
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
                <Col xs={24} sm={12} md={8} lg={4} xl={4}><Text>Passenger</Text><Input value={row.pax_name || voucher.passengers[idx]?.name || ''} onChange={(e) => setRowField('pricing', idx, 'pax_name', e.target.value)} /></Col>
                {flightPricingFields.map(([label, field, inputProps]) => (
                  <Col xs={24} sm={12} md={8} lg={4} xl={4} key={field}>
                    <Text>{label}</Text>
                    <InputNumber
                      {...inputProps}
                      stringMode
                      controls={false}
                      value={row[field] === '' || row[field] === null || row[field] === undefined
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
              {serviceVendorSelect('other_services', idx, row)}
              {serviceMoneyInputs('other_services', idx, row)}
            </Row>
          )}
        </RowGroupCard>
      ),
    },
  ].filter(Boolean);

  return (
    <Tabs
      size="small"
      type="card"
      items={tabItems}
      destroyOnHidden={false}
    />
  );
}
