import airlines from '../../../JSON/airline.json' with { type: 'json' };

const text = (value) => String(value ?? '').trim();
const capitalize = (value) => text(value).replaceAll('_', ' ').toLowerCase().replace(/^\w/, (letter) => letter.toUpperCase());
export const flightAirlineName = (number) => {
  const code = text(number).toUpperCase().match(/^([A-Z0-9]{2})[\s-]*\d/)?.[1];
  return airlines[code]?.name || '';
};
const first = (...values) => values.map(text).find(Boolean) || '';
const rows = (value) => Array.isArray(value) ? value : [];

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
  return {
    agency: first(company.display_name, company.legal_name, 'Travel Agency'),
    contact: [company.address, company.phone, company.email].map(text).filter(Boolean),
    logoUrl: company.logo_url,
    unpaid: company.is_paid === false,
    reference: first(order?.voucher_no, meta.voucher_no, order?.order_number),
    booking: first(order?.booking_reference, meta.booking_reference),
    quote: order?.status === 'quote',
    passengers: (passengers.length ? passengers : rows(meta.pricing).filter((p) => text(p?.pax_name))).map((p) => ({
      name: first(p.name, p.pax_name), ticket: first(p.ticket_no, p.flight_ticket_no),
    })),
    flights: itineraryFlights(order).map((f) => ({
      number: text(f.flight_no), date: text(f.date), from: text(f.from).toUpperCase(), to: text(f.to).toUpperCase(),
      airline: flightAirlineName(f.flight_no),
      fromLabel: airportLabel(text(f.from)), toLabel: airportLabel(text(f.to)),
      departure: text(f.departure), arrival: text(f.arrival), arrivalDate: flightArrivalDate(f),
      cabin: capitalize(f.cabin), bookingClass: text(f.booking_class), baggage: text(f.baggage),
      booking: first(f.gds_pnr, order?.booking_reference, meta.booking_reference), airlineReference: text(f.pnr),
    })),
    emergency: text(meta.emergency_contact),
  };
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

export async function itineraryPdf(data, logo = null, icons = {}) {
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
  paragraph(data.agency, 18, logo ? 130 : 180);
  pdf.setFont('helvetica', 'normal');
  data.contact.forEach((line) => paragraph(line, 9, logo ? 130 : 180));
  if (logo) {
    const scale = Math.min(35 / logo.width, 22 / logo.height);
    pdf.addImage(logo.data, 'PNG', 160, 12, logo.width * scale, logo.height * scale);
    y = Math.max(y, 38);
  }
  y += 4;
  pdf.setFont('helvetica', 'bold');
  paragraph(data.quote ? 'PROPOSED TRAVEL ITINERARY' : 'TRAVEL ITINERARY', 15);
  pdf.setFont('helvetica', 'normal');
  if (data.booking) paragraph(`Booking reference: ${data.booking}`, 10);
  const table = (head, body, options = {}) => {
    autoTable(pdf, {
      startY: y, margin: { top: 16, bottom: 20, left: margin, right: margin },
      head, body, theme: 'grid', rowPageBreak: 'avoid',
      styles: { fontSize: 10, cellPadding: 3, lineColor: [218, 225, 232], textColor: [32, 45, 58], overflow: 'linebreak' },
      headStyles: { fillColor: [32, 62, 87], textColor: 255 }, ...options,
    });
    y = pdf.lastAutoTable.finalY + 8;
  };
  if (data.passengers.length) table([['Passenger', 'Ticket number']], data.passengers.map((p) => [p.name, p.ticket || '-']));
  data.flights.forEach((f) => {
    if (y > 220) { pdf.addPage(); y = 18; }
    table([
      [{ content: `DEPARTURE: ${flightDateLabel(f.date)}`, colSpan: 3 }],
      [{ content: flightTimeReminder, colSpan: 3, styles: { fontStyle: 'normal', fontSize: 9, fillColor: [243, 246, 248], textColor: [75, 90, 105] } }],
    ], [
      [
        { content: `${(f.airline || 'Airline not provided').toUpperCase()}\n${f.number || 'Flight number not provided'}`, styles: { fontStyle: 'bold', fontSize: 10 } },
        `DEPARTURE\n${f.departure || '-'}  ${f.from}\n${f.fromLabel}`,
        `ARRIVAL\n${f.arrival || '-'}  ${f.to}\n${f.toLabel}`,
      ],
      [`Cabin: ${f.cabin || '-'}${f.bookingClass ? ` (${f.bookingClass})` : ''}`, { content: flightDateLabel(f.date), styles: { fontStyle: 'bold' } }, { content: flightDateLabel(f.arrivalDate), styles: { fontStyle: 'bold' } }],
      [`Baggage: ${f.baggage || 'Not provided'}`, `Booking reference: ${f.booking || '-'}`, `Airline reference: ${f.airlineReference || '-'}`],
    ], {
      pageBreak: 'avoid',
      columnStyles: { 0: { cellWidth: 48, fillColor: [245, 243, 235] }, 1: { cellWidth: 66 }, 2: { cellWidth: 66 } },
      didParseCell: ({ section, row, column, cell }) => {
        const icon = column.index === 1 ? icons.takeoff : column.index === 2 ? icons.landing : null;
        if (section === 'body' && row.index === 0 && icon) {
          cell.styles.cellPadding = { top: 3, right: 3, bottom: 3, left: 12 };
          cell.styles.minCellHeight = 12;
        }
      },
      didDrawCell: ({ section, row, column, cell }) => {
        const name = column.index === 1 ? 'takeoff' : column.index === 2 ? 'landing' : null;
        if (section === 'body' && row.index === 0 && icons[name]) {
          pdf.addImage(icons[name], 'PNG', cell.x + 3, cell.y + 3, 6, 6, name);
        }
      },
    });
  });
  if (data.emergency) paragraph(`Emergency contact: ${data.emergency}`, 9);
  paragraph(itineraryNotice, 9);
  for (let page = 1; page <= pdf.getNumberOfPages(); page += 1) {
    pdf.setPage(page);
    pdf.setFontSize(8);
    pdf.setTextColor(100);
    pdf.text(data.agency, 15, 286, { maxWidth: 145 });
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

export async function shareItinerary(uid, sharedToken) {
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
  const url = `${window.location.origin}/shared/itineraries/${encodeURIComponent(token)}`;
  if (navigator.clipboard?.writeText) {
    try { await navigator.clipboard.writeText(url); return true; } catch { /* Show a selectable link below. */ }
  }
  window.prompt('Copy this itinerary link:', url);
  return false;
}
