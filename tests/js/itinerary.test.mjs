import test from 'node:test';
import assert from 'node:assert/strict';
import { flightAirlineName, flightArrivalDate, flightDateLabel, hasItinerary, itineraryData, itineraryPdf } from '../../resources/js/services/itinerary.js';

const order = {
  uid: 'order-1', order_number: 'ORD-100', status: 'quote', total_amount: 99999,
  company: { display_name: 'Example Travel', address: '12 Travel Road', phone: '+92 123456', email: 'travel@example.test' },
  meta: { booking_reference: 'ABC123', passengers: [{ name: 'EXAMPLE/TRAVELLER MR', ticket_no: '1234567890123', passport_no: 'PRIVATE-PASSPORT' }],
    flights: [{ flight_no: 'J2 108', from: 'LGW', to: 'GYD', date: '2027-03-28', departure: '11:25', arrival: '19:55', cabin: 'economy', baggage: '23kg', pnr: 'AIR123' }],
    pricing: [{ flight_cost_base: 77777 }], internal_notes: 'PRIVATE-NOTES' },
};

test('only populated flight rows enable itinerary actions', () => {
  assert.equal(hasItinerary({ meta: { flights: [{ cabin: 'economy', baggage: '' }] } }), false);
  assert.equal(hasItinerary({ meta: { hotels: [{ hotel_name: 'Hotel' }] } }), false);
  assert.equal(hasItinerary(order), true);
});

test('itinerary contains agency and flight details without financial or passport data', () => {
  const data = itineraryData(order, (code) => code === 'LGW' ? 'London Gatwick' : 'Baku');
  assert.equal(data.agency, 'Example Travel');
  assert.equal(data.flights[0].fromLabel, 'London Gatwick');
  assert.equal(data.flights[0].booking, 'ABC123');
  assert.equal(data.flights[0].airlineReference, 'AIR123');
  assert.equal(data.flights[0].arrivalDate, '2027-03-28');
  assert.deepEqual(data.passengers, [{ name: 'EXAMPLE/TRAVELLER MR', ticket: '1234567890123' }]);
  for (const secret of ['99999', '77777', 'PRIVATE-PASSPORT', 'PRIVATE-NOTES']) assert.ok(!JSON.stringify(data).includes(secret));
});

test('pricing-only passenger records retain names and ticket numbers', () => {
  const data = itineraryData({ ...order, meta: { ...order.meta, passengers: [], pricing: [{ pax_name: 'TEST/PAX', flight_ticket_no: '123' }] } });
  assert.deepEqual(data.passengers, [{ name: 'TEST/PAX', ticket: '123' }]);
});

test('PDF includes all segments across pages, branding, and quote wording', async () => {
  const data = itineraryData(order);
  data.flights = Array.from({ length: 12 }, (_, i) => ({ ...data.flights[0], number: `TESTFLIGHT-${i + 1}` }));
  const pdf = await itineraryPdf(data);
  assert.ok(pdf.getNumberOfPages() > 1);
  const output = pdf.output();
  for (let i = 1; i <= 12; i++) assert.ok(output.includes(`TESTFLIGHT-${i}`));
  for (const value of ['Example Travel', 'PROPOSED ITINERARY', 'EXAMPLE/TRAVELLER MR', '1234567890123', 'travel@example.test']) assert.ok(output.includes(value));
  assert.ok(!output.includes('PRIVATE-PASSPORT'));
});

