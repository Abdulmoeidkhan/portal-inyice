# Developer Guide

This guide describes the current Portal inYice codebase and the conventions to follow when changing it.

## Project Stack

- Laravel 13.11
- PHP 8.4 runtime, with Composer requiring `^8.3`
- Laravel Sanctum 4
- React 18
- Ant Design 6
- Vite 8
- Laravel Boost and MCP tooling in development
- Docker compose support

## Important Commands

Install dependencies:

```bash
composer install
npm install
```

Create a local environment:

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

Run tests and focused checks:

```bash
php artisan test
php artisan route:list
php -l app/Http/Controllers/Api/OrderController.php
```

Do not run `npm run build` unless a production asset build is explicitly requested or approved. For frontend-only edits, prefer focused JSX parsing or targeted checks.

## Directory Map

### Backend

- `routes/api.php`: `/api/v1` routes and middleware.
- `routes/web.php`: public web posts and React fallback.
- `app/Http/Controllers/AuthController.php`: Sanctum login/logout.
- `app/Http/Controllers/RegistrationController.php`: tenant, company, owner, roles, and default cash account registration.
- `app/Http/Controllers/Api/OrderController.php`: order list/detail/edit/delete, GDS parsing, voucher order creation, voucher sharing, refund requests.
- `app/Http/Controllers/Api/InvoiceController.php`: invoice list/detail, create-from-order, share/revoke, discount, mark-sent, void, cancel, shared invoice reads.
- `app/Http/Controllers/Api/PaymentController.php`: customer/vendor receipts and payments, invoice settlements, advances, refunds, update/delete operations.
- `app/Http/Controllers/Api/MasterDataController.php`: customers, vendors, staff selectors, and quick-create APIs.
- `app/Http/Controllers/Api/CompanyProfileController.php`: company profile show/update.
- `app/Http/Controllers/Api/CompanyUserController.php`: owner/admin company user management with company user limits.
- `app/Http/Controllers/Api/InternalPortalController.php`: provider/internal company and user management.
- `app/Http/Controllers/Api/ReferenceSearchController.php`: tenant/company scoped cross-reference search.
- `app/Http/Controllers/Api/ReportController.php`: dashboard upcoming, aging, revenue, profit, receipt, payment, cancelled, and customer summary reports.
- `app/Http/Controllers/Api/StatementController.php`: customer and vendor statements.
- `app/Models`: Eloquent models and relationships.
- `app/Services`: business services for GDS parsing, invoices, ledgers, payments, reports, statements, audit, tenancy, and order numbers.
- `app/Traits/TenantAware.php`: tenant scoping support.
- `database/migrations`: schema.
- `database/seeders`: seed data.

### Frontend

- `resources/js/pages/App.jsx`: authenticated shell, routes, role-gated navigation, idle logout, public shared routes, internal user routing.
- `Login.jsx`, `Register.jsx`: auth screens.
- `Dashboard.jsx`: operational landing dashboard.
- `SalesFlow.jsx`: Create Order workspace orchestration.
- `sales-flow/*`: voucher parser, row sections, preview, summary, defaults, and helpers.
- `OrderList.jsx`, `OrderEdit.jsx`, `VoucherDetail.jsx`: order management.
- `InvoiceList.jsx`, `InvoiceDetail.jsx`: invoice management and public invoice view.
- `Payments.jsx`: customer receipts.
- `VendorPayments.jsx`: vendor payments.
- `CounterpartyTransaction.jsx`: customer payments and vendor receipts.
- `CustomerList.jsx`, `VendorList.jsx`: master data.
- `ReferenceSearch.jsx`: broad reference search.
- `CompanyProfile.jsx`, `CompanyUsers.jsx`, `UserProfile.jsx`: profile/user management.
- `InternalPortal.jsx`: provider/internal operations portal.
- `AgingReport.jsx`, `RevenueReport.jsx`, `ProfitReport.jsx`, `PaymentReport.jsx`, `CancelledReport.jsx`: reports.
- `CustomerStatement.jsx`, `VendorStatement.jsx`: statements.

## Authentication And Sessions

Authentication uses Laravel Sanctum tokens.

Login flow:

1. Frontend posts to `/api/v1/auth/login`.
2. Backend validates email/password and rejects inactive users.
3. Backend returns a token and user payload, including role/system-user flags.
4. Frontend stores `auth_token`, `token`, `user`, and an idle activity timestamp in local storage.

