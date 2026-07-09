# User Guide

This guide explains how an agency user should use the current Portal inYice workspace.

## Main Purpose

Portal inYice helps a travel agency move from booking data to financial records:

1. Register or sign in.
2. Parse GDS or enter voucher details manually.
3. Create a quotation/order.
4. Convert the order into an invoice.
5. Record payments, refunds, or advances.
6. Review dashboard, aging, revenue, and statement data.

## Registering A New Agency

Open `/register`.

Registration has four steps:

1. Agency Info
2. Company Details
3. Admin Account
4. Complete

### Agency Info

Enter:

- Agency Code
- Agency Name

Agency Code rules:

- Lowercase alphanumeric
- Minimum 3 characters
- Must be unique

The screen checks code availability before allowing the user to continue.

### Company Details

Enter:

- Legal Company Name
- Company Email
- Phone
- Billing Address
- Base Currency
- Timezone

The base currency is important because internal reporting and account defaults use it.

### Admin Account

Enter:

- Admin Name
- Admin Email
- Password
- Confirm Password

Password must be at least 8 characters.

When registration succeeds:

- A tenant is created.
- A company profile is created.
- Admin, Sales, and Accounts roles are created for that tenant.
- The admin user is created.
- A default `Main Cash Box` account is created.
- The user is signed in automatically after a short redirect.

## Signing In

Open `/login`.

Enter:

- Email
- Password

After login, the app stores the Sanctum token and user profile in browser local storage. Use the profile dropdown in the top bar to log out.

If a user account is inactive, login is blocked.

## App Navigation

The sidebar contains:

- Dashboard
- Finance
- Quotes & GDS
- Invoices
- Payments
- Master Data
- Customers
- Vendors
- Profiles
- Company Profile
- Reports
- Aging Report
- Revenue Report
- Profit Report

The top bar contains:

- Theme style selector: Ocean, Slate, Sand
- Light/dark toggle
- Profile menu
- Logout

## Dashboard

The dashboard is the landing page after login. Use it as the starting point for financial and operational visibility.

Typical use:

- Review high-level business state.
- Move into Finance for order/invoice/payment workflows.
- Move into Reports for aging and revenue views.

## Quotes & GDS

Open Finance -> Quotes & GDS.

This workspace has four top-level tabs:

- GDS Parser
- Voucher Fields
- Create Quotation/Order
- Convert Invoice

### GDS Parser

Use this when you have pasted GDS text.

Current parser behavior:

- Frontend local parsing can pre-fill booking reference, flights, and passengers.
- Backend parse endpoint exists for Sabre and Galileo.
- The current voucher flow can also label parsed data as Amadeus or Other on the frontend side.

Steps:

1. Paste the raw GDS text.
2. Parse it.
3. Review parsed passenger and flight count.
4. Continue to Voucher Fields.

The parser is intended to reduce manual entry, not replace review. Always check passenger names, ticket numbers, routes, PNRs, dates, times, and pricing before creating the order.

### Voucher Fields

Voucher Fields contains header data and detailed tabs.

Header information includes:

- Voucher number
- Issue date
- Travel type
- Package type
- Booking reference
- GDS source
- Emergency contact
- Company/executive contact information
- Active optional service sections

The detailed voucher tabs are:

- Passengers
- Flights
- Visa
- Transfer
- Ziarat
- Hotels
- Services

Optional sections are controlled from the voucher header. If a service section is not active, its tab is not shown.

### Passengers Tab

Use this for traveler-level reference data.

Fields:

- Name
- Passport No
- Ticket No
- Visa Publisher
- Visa No
- Notes

Adding a passenger also adds a matching pricing row. Removing a passenger removes the matching pricing row when possible. Updating a passenger name updates the matching pricing passenger name unless the pricing name was manually changed.

### Flights Tab

Use this for flight segment references and passenger/service pricing.

Flight fields:

- GDS PNR
- Airline PNR
- Date
- Flight No
- From
- To
- Departure
- Arrival
- Cabin
- Booking Class
- Baggage
- Flight Vendor selected from the vendor list