test('agency logo embeds in PDF', async () => {
  const logo = { width: 1, height: 1, data: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4XmNgYGD4DwABBAEA43qFxAAAAABJRU5ErkJggg==' };
  const pdf = await itineraryPdf(itineraryData(order), logo);
  assert.ok(pdf.output().includes('/Subtype /Image'));
});

test('overnight arrivals roll across month, year and leap-day boundaries', () => {
  for (const [date, expected] of [
    ['2027-03-28', '2027-03-29'], ['2027-04-30', '2027-05-01'],
    ['2027-12-31', '2028-01-01'], ['2028-02-28', '2028-02-29'], ['2028-02-29', '2028-03-01'],
  ]) assert.equal(flightArrivalDate({ date, departure: '21:10', arrival: '01:15' }), expected);
  assert.equal(flightArrivalDate({ date: '2027-03-28', departure: '2110', arrival: '0115' }), '2027-03-29');
  assert.equal(flightArrivalDate({ date: '2027-03-28', departure: '09:10 PM', arrival: '01:15 AM' }), '2027-03-29');
});

test('same-day and equal times stay on departure date; explicit arrival dates are preserved', () => {
  for (const arrival of ['11:25', '19:55']) assert.equal(flightArrivalDate({ date: '2027-03-28', departure: '11:25', arrival }), '2027-03-28');
  assert.equal(flightArrivalDate({ date: '2027-03-28', departure: '21:10', arrival: '01:15', arrival_date: '2027-03-30' }), '2027-03-30');
  for (const flight of [
    { date: '2027-02-30', departure: '21:10', arrival: '01:15' },
    { date: '2027-03-28', departure: '', arrival: '01:15' },
    { date: '2027-03-28', departure: '25:00', arrival: '01:15' },
  ]) assert.equal(flightArrivalDate(flight), '');
});

test('PDF uses calendar weekdays and shows next-day arrival heading and date', async () => {
  assert.equal(flightDateLabel('2027-04-14'), 'WEDNESDAY 14 APR');
  const data = itineraryData({ ...order, meta: { ...order.meta, flights: [{ ...order.meta.flights[0], date: '2027-04-14', departure: '21:10', arrival: '01:15' }] } });
  const output = (await itineraryPdf(data)).output();
  for (const value of ['DEPARTURE: WEDNESDAY 14 APR', 'THURSDAY 15 APR', 'Please verify flight times prior to departure']) assert.ok(output.includes(value), value);
});


test('airline lookup handles spaced, compact, lowercase and numeric airline codes', () => {
  for (const number of ['J2 525', 'J2525', 'j2-525']) assert.equal(flightAirlineName(number), 'Azerbaijan Airlines');
  assert.equal(flightAirlineName('BA 123'), 'British Airways');
  assert.equal(flightAirlineName('ZZ 999'), '');
  assert.equal(flightAirlineName(''), '');
});

test('PDF presents airline, capitalized cabin and readable dates without voucher reference', async () => {
  const data = itineraryData({ ...order, voucher_no: 'HV-142', meta: { ...order.meta, flights: [{ ...order.meta.flights[0], date: '2026-07-30' }] } });
  assert.equal(data.flights[0].cabin, 'Economy');
  const output = (await itineraryPdf(data)).output();
  for (const value of ['AZERBAIJAN AIRLINES', 'J2 108', 'Economy', 'THURSDAY 30 JUL']) assert.ok(output.includes(value), value);
  for (const value of ['HV-142', '2026-07-30', 'Cabin: economy']) assert.ok(!output.includes(value), value);
});


test('header summarizes trip, reservations and ticket status', async () => {
  const data = itineraryData(order);
  assert.match(data.tripTitle, /28 MAR 2027.*TRIP TO BAKU, AZERBAIJAN/);
  assert.equal(data.reservationCodes, 'ABC123');
  assert.equal(data.airlineReservationCodes, 'AIR123 (J2)');
  assert.equal(data.status, 'Confirmed');
  const output = (await itineraryPdf(data)).output();
  for (const value of ['TRIP TO BAKU', 'PREPARED FOR', 'RESERVATION CODE', 'Confirmed']) assert.ok(output.includes(value), value);
  const booking = { ...order, status: 'booking', meta: { ...order.meta, passengers: [{ name: 'TEST/PAX' }], pricing: [] } };
  assert.equal(itineraryData(booking).status, 'Booked');
  assert.equal(itineraryData({ ...booking, status: 'quote' }).status, 'Proposed');
  booking.meta.pricing = [{ pax_name: 'TEST/PAX', flight_ticket_no: '123456' }];
  assert.equal(itineraryData(booking).status, 'Confirmed');
  assert.equal(itineraryData(booking).passengers[0].ticket, '123456');
});

test('transit respects overnight dates, matching airports and strict 28-hour cutoff', async () => {
  const flights = [
    { flight_no: 'QR 605', from: 'KHI', to: 'DOH', date: '2026-11-11', departure: '22:00', arrival: '23:30' },
    { flight_no: 'QR 4993', from: 'DOH', to: 'KUL', date: '2026-11-12', departure: '03:10', arrival: '15:00' },
  ];
  const make = () => itineraryData({ ...order, meta: { ...order.meta, flights } });
  assert.equal(make().flights[0].transit, 'Transit at DOH: 3h 40m');
  assert.match(make().tripTitle, /KUALA LUMPUR, MALAYSIA/);
  assert.ok((await itineraryPdf(make())).output().includes('Transit at DOH: 3h 40m'));
  flights[1].date = '2026-11-13';
  flights[1].departure = '03:29';
  assert.equal(make().flights[0].transit, 'Transit at DOH: 27h 59m');
  flights[1].departure = '03:30';
  assert.equal(make().flights[0].transit, '');
  flights[1].date = '2026-11-11';
  for (const departure of ['23:30', '23:00', '']) {
    flights[1].departure = departure;
    assert.equal(make().flights[0].transit, '');
  }
  flights[1].departure = '23:59';
  flights[1].from = 'DXB';
  assert.equal(make().flights[0].transit, '');
});


test('share URL uses existing public token without posting or copying', async () => {
  const { itineraryShareUrl } = await import('../../resources/js/services/itinerary.js');
  const previous = globalThis.window;
  globalThis.window = { location: { origin: 'https://agency.test' } };
  try { assert.equal(await itineraryShareUrl('order-1', 'public-token'), 'https://agency.test/shared/itineraries/public-token'); }
  finally { if (previous === undefined) delete globalThis.window; else globalThis.window = previous; }
});

test('airline images use flight code and PDF wraps long names with logo', async () => {
  const { airlineLogoUrl, airlineLogoFallback } = await import('../../resources/js/services/itinerary.js');
  assert.equal(airlineLogoUrl('qr-605'), '/images/airline-logos/QR.png');
  assert.equal(airlineLogoUrl(''), airlineLogoFallback);
  const data = itineraryData(order);
  data.flights[0].airline = 'An Extremely Long Airline Name That Must Stay On One Line';
  const logo = { width: 1, height: 1, data: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4XmNgYGD4DwABBAEA43qFxAAAAABJRU5ErkJggg==' };
  const pdf = await itineraryPdf(data, logo, { airlines: { J2: logo } }, logo, logo);
  assert.ok(pdf.output().includes('/Subtype /Image'));
  assert.ok(pdf.output().includes('AN EXTREMELY'));
  assert.ok(!pdf.output().includes(data.flights[0].airline.toUpperCase()));
  assert.equal(Math.round(pdf.internal.pageSize.getWidth()), 210);
});


test('estimated flight duration handles routes and missing airports in PDF', async () => {
  const { estimatedFlightDuration } = await import('../../resources/js/services/itinerary.js');
  const estimate = estimatedFlightDuration('KHI', 'DOH');
  assert.match(estimate, /^[1-9][0-9]*h [0-5]?[0-9]m$/);
  assert.equal(estimatedFlightDuration(' doh ', 'khi'), estimate);
  assert.equal(estimatedFlightDuration('KHI', 'KHI'), '');
  assert.equal(estimatedFlightDuration('UNKNOWN', 'DOH'), '');
  assert.equal(estimatedFlightDuration('', ''), '');
  const data = itineraryData(order);
  assert.ok(data.flights[0].estimatedDuration);
  const output = (await itineraryPdf(data)).output();
  assert.equal(data.flights[0].durationLabel, 'Scheduled flight duration');
  assert.ok(output.includes('Scheduled flight duration'));
  assert.ok(output.includes(data.flights[0].estimatedDuration));
});


test('duration follows entered times in airport time zones, not route distance', async () => {
  const flights = [
    { flight_no: 'TG342', from: 'KHI', to: 'BKK', date: '2026-08-28', departure: '23:30', arrival: '06:20' },
    { flight_no: 'TG475', from: 'BKK', to: 'SYD', date: '2026-08-29', departure: '08:30', arrival: '20:30' },
  ];
  const data = itineraryData({ ...order, meta: { ...order.meta, flights } });
  assert.equal(data.flights[0].estimatedDuration, '4h 50m');
  assert.equal(data.flights[0].arrivalDate, '2026-08-29');
  assert.equal(data.flights[1].estimatedDuration, '9h 0m');
  const output = (await itineraryPdf(data)).output();
  assert.ok(output.includes('4h 50m'));
  assert.ok(output.includes('9h 0m'));
  flights[0].arrival = '06:05';
  flights[1].arrival = '20:50';
  const adjusted = itineraryData({ ...order, meta: { ...order.meta, flights } });
  assert.equal(adjusted.flights[0].estimatedDuration, '4h 35m');
  assert.equal(adjusted.flights[1].estimatedDuration, '9h 20m');
});

test('scheduled timing handles DST, westbound arrivals and the date line', async () => {
  const { scheduledFlightTiming } = await import('../../resources/js/services/itinerary.js');
  assert.equal(scheduledFlightTiming({ from: 'BKK', to: 'SYD', date: '2026-12-01', departure: '08:30', arrival: '20:30' }).minutes, 480);
  assert.deepEqual(scheduledFlightTiming({ from: 'BKK', to: 'KHI', date: '2026-08-28', departure: '23:30', arrival: '23:00' }), { arrivalDate: '2026-08-28', minutes: 90 });
  assert.deepEqual(scheduledFlightTiming({ from: 'SYD', to: 'LAX', date: '2026-08-28', departure: '10:00', arrival: '06:00', arrival_date: '2026-08-28' }), { arrivalDate: '2026-08-28', minutes: 780 });
  assert.equal(scheduledFlightTiming({ from: 'JFK', to: 'LHR', date: '2026-11-01', departure: '01:30', arrival: '14:00' }), null);
  assert.equal(scheduledFlightTiming({ from: 'JFK', to: 'LHR', date: '2026-03-08', departure: '02:30', arrival: '14:00' }), null);
  assert.equal(scheduledFlightTiming({ from: 'KHI', to: 'BKK', date: '2026-08-28', departure: '', arrival: '06:20' }), null);
  assert.equal(scheduledFlightTiming({ from: 'KHI', to: 'BKK', date: '2026-08-28', departure: '23:30', arrival: '06:20', arrival_date: 'invalid' }), null);
});

test('duration retains fractional timezone offsets and infers date-line arrivals', async () => {
  const { scheduledFlightTiming } = await import('../../resources/js/services/itinerary.js');
  for (const [flight, expected] of [
    [{ from: 'DEL', to: 'KTM', date: '2026-11-14', departure: '10:00', arrival: '12:00' }, { arrivalDate: '2026-11-14', minutes: 105 }],
    [{ from: 'SYD', to: 'LAX', date: '2026-08-28', departure: '10:00', arrival: '06:00' }, { arrivalDate: '2026-08-28', minutes: 780 }],
    [{ from: 'LAX', to: 'SYD', date: '2026-08-28', departure: '23:00', arrival: '07:00' }, { arrivalDate: '2026-08-30', minutes: 900 }],
    [{ from: 'KHI', to: 'BKK', date: '2028-02-29', departure: '23:30', arrival: '06:20' }, { arrivalDate: '2028-03-01', minutes: 290 }],
  ]) assert.deepEqual(scheduledFlightTiming(flight), expected);
});

test('duration fallback remains usable for incomplete schedules and unknown airports', async () => {
  const { estimatedFlightDuration } = await import('../../resources/js/services/itinerary.js');
  const make = (flight) => itineraryData({ meta: { flights: [flight] } }).flights[0];
  assert.equal(make({ from: 'KHI', to: 'BKK', date: '2026-08-28', departure: '', arrival: '06:20' }).estimatedDuration, estimatedFlightDuration('KHI', 'BKK'));
  assert.equal(make({ from: 'UNKNOWN', to: 'BKK', date: '2026-08-28', departure: '23:30', arrival: '06:20' }).estimatedDuration, '');
  assert.equal(make({ from: ' khi ', to: ' bkk ', date: '2026-08-28', departure: '11:30 PM', arrival: '06:20 AM' }).estimatedDuration, '4h 50m');
  const fallback = itineraryData({ meta: { flights: [{ from: 'KHI', to: 'MED' }] } });
  assert.equal(fallback.flights[0].durationLabel, 'Estimated flight duration');
  assert.ok((await itineraryPdf(fallback)).output().includes('Estimated flight duration'));
});

test('company itinerary notes follow the last segment and remain selectable across PDF pages', async () => {
  const data = itineraryData({ ...order, company: { ...order.company, itinerary_notes: 'NOTES START\n' + 'Please verify your documents.\n'.repeat(100) + 'NOTES END', voucher_notes: 'VOUCHER ONLY' } });
  assert.ok(data.notes.startsWith('NOTES START'));
  const pdf = await itineraryPdf(data);
  const output = pdf.output();
  assert.ok(pdf.getNumberOfPages() > 1);
  assert.ok(output.indexOf('NOTES START') > output.indexOf('Baggage:'));
  assert.ok(output.includes('NOTES END'));
  assert.ok(!output.includes('VOUCHER ONLY'));
  assert.equal(itineraryData({ ...order, company: { itinerary_notes: '  ' } }).notes, '');
});
