import React from 'react';
import { Empty, Typography, Watermark } from 'antd';
import { buildVoucherSummaryRows } from './VoucherSummaryCard';
import { getAirportCity } from './airportLookup';
import { stripUtcMidnightSuffix } from '../../services/dateFormat';
import Table from '../../components/CsvTable';

const { Text, Title } = Typography;

const firstFilled = (...values) => values.find((value) => value !== null && value !== undefined && String(value).trim() !== '') || '';
const hasValue = (value) => value !== null && value !== undefined && String(value).trim() !== '';
const dayMs = 24 * 60 * 60 * 1000;

const cleanRows = (rows, keys) => (Array.isArray(rows) ? rows.filter((row) => keys.some((key) => firstFilled(row?.[key]))) : []);

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

  return String(Math.round((end - start) / dayMs));
};

const calculatePackageDays = (voucher) => {
  const dates = [];
  const addDate = (value) => {
    const parsed = dateOnlyMs(value);
    if (parsed !== null) {
      dates.push(parsed);
    }
  };

  (voucher.flights || []).forEach((row) => addDate(row.date));
  (voucher.hotels || []).forEach((row) => {
    addDate(row.check_in);
    addDate(row.check_out);
  });
  (voucher.city_tours || []).forEach((row) => addDate(row.date));

  if (!dates.length) {
    return '';
  }

  const start = Math.min(...dates);
  const end = Math.max(...dates);

  return String(Math.max(1, Math.round((end - start) / dayMs) + 1));
};

const buildPassengerRows = (passengers, visaRows) => {
  const maxRows = Math.max(passengers?.length || 0, visaRows.length);

  return Array.from({ length: maxRows }, (_, index) => {
    const passenger = passengers?.[index] || {};
    const visa = visaRows[index] || {};

    return {
      ...passenger,
      name: firstFilled(passenger.name, visa.passenger_name),
      visa_no: firstFilled(passenger.visa_no, visa.visa_no),
      visa_publisher: firstFilled(passenger.visa_publisher, visa.visa_publisher),
      notes: firstFilled(passenger.notes, visa.notes),
    };
  }).filter((row) => ['name', 'passport_no', 'ticket_no', 'visa_publisher', 'visa_no', 'notes'].some((key) => firstFilled(row?.[key])));
};

const formatDate = (value) => {
  const raw = stripUtcMidnightSuffix(value, '');
  if (!raw) {
    return '';
  }

  const iso = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (iso) {
    return `${iso[3]}-${iso[2]}-${iso[1]}`;
  }

  const date = new Date(raw);
  if (Number.isNaN(date.getTime())) {
    return raw;
  }

  return date.toLocaleDateString();
};

const formatAirport = (value) => {
  const code = String(value || '').trim().toUpperCase();
  return code ? (getAirportCity(code) || code) : '';
};

const formatPlace = (value) => {
  const raw = String(value || '').trim();
  if (!raw) {
    return '';
  }

  const code = raw.toUpperCase();
  return /^[A-Z]{3}$/.test(code) ? (getAirportCity(code) || code) : raw;
};

const uniqueJoined = (...values) => {
  const seen = new Set();

  return values
    .flat()
    .map((value) => String(value || '').trim())
    .filter(Boolean)
    .filter((value) => {
      const key = value.toUpperCase();
      if (seen.has(key)) {
        return false;
      }

      seen.add(key);
      return true;
    })
    .join(' / ');
};