Airport fields show a helper label when the airport code is known in `airportLookup.js`.

Pricing is inside the Flights tab and is service-per-passenger.

Pricing fields:

- Passenger
- Flight cost
- Flight profit
- Flight amount/sales
- Total (optional)

Pricing behavior:

- Each row represents one passenger.
- Component prices create separate order items.
- If component prices are blank but `Total (optional)` is filled, the total creates a package total item.
- Other Service pricing is included in the order item calculation.

### Visa Tab

Use this for visa reference rows.

Fields:

- Passenger Name
- Visa Type: Visit, Umrah, Hajj, Tourist, or Other
- Validity
- Visa Number
- Visa Vendor selected from the vendor list
- Cost
- Profit
- Amount/Sales
- Notes

### Transfer Tab

Use this for transfer service rows.

Fields:

- TN
- Service
- From
- To
- Vehicle
- Contact Person
- Transfer Vendor selected from the vendor list
- Cost
- Profit
- Amount/Sales
- Notes

### Ziarat Tab

Use this for city tour or ziarat rows.

Fields:

- City
- Tour Title
- Attractions
- Date
- Ziarat Vendor selected from the vendor list
- Cost
- Profit
- Amount/Sales
- Notes

### Hotels Tab

Use this for hotel rows.

Fields:

- HCN
- City
- Hotel Name
- Room Type
- Check In
- Check Out
- Lead Passenger
- Hotel Vendor selected from the vendor list
- Cost
- Profit
- Amount/Sales
- Notes

### Services Tab

Use this for other service rows.

Fields:

- Description
- Vendor selected from the vendor list
- Cost
- Profit
- Amount/Sales

## Creating A Quotation Or Order

Open the Create Quotation/Order tab inside Quotes & GDS.

Typical inputs:

- Customer
- Optional vendor
- Currency
- Status
- Notes

Customers and vendors are selected from searchable lists. If the required customer or vendor does not exist, use the `+ Add Customer` or `+ Add Vendor` action in the dropdown to create it without leaving the order screen.

## Master Data

### Customers

Open Master Data -> Customers.

Use this module to create customers before using them in quotations, orders, invoices, reports, and statements.

Customer fields:

- Name
- Type: B2C or B2B
- Email
- Phone
- Address
- City
- Country Code
- Currency Code

Customers created here immediately become available in the customer selector on the Create Quotation/Order tab.

### Vendors

Open Master Data -> Vendors.

Use this module to create suppliers once and reuse them across voucher services. Vendors are currently selectable for:

- Order vendor
- Flight vendor
- Visa vendor
- Hotel, transfer, ziarat, and other service vendors

They are also ready for upcoming service modules such as hotels, transport, ziarat, and other supplier-based workflows.

Vendor fields:

- Name
- Type: B2C or B2B
- Email
- Phone
- Address
- City
- Country Code
- Currency Code
- Payment Terms

Vendors created here immediately become available in order and service vendor selectors.

When submitted:

- The voucher payload is sent to `/api/v1/orders/create-from-voucher`.
- The backend creates an order.
- The voucher detail is stored in `orders.meta`.
- Order items are generated from flights, pricing, hotels, transfers, ziarat, visa, and other services.
- The order total is calculated from generated order item totals.

Order item rules:

- Flight references are kept as zero-value traceability rows.
- Passenger pricing creates priced line items per service.
- Hotel, transfer, ziarat, visa, and other service amount/sales fields can also create priced line items.
- Vendor cost fields create supplier cost rows for profit reporting and vendor payables.
- Visa order item descriptions include visa type, visa number, validity, and visa vendor when provided.
- Flight reference rows include flight vendor when provided.
- If no items are generated, a zero-value `Voucher Booking` item is created for traceability.

## Converting To Invoice

Open the Convert Invoice tab after creating an order.

Use it to create an invoice from the generated order.

The backend copies order items into invoice lines through the invoice service. The invoice tracks:

