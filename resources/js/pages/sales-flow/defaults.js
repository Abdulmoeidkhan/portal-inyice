export const normalizeCabin = (value = '') => {
  const normalized = String(value).trim().toLowerCase().replace(/[ _-]+/g, ' ');
  const cabins = {
    eco: 'economy',
    economy: 'economy',
    'eco+': 'economy+',
    'economy+': 'economy+',
    'premium economy': 'economy+',
    business: 'business',
    'business+': 'business+',
    first: 'first_class',
    'first class': 'first_class',
    'first class+': 'first_class+',
  };

  return cabins[normalized] || 'economy';
};

export const normalizeFlightDate = (value = '', today = new Date()) => {
  const raw = String(value).trim();
  if (!raw) return '';

  const isoDate = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (isoDate) return raw;

  const monthNumbers = {
    JAN: 1, FEB: 2, MAR: 3, APR: 4, MAY: 5, JUN: 6,
    JUL: 7, AUG: 8, SEP: 9, OCT: 10, NOV: 11, DEC: 12,
  };
  const textDate = raw.toUpperCase().match(/^(\d{1,2})[-/\s]?([A-Z]{3})(?:[-/\s]?(\d{2,4}))?$/);
  const numericDate = raw.match(/^(\d{1,2})[-/](\d{1,2})(?:[-/](\d{2,4}))?$/);

  const day = Number(textDate?.[1] || numericDate?.[1]);
  const month = textDate ? monthNumbers[textDate[2]] : Number(numericDate?.[2]);
  const suppliedYear = textDate?.[3] || numericDate?.[3];
  if (!day || !month || month > 12) return raw;

  let year = suppliedYear
    ? Number(suppliedYear.length === 2 ? `20${suppliedYear}` : suppliedYear)
    : today.getFullYear();
  let candidate = new Date(year, month - 1, day);
  if (candidate.getFullYear() !== year || candidate.getMonth() !== month - 1 || candidate.getDate() !== day) {
    return raw;
  }

  if (!suppliedYear) {
    const todayStart = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    if (candidate < todayStart) {
      year += 1;
      candidate = new Date(year, month - 1, day);
    }
  }

  return `${candidate.getFullYear()}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
};

export const blankFlight = () => ({
  gds_pnr: '',
  pnr: '',
  date: '',
  flight_no: '',
  from: '',
  to: '',
  departure: '',
  arrival: '',
  cabin: normalizeCabin(),
  booking_class: '',
  baggage: '',
});

export const blankPassenger = () => ({
  name: '',
  passport_no: '',
  ticket_no: '',
  visa_publisher: '',
  visa_no: '',
  notes: '',
});

export const blankPricing = () => ({
  pax_name: '',
  vendor_id: null,
  vendor_name: '',
  flight_ticket_no: '',
  flight_cost: '0',
  flight_profit: '0',
  flight_sales: '0',
  hotel_cost: '',
  hotel_profit: '',
  hotel_sales: '',
  visa_cost: '',
  visa_profit: '',
  visa_sales: '',
  transfer_cost: '',
  transfer_profit: '',
  transfer_sales: '',
  city_tour_ziarat_cost: '',
  city_tour_ziarat_profit: '',
  city_tour_ziarat_sales: '',
  other_service_cost: '',
  other_service_profit: '',
  other_service_sales: '',
  total: '',
});

export const blankHotel = () => ({
  vendor_id: null,
  vendor_name: '',
  hcn: '',
  city: '',
  hotel_name: '',
  room_type: '',
  check_in: '',
  check_out: '',
  lead_passenger: '',
  notes: '',
  cost: '',
  profit: '',
  sales: '',
  amount: '',
});

export const blankTransfer = () => ({
  vendor_id: null,
  vendor_name: '',
  tn: '',
  service: '',
  from_city: '',
  to_city: '',
  vehicle: '',
  contact_person: '',
  notes: '',
  cost: '',
  profit: '',
  sales: '',
  amount: '',
});

export const blankCityTour = () => ({
  vendor_id: null,
  vendor_name: '',
  city: '',
  title: '',
  attractions: '',
  date: '',
  notes: '',
  cost: '',
  profit: '',
  sales: '',
  amount: '',
});

export const blankVisa = () => ({
  passenger_name: '',
  visa_type: 'umrah',
  validity: '',
  visa_no: '',
  vendor_id: null,
  visa_vendor: '',
  notes: '',
  cost: '',
  profit: '',
  sales: '',
  amount: '',
});

export const blankOtherService = () => ({
  vendor_id: null,
  vendor_name: '',
  description: '',
  cost: '',
  profit: '',
  sales: '',
  amount: '',
});

export const optionalVoucherSections = [
  { label: 'Flights', value: 'flights' },
  { label: 'Hotels', value: 'hotels' },
  { label: 'Transfers', value: 'transfers' },
  { label: 'City Tours/Ziarat', value: 'city_tours' },
  { label: 'Visa', value: 'visa' },
  { label: 'Other Services', value: 'other_services' },
];

export const createInitialVoucher = () => ({
  voucher_no: '',
  issue_date: '',
  package_type: '',
  booking_reference: '',
  gds_source: null,
  gds_parsed_record_id: null,
  emergency_contact: '',
  contact: {
    company_name: '',
    executive_name: '',
    email: '',
    phone: '',
    address: '',
  },
  // active_sections: optionalVoucherSections.map((x) => x.value),
  active_sections: ['flights', 'visa'],
  flights: [blankFlight()],
  passengers: [blankPassenger()],
  pricing: [blankPricing()],
  hotels: [blankHotel()],
  transfers: [blankTransfer()],
  city_tours: [blankCityTour()],
  visa: [blankVisa()],
  other_services: [blankOtherService()],
});

const firstFilled = (...values) => values.find((value) => value !== undefined && value !== null && value !== '') || '';

const buildFlightNo = (segment) => {
  const referenceFlightNo = firstFilled(segment.flightNo);
  if (referenceFlightNo) {
    return referenceFlightNo;
  }

  const combinedFlightNo = firstFilled(segment.flight_no, segment.flight_number);
  const airlineCode = firstFilled(segment.airline_code, segment.airline);

  return `${airlineCode}${combinedFlightNo}`.trim();
};

export const buildVoucherFromParsed = (prevVoucher, gdsSource, parsedPayload) => {
  const segments = parsedPayload?.flights?.length ? parsedPayload.flights : (parsedPayload?.segments || []);
  const passengers = parsedPayload?.passengers || [];
  const tickets = parsedPayload?.ticket_info || [];
  const bookingReference = firstFilled(parsedPayload?.booking_reference, parsedPayload?.pnr, prevVoucher.booking_reference);

  return {
    ...prevVoucher,
    gds_source: firstFilled(parsedPayload?.gds_source, parsedPayload?.gds, gdsSource),
    booking_reference: bookingReference,
    active_sections: Array.from(new Set([...prevVoucher.active_sections, ...(segments.length ? ['flights'] : [])])),
    flights: segments.length
      ? segments.map((segment, idx) => ({
          ...blankFlight(),
          gds_pnr: firstFilled(segment.gds_pnr, segment.gdsPnr, bookingReference),
          pnr: firstFilled(segment.pnr, segment.airline_pnr, segment.airlinePnr),
          flight_no: buildFlightNo(segment),
          date: normalizeFlightDate(firstFilled(segment.departure_date, segment.date)),
          from: firstFilled(segment.departure_airport, segment.from).toUpperCase(),
          to: firstFilled(segment.arrival_airport, segment.to).toUpperCase(),
          departure: firstFilled(segment.departure_time, segment.departure),
          arrival: firstFilled(segment.arrival_time, segment.arrival),
          cabin: normalizeCabin(firstFilled(segment.cabin, segment.cabin_class)),
          booking_class: firstFilled(segment.booking_class, segment.bookingClass, segment.class),
          baggage: firstFilled(segment.baggage, segment.baggage_allowance),
          notes: segment.notes || `Segment ${idx + 1}`,
        }))
      : prevVoucher.flights,
    passengers: passengers.length
      ? passengers.map((pax, idx) => ({
          ...blankPassenger(),
          name: pax.name || '',
          passport_no: firstFilled(pax.passport_no, pax.passportNo, pax.passport_number),
          ticket_no: firstFilled(pax.ticket_no, pax.ticketNo, pax.ticket_number, tickets[idx]?.ticket_number),
          visa_publisher: firstFilled(pax.visa_publisher, pax.visaPublisher),
          visa_no: firstFilled(pax.visa_no, pax.visaNo),
          notes: firstFilled(pax.notes, pax.ptc),
        }))
      : prevVoucher.passengers,
    pricing: passengers.length
      ? passengers.map((pax, idx) => ({
          ...blankPricing(),
          pax_name: pax.name || '',
          flight_ticket_no: firstFilled(pax.ticket_no, pax.ticketNo, pax.ticket_number, tickets[idx]?.ticket_number),
          flight_sales: firstFilled(tickets[idx]?.amount) || '0',
        }))
      : prevVoucher.pricing,
  };
};