The React shell signs users out after four hours of inactivity. Logout posts to `/api/v1/auth/logout`, clears local storage, and redirects to `/login`.

Protected routes use `auth:sanctum`, `active.user`, role middleware, system-role middleware, and sensitive-write throttling where appropriate.

## Registration And Company Users

Registration endpoint:

- `POST /api/v1/registration/register`

Registration creates a tenant, company, `owner`, `admin`, `sales`, and `accounts` tenant roles, the owner user, a default `Main Cash Box`, and an owner Sanctum token.

Supporting public endpoints:

- `GET /api/v1/registration/currencies`
- `GET /api/v1/registration/timezones`
- `GET /api/v1/registration/check-code`

Company user management:

- `GET /api/v1/company-users`
- `POST /api/v1/company-users`

Only owner/admin users can list and create company users. New company users can be assigned `admin`, `sales`, or `accounts`. The company `user_limit` controls capacity; the default is 4 users unless changed by the internal portal.

## Multi-Tenant Rules

- Business data must be scoped by authenticated `tenant_id` and usually `company_id`.
- Prefer existing model scopes and `TenantAware`.
- Use `uid` for public/detail references where controllers already do so.
- Never trust raw `id` values without tenant/company checks.
- Public registration, auth, and shared invoice/voucher token reads are the intentionally unauthenticated business exceptions.
- Report and statement screens use the signed-in user's company; do not add frontend company selectors without explicit authorization design.

## Role Rules

Tenant roles:

- `owner`
- `admin`
- `sales`
- `accounts`

System roles:

- `super-admin`
- `inyice-admin`
- `support-executive`

Owner users satisfy admin-level checks in the `User` model. Internal provider routes use `system-role:super-admin,inyice-admin,support-executive`, with some actions limited to `super-admin`.

Examples:

- GDS parse and voucher order creation: `admin`, `sales`
- Invoice create/share: `admin`, `sales`, `accounts`
- Invoice sent/discount/void/cancel: `admin`, `accounts`
- Receipt/payment/account mutations: `admin`, `accounts`
- Company profile update: `owner`
- Company user management: `owner`, `admin`
- Cancelled report: owner/admin in the UI and general authenticated API route
- Financial reports/statements: `admin`, `accounts`

## Sales Flow Architecture

`SalesFlow.jsx` owns the top-level state for active tab, voucher payload, parse result, created order, created invoice, and loading flags.

Voucher payload fields include:

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

The detailed UI tabs are Passengers, Flights, Visa, Transfer, Ziarat, Hotels, and Services. Pricing stays inside Flights and remains stored as `voucher.pricing`.

Passenger/pricing alignment behavior:

- Adding a passenger adds a pricing row.
- Removing a passenger removes the matching pricing row where possible.
- Editing a passenger name updates the matching pricing row if it was not manually changed.

When changing voucher fields, update the frontend defaults/UI, `SalesFlow.jsx` alignment if needed, `OrderController::buildVoucherOrderItems()`, vendor cost generation, and the docs.

## Voucher To Order Backend

Endpoint:

- `POST /api/v1/orders/create-from-voucher`

Flow:

1. Validate customer/vendor/company/currency/status and voucher shape.
2. Resolve authenticated tenant/company.
3. Merge company/user contact data into voucher contact.
4. Create the order and copy searchable voucher fields onto order columns.
5. Store the full voucher in `orders.meta`.
6. Build order items from flight references, passenger pricing, visa, hotel, transfer, ziarat, and other service rows.
7. Build `order_vendor_costs` from vendor-linked cost rows.
8. Sum order items into `orders.total_amount`.

Generated order behavior:

- Flight rows create zero-amount traceability items.
- Passenger pricing creates sellable order items.
- Service sales fields create customer-facing order items.
- Service/vendor cost fields create supplier payable rows.
- A fallback `Voucher Booking` item is created when no item rows are generated.

## Orders, Sharing, And Editing

Current order APIs include list, show, update, delete, share, revoke share, refund request, parse GDS, and create-from-voucher.

Shared voucher links are created from authenticated order actions and read publicly through:

- Backend: `GET /api/v1/shared-vouchers/{token}`
- Frontend: `/shared/vouchers/:token`