const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const voucherFromOrder = (order = {}) => {
  const meta = order.meta || {};

  return {
    ...meta,
    voucher_no: firstFilled(order.voucher_no, meta.voucher_no, order.order_number),
    issue_date: firstFilled(meta.issue_date, order.created_at),
    booking_reference: firstFilled(order.booking_reference, meta.booking_reference),
    package_type: firstFilled(meta.package_type, order.order_type),
    contact: meta.contact || {},
    passengers: Array.isArray(meta.passengers) ? meta.passengers : [],
    pricing: Array.isArray(meta.pricing) ? meta.pricing : [],
    flights: Array.isArray(meta.flights) ? meta.flights : [],
    visa: Array.isArray(meta.visa) ? meta.visa : [],
    hotels: Array.isArray(meta.hotels) ? meta.hotels : [],
    transfers: Array.isArray(meta.transfers) ? meta.transfers : [],
    city_tours: Array.isArray(meta.city_tours) ? meta.city_tours : [],
    other_services: Array.isArray(meta.other_services) ? meta.other_services : [],
  };
};

const Section = ({ title, children }) => (
  <section className="voucher-preview-section">
    <Text className="voucher-preview-section-title">{title}</Text>
    {children}
  </section>
);

const compactColumns = (columns, data) => columns.filter((column) => {
  if (column.alwaysVisible) {
    return true;
  }

  const key = column.dataIndex;

  return data.some((row) => hasValue(row?.[key]));
});

const PreviewTable = ({ columns, data }) => {
  const visibleColumns = compactColumns(columns, data);
  const keyedData = data.map((row, index) => ({
    ...row,
    __previewRowKey: row.key || `${index}:${visibleColumns.map((column) => row?.[column.dataIndex] ?? '').join('|')}`,
  }));

  if (!data.length || !visibleColumns.length) {
    return null;
  }

  return (
    <Table
      size="small"
      rowKey="__previewRowKey"
      columns={visibleColumns}
      dataSource={keyedData}
      pagination={false}
      scroll={{ x: 'max-content' }}
      locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No data" /> }}
    />
  );
};

