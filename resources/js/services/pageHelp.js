import { matchPath } from 'react-router-dom';

// Source: documentations/main-project-help-links.txt. Keep private route values out of URLs.
export const PAGE_HELP = [
  ["/", "Dashboard", "https://help.inyice.com/articles/dashboard"],
  ["/login", "Sign In", "https://help.inyice.com/articles/sign-in"],
  ["/register", "Register Your Agency", "https://help.inyice.com/articles/register-your-agency"],
  ["/forgot-password", "Forgot Password", "https://help.inyice.com/articles/forgot-password"],
  ["/reset-password", "Reset Password", "https://help.inyice.com/articles/reset-password"],
  ["/sales-flow", "Create Order", "https://help.inyice.com/articles/create-order"],
  ["/orders", "Orders", "https://help.inyice.com/articles/orders-page"],
  ["/orders/:uid/edit", "Edit Order", "https://help.inyice.com/articles/edit-order"],
  ["/orders/:uid/voucher", "Voucher Details", "https://help.inyice.com/articles/voucher-detail"],
  ["/orders/:uid/quotation", "Quotation Details", "https://help.inyice.com/articles/quotation-detail"],
  ["/invoices", "Invoices", "https://help.inyice.com/articles/invoices-page"],
  ["/invoices/:uid", "Invoice Details", "https://help.inyice.com/articles/invoice-detail"],
  ["/reference-search", "Reference Search", "https://help.inyice.com/articles/reference-search"],
  ["/receivings", "Receivings", "https://help.inyice.com/articles/receivings"],
  ["/profit-shares", "Profit Shares", "https://help.inyice.com/articles/profit-shares"],
  ["/payments", "Customer Receipts", "https://help.inyice.com/articles/customer-receipts"],
  ["/customer-payments", "Customer Payments", "https://help.inyice.com/articles/customer-payments"],
  ["/vendor-payments", "Vendor Payments", "https://help.inyice.com/articles/vendor-payments"],
  ["/vendor-receipts", "Vendor Receipts", "https://help.inyice.com/articles/vendor-receipts"],
  ["/refund-allocations", "Refund Allocation", "https://help.inyice.com/articles/refund-allocation"],
  ["/customers", "Customers", "https://help.inyice.com/articles/customers-page"],
  ["/vendors", "Vendors", "https://help.inyice.com/articles/vendors-page"],
  ["/profile/company", "Company Profile", "https://help.inyice.com/articles/company-profile"],
  ["/profile/company-users", "Company Users", "https://help.inyice.com/articles/team-permissions"],
  ["/profile/user", "User Profile", "https://help.inyice.com/articles/user-profile"],
  ["/reports/aging", "Aging Report", "https://help.inyice.com/articles/aging-report"],
  ["/reports/revenue", "Revenue Report", "https://help.inyice.com/articles/revenue-report"],
  ["/reports/performance", "Performance Report", "https://help.inyice.com/articles/performance-report"],
  ["/reports/profit", "Profit Report", "https://help.inyice.com/articles/profit-report"],
  ["/reports/discounts", "Discount Report", "https://help.inyice.com/articles/discount-report"],
  ["/reports/receipts", "Receipt Report", "https://help.inyice.com/articles/receipt-report"],
  ["/reports/payments", "Payment Report", "https://help.inyice.com/articles/payment-report"],
  ["/reports/cancelled", "Cancelled Report", "https://help.inyice.com/articles/cancelled-report"],
  ["/statements/customers", "Customer Statement", "https://help.inyice.com/articles/customer-statement"],
  ["/statements/vendors", "Vendor Statement", "https://help.inyice.com/articles/vendor-statement"],
  ["/shared/invoices/:token", "Shared Invoice", "https://help.inyice.com/articles/shared-invoice"],
  ["/shared/vouchers/:token", "Shared Voucher", "https://help.inyice.com/articles/shared-voucher"],
  ["/shared/quotations/:token", "Shared Quotation", "https://help.inyice.com/articles/shared-quotation"],
  ['/vat-calculator', 'VAT Calculator', 'https://help.inyice.com/pages'],
];

export function getPageHelp(pathname) {
  const path = pathname.split(/[?#]/)[0];
  const entry = PAGE_HELP.find(([pattern]) => matchPath({ path: pattern, end: true }, path));
  if (entry) return { title: entry[1], href: entry[2] };
  // Internal screens have no published guides; use the directory.
  if (matchPath('/internal/*', path)) return { title: 'Help Center', href: 'https://help.inyice.com/pages' };
  return { title: 'Page Not Found', href: 'https://help.inyice.com/articles/page-not-found' };
}