- Invoice number
- Invoice date
- Due date
- Currency
- Subtotal
- Tax amount
- Total amount
- Outstanding amount
- Advance balance
- Status

## Invoices

Open Finance -> Invoices.

Use this area to:

- View invoices.
- Inspect invoice details.
- Mark invoices as sent.
- Void invoices when allowed.
- Check aging status.
- Start payment workflows where the screen supports it.

Invoice statuses include:

- Draft
- Issued
- Sent
- Partial paid
- Paid
- Overdue
- Void

## Customer Receipts And Vendor Payments

Open Finance -> Customer Receipts to record money received from a customer.

1. Select the customer. The allocation grid loads their open invoices.
2. Enter the receipt date and choose Cash, Bank, Card, or Cheque.
3. Optionally select the matching cash/bank account and enter a reference and description.
4. Tick one or more invoices. Select all fills every open balance; each allocation can be reduced for a partial receipt.
5. Confirm that Receipt total matches the money received, then choose Record receipt.

One selected invoice creates a normal receipt. Multiple selected invoices create one bulk receipt with a settlement against each invoice. A receipt cannot exceed any selected invoice balance, and invoices must belong to the same customer, company, and currency. The Receipt history tab shows the receipt number, method, reference, allocated invoices, and total.

Open Finance -> Vendor Payments to record money paid to a supplier. Select a vendor, choose one or more payable orders, adjust any partial allocations, and record the payment. One payment number is retained even when the payment is allocated across several orders. Older payments created before order allocation support are applied to the oldest payable orders when the grid calculates remaining balances.

Cash and bank selections create the corresponding ledger deposit or withdrawal when an account is supplied. Refund and advance endpoints remain available for invoice-specific accounting workflows.

## Reports

### Aging Report

Open Reports -> Aging Report.

Use this to review outstanding invoices by aging bucket.

### Revenue Report

Open Reports -> Revenue Report.

Use this to review revenue over a selected period and grouping.

The report uses the signed-in user's company automatically.

### Profit Report

Open Reports -> Profit Report.

Use this to check gross profit over a selected period. The View selector switches between customer-wise, vendor-wise, and staff-wise reporting. After choosing the view, select All or one customer/vendor/staff member before the report list is shown.

Profit is calculated from invoiced order revenue minus supplier costs captured from voucher flight costs and visa vendor amounts. Draft quotations and uninvoiced orders are not included. Customer-wise and staff-wise views show the full invoiced order revenue and cost under the customer or staff member. Vendor-wise view allocates each invoiced order's revenue proportionally across its supplier cost rows so multi-vendor orders are not double-counted.

The company is selected automatically from the signed-in user.

The report includes:

- Summary profit by currency
- Grouped totals with revenue, cost, profit, and margin
- Order-level details for checking vouchers, PNRs, customers, vendors, staff, and status
- CSV export for the grouped and detail data

Customer Statement and Vendor Statement also use the signed-in user's company automatically. Vendor payable rows include invoiced orders only.

## Profiles

### Company Profile

Open Profiles -> Company Profile.

Use this to review company identity and base details loaded from the authenticated user/company context.

### User Profile

Open the profile dropdown and choose User Profile.

Use this to review the signed-in user's basic account details.

## Practical Operating Rules

- Always review parsed GDS output before creating an order.
- Keep passenger names consistent across passengers, visa rows, hotel lead passenger, and pricing.
- Use pricing inside Flights for service-per-passenger amounts.
- Use service tabs for operational details and supplier/reference notes.
- Use order notes for internal context that should travel with the order.
- Confirm customer and currency before creating an order because invoice and payment flows depend on them.
- Use active sections to keep the voucher UI concise.

## Roles And Permissions

Current role behavior is enforced by API middleware:

- Admin can access management and financial actions.
- Sales can parse GDS and create voucher orders.
- Accounts can work with invoices, payments, and accounts.
- Some read/list endpoints are available to any authenticated active user.

If a user cannot perform an action, confirm:

- The user is active.
- The user has the correct role.
- The token is valid.
- The record belongs to the same tenant.
