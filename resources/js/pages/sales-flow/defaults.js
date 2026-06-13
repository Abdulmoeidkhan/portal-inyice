export const blankFlight = () => ({
  gds_pnr: '',
  pnr: '',
  date: '',
  flight_no: '',
  from: '',
  to: '',
  departure: '',
  arrival: '',
  cabin: 'Economy',
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
  flight_fare: '',
  hotel_price: '',
  visa_price: '',
  transfer_price: '',
  city_tour_ziarat_price: '',
  total: '',
});

export const blankHotel = () => ({
  hcn: '',
  city: '',
  hotel_name: '',
  room_type: '',
  check_in: '',
  check_out: '',
  lead_passenger: '',
  notes: '',
  amount: '',
});

export const blankTransfer = () => ({
  tn: '',
  service: '',
  from_city: '',
  to_city: '',
  vehicle: '',
  contact_person: '',
  notes: '',
  amount: '',
});

export const blankCityTour = () => ({
  city: '',
  title: '',
  attractions: '',
  date: '',
  notes: '',
  amount: '',
});

export const blankVisa = () => ({
  passenger_name: '',
  visa_no: '',
  publisher: '',
  notes: '',
  amount: '',
});

export const blankOtherService = () => ({
  description: '',
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
  travel_type: '',
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
  active_sections: ['flights'],
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
          date: firstFilled(segment.departure_date, segment.date),
          from: firstFilled(segment.departure_airport, segment.from).toUpperCase(),
          to: firstFilled(segment.arrival_airport, segment.to).toUpperCase(),
          departure: firstFilled(segment.departure_time, segment.departure),
          arrival: firstFilled(segment.arrival_time, segment.arrival),
          cabin: firstFilled(segment.cabin, segment.cabin_class) || 'Economy',
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
          flight_fare: firstFilled(tickets[idx]?.amount),
        }))
      : prevVoucher.pricing,
  };
};
