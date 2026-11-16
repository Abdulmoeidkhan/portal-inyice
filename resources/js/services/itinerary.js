import { baggageSummary, passengerBaggage } from './baggage.js';
import airlines from '../../../JSON/airline.json' with { type: 'json' };
import airports from '../../../JSON/airports.json' with { type: 'json' };

const airportByCode = Object.fromEntries(Object.values(airports).filter((a) => a.iata).map((a) => [a.iata.toUpperCase(), a]));
const tripDate = (value) => {
  const date = calendarDate(value);
  return date ? new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(date).toUpperCase() : '';
};

export function transitMinutes(flight, next) {
  if (!next || !flight.to || flight.to !== next.from) return null;
  const arrival = calendarDate(flight.arrivalDate);
  const departure = calendarDate(next.date);
  const arrivalTime = timeMinutes(flight.arrival);
  const departureTime = timeMinutes(next.departure);
  if (!arrival || !departure || arrivalTime === null || departureTime === null) return null;
  return (departure - arrival) / 60000 + departureTime - arrivalTime;
}

const text = (value) => String(value ?? '').trim();
const capitalize = (value) => text(value).replaceAll('_', ' ').toLowerCase().replace(/^\w/, (letter) => letter.toUpperCase());
export const flightAirlineCode = (number) => text(number).toUpperCase().match(/^([A-Z0-9]{2})[\s-]*\d/)?.[1] || '';
export const airlineLogoFallback = '/images/airline-logos/no-image.svg';
export const airlineLogoUrl = (number) => flightAirlineCode(number) ? `/images/airline-logos/${flightAirlineCode(number)}.png` : airlineLogoFallback;
export const flightAirlineName = (number) => {
  const code = flightAirlineCode(number);
  return airlines[code]?.name || '';
};
const first = (...values) => values.map(text).find(Boolean) || '';
const rows = (value) => Array.isArray(value) ? value : [];

export function estimatedFlightDuration(from, to) {
  const origin = airportByCode[text(from).toUpperCase()];
  const destination = airportByCode[text(to).toUpperCase()];
  const valid = (airport) => airport && Number.isFinite(airport.lat) && Number.isFinite(airport.lon)
    && Math.abs(airport.lat) <= 90 && Math.abs(airport.lon) <= 180;
  if (!valid(origin) || !valid(destination)) return '';
  const radians = (degrees) => degrees * Math.PI / 180;
  const lat = radians(destination.lat - origin.lat);
  const lon = radians(destination.lon - origin.lon);
  const a = Math.sin(lat / 2) ** 2 + Math.cos(radians(origin.lat)) * Math.cos(radians(destination.lat)) * Math.sin(lon / 2) ** 2;
  const distance = 6371 * 2 * Math.asin(Math.sqrt(Math.min(1, Math.max(0, a))));
  if (distance < 1) return '';
  // Rough route estimate: 800 km/h plus 30 minutes for takeoff/landing, rounded to 5 minutes.
  const minutes = Math.round((distance / 800 * 60 + 30) / 5) * 5;
  return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

const calendarDate = (value) => {
  const raw = text(value);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(raw)) return null;
  const date = new Date(`${raw}T00:00:00Z`);
  return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === raw ? date : null;
};

const timeMinutes = (value) => {
  const match = text(value).match(/^(\d{1,2}):?(\d{2})(?::\d{2})?\s*(AM|PM)?$/i);
  if (!match) return null;
  let hours = Number(match[1]);
  const minutes = Number(match[2]);
  if (minutes > 59 || hours > (match[3] ? 12 : 23) || (match[3] && hours < 1)) return null;
  if (match[3]) hours = hours % 12 + (match[3].toUpperCase() === 'PM' ? 12 : 0);
  return hours * 60 + minutes;
};

