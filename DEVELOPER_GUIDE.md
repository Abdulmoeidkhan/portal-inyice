# Developer Guide

This guide explains the current project structure and the conventions to follow when changing Portal inYice.

## Project Stack

- Laravel 13
- PHP 8.3
- Laravel Sanctum
- React 18
- Ant Design
- Vite
- MySQL-oriented migrations
- Docker compose support

## Important Commands

Install dependencies:

```bash
composer install
npm install
```

Create local environment:

```bash
copy .env.example .env
php artisan key:generate
php artisan migrate
```

Run development servers:

```bash
php artisan serve
npm run dev
```

Build frontend assets:

```bash
npm run build
```

Run tests:

```bash
php artisan test
```

Useful focused checks:

```bash
php -l app/Http/Controllers/Api/OrderController.php
php artisan route:list
```

For focused JSX edits, parse touched files with `@babel/parser` when available.

## Directory Map

### Backend

- `app/Http/Controllers/AuthController.php`: login/logout token flow
- `app/Http/Controllers/RegistrationController.php`: tenant/company/owner registration
- `app/Http/Controllers/Api/OrderController.php`: order listing, GDS parse endpoint, voucher-to-order creation
- `app/Http/Controllers/Api/MasterDataController.php`: customer/vendor list and quick-create endpoints
- `app/Http/Controllers/Api/InvoiceController.php`: invoice workflows
- `app/Http/Controllers/Api/PaymentController.php`: payment/refund/advance workflows
- `app/Http/Controllers/Api/AccountController.php`: cash and bank account APIs
- `app/Http/Controllers/Api/ReportController.php`: reporting APIs
- `app/Http/Controllers/Api/StatementController.php`: statement APIs
- `app/Models`: Eloquent models and relationships
- `app/Services`: business services
- `app/Traits/TenantAware.php`: tenant scoping support
- `routes/api.php`: API routes
- `routes/web.php`: React app fallback and public web auth routes
- `database/migrations`: schema
- `database/seeders`: seed data

### Frontend

- `resources/js/pages/App.jsx`: authenticated layout, routes, navigation
- `resources/js/pages/Login.jsx`: sign in
- `resources/js/pages/Register.jsx`: agency registration wizard
- `resources/js/pages/Dashboard.jsx`: dashboard
- `resources/js/pages/SalesFlow.jsx`: voucher/GDS/order/invoice workspace orchestration
- `resources/js/pages/CustomerList.jsx`: customer master-data module
- `resources/js/pages/VendorList.jsx`: vendor master-data module
- `resources/js/pages/InvoiceList.jsx`: invoices
- `resources/js/pages/Payments.jsx`: payments
- `resources/js/pages/AgingReport.jsx`: aging report
- `resources/js/pages/RevenueReport.jsx`: revenue report
- `resources/js/pages/CompanyProfile.jsx`: company profile
- `resources/js/pages/UserProfile.jsx`: user profile
- `resources/js/pages/sales-flow`: focused sales-flow components and helpers

## Authentication

Authentication uses Laravel Sanctum tokens.

Login flow:

1. Frontend posts to `/api/v1/auth/login`.
2. Backend validates email/password.
3. Backend rejects inactive users.
4. Backend returns a token and user payload.
5. Frontend stores `auth_token`, `token`, and `user` in local storage.

Logout flow:

1. Frontend posts to `/api/v1/auth/logout`.
2. Backend deletes the current token.
3. Frontend clears local storage and redirects to `/login`.

Protected routes use:

- `auth:sanctum`
- `active.user`
- role middleware where needed

## Registration Flow

Registration endpoint:

- `/api/v1/registration/register`

Registration creates:

- `tenants` row
- tenant roles: `owner`, `admin`, `sales`, `accounts`
- `companies` row
- owner `users` row
- default `cash_accounts` row named `Main Cash Box`
- Sanctum token for the owner

Supporting endpoints:

- `/api/v1/registration/currencies`
- `/api/v1/registration/timezones`
- `/api/v1/registration/check-code`

## Multi-Tenant Rules

Most business data belongs to a tenant.

Development rules:

- Always scope reads and writes by authenticated user's `tenant_id`.
- Prefer `uid` for public/detail identifiers where existing controllers use it.
- Do not let raw `id` access cross tenant boundaries.
- New models that contain tenant-owned business data should include `tenant_id`.
- New Eloquent models should use `TenantAware` if they follow existing tenant scoping behavior.
- Registration and login are the main public flows.

## Role Rules

Current role codes:

- `owner`
- `admin`
- `sales`
- `accounts`
- `super-admin` for provider/system behavior

The initial signup user is assigned `owner`. Owner users satisfy admin-level permission checks.

Route examples:

