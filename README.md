# Portal inYice

Portal inYice is a Laravel 13 and React 18 travel-agency order workspace. The current focus is voucher-to-order flow, local GDS text parsing, passenger and flight references, per-passenger service pricing, orders, invoices, payments, reports, and statements.

## Current Stack

- Backend: Laravel 13, PHP 8.3, Laravel Sanctum
- Frontend: React 18, Ant Design, Vite
- Database: MySQL in normal runtime, with Laravel migrations in `database/migrations`
- Runtime support: local PHP/Vite development and Docker compose files

## Key Areas

- Authentication and registration: `app/Http/Controllers/AuthController.php`, `RegistrationController.php`
- API routes: `routes/api.php`
- React shell and navigation: `resources/js/pages/App.jsx`
- Voucher/order workspace: `resources/js/pages/SalesFlow.jsx`
- Sales-flow components: `resources/js/pages/sales-flow`
- Customer module: `resources/js/pages/CustomerList.jsx`
- Vendor module: `resources/js/pages/VendorList.jsx`
- Voucher-to-order backend: `app/Http/Controllers/Api/OrderController.php`
- Customer/vendor selectors and quick-create API: `app/Http/Controllers/Api/MasterDataController.php`
- Invoice and payment APIs: `app/Http/Controllers/Api`

## Project Docs

- [Database relations](DATABASE_RELATIONS.md)
- [User guide](USER_GUIDE.md)
- [Developer guide](DEVELOPER_GUIDE.md)

## Sales Flow Notes

The voucher workspace currently uses a concise tabbed UI:

- Passengers
- Flights, with selectable flight vendor and service-per-passenger pricing inside the Flights tab
- Visa
- Transfer
- Ziarat
- Hotels
- Services

Passenger rows and pricing rows are kept aligned in `SalesFlow.jsx`. The pricing payload is still stored as `voucher.pricing`, with fields for `flight_fare`, `hotel_price`, `visa_price`, `transfer_price`, `city_tour_ziarat_price`, `other_service_price`, and optional `total`. Visa rows store visa type, validity, visa number, selected visa vendor, price, and notes.

## Local Setup

```bash
composer install
npm install
copy .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
php artisan serve
```

For a production asset build:

```bash
npm run build
```

## Useful Checks

```bash
php artisan test
php -l app/Http/Controllers/Api/OrderController.php
npm run build
```

For frontend syntax checks during focused UI edits, parse touched JSX files with `@babel/parser` if available.

## API Shape

Public endpoints include registration, currency/timezone helpers, agency-code checking, and login.

Authenticated API groups include:

- `/api/user`
- `/api/customers`
- `/api/vendors`
- `/api/orders`
- `/api/invoices`
- `/api/payments`
- `/api/accounts`
- `/api/reports`
- `/api/statements`

Route definitions in `routes/api.php` are the source of truth.

## Documentation Policy

Root markdown has been consolidated into this README to avoid stale draft and completion documents. When project behavior changes, update this file with only current, actionable information.
