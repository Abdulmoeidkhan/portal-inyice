# User Guide

This guide explains how an agency user should use the current Portal inYice workspace.

## Main Purpose

Portal inYice helps a travel agency move from booking data to financial records:

1. Register or sign in.
2. Maintain company profile, company users, customers, and vendors.
3. Parse GDS or enter voucher details manually in Create Order.
4. Create a quotation/order.
5. Convert the order into an invoice.
6. Share voucher or invoice links when needed.
7. Record customer receipts, customer payments, vendor receipts, or vendor payments.
8. Review dashboard, reports, statements, and reference search.

## Registering A New Agency

Open `/register`.

Registration has four steps:

1. Agency Info
2. Company Details
3. Admin Account
4. Complete

Agency Code must be lowercase alphanumeric, at least 3 characters, and unique. The registration screen checks availability before continuing.

Company Details include legal name, company email, phone, billing address, base currency, and timezone. Base currency is used for reporting and default account setup.

The Admin Account step creates the initial owner/admin user. Passwords must be at least 8 characters.

When registration succeeds:

- A tenant is created.
- A company profile is created.
- Owner, Admin, Sales, and Accounts roles are created.
- The initial user is created and signed in.
- A default `Main Cash Box` account is created.

## Signing In And Sessions

Open `/login`, then enter email and password.

After login, the app stores the Sanctum token and user profile in browser local storage. The top profile menu contains User Profile and Logout. Inactive users cannot sign in, and active sessions expire after four hours of inactivity.

## App Navigation

The sidebar contains:

- Dashboard
- Finance: Create Order, Orders, Invoices, Reference Search, Customer Receipts, Customer Payments, Vendor Receipts, Vendor Payments
- Master Data: Customers, Vendors
- Profiles: Company Profile, Company Users
- Reports: Aging Report, Revenue Report, Profit Report, Receipt Report, Payment Report, Cancelled Report, Customer Statement, Vendor Statement

Sales users do not see the Reports group. Cancelled Report is shown to owner/admin users. The top bar contains the Ocean/Slate/Sand theme selector, light/dark toggle, and profile menu.

## Dashboard

Dashboard is the landing page after login. Use it for high-level operational visibility and quick movement into finance/reporting workflows.

## Create Order

Open Finance -> Create Order.

The workspace has four top-level tabs:

- GDS Parser
- Voucher Fields
- Create Quotation/Order
- Convert Invoice

### GDS Parser

Use this when you have pasted GDS text. The parser can pre-fill booking reference, passenger rows, flight rows, GDS source, and ticket references. Backend parsing currently accepts Sabre and Galileo; the frontend can also label Amadeus or Other data for manual review.

Always review passenger names, ticket numbers, routes, PNRs, dates, times, and pricing before creating the order.

### Voucher Fields

Voucher header fields include:

- Voucher number
- Issue date
- Travel type
- Package type
- Booking reference
- GDS source
- Emergency contact
- Company/executive contact information
- Active optional service sections

Detailed tabs include:

- Passengers
- Flights
- Visa
- Transfer
- Ziarat
- Hotels
- Services

Optional sections are controlled from the voucher header.

### Passengers

Passenger fields include name, passport number, ticket number, visa publisher, visa number, and notes.

Adding a passenger adds a matching pricing row. Removing a passenger removes the matching pricing row when possible. Updating a passenger name updates the matching pricing passenger name unless the pricing row was manually changed.

### Flights And Pricing

Flight fields include GDS PNR, airline PNR, date, flight number, from/to airports, departure/arrival, cabin, booking class, baggage, and flight vendor.

Pricing is inside the Flights tab and is passenger-based. Pricing fields include passenger, flight cost, flight profit, flight amount/sales, and optional total.

Flight references create zero-value traceability rows on the order. Sales/amount fields create customer-facing order items. Vendor-linked cost fields create supplier payable rows for vendor payments and profit reporting.

### Visa, Transfer, Ziarat, Hotels, And Services

Service rows can store operational details, selected vendors, cost, profit, sales/amount, and notes where the screen exposes them.

- Visa rows include passenger name, visa type, validity, visa number, visa vendor, cost, profit, sales/amount, and notes.
- Transfer rows include transfer/service details, vendor, cost, profit, sales/amount, and notes.
- Ziarat rows include city/tour/date details, vendor, cost, profit, sales/amount, and notes.
- Hotel rows include hotel/stay details, lead passenger, vendor, cost, profit, sales/amount, and notes.
- Service rows include description, vendor, cost, profit, and sales/amount.

## Creating A Quotation Or Order

Open the Create Quotation/Order tab.

Typical inputs:

- Customer
- Optional order vendor
- Currency
- Status
- Notes

Customers and vendors are searchable. Use `+ Add Customer` or `+ Add Vendor` in the dropdown when a record does not exist yet.

When submitted:

- The voucher payload is sent to `/api/v1/orders/create-from-voucher`.
- The backend creates an order and stores voucher detail in `orders.meta`.
- Searchable voucher fields are copied to order columns.
- Order items are generated from flights, pricing, hotels, transfers, ziarat, visa, and other services.
- Vendor costs are generated for vendor-linked cost rows.
- The order total is calculated from generated order items.

## Orders And Vouchers

Open Finance -> Orders.

Use Orders to:

- View order/voucher records.
- Open voucher detail.
- Edit editable orders.
- Share a public voucher link.
- Revoke sharing.
- Create refund requests where available.
- Delete orders when permitted.