export default function VoucherPreview({ order }) {
  if (!order) {
    return null;
  }

  const voucher = voucherFromOrder(order);
  const contact = voucher.contact || {};
  const company = order.company || {};
  const summaryRows = buildVoucherSummaryRows(voucher);
  const grandTotal = summaryRows.reduce((sum, row) => sum + row.total, 0);

  const flightRows = cleanRows(voucher.flights, ['gds_pnr', 'pnr', 'flight_no', 'from', 'to', 'date', 'departure', 'arrival']);
  const visaRows = cleanRows(voucher.visa, ['passenger_name', 'visa_type', 'validity', 'visa_no', 'visa_publisher', 'notes']);
  const passengerRows = buildPassengerRows(voucher.passengers, visaRows);
  const hotelRows = cleanRows(voucher.hotels, ['hcn', 'city', 'hotel_name', 'room_type', 'check_in', 'check_out', 'lead_passenger', 'notes'])
    .map((row) => ({ ...row, nights: calculateNights(row.check_in, row.check_out) }));
  const transferRows = cleanRows(voucher.transfers, ['tn', 'service', 'from_city', 'to_city', 'vehicle', 'contact_person', 'notes']);
  const cityTourRows = cleanRows(voucher.city_tours, ['city', 'title', 'attractions', 'date', 'notes']);
  const serviceRows = cleanRows(voucher.other_services, ['description']);
  const packageDays = calculatePackageDays(voucher);
  const bookingRef = uniqueJoined(voucher.booking_reference, flightRows.map((row) => row.gds_pnr));
  const airlineRef = uniqueJoined(flightRows.map((row) => row.pnr));
  const hcnRef = uniqueJoined(hotelRows.map((row) => row.hcn));
  const tnRef = uniqueJoined(transferRows.map((row) => row.tn));
  const leadPassenger = uniqueJoined(
    hotelRows.map((row) => row.lead_passenger),
    passengerRows[0]?.name,
    visaRows[0]?.passenger_name,
    voucher.pricing?.[0]?.pax_name
  );
  const primaryReferenceItems = [
    ['Voucher', voucher.voucher_no],
    ['Issue Date', formatDate(voucher.issue_date)],
    ['Travel', voucher.package_type],
    ['Pax', passengerRows.length || voucher.pricing?.length || ''],
    ['Days', packageDays],
  ].filter(([, value]) => hasValue(value));
  const secondaryReferenceItems = [
    ['Lead Passenger', leadPassenger],
    ['Booking Ref', bookingRef],
    ['Airline Ref', airlineRef],
    ['HCN', hcnRef],
    ['TN', tnRef],
  ].filter(([, value]) => hasValue(value));
  const companyName = firstFilled(company.display_name, company.legal_name, contact.company_name, 'inYice Travel Voucher');
  const companyAddress = firstFilled(company.address, contact.address);
  const companyEmail = firstFilled(company.email, contact.email);
  const companyPhone = firstFilled(company.phone, contact.phone);
  const contactLine = [companyAddress, [companyEmail, companyPhone].filter(Boolean).join(' | ')].filter(Boolean).join('\n');
  const footerHasContent = hasValue(voucher.emergency_contact) || hasValue(order.notes) || hasValue(company.footer_logo_url);
  const showInyiceWatermark = company && company.is_paid === false;
  const documentLabel = order.status === 'quote' ? 'Quotation' : 'Voucher';

  return (
    <div className="voucher-preview">
      <Watermark
        content={showInyiceWatermark ? 'InYice' : undefined}
        rotate={-24}
        gap={[140, 120]}
        font={{ color: 'rgba(0, 0, 0, 0.1)', fontSize: 28, fontWeight: 700 }}
      >
        <header className="voucher-preview-header">
          <div>
            <Title level={3}>{companyName}</Title>
            <Text className="voucher-preview-document-title">{documentLabel}</Text>
            {contactLine && <Text type="secondary">{contactLine}</Text>}
          </div>
          {company.logo_url && (
            <img className="voucher-preview-logo" src={company.logo_url} alt={`${companyName} logo`} />
          )}
        </header>

        {primaryReferenceItems.length > 0 && (
          <div className="voucher-reference-table voucher-reference-table-primary">
            {primaryReferenceItems.map(([label, value]) => (
              <div className="voucher-reference-cell" key={label}>
                <Text type="secondary">{label === 'Voucher' ? documentLabel : label}</Text>
                <Text strong>{value}</Text>
              </div>
            ))}
          </div>
        )}

      {secondaryReferenceItems.length > 0 && (
        <div className="voucher-reference-table voucher-reference-table-secondary">
          {secondaryReferenceItems.map(([label, value]) => (
            <div className="voucher-reference-cell" key={label}>
              <Text type="secondary">{label}</Text>
              <Text strong>{value}</Text>
            </div>
          ))}
        </div>
      )}

      {contact.executive_name && (
        <div className="voucher-preview-contact">
          <span>{`Executive: ${contact.executive_name}`}</span>
        </div>
      )}

      {passengerRows.length > 0 && <Section title="Passenger List">
        <PreviewTable
          data={passengerRows}
          columns={[
            { title: 'Passenger', dataIndex: 'name', width: 145, render: (value) => value || '', alwaysVisible: true },
            { title: 'Passport No', dataIndex: 'passport_no', width: 105, render: (value) => value || '' },
            { title: 'Ticket No', dataIndex: 'ticket_no', width: 110, render: (value) => value || '' },
            { title: 'Visa No', dataIndex: 'visa_no', width: 105, render: (value) => value || '' },
            { title: 'Publisher', dataIndex: 'visa_publisher', width: 120, render: (value) => value || '' },
            { title: 'Notes', dataIndex: 'notes', width: 130, render: (value) => value || '' },
          ]}
        />
      </Section>}

      {flightRows.length > 0 && <Section title="Flight Details">
        <PreviewTable
          data={flightRows}
          columns={[
            { title: 'Flight', dataIndex: 'flight_no', width: 95, render: (value) => value || '', alwaysVisible: true },
            { title: 'Date', dataIndex: 'date', width: 95, render: formatDate },
            { title: 'From', dataIndex: 'from', width: 115, render: formatAirport },
            { title: 'To', dataIndex: 'to', width: 115, render: formatAirport },
            { title: 'Dep', dataIndex: 'departure', width: 70, render: (value) => value || '' },
            { title: 'Arr', dataIndex: 'arrival', width: 70, render: (value) => value || '' },
            { title: 'Baggage', dataIndex: 'baggage', width: 80, render: (value) => value || '' },
          ]}
        />
      </Section>}

      {hotelRows.length > 0 && (
        <Section title="Accommodation Details">
          <PreviewTable
            data={hotelRows}
            columns={[
              { title: 'City', dataIndex: 'city', width: 90, render: formatPlace },
              { title: 'Hotel', dataIndex: 'hotel_name', width: 155, render: (value) => value || '', alwaysVisible: true },
              { title: 'HCN', dataIndex: 'hcn', width: 90, render: (value) => value || '' },
              { title: 'Room', dataIndex: 'room_type', width: 90, render: (value) => value || '' },
              { title: 'Check In', dataIndex: 'check_in', width: 90, render: formatDate },
              { title: 'Check Out', dataIndex: 'check_out', width: 90, render: formatDate },
              { title: 'Nights', dataIndex: 'nights', width: 70, align: 'center', render: (value) => value || '' },
              { title: 'Notes', dataIndex: 'notes', width: 125, render: (value) => value || '' },
            ]}
          />
        </Section>
      )}

      {transferRows.length > 0 && (
        <Section title="Transport Details">
          <PreviewTable
            data={transferRows}
            columns={[
              { title: 'Service', dataIndex: 'service', width: 125, render: (value) => value || '', alwaysVisible: true },
              { title: 'From', dataIndex: 'from_city', width: 95, render: formatPlace },
              { title: 'To', dataIndex: 'to_city', width: 95, render: formatPlace },
              { title: 'Vehicle', dataIndex: 'vehicle', width: 110, render: (value) => value || '' },
              { title: 'Contact', dataIndex: 'contact_person', width: 120, render: (value) => value || '' },
              { title: 'Notes', dataIndex: 'notes', width: 125, render: (value) => value || '' },
            ]}
          />
        </Section>
      )}

      {cityTourRows.length > 0 && (
        <Section title="City Tour / Ziarat">
          <PreviewTable
            data={cityTourRows}
            columns={[
              { title: 'City', dataIndex: 'city', width: 90, render: formatPlace },
              { title: 'Title', dataIndex: 'title', width: 145, render: (value) => value || '', alwaysVisible: true },
              { title: 'Attractions', dataIndex: 'attractions', width: 190, render: (value) => value || '' },
              { title: 'Date', dataIndex: 'date', width: 90, render: formatDate },
              { title: 'Notes', dataIndex: 'notes', width: 125, render: (value) => value || '' },
            ]}
          />
        </Section>
      )}

      {serviceRows.length > 0 && (
        <Section title="Other Services">
          <PreviewTable
            data={serviceRows}
            columns={[
              { title: 'Description', dataIndex: 'description', render: (value) => value || '', alwaysVisible: true },
            ]}
          />
        </Section>
      )}

      {footerHasContent && (
        <section className="voucher-preview-footer">
          <div className="voucher-preview-footer-notes">
            {voucher.emergency_contact && (
              <div>
                <Text className="voucher-preview-section-title">Emergency Contact(s)</Text>
                <p>{voucher.emergency_contact}</p>
              </div>
            )}
            {order.notes && (
              <div>
                <Text className="voucher-preview-section-title">Notes</Text>
                <p>{order.notes}</p>
              </div>
            )}
          </div>
          {company.footer_logo_url && (
            <div className="voucher-preview-footer-logo">
              <Text className="voucher-preview-section-title">QR</Text>
              <img src={company.footer_logo_url} alt="Voucher footer logo or QR" />
            </div>
          )}
        </section>
      )}
      </Watermark>
    </div>
  );
}
