const serviceCostSections = [
  ['hotels', 'Hotel'],
  ['transfers', 'Transfer'],
  ['city_tours', 'City tour'],
  ['visa', 'Visa'],
  ['other_services', 'Service'],
];

const toAmount = (value) => {
  if (value === null || value === undefined || value === '') {
    return 0;
  }

  const parsed = Number(String(value).replace(/[^0-9.-]/g, ''));

  return Number.isFinite(parsed) ? parsed : 0;
};

const hasSelectedVendor = (row = {}) => Number(row.vendor_id || 0) > 0;
const hasCost = (row = {}, field = 'cost') => toAmount(row[field]) !== 0;

export const findVoucherCostVendorError = (voucher = {}) => {
  const activeSections = Array.isArray(voucher.active_sections) ? voucher.active_sections : [];
  const hasActiveSection = (section) => activeSections.length === 0 || activeSections.includes(section);

  if (hasActiveSection('flights')) {
    const pricingIndex = (voucher.pricing || []).findIndex((row) => hasCost(row, 'flight_cost') && !hasSelectedVendor(row));

    if (pricingIndex !== -1) {
      return `Select a flight vendor before saving the cost in flight amount row ${pricingIndex + 1}.`;
    }
  }

  for (const [section, label] of serviceCostSections) {
    if (!hasActiveSection(section)) {
      continue;
    }

    const rowIndex = (voucher[section] || []).findIndex((row) => hasCost(row) && !hasSelectedVendor(row));

    if (rowIndex !== -1) {
      return `Select a vendor before saving the cost in ${label.toLowerCase()} row ${rowIndex + 1}.`;
    }
  }

  return '';
};
