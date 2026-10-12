export const stripUtcMidnightSuffix = (value, fallback = '-') => {
  const text = String(value ?? '').trim();

  if (!text) {
    return fallback;
  }

  const cleaned = text
    .replace(/[T\s]00:00:00(?:\.000000)?Z?$/i, '')
    .replace(/\s*00:00:00\.000000Z/gi, '')
    .trim();

  return cleaned || fallback;
};

export const dateOnly = (value, fallback = '-') => {
  const cleaned = stripUtcMidnightSuffix(value, '');

  return cleaned ? cleaned.slice(0, 10) : fallback;
};

// Keep the local calendar day when converting a Date to an input/API value.
export const localDateString = (date) => new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