- GDS parse and voucher order creation: `admin`, `sales`
- Invoice create-from-order: `admin`, `sales`, `accounts`
- Invoice sent/void actions: `admin`, `accounts`
- Payment/account mutations: `admin`, `accounts`

When adding endpoints:

- Decide whether the route is public, authenticated, or role-restricted.
- Add role middleware in `routes/api.php`.
- Keep read/list behavior tenant-scoped even when no role middleware is applied.

## Sales Flow Architecture

### Frontend Modules

`SalesFlow.jsx` owns state for:

- active top-level tab
- voucher payload
- parse result
- created order
- created invoice
- loading flags

Sales-flow components:

- `GdsParserCard.jsx`: paste/parse UI
- `VoucherHeaderCard.jsx`: voucher header and active sections
- `VoucherRowsSections.jsx`: tabbed passenger/service UI
- `OrderInvoiceCards.jsx`: create order and convert invoice cards
- `RowGroupCard.jsx`: reusable row card wrapper
- `defaults.js`: blank row factories and parsed-payload mapping
- `gdsParser.js`: frontend local parser
- `airportLookup.js`: airport code helper labels

### Voucher Shape

The voucher payload includes:

- `voucher_no`
- `issue_date`
- `travel_type`
- `package_type`
- `booking_reference`
- `gds_source`
- `gds_parsed_record_id`
- `emergency_contact`
- `contact`
- `active_sections`
- `flights`
- `passengers`
- `pricing`
- `hotels`
- `transfers`
- `city_tours`
- `visa`
- `other_services`

### Current Voucher UI

The detailed voucher UI is tabbed:

- Passengers
- Flights
- Visa
- Transfer
- Ziarat
- Hotels
- Services

Pricing is intentionally inside the Flights tab.

Pricing remains stored in `voucher.pricing` because the backend order builder expects that shape.

Flight rows now also support selectable vendors:

- `vendor_id`
- `vendor_name`

Visa rows now support:

- `passenger_name`
- `visa_type`
- `validity`
- `visa_no`
- `vendor_id`
- `visa_vendor`
- `amount`
- `notes`

### Passenger/Pricing Alignment

`SalesFlow.jsx` keeps passengers and pricing rows aligned:

- Adding a passenger adds a blank pricing row.
- Removing a passenger removes the matching pricing row when possible.
- Editing passenger name updates the matching pricing row if the pricing name was not manually changed.

Keep this behavior intact unless the backend payload shape is changed at the same time.

## Voucher To Order Backend

Endpoint:

- `POST /api/v1/orders/create-from-voucher`

Controller:

- `app/Http/Controllers/Api/OrderController.php`

Flow:

1. Validate customer/vendor/company/currency/status and voucher shape.
2. Resolve authenticated user tenant.
3. Resolve company inside the same tenant.
4. Merge company/user contact into voucher contact.
5. Create `orders` row.
6. Store full voucher payload in `orders.meta`.
7. Build `order_items` from voucher rows.
8. Sum order item totals into `orders.total_amount`.

Pricing component map:

- `flight_fare` -> Flight Fare
- `hotel_price` -> Hotel
- `visa_price` -> Visa
- `transfer_price` -> Transfer
- `city_tour_ziarat_price` -> City Tour/Ziarat
- `other_service_price` -> Other Service

Important behavior:

- Flight rows create zero-amount traceability items and include the flight vendor in the description when present.
- Pricing component amounts create per-passenger priced items.
- If only `total` is provided, it creates a package total item.
- Visa rows include visa type, visa number, validity, and visa vendor in their order item description.
- Service rows with amount fields also create order items.
- A fallback `Voucher Booking` item is created if no items are generated.

## Customer And Vendor Modules

Order creation uses searchable customer and vendor selects. Dedicated Customer and Vendor modules are available from the sidebar under Master Data.

API endpoints:

- `GET /api/v1/customers`
- `POST /api/v1/customers`
- `GET /api/v1/vendors`
- `POST /api/v1/vendors`

Frontend API helpers live in `resources/js/services/salesFlowApi.js`.

Frontend modules:

- `resources/js/pages/CustomerList.jsx`
- `resources/js/pages/VendorList.jsx`

The create-order card can quick-create a customer or vendor from the dropdown. Created records are scoped to the authenticated user's tenant and default company unless a `company_id` is supplied.

Flight and visa vendor fields in `VoucherRowsSections.jsx` use the same vendor list. They store both the selected vendor id and display name in voucher metadata so order item descriptions remain readable.

## GDS Parsing

Backend service:

- `app/Services/GdsParserService.php`

Backend endpoint:

- `POST /api/v1/orders/parse-gds`

Accepted backend `gds_source` values:

- `sabre`
- `galileo`

Frontend parser files:

- `resources/js/pages/sales-flow/gdsParser.js`
- `resources/js/pages/sales-flow/defaults.js`

