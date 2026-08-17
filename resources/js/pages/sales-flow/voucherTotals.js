export const toAmount = (value) => {
  if (value === null || value === undefined || value === '') {
    return 0;
  }

  const normalized = String(value).replace(/[^0-9.-]/g, '');
  const parsed = Number(normalized);

  return Number.isFinite(parsed) ? parsed : 0;
};

export const firstFilled = (...values) => values.find((value) => value !== null && value !== undefined && String(value).trim() !== '') || '';

export const salesAmount = (row = {}) => toAmount(firstFilled(row.sales, row.amount));
export const costAmount = (row = {}) => toAmount(row.cost);

const roundMoney = (value) => Math.round((Number(value || 0) + Number.EPSILON) * 10000) / 10000;

export const calculateVoucherGrossTotal = (voucher = {}) => {
  let total = 0;

  (voucher.pricing || []).forEach((row) => {
    total += toAmount(firstFilled(row.flight_sales, row.flight_fare, row.total));
  });

  ['visa', 'transfers', 'city_tours', 'hotels', 'other_services'].forEach((section) => {
    (voucher[section] || []).forEach((row) => {
      total += salesAmount(row);
    });
  });

  return roundMoney(total);
};

export const calculateVoucherDiscounts = (voucher = {}, grossTotal = calculateVoucherGrossTotal(voucher)) => {
  let remainingBase = Math.max(0, Number(grossTotal || 0));

  return (voucher.discounts || [])
    .filter((discount) => discount && typeof discount === 'object')
    .map((discount, index) => {
      const discountType = discount.discount_type === 'percentage' ? 'percentage' : 'amount';
      const inputValue = discountType === 'percentage' ? toAmount(discount.percentage) : toAmount(discount.amount);
      const amount = discountType === 'percentage'
        ? roundMoney(remainingBase * (Math.min(inputValue, 100) / 100))
        : roundMoney(Math.min(inputValue, remainingBase));

      remainingBase = Math.max(0, remainingBase - amount);

      return {
        ...discount,
        key: discount.key || discount.uid || `discount-${index}`,
        discount_type: discountType,
        percentage: discountType === 'percentage' ? inputValue : null,
        amount: discountType === 'amount' ? inputValue : discount.amount,
        computed_amount: amount,
      };
    })
    .filter((discount) => discount.computed_amount > 0);
};

export const calculateVoucherTotals = (voucher = {}) => {
  const grossTotal = calculateVoucherGrossTotal(voucher);
  const discounts = calculateVoucherDiscounts(voucher, grossTotal);
  const discountTotal = roundMoney(discounts.reduce((sum, discount) => sum + Number(discount.computed_amount || 0), 0));

  return {
    grossTotal,
    discounts,
    discountTotal,
    netTotal: roundMoney(Math.max(0, grossTotal - discountTotal)),
  };
};
