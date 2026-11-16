import test from 'node:test';
import assert from 'node:assert/strict';
import { passengerBaggage, setPassengerBaggage, removePassengerBaggage, baggageSummary } from '../../resources/js/services/baggage.js';
import { itineraryData } from '../../resources/js/services/itinerary.js';

const passengers = [{ name: 'Adult' }, { name: 'Child' }, { name: 'Infant' }];
const flights = [
  { from: 'KHI', to: 'ISB', passenger_baggage: ['30K', '30K', '10K'] },
  { from: 'ISB', to: 'KHI', passenger_baggage: ['45K', '25K', '10K'] },
];

test('each passenger keeps a distinct allowance for each journey in itinerary and PDF data', () => {
  const data = itineraryData({ meta: { passengers, flights } });
  assert.equal(data.flights[0].baggage, 'PAX 1 30K, PAX 2 30K, PAX 3 10K');
  assert.equal(data.flights[1].baggage, 'PAX 1 45K, PAX 2 25K, PAX 3 10K');
  const changed = setPassengerBaggage(flights[1], passengers, 1, '20K');
  assert.deepEqual(changed, ['45K', '20K', '10K']);
  assert.deepEqual(flights[0].passenger_baggage, ['30K', '30K', '10K']);
  assert.deepEqual(flights[1].passenger_baggage, ['45K', '25K', '10K']);
});

test('removing a passenger keeps remaining allowances aligned on every flight', () => {
  const remaining = removePassengerBaggage(flights, 1);
  assert.deepEqual(remaining.map(f => f.passenger_baggage), [['30K', '10K'], ['45K', '10K']]);
  assert.equal(passengerBaggage(remaining[0], 2), '');
});

test('legacy allowance survives editing while explicit blanks and new passengers do not inherit it', () => {
  const legacy = { baggage: '30K' };
  assert.equal(baggageSummary(legacy, passengers), '30K');
  const flight = { ...legacy, passenger_baggage: setPassengerBaggage(legacy, passengers, 2, '') };
  assert.deepEqual(flight.passenger_baggage, ['30K', '30K', '']);
  assert.equal(passengerBaggage(flight, 2), '');
  assert.equal(passengerBaggage(flight, 3), '');
});