// Resolve airport-local wall time without using the computer's time zone.
// Reject missing/ambiguous DST times rather than inventing a duration.
const airportInstant = (dateValue, timeValue, zone) => {
  const date = calendarDate(dateValue);
  const minutes = timeMinutes(timeValue);
  if (!date || minutes === null || !zone) return null;
  try {
    const formatter = new Intl.DateTimeFormat('en-GB', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
    const wallTime = (instant) => {
      const parts = Object.fromEntries(formatter.formatToParts(new Date(instant)).map(({ type, value }) => [type, value]));
      return Date.UTC(+parts.year, +parts.month - 1, +parts.day, +parts.hour, +parts.minute);
    };
    const target = date.getTime() + minutes * 60000;
    const offsets = new Set([-36, -12, 0, 12, 36].map((hours) => {
      const probe = target + hours * 3600000;
      return wallTime(probe) - probe;
    }));
    const candidates = [...offsets].map((offset) => target - offset).filter((instant) => wallTime(instant) === target);
    return candidates.length === 1 ? candidates[0] : null;
  } catch { return null; }
};

export function scheduledFlightTiming(flight) {
  const fromZone = airportByCode[text(flight.from).toUpperCase()]?.tz;
  const toZone = airportByCode[text(flight.to).toUpperCase()]?.tz;
  const departure = airportInstant(flight.date, flight.departure, fromZone);
  if (departure === null) return null;
  const explicitDate = text(flight.arrival_date);
  const dates = explicitDate ? [explicitDate] : [-1, 0, 1, 2].map((offset) => {
    const date = calendarDate(flight.date);
    date.setUTCDate(date.getUTCDate() + offset);
    return date.toISOString().slice(0, 10);
  });
  const candidates = dates.map((arrivalDate) => {
    const arrival = airportInstant(arrivalDate, flight.arrival, toZone);
    const minutes = arrival === null ? null : (arrival - departure) / 60000;
    return { arrivalDate, minutes };
  }).filter(({ minutes }) => minutes > 0 && minutes <= 24 * 60);
  return candidates.length === 1 ? candidates[0] : null;
}

export const flightDateLabel = (value) => {
  const date = calendarDate(value);
  if (!date) return text(value) || 'Date not provided';
  const parts = new Intl.DateTimeFormat('en-GB', {
    weekday: 'long', day: '2-digit', month: 'short', timeZone: 'UTC',
  }).formatToParts(date);
  return ['weekday', 'day', 'month'].map((type) => parts.find((part) => part.type === type).value).join(' ').toUpperCase();
};

export const flightArrivalDate = (flight) => {
  if (text(flight.arrival_date)) return text(flight.arrival_date);
  const timing = scheduledFlightTiming(flight);
  if (timing) return timing.arrivalDate;
  const date = calendarDate(flight.date);
  const departure = timeMinutes(flight.departure);
  const arrival = timeMinutes(flight.arrival);
  if (!date || departure === null || arrival === null) return '';
  if (departure > arrival) date.setUTCDate(date.getUTCDate() + 1);
  return date.toISOString().slice(0, 10);
};

export const flightTimeReminder = 'Please verify flight times prior to departure';

export const itineraryFlights = (order) => rows(order?.meta?.flights).filter((flight) =>
  ['flight_no', 'from', 'to', 'date', 'departure', 'arrival', 'pnr', 'gds_pnr'].some((key) => text(flight?.[key])));
export const hasItinerary = (order) => itineraryFlights(order).length > 0;

export function itineraryData(order, airportLabel = () => '') {
  const meta = order?.meta || {};
  const company = order?.company || {};
  const passengers = rows(meta.passengers).filter((p) => text(p?.name));
  const phones = [...new Set(text(company.phone).split(/[,;\/\n\r|]+/).map(text).filter(Boolean))];
  const contacts = [
    ...phones.map((value) => ({ type: 'phone', value })),
    ...(text(company.email) ? [{ type: 'email', value: text(company.email) }] : []),
  ];
  const data = {
    agency: first(company.display_name, company.legal_name, 'Travel Agency'),
    contact: contacts.map(({ value }) => value),
    contacts,
    logoUrl: company.logo_url,
    footerLogoUrl: company.footer_logo_url,
    address: text(company.address),
    unpaid: company.is_paid === false,
    reference: first(order?.voucher_no, meta.voucher_no, order?.order_number),
    booking: first(order?.booking_reference, meta.booking_reference),
    quote: order?.status === 'quote',
    passengers: (passengers.length ? passengers : rows(meta.pricing).filter((p) => text(p?.pax_name))).map((p) => ({
      name: first(p.name, p.pax_name), ticket: first(p.ticket_no, p.flight_ticket_no),
    })),
    flights: itineraryFlights(order).map((f) => {
      const timing = scheduledFlightTiming(f);
      return {
      number: text(f.flight_no), date: text(f.date), from: text(f.from).toUpperCase(), to: text(f.to).toUpperCase(),
      airline: flightAirlineName(f.flight_no), airlineCode: flightAirlineCode(f.flight_no), logoUrl: airlineLogoUrl(f.flight_no),
      estimatedDuration: timing ? `${Math.floor(timing.minutes / 60)}h ${timing.minutes % 60}m` : estimatedFlightDuration(f.from, f.to),
      durationLabel: timing ? 'Scheduled flight duration' : 'Estimated flight duration',
      fromLabel: airportLabel(text(f.from)), toLabel: airportLabel(text(f.to)),
      departure: text(f.departure), arrival: text(f.arrival), arrivalDate: timing?.arrivalDate || flightArrivalDate(f),
      cabin: capitalize(f.cabin), bookingClass: text(f.booking_class), baggage: baggageSummary(f, rows(meta.passengers)),
      booking: first(f.gds_pnr, order?.booking_reference, meta.booking_reference), airlineReference: text(f.pnr),
      };
    }),
    emergency: text(meta.emergency_contact),
    notes: text(company.itinerary_notes),
  };
  // Pricing may contain the ticket even when the passenger row only contains a name.
  data.passengers.forEach((p) => {
    const pricing = rows(meta.pricing).find((row) => text(row.pax_name).toUpperCase() === p.name.toUpperCase());
    p.ticket = first(p.ticket, pricing?.flight_ticket_no);
  });
  data.passengerSections = (passengers.length
    ? rows(meta.passengers).map((p, index) => ({ p, index })).filter(({ p }) => text(p?.name))
    : rows(meta.pricing).map((p, index) => ({ p, index })).filter(({ p }) => text(p?.pax_name))
  ).map(({ p, index }, displayIndex) => ({
    number: index + 1,
    name: first(p.name, p.pax_name),
    ticket: data.passengers[displayIndex]?.ticket || '',
    segments: itineraryFlights(order).map((flight, segmentIndex) => ({
      number: segmentIndex + 1,
      flight: text(flight.flight_no),
      date: text(flight.date),
      from: text(flight.from).toUpperCase(),
      to: text(flight.to).toUpperCase(),
      baggage: text(passengerBaggage(flight, index)),
    })),
  }));
  data.status = data.passengers.some((p) => p.ticket) ? 'Confirmed' : data.quote ? 'Proposed' : 'Booked';
  data.flights.forEach((flight, index) => {
    if (data.passengerSections.length) {
      flight.baggage = data.passengerSections.map((passenger) =>
        `PAX ${passenger.number} ${passenger.segments[index].baggage || 'Not provided'}`).join(', ');
    }
  });
  data.reservationCodes = [...new Set([data.booking, ...data.flights.map((f) => f.booking)].filter(Boolean))].join(' / ');
  data.airlineReservationCodes = [...new Set(data.flights.filter((f) => f.airlineReference).map((f) => {
    const code = f.number.toUpperCase().match(/^([A-Z0-9]{2})[\s-]*\d/)?.[1];
    return `${f.airlineReference}${code ? ` (${code})` : ''}`;
  }))].join(' / ');
  data.flights.forEach((f, i) => {
    const minutes = transitMinutes(f, data.flights[i + 1]);
    f.transit = minutes > 0 && minutes < 28 * 60 ? `Transit at ${f.to}: ${Math.floor(minutes / 60)}h ${minutes % 60}m` : '';
  });
  const destination = data.flights.find((f, i) => transitMinutes(f, data.flights[i + 1]) >= 28 * 60) || data.flights.at(-1);
  const airport = airportByCode[destination?.to];
  const country = airport?.country ? new Intl.DisplayNames(['en'], { type: 'region' }).of(airport.country) : '';
  const place = [airport?.city, country].filter(Boolean).join(', ') || destination?.to || '';
  const dates = [...new Set([tripDate(data.flights[0]?.date), tripDate(data.flights.at(-1)?.arrivalDate || data.flights.at(-1)?.date)].filter(Boolean))].join(' - ');
  data.tripTitle = [data.quote ? 'PROPOSED ITINERARY : ' : 'ITINERARY : ', dates, place ? `TRIP TO ${place.toUpperCase()}` : ''].filter(Boolean).join('  ');
  return data;
}

export function itineraryFilename(data) {
  const flight = data.flights?.[0] || {};
  const date = calendarDate(flight.date);
  const dateLabel = date ? new Intl.DateTimeFormat('en-US', {
    month: 'long', day: 'numeric', timeZone: 'UTC',
  }).format(date) : '';
  let name = text(data.passengers?.[0]?.name).toUpperCase();
  const title = name.match(/^(MR|MRS|MS|MISS|MSTR|DR|PROF)\.?\s+/)?.[1]
    || name.match(/\s+(MR|MRS|MS|MISS|MSTR|DR|PROF)\.?$/)?.[1] || '';
  name = name.replace(/^(MR|MRS|MS|MISS|MSTR|DR|PROF)\.?\s+/, '')
    .replace(/\s+(MR|MRS|MS|MISS|MSTR|DR|PROF)\.?$/, '');
  if (name.includes('/')) {
    const [surname, ...givenNames] = name.split('/');
    name = [...givenNames, surname].join(' ');
  }
  const passenger = [title, name].filter(Boolean).join(' ') || 'Traveller';
  const booking = first(data.booking, flight.booking, flight.airlineReference).toUpperCase();
  const filename = ['Travel Reservation', dateLabel, 'for', passenger, booking].filter(Boolean).join(' ')
    .replace(/[<>:"/\\|?*\x00-\x1f]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200).replace(/[. ]+$/, '');
  return `${filename}.pdf`;
}

export const itineraryNotice = 'All times are local to the departure or arrival airport. Verify flight times, baggage and travel requirements with the airline before departure. This itinerary is not a ticket.';

export async function itineraryPdf(data, logo = null, icons = {}, footerLogo = null, shareQr = null) {
  const [{ jsPDF }, { autoTable }] = await Promise.all([import('jspdf'), import('jspdf-autotable')]);
  const pdf = new jsPDF();
  pdf.setProperties({ title: 'Travel Itinerary', author: data.agency });
  const margin = 15;
  let y = 18;
  const paragraph = (value, size = 10, width = 180) => {
    pdf.setFontSize(size);
    const lines = pdf.splitTextToSize(value, width);
    for (const line of lines) {
      if (y > 270) { pdf.addPage(); y = 18; }
      pdf.text(line, margin, y);
      y += size * 0.45;
    }
    y += 3;
  };
  pdf.setTextColor(24, 48, 72);
  pdf.setFont('helvetica', 'bold');
  const qrImages = [shareQr, footerLogo].filter(Boolean);
  pdf.setFont('helvetica', 'normal');
  const table = (head, body, options = {}) => {
    autoTable(pdf, {
      startY: y, margin: { top: 16, bottom: 20, left: margin, right: margin },
      head, body, theme: 'grid', rowPageBreak: 'avoid',
      styles: { fontSize: 10, cellPadding: 3, lineColor: [218, 225, 232], textColor: [32, 45, 58], overflow: 'linebreak' },
      headStyles: { fillColor: [32, 62, 87], textColor: 255 }, ...options,
    });
    y = pdf.lastAutoTable.finalY + 8;
  };
  pdf.setFontSize(10);
  pdf.setFont('helvetica', 'bold');
  const logoOffset = logo ? 22 : 0;
  pdf.setFontSize(14);
  const agencyLines = pdf.splitTextToSize(data.agency, 110 - logoOffset);
  pdf.setFont('helvetica', 'normal');
  pdf.setFontSize(10);
  const contactLines = data.contacts.map((contact) => ({ ...contact, lines: pdf.splitTextToSize(contact.value, 104 - logoOffset) }));
  const textHeight = agencyLines.length * 6 + 3
    + contactLines.reduce((height, contact) => height + contact.lines.length * 4.5 + 2, 0);
  const rowHeight = Math.max(logo ? 22 : 0, textHeight);
  pdf.setFontSize(9);
  const addressLines = data.address ? pdf.splitTextToSize(data.address, 110) : [];
  const brandingHeight = rowHeight + (addressLines.length ? 2 + addressLines.length * 4 : 0) + 6;
  table([], [[
    { content: '', styles: { cellWidth: 116, minCellHeight: Math.max(brandingHeight, qrImages.length ? 38 : 0) } },
    { content: '', styles: { cellWidth: 64 } },
  ]], {
    theme: 'plain', pageBreak: 'avoid',
    didDrawCell: ({ section, column, cell }) => {
      if (section !== 'body') return;
      if (column.index === 1) {
        qrImages.forEach((qr, i) => {
          const scale = Math.min(28 / qr.width, 28 / qr.height);
          pdf.addImage(qr.data, 'PNG', cell.x + i * 32 + 2, cell.y + 3, qr.width * scale, qr.height * scale);
          pdf.setFont('helvetica', 'normal');
          pdf.setFontSize(7);
          pdf.text(qr === shareQr ? 'Itinerary' : 'Agency', cell.x + i * 32 + 16, cell.y + 34, { align: 'center' });
        });
        return;
      }
      const x = cell.x + 3 + logoOffset;
      let brandY = cell.y + 3;
      if (logo) {
        const scale = Math.min(18 / logo.width, 22 / logo.height);
        pdf.addImage(logo.data, 'PNG', cell.x + 3, cell.y + 3, logo.width * scale, logo.height * scale);
      }
      pdf.setFontSize(14);
      pdf.setFont('helvetica', 'bold');
      agencyLines.forEach((line) => { pdf.text(line, x, brandY + 4.5); brandY += 6; });
      pdf.setFontSize(10);
      brandY += 3;
      pdf.setFont('helvetica', 'normal');
      pdf.setDrawColor(32, 62, 87);
      pdf.setLineWidth(0.3);
      contactLines.forEach(({ type, lines }) => {
        if (type === 'email') {
          pdf.rect(x, brandY + 0.5, 4, 3);
          pdf.line(x, brandY + 0.5, x + 2, brandY + 2.2);
          pdf.line(x + 2, brandY + 2.2, x + 4, brandY + 0.5);
        } else {
          pdf.roundedRect(x + 0.7, brandY, 2.6, 4.2, 0.4, 0.4);
          pdf.line(x + 1.4, brandY + 3.4, x + 2.6, brandY + 3.4);
        }
        lines.forEach((line) => { pdf.text(line, x + 6, brandY + 3.5); brandY += 4.5; });
        brandY += 2;
      });
      pdf.setFontSize(9);
      addressLines.forEach((line, i) => pdf.text(line, cell.x + 3, cell.y + 3 + rowHeight + 5 + i * 4));
    },
  });
  pdf.setDrawColor(32, 62, 87);
  pdf.setLineWidth(0.3);
  pdf.line(margin, y - 3, 195, y - 3);
  y += 2;
  pdf.setFont('helvetica', 'bold');
  paragraph(data.tripTitle, 12);
  pdf.setFont('helvetica', 'normal');
  table([], [[
    data.passengers.length ? `PREPARED FOR\n${data.passengers.map((p) => `${p.name}${p.ticket ? ` - ${p.ticket}` : ''}`).join('\n')}` : '',
    `RESERVATION CODE: ${data.reservationCodes || '-'}
AIRLINE RESERVATION CODE: ${data.airlineReservationCodes || '-'}
STATUS: ${data.status}`,
  ]], {
    theme: 'plain',
    styles: { fontSize: 10, cellPadding: 0, overflow: 'linebreak', valign: 'top' },
    columnStyles: { 0: { cellWidth: 98, cellPadding: { right: 8 } }, 1: { cellWidth: 82 } },
  });
  data.flights.forEach((f) => {
    if (y > 205) { pdf.addPage(); y = 18; }
    const airlineLogo = icons.airlines?.[f.airlineCode];
    const departureHeading = `DEPARTURE: ${flightDateLabel(f.date)}${f.arrivalDate && f.arrivalDate !== f.date ? ` / ARRIVAL: ${flightDateLabel(f.arrivalDate)}` : ''}`;
    table([[{ content: departureHeading, colSpan: 3, styles: { cellPadding: { top: 3, bottom: 1, left: airlineLogo ? 17 : 0, right: 0 } } }],
      [{ content: flightTimeReminder, colSpan: 3, styles: { fontSize: 8, fontStyle: 'normal', cellPadding: { top: 0, bottom: 3, left: airlineLogo ? 17 : 0, right: 0 } } }]], [
      [
        { content: `${(f.airline || 'Airline not provided').toUpperCase()}\n${f.number || 'Flight number not provided'}\n\n${f.durationLabel || 'Estimated flight duration'}\n${f.estimatedDuration || 'Unavailable'}\n\nCabin\n${[f.cabin, f.bookingClass].filter(Boolean).join(' / ') || 'Not provided'}\n\nStatus\n${data.status}`, rowSpan: 2, styles: { fillColor: [243, 240, 230] } },
        `${f.from || '-'}\n${f.fromLabel}`,
        `${f.to || '-'}\n${f.toLabel}`,
      ],
      [
        `Departing at:\n${f.departure || 'Not provided'}${f.arrivalDate !== f.date ? `\n${flightDateLabel(f.date)}` : ''}`,
        `Arriving at:\n${f.arrival || 'Not provided'}${f.arrivalDate !== f.date ? `\n${flightDateLabel(f.arrivalDate)}` : ''}`,
      ],
    ], {
      pageBreak: 'avoid',
      headStyles: { fillColor: [255, 255, 255], textColor: [32, 45, 58], fontSize: 10, lineWidth: 0 },
      columnStyles: { 0: { cellWidth: 60 }, 1: { cellWidth: 60 }, 2: { cellWidth: 60 } },
      didDrawCell: ({ section, cell, row }) => {
        if (section === 'head' && row.index === 0 && airlineLogo) {
          const scale = Math.min(12 / airlineLogo.width, 7 / airlineLogo.height);
          pdf.addImage(airlineLogo.data, 'PNG', cell.x + 2, cell.y + 1, airlineLogo.width * scale, airlineLogo.height * scale);
        }
      },
    });
    y -= 4;
    paragraph(`Baggage: ${f.baggage || 'Not provided'}`, 9);
    if (f.transit) paragraph(f.transit, 10);
  });
  if (data.notes) {
    if (y > 250) { pdf.addPage(); y = 18; }
    pdf.setDrawColor(180);
    pdf.setLineWidth(0.2);
    pdf.line(margin, y, 195, y);
    y += 6;
    pdf.setFont('helvetica', 'bold');
    paragraph('Notes', 10);
    pdf.setFont('helvetica', 'normal');
    paragraph(data.notes, 9);
  }
  if (data.emergency) paragraph(`Emergency contact: ${data.emergency}`, 9);
  paragraph(itineraryNotice, 9);
  for (let page = 1; page <= pdf.getNumberOfPages(); page += 1) {
    pdf.setPage(page);
    pdf.setFontSize(8);
    pdf.setTextColor(100);

    pdf.text(`${page} / ${pdf.getNumberOfPages()}`, 195, 286, { align: 'right' });
    if (data.unpaid) pdf.text('Powered by InYice', 105, 292, { align: 'center' });
  }
  return pdf;
}

export async function fetchItineraryOrder(uid, token) {
  const authToken = localStorage.getItem('auth_token') || localStorage.getItem('token');
  const response = await fetch(token ? `/api/v1/shared-vouchers/${token}` : `/api/v1/orders/${uid}`, {
    headers: { Accept: 'application/json', ...(token ? {} : { Authorization: `Bearer ${authToken}` }) },
  });
  const data = await response.json();
  if (!response.ok) throw new Error(data.message || 'Could not load itinerary');
  if (!hasItinerary(data)) throw new Error('This record has no flight details.');
  return data;
}

export async function itineraryShareUrl(uid, sharedToken) {
  let token = sharedToken;
  if (!token) {
    const authToken = localStorage.getItem('auth_token') || localStorage.getItem('token');
    const response = await fetch(`/api/v1/orders/${uid}/share`, {
      method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${authToken}` },
    });
    const data = await response.json();
    if (!response.ok || !data.share_token) throw new Error(data.message || 'Could not create share link');
    token = data.share_token;
  }
  return `${window.location.origin}/shared/itineraries/${encodeURIComponent(token)}`;
}

export async function shareItinerary(uid, sharedToken) {
  const url = await itineraryShareUrl(uid, sharedToken);
  if (navigator.clipboard?.writeText) {
    try { await navigator.clipboard.writeText(url); return true; } catch { /* Show a selectable link below. */ }
  }
  window.prompt('Copy this itinerary link:', url);
  return false;
}