Parsed payload is mapped into:

- booking reference
- GDS source
- passengers
- flights/segments
- ticket info

When extending parser support:

- Add parsing behavior in frontend and/or backend intentionally.
- Keep field names compatible with `buildVoucherFromParsed()`.
- Add or update fixture-like manual test strings.
- Verify passenger names, flight number, route, date, departure, arrival, ticket number, and fare.

## Invoice And Payment Flow

Invoice conversion starts from created orders.

Relevant services:

- `InvoiceService.php`
- `PaymentService.php`
- `LedgerService.php`
- `ReportService.php`
- `StatementService.php`

Invoice model tracks:

- subtotal
- tax amount
- total amount
- outstanding amount
- advance balance
- status
- FX rate to base

Payment behavior includes:

- record customer receipt against one invoice
- record one bulk customer receipt across multiple invoices
- list customer receipts with invoice allocations
- record vendor payment against one or many payable orders
- list vendor order balances and payment allocations
- record refund
- record advance
- apply advance
- invoice settlements
- ledger entries for cash/bank accounts

The two financial workbenches are `resources/js/pages/Payments.jsx` (customer receipts) and `resources/js/pages/VendorPayments.jsx` (vendor payments). Both keep per-document allocation amounts in client state and post one atomic transaction. Customer allocations use `invoice_settlements`; vendor allocations use `vendor_payment_allocations`.

Key endpoints:

- `GET /api/v1/payments/customer`
- `POST /api/v1/payments/record`
- `POST /api/v1/payments/record-bulk`
- `GET /api/v1/payments/vendor`
- `GET /api/v1/payments/vendor/{vendorId}/payables`
- `POST /api/v1/payments/vendor`

When changing payment behavior:

- Keep settlement and invoice outstanding calculations consistent.
- Keep account balance and ledger entries synchronized.
- Preserve tenant scoping.
- Use transactions for multi-table money updates.
- Validate that allocation totals equal the receipt/payment header total and that no row exceeds its live outstanding balance.

## Frontend Conventions

Use existing patterns:

- Ant Design components
- React functional components
- Local component state where the page already uses it
- Service modules for API calls where present
- Compact operational UI, not marketing-style layouts

For sales-flow rows:

- Use `RowGroupCard` for repeatable row sections.
- Use factories from `defaults.js` for blank rows.
- Update both frontend shape and backend order builder when adding priced fields.
- Keep active sections controlled through `VoucherHeaderCard`.

## API Conventions

Current API base path:

- `/api/v1`

API route groups:

- `/registration`
- `/auth`
- `/user`
- `/customers`
- `/vendors`
- `/orders`
- `/invoices`
- `/payments`
- `/accounts`
- `/reports`
- `/statements`

Controller responses are JSON.

When adding endpoints:

- Validate request data in the controller or form request.
- Use transactions for multi-write operations.
- Return explicit success flags when existing endpoints do so.
- Use `uid` in response payloads for external references.
- Avoid leaking unrelated tenant data through relationship eager loading.

## Database Change Rules

When adding a table:

- Include `tenant_id` for tenant-owned business data.
- Include `uid` when records may be referenced outside internal database joins.
- Add indexes for tenant, foreign keys, status, and date filters as needed.
- Add Eloquent relationships on both sides when useful.
- Add casts for dates, JSON, booleans, and decimals.

When changing a voucher/pricing field:

- Update `defaults.js`.
- Update `VoucherRowsSections.jsx`.
- Update `SalesFlow.jsx` if row alignment is affected.
- Update `OrderController::buildVoucherOrderItems()`.
- Update `MasterDataController.php` or sales-flow selectors if customer/vendor behavior changes.
- Update `DATABASE_RELATIONS.md`, `USER_GUIDE.md`, and this guide.

## Verification Checklist

For PHP/backend changes:

- `php -l` touched PHP files
- `php artisan test` when tests exist for the area
- `php artisan route:list` when routes changed
- Manual API test for new endpoints

For React/frontend changes:

- Parse touched JSX
- `npm run build` when allowed
- Verify route renders
- Check mobile and desktop layout for tabbed/row UIs

For sales-flow changes:

- Parse sample GDS text
- Confirm passengers populate
- Confirm flights populate
- Confirm pricing remains inside Flights
- Add/remove passenger and confirm pricing rows stay aligned
- Create order and confirm order items/totals
- Convert invoice and confirm invoice lines

## Documentation Rules

Current root docs:

- `README.md`
- `DATABASE_RELATIONS.md`
- `USER_GUIDE.md`
- `DEVELOPER_GUIDE.md`

Keep these files current when behavior changes. Avoid adding temporary planning or completion-summary markdown in the project root; stale docs have already caused confusion.