Orders use soft deletes. Editing an already-invoiced order can cancel the current invoice and create a replacement order that can be invoiced manually, preserving an audit trail instead of silently mutating posted financial records.

## Invoices

Invoice APIs include list, show, create-from-order, share/revoke share, mark sent, discount, void, cancel, and aging status.

Shared invoice links are read publicly through:

- Backend: `GET /api/v1/shared-invoices/{token}`
- Frontend: `/shared/invoices/:token`

Invoice statuses include:

- `draft`
- `issued`
- `sent`
- `partial_paid`
- `paid`
- `overdue`
- `void`
- `cancel`

Invoice creation enforces the company's monthly invoice limit. Discounting adds a negative invoice line and recalculates totals/outstanding. Cancelled invoices are removed from the active invoice list and included in Cancelled Report.

## Receipts, Payments, And Accounts

Money-in endpoints live under `/api/v1/receipts`; money-out endpoints live under `/api/v1/payments`.

Current supported workflows:

- Customer receipt against one invoice.
- Bulk customer receipt across multiple invoices.
- Customer advance and advance application.
- Customer payment/refund.
- Vendor receipt.
- Vendor payment against one or more payable orders.
- Receipt/payment detail, update, and delete where routes expose it.
- Ledger deposits/withdrawals for cash and bank accounts when an account is supplied.

Customer allocations use `invoice_settlements`. Vendor allocations use `vendor_payment_allocations`.

When changing money behavior:

- Use transactions for multi-table updates.
- Keep invoice outstanding values, account balances, ledger entries, and allocation totals consistent.
- Validate that no allocation exceeds live outstanding/payable balances.
- Preserve tenant/company scope.

## Reports, Statements, And Search

Report endpoints include:

- `GET /api/v1/reports/dashboard-upcoming`
- `GET /api/v1/reports/aging`
- `GET /api/v1/reports/revenue`
- `GET /api/v1/reports/profit`
- `GET /api/v1/reports/receipts`
- `GET /api/v1/reports/payments`
- `GET /api/v1/reports/cancelled`
- `GET /api/v1/reports/customer-summary`

Statement endpoints:

- `GET /api/v1/statements/customer/{customerId}`
- `GET /api/v1/statements/vendor/{vendorId}`

Reference search:

- `GET /api/v1/reference-search`

Reference search is tenant/company scoped and can search across orders, invoices, customers, vendors, receipts, and payments by references, passenger/customer fields, dates, statuses, and amount ranges.

## Internal Portal

System users are routed to `/internal/*`. The internal portal can:

- List/search non-internal companies.
- Inspect company usage, users, recent orders, invoices, payments, and receipts.
- Update company monthly invoice and user limits.
- Block/unblock companies.
- Manage internal users.
- Reset internal user passwords.
- View selected orders and invoices across tenants.

Use `withoutGlobalScopes()` intentionally and narrowly in internal APIs. Never expose the provider tenant (`INYICE`) in normal agency lists.

## Database Change Rules

When adding tenant-owned tables:

- Include `tenant_id`.
- Include `company_id` where data is company-specific.
- Include `uid` when records are referenced outside internal joins.
- Add indexes for tenant/company, foreign keys, status, date, and search filters as needed.
- Add Eloquent relationships and casts.
- Update `DATABASE_RELATIONS.md`, `USER_GUIDE.md`, and this guide.

## Verification Checklist

For PHP/backend changes:

- `php -l` touched PHP files
- `php artisan test` when tests exist or behavior is shared/risky
- `php artisan route:list` when routes changed
- Manual API checks for new/changed endpoints

For React/frontend changes:

- Parse touched JSX when possible
- Check route rendering
- Check mobile and desktop layout for dense operational screens
- Run `npm run build` only when explicitly requested or approved

For sales-flow changes:

- Parse sample GDS text
- Confirm passengers, flights, pricing, and service rows populate
- Create an order and verify order items/totals/vendor costs
- Convert an invoice and verify invoice lines

## Documentation Rules

Current root docs:

- `README.md`
- `DATABASE_RELATIONS.md`
- `USER_GUIDE.md`
- `DEVELOPER_GUIDE.md`
- `AGENTS.md`

`AGENTS.md` is workflow guidance for coding agents. Keep product behavior in README, user, developer, database, and project brief docs. Avoid root-level temporary planning or completion Markdown.
