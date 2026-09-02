export const refundSignedFields = new Set([
  'amount',
  'cost',
  'cost_base',
  'sales',
  'total',
  'flight_cost',
  'flight_cost_base',
  'flight_sales',
  'flight_fare',
  'hotel_cost',
  'hotel_sales',
  'visa_cost',
  'visa_sales',
  'transfer_cost',
  'transfer_sales',
  'city_tour_ziarat_cost',
  'city_tour_ziarat_sales',
  'other_service_cost',
  'other_service_sales',
]);

const toNumber = (value) => {
  if (value === null || value === undefined || value === '') {
    return 0;
  }

  const parsed = Number(String(value).replace(/[^0-9.-]/g, ''));
  return Number.isFinite(parsed) ? parsed : null;
};

export const moneyValue = (value) => {
  const rounded = Math.round((Number(value || 0) + Number.EPSILON) * 100) / 100;
  return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(2);
};

export const refundInputValue = (value) => {
  if (value === '' || value === null || value === undefined) {
    return null;
  }

  const amount = toNumber(value);
  return amount === null ? value : moneyValue(Math.abs(amount));
};

export const signedRefundValue = (value, fallback = '0') => {
  const nextValue = value ?? fallback;
  if (nextValue === '') {
    return nextValue;
  }

  const amount = toNumber(nextValue);
  return amount === null ? nextValue : moneyValue(-Math.abs(amount));
};

export const coerceRefundVoucherValues = (payload) => {
  if (Array.isArray(payload)) {
    return payload.map((item) => coerceRefundVoucherValues(item));
  }

  if (!payload || typeof payload !== 'object') {
    return payload;
  }

  return Object.entries(payload).reduce((next, [key, value]) => {
    if (value && typeof value === 'object') {
      next[key] = coerceRefundVoucherValues(value);
      return next;
    }

    next[key] = refundSignedFields.has(key) && value !== null && value !== undefined && value !== ''
      ? signedRefundValue(value, value)
      : value;
    return next;
  }, {});
};
