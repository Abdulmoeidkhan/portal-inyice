const isDateToken = (token) => /^(\d{2}[A-Z]{3}(\d{2,4})?|\d{2}[-/]\d{2}[-/]\d{2,4})$/i.test(token);

const normalizeDate = (raw) => {
  const clean = raw.trim().toUpperCase();
  const gdsDate = clean.match(/^(\d{2})([A-Z]{3})(\d{2,4})?$/);
  if (gdsDate) {
    return gdsDate[3] ? `${gdsDate[1]}-${gdsDate[2]}-${gdsDate[3]}` : `${gdsDate[1]}-${gdsDate[2]}`;
  }

  return clean;
};

const normalizeTime = (raw) => {
  const clean = raw.trim().toUpperCase().replace(/\s+/g, '').replace(/^#/, '').replace(/\+\d+$/, '');
  const colon = clean.match(/^(\d{1,2}):(\d{2})(AM|PM)?$/);

  if (colon) {
    let hour = Number(colon[1]);
    const suffix = colon[3];
    if (suffix === 'PM' && hour < 12) hour += 12;
    if (suffix === 'AM' && hour === 12) hour = 0;
    return `${hour.toString().padStart(2, '0')}:${colon[2]}`;
  }

  const compact = clean.match(/^(\d{3,4})(A|P|AM|PM)?$/);
  if (compact) {
    const digits = compact[1].padStart(4, '0');
    let hour = Number(digits.slice(0, 2));
    const suffix = compact[2];
    if ((suffix === 'P' || suffix === 'PM') && hour < 12) hour += 12;
    if ((suffix === 'A' || suffix === 'AM') && hour === 12) hour = 0;
    return `${hour.toString().padStart(2, '0')}:${digits.slice(2, 4)}`;
  }

  return raw;
};

const isTimeToken = (token) => {
  const clean = token.toUpperCase().replace(/^#/, '').replace(/\+\d+$/, '');
  return /^\d{1,2}:\d{2}(AM|PM)?$/.test(clean) || /^\d{3,4}(A|P|AM|PM)?$/.test(clean);
};

const extractTimes = (tokens) => {
  const times = [];

  for (let i = 0; i < tokens.length; i += 1) {
    const tokenClean = tokens[i].toUpperCase().replace(/^#/, '').replace(/\+\d+$/, '');
    const nextClean = tokens[i + 1]?.toUpperCase().replace(/^#/, '').replace(/\+\d+$/, '');

    if (/^\d{1,2}:\d{2}$/.test(tokenClean) && (nextClean === 'AM' || nextClean === 'PM')) {
      times.push(normalizeTime(`${tokenClean}${nextClean}`));
      i += 1;
      continue;
    }

    if (/^\d{3,4}$/.test(tokenClean) && ['A', 'P', 'AM', 'PM'].includes(nextClean)) {
      times.push(normalizeTime(`${tokenClean}${nextClean}`));
      i += 1;
      continue;
    }

    if (isTimeToken(tokenClean)) {
      times.push(normalizeTime(tokenClean));
    }
  }

  return times;
};

const detectGdsAndPnr = (lines) => {
  const first = lines.find((line) => line.trim())?.trim().toUpperCase() ?? '';

  for (const line of lines) {
    const upperLine = line.trim().toUpperCase();
    if (upperLine.startsWith('RP/')) {
      return { gds: 'amadeus', pnr: upperLine.match(/([A-Z0-9]{6})\s*$/)?.[1] ?? '' };
    }

    const amadeusLocator = upperLine.match(/\b(?:RLR|RL|RECLOC|RECORD\s+LOCATOR)\s*[:/-]?\s*([A-Z0-9]{6})\b/i)?.[1];
    if (amadeusLocator) {
      return { gds: 'amadeus', pnr: amadeusLocator.toUpperCase() };
    }
  }

  for (const line of lines) {
    const taggedPnr = line.trim().toUpperCase().match(/\b(?:GDS\s+PNR|AIRLINE\s+PNR|PNR)\s*[:-]?\s*([A-Z0-9]{5,8})\b/i)?.[1];
    if (taggedPnr) {
      return { gds: 'other', pnr: taggedPnr.toUpperCase() };
    }
  }

  if (/^[A-Z]{6}$/.test(first)) {
    return { gds: 'sabre', pnr: first };
  }

  if (/^[A-Z0-9]{6}$/.test(first)) {
    return { gds: 'other', pnr: first };
  }

  const galileoPnr = first.match(/^([A-Z0-9]{6})\//)?.[1] ?? '';
  if (galileoPnr) {
    return { gds: 'galileo', pnr: galileoPnr };
  }

  return { gds: 'other', pnr: '' };
};

const extractRoute = (tokens) => {
  for (let i = 0; i < tokens.length; i += 1) {
    const token = tokens[i].toUpperCase().replace(/[.,;:]+$/, '');

    if (/^[A-Z]{3}[-/][A-Z]{3}$/.test(token)) {
      const [from, to] = token.split(/[-/]/);
      return { from, to, routeIdx: i };
    }

    const compact = token.replace(/\*(?:HK|SS|KL|TK|NN|HL|GK|AK)\d+$/, '');
    if (/^[A-Z]{6}$/.test(compact)) {
      return { from: compact.slice(0, 3), to: compact.slice(3, 6), routeIdx: i };
    }

    const next = tokens[i + 1]?.toUpperCase().replace(/[.,;:]+$/, '');
    if (/^[A-Z]{3}$/.test(token) && /^[A-Z]{3}$/.test(next)) {
      return { from: token, to: next, routeIdx: i + 1 };
    }
  }

  return { from: '', to: '', routeIdx: -1 };
};

const extractAirlinePnrFromLine = (line) => {
  const upper = line.toUpperCase();
  return (
    upper.match(/\*([A-Z0-9]{6})\s*\/E\b/)?.[1] ||
    upper.match(/\b([A-Z0-9]{6})\s*\/E\b/)?.[1] ||
    upper.match(/\b[A-Z0-9]{2,3}\/([A-Z0-9]{5,8})\b/)?.[1] ||
    ''
  );
};

const buildFlightDetails = (tokens, dateIdx) => {
  if (dateIdx >= 2 && /^[A-Z0-9]{2,3}$/i.test(tokens[dateIdx - 2])) {
    const airline = tokens[dateIdx - 2].toUpperCase();
    const flightClass = tokens[dateIdx - 1].toUpperCase();
    if (/^\d{1,4}[A-Z]$/.test(flightClass)) {
      return { flightNo: `${airline}${flightClass.replace(/[A-Z]$/, '')}`, bookingClass: flightClass.slice(-1) };
    }
  }

  if (
    dateIdx >= 3 &&
    /^[A-Z0-9]{2,3}$/i.test(tokens[dateIdx - 3]) &&
    /^\d{1,4}[A-Z]?$/i.test(tokens[dateIdx - 2]) &&
    /^[A-Z]$/i.test(tokens[dateIdx - 1])
  ) {
    return {
      flightNo: `${tokens[dateIdx - 3].toUpperCase()}${tokens[dateIdx - 2].toUpperCase().replace(/[A-Z]$/, '')}`,
      bookingClass: tokens[dateIdx - 1].toUpperCase(),
    };
  }

  if (dateIdx >= 1 && /^(?=.*\d)[A-Z0-9]{2,3}-?\d{1,4}[A-Z]?$/i.test(tokens[dateIdx - 1])) {
    const raw = tokens[dateIdx - 1].toUpperCase();
    return { flightNo: raw.replace(/[A-Z]$/, ''), bookingClass: raw.match(/\d([A-Z])$/)?.[1] ?? '' };
  }

  return { flightNo: '', bookingClass: '' };
};

const isAmadeusStatusToken = (token) => /^(HK|SS|HL|TK|KK|KL|NN|HN|GK|UN|UC|NO|HX|RR|WL)\d+$/i.test(token);

const parseAmadeusLine = (line, defaultGdsPnr, defaultAirlinePnr = '') => {
  const compactLine = line.trim().replace(/\s+/g, ' ');
  if (!compactLine || !/^\d+\s+/.test(compactLine)) return null;

  let tokens = compactLine.split(' ').map((token) => token.trim()).filter(Boolean);
  if (tokens.length < 7) return null;

  tokens = tokens.slice(1);

  const dateIdx = tokens.findIndex((token) => isDateToken(token.toUpperCase()));
  if (dateIdx < 0) return null;

  const { flightNo, bookingClass } = buildFlightDetails(tokens, dateIdx);
  const { from, to, routeIdx } = extractRoute(tokens);
  const statusIdx = tokens.findIndex((token, idx) => idx > dateIdx && isAmadeusStatusToken(token));

  if (!flightNo || !from || !to || statusIdx < 0) return null;

  const timeStartIdx = Math.max(routeIdx, statusIdx) + 1;
  const times = extractTimes(tokens.slice(timeStartIdx));

  return {
    gdsPnr: defaultGdsPnr,
    pnr: extractAirlinePnrFromLine(compactLine) || defaultAirlinePnr,
    bookingClass,
    baggage: '',
    cabin: 'Economy',
    date: normalizeDate(tokens[dateIdx]),
    flightNo,
    from,
    to,
    departure: times[0] || '',
    arrival: times[1] || '',
  };
};

const parseLine = (line, defaultGdsPnr, defaultAirlinePnr = '') => {
  const compactLine = line.trim().replace(/\s+/g, ' ');
  if (!compactLine) return null;

  const amadeusFlight = parseAmadeusLine(compactLine, defaultGdsPnr, defaultAirlinePnr);
  if (amadeusFlight) return amadeusFlight;

  const manual = compactLine.match(/^([A-Z0-9]{5,8})\s+(\d{2}[-/]\d{2}[-/]\d{2,4}|\d{2}[A-Z]{3}(?:\d{2,4})?)\s+([A-Z0-9]{2,3}-?\d{1,4}[A-Z]?)\s+([A-Z]{3})[-/]([A-Z]{3})\s+([#]?\d{3,4}|\d{1,2}:\d{2}(?:\s?[AP]M?)?)\s+([#]?\d{3,4}(?:\+\d+)?|\d{1,2}:\d{2}(?:\s?[AP]M?)?)/i);
  if (manual) {
    const flightToken = manual[3].toUpperCase();
    return {
      gdsPnr: defaultGdsPnr,
      pnr: manual[1].toUpperCase(),
      bookingClass: flightToken.match(/\d([A-Z])$/)?.[1] ?? '',
      baggage: '',
      cabin: 'Economy',
      date: normalizeDate(manual[2]),
      flightNo: flightToken.replace(/[A-Z]$/, ''),
      from: manual[4].toUpperCase(),
      to: manual[5].toUpperCase(),
      departure: normalizeTime(manual[6]),
      arrival: normalizeTime(manual[7]),
    };
  }

  let tokens = compactLine.split(' ').map((token) => token.trim()).filter((token) => token && token !== '.');
  if (tokens.length < 4) return null;
  if (/^\d+[.)]?$/.test(tokens[0])) tokens = tokens.slice(1);

  const dateIdx = tokens.findIndex((token) => isDateToken(token.toUpperCase()));
  if (dateIdx < 0) return null;

  const { flightNo, bookingClass } = buildFlightDetails(tokens, dateIdx);
  const { from, to, routeIdx } = extractRoute(tokens);
  if (!flightNo || !from || !to) return null;

  const timeTokens = tokens.slice(Math.max(dateIdx, routeIdx) + 1);
  const times = extractTimes(timeTokens);

  return {
    gdsPnr: defaultGdsPnr,
    pnr: extractAirlinePnrFromLine(compactLine) || defaultAirlinePnr,
    bookingClass,
    baggage: '',
    cabin: 'Economy',
    date: normalizeDate(tokens[dateIdx]),
    flightNo,
    from,
    to,
    departure: times[0] || '',
    arrival: times[1] || '',
  };
};

const titleToken = 'MR|MRS|MS|MISS|MSTR|MASTER|INF|INFANT|CHD|CNN|YTH';
const embeddedTitleRe = new RegExp(`\\b(${titleToken})\\b`, 'i');

const formatPassengerName = (raw, title) => {
  const cleaned = raw.replace(/\*/g, ' ').replace(/\s+/g, ' ').trim();
  const parts = cleaned.split('/');
  let normalized = cleaned;
  let resolvedTitle = title;

  if (parts.length >= 2) {
    const last = parts[0].trim();
    let first = parts.slice(1).join(' ').trim();
    const embeddedMatch = first.match(embeddedTitleRe);
    if (embeddedMatch) {
      resolvedTitle ||= embeddedMatch[1];
      first = first.replace(embeddedTitleRe, '').replace(/\s+/g, ' ').trim();
    }
    normalized = `${first} ${last}`.replace(/\s+/g, ' ').trim();
  }

  return resolvedTitle ? `${resolvedTitle.toUpperCase()} ${normalized}`.trim() : normalized;
};

const splitPaxSegments = (line) => line.split(/(?=\s+\d+\.\d*[A-Z])/);

const parsePassengers = (lines) => {
  const passengers = [];
  const seen = new Set();
  const paxRegex = new RegExp(`(?:^|\\s)(\\d+)\\.(?:I\\/\\d+|\\d+)?([A-Z][A-Z\\s'\\-]+\\/[A-Z][A-Z '\\-]*)(?:\\s+(${titleToken}))?`, 'gi');
  const amadeusNmRegex = new RegExp(`(?:^|\\s)NM\\d+\\s+([A-Z][A-Z\\s'\\-]+\\/[A-Z][A-Z '\\-]*)(?:\\s+(${titleToken}))?`, 'gi');

  const pushPassenger = (rawName, title) => {
    const name = formatPassengerName(rawName?.trim() || '', title?.trim());
    const key = name.toUpperCase();
    if (!name || seen.has(key)) return;
    seen.add(key);

    passengers.push({
      name,
      passportNo: '',
      ticketNo: '',
      visaPublisher: '',
      visaNo: '',
      notes: '',
    });
  };

  for (const line of lines.flatMap(splitPaxSegments)) {
    const upperLine = line.toUpperCase();
    for (const match of upperLine.matchAll(paxRegex)) {
      pushPassenger(match[2], match[3]);
    }

    for (const match of upperLine.matchAll(amadeusNmRegex)) {
      pushPassenger(match[1], match[2]);
    }
  }

  return passengers;
};

export const parseGdsData = (raw, selectedSource = 'auto') => {
  const lines = raw.split('\n').map((line) => line.trim()).filter(Boolean);

  if (!lines.length) {
    const emptySource = selectedSource === 'auto' ? 'other' : selectedSource;
    return { gds: emptySource, gds_source: emptySource, pnr: '', flights: [], passengers: [], segments: [], booking_reference: '' };
  }

  const detected = detectGdsAndPnr(lines);
  const gds = selectedSource === 'auto' ? (detected.gds || 'other') : selectedSource;
  const taggedGdsPnr = lines.map((line) => line.match(/\bGDS\s+PNR\s*[:-]?\s*([A-Z0-9]{5,8})\b/i)?.[1] ?? '').find(Boolean) ?? '';
  const taggedAirlinePnr = lines.map((line) => line.match(/\bAIRLINE\s+PNR\s*[:-]?\s*([A-Z0-9]{5,8})\b/i)?.[1] ?? '').find(Boolean) ?? '';
  const pnr = taggedGdsPnr || detected.pnr;
  const flights = lines.map((line) => parseLine(line, pnr, taggedAirlinePnr)).filter(Boolean);
  const passengers = parsePassengers(lines);

  return {
    gds,
    gds_source: gds,
    pnr,
    booking_reference: pnr,
    flights,
    segments: flights,
    passengers,
    ticket_info: [],
  };
};
