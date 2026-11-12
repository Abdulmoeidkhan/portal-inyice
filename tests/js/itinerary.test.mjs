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
  for (const value of ['Example Travel', 'PROPOSED TRAVEL ITINERARY', 'EXAMPLE/TRAVELLER MR', '1234567890123', 'travel@example.test']) assert.ok(output.includes(value));
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
