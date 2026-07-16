import airports from '../../../../JSON/airports.json';

const airportByIata = Object.values(airports).reduce((lookup, airport) => {
  if (airport?.iata) {
    lookup[airport.iata.toUpperCase()] = airport;
  }
  return lookup;
}, {});

export const getAirportLabel = (code) => {
  const normalized = (code || '').trim().toUpperCase();
  if (!normalized) {
    return '';
  }

  const airport = airportByIata[normalized];
  if (!airport) {
    return '';
  }

  return [airport.city, airport.name, airport.country].filter(Boolean).join(' - ');
};

export const getAirportCity = (code) => {
  const normalized = (code || '').trim().toUpperCase();
  if (!normalized) {
    return '';
  }

  return airportByIata[normalized]?.city || '';
};
