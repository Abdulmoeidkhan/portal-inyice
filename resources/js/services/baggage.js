// Allowances are aligned with the voucher's passenger rows within each flight.
// A legacy flight allowance is used only until passenger-specific values exist.
export const passengerBaggage = (flight, index) => Array.isArray(flight?.passenger_baggage)
  ? flight.passenger_baggage[index] ?? ''
  : flight?.baggage || '';

export const setPassengerBaggage = (flight, passengers, index, value) =>
  passengers.map((_, i) => i === index ? value : passengerBaggage(flight, i));

export const removePassengerBaggage = (flights, index) => flights.map((flight) =>
  Array.isArray(flight.passenger_baggage)
    ? { ...flight, passenger_baggage: flight.passenger_baggage.filter((_, i) => i !== index) }
    : flight);

export const baggageSummary = (flight, passengers = []) => {
  if (!Array.isArray(flight?.passenger_baggage)) return flight?.baggage || '';
  return passengers.map((p, i) => `PAX ${i + 1}${p.name ? ` (${p.name})` : ''}: ${passengerBaggage(flight, i) || 'Not provided'}`).join('; ');
};
