// Reuse the locale formatter across table cells without changing rounding or fallbacks.
const moneyFormatter = new Intl.NumberFormat(undefined, {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

export const formatMoney = (value) => moneyFormatter.format(Number(value || 0));
