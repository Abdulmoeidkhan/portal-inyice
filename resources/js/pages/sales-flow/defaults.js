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
  active_sections: optionalVoucherSections.map((x) => x.value),
  flights: [blankFlight()],
  passengers: [blankPassenger()],
  pricing: [blankPricing()],
  hotels: [blankHotel()],
  transfers: [blankTransfer()],
  city_tours: [blankCityTour()],
  visa: [blankVisa()],
  other_services: [blankOtherService()],
});

export const buildVoucherFromParsed = (prevVoucher, gdsSource, parsedPayload) => {
  const segments = parsedPayload?.segments || [];
  const passengers = parsedPayload?.passengers || [];

  return {
    ...prevVoucher,
    gds_source: gdsSource,
    booking_reference: parsedPayload?.booking_reference || prevVoucher.booking_reference,
    flights: segments.length
      ? segments.map((segment) => ({
          ...blankFlight(),
          gds_pnr: parsedPayload?.booking_reference || '',
          flight_no: `${segment.airline_code || ''}${segment.flight_number || ''}`.trim(),
          date: segment.departure_date || '',
          from: segment.departure_airport || '',
          to: segment.arrival_airport || '',
        }))
      : prevVoucher.flights,
    passengers: passengers.length
      ? passengers.map((pax) => ({
          ...blankPassenger(),
          name: pax.name || '',
        }))
      : prevVoucher.passengers,
  };
};