Shared voucher links open through `/shared/vouchers/:token` and do not require the recipient to sign in.

Editing an order that already has a live invoice may cancel the current invoice and create a replacement order for manual invoicing. This preserves financial history instead of silently changing posted invoice data.

## Converting To Invoice

Open the Convert Invoice tab after creating an order.

Invoice conversion copies order items into invoice lines. The invoice tracks invoice number, invoice date, due date, currency, subtotal, tax amount, total amount, outstanding amount, advance balance, status, and FX rate to base.

Company monthly invoice limits are enforced during invoice creation.

## Invoices

Open Finance -> Invoices.

Use this area to:

- View invoices.
- Inspect invoice details.
- Copy a public invoice share link.
- Mark invoices as sent.
- Add a discount.
- Create a refund request from an invoice's order.
- Void invoices when allowed.
- Cancel invoices when allowed.
- Check aging status.

Invoice statuses include:

- Draft
- Issued
- Sent
- Partial paid
- Paid
- Overdue
- Void
- Cancel

Shared invoice links open through `/shared/invoices/:token` and do not require the recipient to sign in.

## Receipts And Payments

Portal inYice separates cash movement by direction.

- Customer Receipts: money received from customers.
- Customer Payments: money paid back to customers, including refunds.
- Vendor Receipts: money received from vendors.
- Vendor Payments: money paid to vendors/suppliers.

### Customer Receipts

Open Finance -> Customer Receipts.

1. Select the customer.
2. Load open invoices.
3. Choose receipt date, method, optional cash/bank account, reference, and description.
4. Select one or more invoices.
5. Adjust allocations for partial receipts.
6. Record the receipt when the receipt total matches the selected allocations.

One selected invoice creates a normal receipt. Multiple selected invoices create one bulk receipt with a settlement against each invoice.

### Vendor Payments

Open Finance -> Vendor Payments.

Select a vendor, choose one or more payable orders, adjust allocations, and record the payment. One payment number is retained even when the payment is allocated across several orders.

### Customer Payments And Vendor Receipts

Open Finance -> Customer Payments or Vendor Receipts for the other cash-direction workflows. These screens use the shared counterparty transaction interface for recording, updating, and deleting supported transactions.

Cash and bank selections create ledger deposits or withdrawals when an account is supplied.

## Master Data

### Customers

Open Master Data -> Customers.

Customer fields include name, type, email, phone, address, city, country code, postal code, tax id, and currency code. Customers created here immediately become available in order, invoice, report, statement, and receipt workflows.

### Vendors

Open Master Data -> Vendors.

Vendor fields include name, type, email, phone, address, city, country code, postal code, tax id, currency code, and payment terms. Vendors become available in order and service vendor selectors, vendor payments, vendor receipts, reports, and statements.

## Reference Search

Open Finance -> Reference Search.

Use it to search across orders, invoices, customers, vendors, receipts, and payments. Filters include general text, PNR, airline PNR, internal reference, folder/order/invoice numbers, passenger/customer data, ticket number, destination, status, payment status, date ranges, and amount ranges.

Results are scoped to the signed-in user's tenant and company.

## Reports

### Aging Report

Open Reports -> Aging Report to review outstanding invoices by aging bucket.

### Revenue Report

Open Reports -> Revenue Report to review revenue over a selected period and grouping. The signed-in user's company is used automatically.

### Profit Report

Open Reports -> Profit Report to review gross profit over a selected period. The View selector switches between customer-wise, vendor-wise, and staff-wise reporting. Choose All or a specific customer/vendor/staff member before the list is shown.

Profit uses invoiced order revenue minus supplier costs from `order_vendor_costs`. Vendor-wise view allocates multi-vendor order revenue proportionally across supplier cost rows.

### Receipt And Payment Reports

Receipt Report shows money received. Payment Report shows money paid. Both can be filtered by counterparty, method, date, and search text.

### Cancelled Report

Open Reports -> Cancelled Report to review cancelled invoices over a selected date range. This report is available to owner/admin users.

## Statements

Customer Statement and Vendor Statement use the signed-in user's company automatically. Vendor payable rows include invoiced orders only, excluding void/cancelled invoices.

## Profiles And Users

### Company Profile

Open Profiles -> Company Profile to review or update company details. The screen also shows configured monthly invoice and user limits.

### Company Users

Open Profiles -> Company Users to list company users and create new admin, sales, or accounts users. Owner/admin users can create company users until the company `user_limit` is reached.

### User Profile

Open the profile dropdown and choose User Profile to review signed-in user details.

## Roles And Permissions

- Owner/admin users manage company users and broad operational setup.
- Sales users can create voucher orders and work operational sales flows.
- Accounts users work with invoices, receipts, payments, accounts, reports, and statements.
- Some read/list endpoints are available to any authenticated active user.

If an action is unavailable, confirm the user is active, has the correct role, has a valid token, and is working with records from the same tenant/company.

## Practical Operating Rules

- Always review parsed GDS output before creating an order.
- Keep passenger names consistent across voucher tabs.
- Use pricing inside Flights for passenger-level flight amounts.
- Use service tabs for operational details, supplier references, costs, and sales values.
- Confirm customer and currency before creating an order.
- Use active sections to keep the voucher UI focused.
- Use public share links only for records intended to be visible outside the signed-in workspace.
