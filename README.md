# Portal inYice

Portal inYice, branded in the UI as InYice OS, is a Laravel and React travel-finance workspace for agencies. The current product covers tenant registration, company setup, limited company users, customer/vendor master data, GDS-assisted voucher capture, order creation, invoice conversion, receipts, payments, statements, reports, public sharing links, and an internal provider portal.

## Current Stack

- Backend: Laravel 13.11, PHP 8.4 runtime, Laravel Sanctum
- Frontend: React 18, Ant Design 6, Vite 8
- Database: Laravel migrations for the relational schema; this environment is connected to SQLite through Laravel Boost, while local/runtime environments may use MySQL-compatible migrations
- Runtime support: local PHP/Vite development and Docker compose files

## Main Product Areas

- Public registration and login: `app/Http/Controllers/RegistrationController.php`, `app/Http/Controllers/AuthController.php`
- Authenticated API routes: `routes/api.php`, under `/api/v1`
- React shell and navigation: `resources/js/pages/App.jsx`
- Create Order workspace: `resources/js/pages/SalesFlow.jsx` and `resources/js/pages/sales-flow`
- Order list, edit, voucher detail, and sharing: `OrderList.jsx`, `OrderEdit.jsx`, `VoucherDetail.jsx`
- Invoices and invoice sharing: `InvoiceList.jsx`, `InvoiceDetail.jsx`
- Customer/vendor master data: `CustomerList.jsx`, `VendorList.jsx`, `MasterDataController.php`
- Customer receipts, customer payments, vendor receipts, vendor payments: `Payments.jsx`, `VendorPayments.jsx`, `CounterpartyTransaction.jsx`, `PaymentController.php`
- Reports and statements: `ReportController.php`, `ReportService.php`, `StatementController.php`, `StatementService.php`
- Company profile and company users: `CompanyProfileController.php`, `CompanyUserController.php`
- Internal provider portal: `InternalPortal.jsx`, `InternalPortalController.php`
- Cross-reference search: `ReferenceSearch.jsx`, `ReferenceSearchController.php`

## Financial Terminology

Cash movement names are direction-based:

- **Receipt:** money received by the company. Customer receipts settle customer invoices or advances. Vendor receipts record money received from vendors, such as rebates or returned overpayments.
- **Payment:** money paid by the company. Vendor payments settle supplier/order payables. Customer payments record money paid back to customers, including refunds.

The finance screens are Customer Receipts, Customer Payments, Vendor Receipts, and Vendor Payments. Receipt Report contains money-in records; Payment Report contains money-out records. Reports use the signed-in user's company automatically.

## Current Workflow

1. Register a tenant/company or sign in.
2. Create company users within the company's configured user limit.
3. Maintain customers and vendors.
4. Use Create Order to parse GDS text or enter voucher data manually.
5. Create a quote/order from the voucher.
6. Review orders, edit editable orders, share voucher links when needed, or request refunds.
7. Convert orders into invoices.
8. Share invoices, mark sent, discount, void, cancel, or settle them through receipts/payments.
9. Review dashboard, aging, revenue, profit, receipt, payment, cancelled, statement, and reference-search views.

## Sales Flow Notes

The Create Order workspace has four top-level tabs:

- GDS Parser
- Voucher Fields
- Create Quotation/Order
- Convert Invoice

Voucher detail tabs are:

- Passengers
- Flights, including flight vendor and per-passenger pricing
- Visa
- Transfer
- Ziarat
- Hotels
- Services

The voucher payload is stored on orders in `orders.meta`, with selected search fields also copied to order columns such as `voucher_no`, `issue_date`, `package_type`, `active_sections`, and `emergency_contact`. Supplier costs are stored in `order_vendor_costs` for vendor payables and profit reporting.

## API Shape

Public endpoints include registration, currency/timezone helpers, agency-code checking, login, and shared invoice/voucher reads.

Authenticated API groups include:

- `/api/v1/user`
- `/api/v1/company-users`
- `/api/v1/company-profile`
- `/api/v1/internal`
- `/api/v1/customers`
- `/api/v1/vendors`
- `/api/v1/staff`
- `/api/v1/reference-search`
- `/api/v1/orders`
- `/api/v1/invoices`
- `/api/v1/payments`
- `/api/v1/receipts`
- `/api/v1/accounts`
- `/api/v1/reports`
- `/api/v1/statements`

Route definitions in `routes/api.php` are the source of truth.

## Local Setup

```bash
composer install
npm install
copy .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
npm run dev
```

Run production frontend builds only when explicitly needed:

```bash
npm run build
```

## Useful Checks

```bash
php artisan test
php artisan route:list
php -l app/Http/Controllers/Api/OrderController.php
```

For focused frontend edits, parse touched JSX with `@babel/parser` when available instead of running a full build by default.

## Project Docs

- [Database relations](DATABASE_RELATIONS.md)
- [User guide](USER_GUIDE.md)
- [Developer guide](DEVELOPER_GUIDE.md)

## Documentation Policy

Keep root documentation current with shipped behavior only. Avoid adding temporary planning, completion, or scratch Markdown files in the project root.
