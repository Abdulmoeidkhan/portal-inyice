import React from 'react';
import { Col, Input, Row, Select, Tabs, Typography } from 'antd';
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

const hasSection = (voucher, section) => voucher.active_sections.includes(section);

const flightPricingFields = [
  ['Ticket Number', 'flight_ticket_no'],
  ['Cost', 'flight_cost'],
  ['Profit', 'flight_profit'],
  ['Sales', 'flight_sales'],
];

const toNumber = (value) => {
  if (value === null || value === undefined || value === '') {
    return null;
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

export default function VoucherRowsSections({ voucher, vendors = [], onSearchVendors, setRowField, addRow, removeRow }) {
  const vendorOptions = vendors.map((vendor) => ({
    value: vendor.id,
    label: `${vendor.name}${vendor.phone ? ` - ${vendor.phone}` : ''}`,
    vendor,
  }));

  const setVendorFields = (section, idx, idField, nameField, vendorId) => {
    const vendor = vendors.find((item) => item.id === vendorId);
    setRowField(section, idx, idField, vendorId || null);
    setRowField(section, idx, nameField, vendor?.name || '');
  };

  const setPricingField = (idx, field, value) => {
    const row = voucher.pricing[idx] || {};
    const nextRow = { ...row, [field]: value };
    const cost = toNumber(nextRow.flight_cost);
    const profit = toNumber(nextRow.flight_profit);
    const sales = toNumber(nextRow.flight_sales);

    setRowField('pricing', idx, field, value);

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
      setRowField('pricing', idx, 'flight_profit', moneyValue(sales - cost));
    }
  };

  const tabItems = [
    {
      key: 'passengers',
      label: 'Passengers',
      children: (
        <RowGroupCard title="Passengers" rows={voucher.passengers} addLabel="+ Add Passenger" onAdd={() => addRow('passengers', blankPassenger)} onRemove={(idx) => removeRow('passengers', idx)}>
          {(row, idx) => (
            <Row gutter={8}>
              <Col xs={24} md={8}><Text>Name</Text><Input value={row.name} onChange={(e) => setRowField('passengers', idx, 'name', e.target.value)} /></Col>
              <Col xs={24} md={8}><Text>Passport No</Text><Input value={row.passport_no} onChange={(e) => setRowField('passengers', idx, 'passport_no', e.target.value)} /></Col>
              <Col xs={24} md={8}><Text>Ticket No</Text><Input value={row.ticket_no} onChange={(e) => setRowField('passengers', idx, 'ticket_no', e.target.value)} /></Col>
              <Col xs={24} md={8}><Text>Visa Publisher</Text><Input value={row.visa_publisher} onChange={(e) => setRowField('passengers', idx, 'visa_publisher', e.target.value)} /></Col>
              <Col xs={24} md={8}><Text>Visa No</Text><Input value={row.visa_no} onChange={(e) => setRowField('passengers', idx, 'visa_no', e.target.value)} /></Col>
              <Col xs={24} md={8}><Text>Notes</Text><Input value={row.notes} onChange={(e) => setRowField('passengers', idx, 'notes', e.target.value)} /></Col>
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
                <Col xs={24} md={6}><Text>GDS PNR</Text><Input value={row.gds_pnr} onChange={(e) => setRowField('flights', idx, 'gds_pnr', e.target.value)} /></Col>
                <Col xs={24} md={6}><Text>Airline PNR</Text><Input value={row.pnr} onChange={(e) => setRowField('flights', idx, 'pnr', e.target.value)} /></Col>
                <Col xs={24} md={6}><Text>Date</Text><Input value={row.date} onChange={(e) => setRowField('flights', idx, 'date', e.target.value)} /></Col>
                <Col xs={24} md={6}><Text>Flight No</Text><Input value={row.flight_no} onChange={(e) => setRowField('flights', idx, 'flight_no', e.target.value)} /></Col>
                <Col xs={24} md={6}>
                  <AirportInput label="From" value={row.from} onChange={(value) => setRowField('flights', idx, 'from', value)} />
                </Col>
                <Col xs={24} md={6}>
                  <AirportInput label="To" value={row.to} onChange={(value) => setRowField('flights', idx, 'to', value)} />
                </Col>
                <Col xs={24} md={6}><Text>Departure</Text><Input value={row.departure} onChange={(e) => setRowField('flights', idx, 'departure', e.target.value)} /></Col>
                <Col xs={24} md={6}><Text>Arrival</Text><Input value={row.arrival} onChange={(e) => setRowField('flights', idx, 'arrival', e.target.value)} /></Col>
                <Col xs={24} md={8}><Text>Cabin</Text><Input value={row.cabin} onChange={(e) => setRowField('flights', idx, 'cabin', e.target.value)} /></Col>
                <Col xs={24} md={8}><Text>Booking Class</Text><Input value={row.booking_class} onChange={(e) => setRowField('flights', idx, 'booking_class', e.target.value)} /></Col>
                <Col xs={24} md={8}><Text>Baggage</Text><Input value={row.baggage} onChange={(e) => setRowField('flights', idx, 'baggage', e.target.value)} /></Col>
                <Col xs={24} md={8}>
                  <Text>Flight Vendor</Text>
                  <Select
                    allowClear
                    showSearch
                    value={row.vendor_id}
                    placeholder="Select vendor"
                    filterOption={false}
                    onSearch={onSearchVendors}
                    options={vendorOptions}
                    onChange={(value) => setVendorFields('flights', idx, 'vendor_id', 'vendor_name', value)}
                    style={{ width: '100%' }}
                  />
                </Col>
              </Row>
            )}
          </RowGroupCard>
          <RowGroupCard title="Flight Pricing (Per Passenger)" rows={voucher.pricing} addLabel="+ Add Flight Pricing" onAdd={() => addRow('pricing', blankPricing)} onRemove={(idx) => removeRow('pricing', idx)}>
            {(row, idx) => (
              <Row gutter={8}>
                <Col xs={20} md={6}><Text>Passenger</Text><Input value={row.pax_name || voucher.passengers[idx]?.name || ''} onChange={(e) => setRowField('pricing', idx, 'pax_name', e.target.value)} /></Col>
                {flightPricingFields.map(([label, field]) => (
                  <Col xs={20} md={4} key={field}>
                    <Text>{label}</Text>
                    <Input value={row[field] || ''} onChange={(e) => setPricingField(idx, field, e.target.value)} />
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
        <RowGroupCard title="Visa" rows={voucher.visa} addLabel="+ Add Visa" onAdd={() => addRow('visa', blankVisa)} onRemove={(idx) => removeRow('visa', idx)}>
          {(row, idx) => (
            <Row gutter={8}>
              <Col xs={24} md={6}><Text>Passenger Name</Text><Input value={row.passenger_name} onChange={(e) => setRowField('visa', idx, 'passenger_name', e.target.value)} /></Col>
              <Col xs={24} md={6}><Text>Visa Type</Text><Select value={row.visa_type} options={visaTypeOptions} onChange={(value) => setRowField('visa', idx, 'visa_type', value)} style={{ width: '100%' }} /></Col>
              <Col xs={24} md={6}><Text>Validity</Text><Input value={row.validity} onChange={(e) => setRowField('visa', idx, 'validity', e.target.value)} placeholder="30 days / 1 year" /></Col>
              <Col xs={24} md={6}><Text>Visa Number</Text><Input value={row.visa_no} onChange={(e) => setRowField('visa', idx, 'visa_no', e.target.value)} /></Col>
              <Col xs={24} md={8}>
                <Text>Visa Vendor</Text>
                <Select
                  allowClear
                  showSearch
                  value={row.vendor_id}
                  placeholder="Select vendor"
                  filterOption={false}
                  onSearch={onSearchVendors}
                  options={vendorOptions}
                  onChange={(value) => setVendorFields('visa', idx, 'vendor_id', 'visa_vendor', value)}
                  style={{ width: '100%' }}
                />
              </Col>
              <Col xs={24} md={8}><Text>Price</Text><Input value={row.amount} onChange={(e) => setRowField('visa', idx, 'amount', e.target.value)} /></Col>
              <Col xs={24} md={24}><Text>Notes</Text><Input value={row.notes} onChange={(e) => setRowField('visa', idx, 'notes', e.target.value)} /></Col>
            </Row>
          )}
        </RowGroupCard>
      ),
    },
    hasSection(voucher, 'transfers') && {
      key: 'transfers',
      label: 'Transfer',
      children: (
        <RowGroupCard title="Transfers" rows={voucher.transfers} addLabel="+ Add Transfer" onAdd={() => addRow('transfers', blankTransfer)} onRemove={(idx) => removeRow('transfers', idx)}>
          {(row, idx) => (
            <Row gutter={8}>
              <Col xs={24} md={6}><Text>TN</Text><Input value={row.tn} onChange={(e) => setRowField('transfers', idx, 'tn', e.target.value)} /></Col>
              <Col xs={24} md={6}><Text>Service</Text><Input value={row.service} onChange={(e) => setRowField('transfers', idx, 'service', e.target.value)} /></Col>
              <Col xs={24} md={6}><Text>From</Text><Input value={row.from_city} onChange={(e) => setRowField('transfers', idx, 'from_city', e.target.value)} /></Col>
              <Col xs={24} md={6}><Text>To</Text><Input value={row.to_city} onChange={(e) => setRowField('transfers', idx, 'to_city', e.target.value)} /></Col>
              <Col xs={24} md={6}><Text>Vehicle</Text><Input value={row.vehicle} onChange={(e) => setRowField('transfers', idx, 'vehicle', e.target.value)} /></Col>
              <Col xs={24} md={6}><Text>Contact Person</Text><Input value={row.contact_person} onChange={(e) => setRowField('transfers', idx, 'contact_person', e.target.value)} /></Col>
              <Col xs={24} md={6}><Text>Amount</Text><Input value={row.amount} onChange={(e) => setRowField('transfers', idx, 'amount', e.target.value)} /></Col>
              <Col xs={24} md={24}><Text>Notes</Text><Input value={row.notes} onChange={(e) => setRowField('transfers', idx, 'notes', e.target.value)} /></Col>
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
              <Col xs={24} md={6}><Text>City</Text><Input value={row.city} onChange={(e) => setRowField('city_tours', idx, 'city', e.target.value)} /></Col>
              <Col xs={24} md={6}><Text>Tour Title</Text><Input value={row.title} onChange={(e) => setRowField('city_tours', idx, 'title', e.target.value)} /></Col>
              <Col xs={24} md={6}><Text>Attractions</Text><Input value={row.attractions} onChange={(e) => setRowField('city_tours', idx, 'attractions', e.target.value)} /></Col>
              <Col xs={24} md={6}><Text>Date</Text><Input type="date" value={row.date} onChange={(e) => setRowField('city_tours', idx, 'date', e.target.value)} /></Col>
              <Col xs={24} md={8}><Text>Amount</Text><Input value={row.amount} onChange={(e) => setRowField('city_tours', idx, 'amount', e.target.value)} /></Col>
              <Col xs={24} md={24}><Text>Notes</Text><Input value={row.notes} onChange={(e) => setRowField('city_tours', idx, 'notes', e.target.value)} /></Col>
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
              <Col xs={24} md={6}><Text>HCN</Text><Input value={row.hcn} onChange={(e) => setRowField('hotels', idx, 'hcn', e.target.value)} /></Col>
              <Col xs={24} md={6}><Text>City</Text><Input value={row.city} onChange={(e) => setRowField('hotels', idx, 'city', e.target.value)} /></Col>
              <Col xs={24} md={6}><Text>Hotel Name</Text><Input value={row.hotel_name} onChange={(e) => setRowField('hotels', idx, 'hotel_name', e.target.value)} /></Col>
              <Col xs={24} md={6}><Text>Room Type</Text><Input value={row.room_type} onChange={(e) => setRowField('hotels', idx, 'room_type', e.target.value)} /></Col>
              <Col xs={24} md={6}><Text>Check In</Text><Input type="date" value={row.check_in} onChange={(e) => setRowField('hotels', idx, 'check_in', e.target.value)} /></Col>
              <Col xs={24} md={6}><Text>Check Out</Text><Input type="date" value={row.check_out} onChange={(e) => setRowField('hotels', idx, 'check_out', e.target.value)} /></Col>
              <Col xs={24} md={6}><Text>Lead Passenger</Text><Input value={row.lead_passenger} onChange={(e) => setRowField('hotels', idx, 'lead_passenger', e.target.value)} /></Col>
              <Col xs={24} md={6}><Text>Amount</Text><Input value={row.amount} onChange={(e) => setRowField('hotels', idx, 'amount', e.target.value)} /></Col>
              <Col xs={24} md={24}><Text>Notes</Text><Input value={row.notes} onChange={(e) => setRowField('hotels', idx, 'notes', e.target.value)} /></Col>
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
              <Col xs={24} md={16}><Text>Description</Text><Input value={row.description} onChange={(e) => setRowField('other_services', idx, 'description', e.target.value)} /></Col>
              <Col xs={24} md={8}><Text>Amount</Text><Input value={row.amount} onChange={(e) => setRowField('other_services', idx, 'amount', e.target.value)} /></Col>
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
