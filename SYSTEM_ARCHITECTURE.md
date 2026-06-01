# inYice-Lite System Architecture (MVP)

Date: May 20, 2026
Architecture: Modular Monolith
Deployment: Docker on VPS with Traefik routing

## 1. Goals

- Build fast with clear module boundaries.
- Keep tenant data fully isolated.
- Support operational bookkeeping with complete auditability.
- Keep customer-facing financial documents in original transaction currency.
- Keep internal consolidated reporting in company base currency.

## 2. High-Level Architecture

Single Laravel project (backend + web UI) with internal modules:
- Identity and Access
- Tenant and Company
- Party Management (Customer and Vendor)
- Order Lifecycle
- GDS Parsing
- Finance (Invoices, Receipts, Payments, Refunds, Advances)
- Statements and Reporting
- Audit Logging

UI delivery model:
- React + Ant Design UI bundled inside the same Laravel project.
- Laravel serves API and web app from one codebase.
- Role-based routes and table-first pages with filter panels.
- Print/share views for customer-facing documents.

Data layer:
- MySQL primary database
- Redis for cache/queue support
- App-managed audit logs in MySQL
- Currency and exchange-rate tables in MySQL

## 3. Deployment Topology (Docker + Traefik)

Core services:
- app: Laravel application container (PHP-FPM + app code)
- web: Nginx container serving Laravel public files and proxying PHP requests
- worker: Laravel queue worker (same image/code as app)
- scheduler: Laravel scheduler (same image/code as app)
- mysql: primary DB
- redis: cache and queue backend

Routing:
- Traefik routes domain to the Nginx web container.
- API and web UI are served by the same Laravel project.
- HTTPS termination handled by Traefik.
- For Docker manager environments, Traefik labels and external network binding are applied via a compose override file.

Build artifact:
- One Dockerfile builds the Laravel runtime image used by app/worker/scheduler.

## 4. Tenant Isolation Strategy

- Every business table contains tenant_id.
- Tenant context resolved at request boundary.
- Query guard enforces tenant_id filters in repositories/services.
- Provider super-admin routes are explicit and isolated from agency routes.

## 5. Identity and Permissions

Authentication:
- Laravel Sanctum for session/token auth.
- Google social login supported for user sign-in bootstrap.

Authorization:
- Agency roles: Admin, Sales, Accounts.
- Provider role: super-admin (cross-tenant visibility only for provider context).

## 6. Currency and Financial Rules

Currency set:
- PKR, USD, GBP, EUR, SAR, AED, INR.

Company base currency:
- Set during company setup.
- Used for internal consolidated reporting.

Document currency:
- Orders/invoices/payments can be created in supported transaction currency.
- Customer-facing invoices remain in original document currency.
- External customer/vendor statements remain in original transaction currency.

FX rates:
- Prefer free API source when available.
- Admin manual entry/update fallback is mandatory.
- Manual override always available.

Settlement behavior:
- Partial payment and partial refund supported.
- Overpayment allowed and stored as advance balance.
- Advance application to invoices is manual only.

## 7. Module Responsibilities

1. Identity and Access
- Sign-in, social auth callback, session management
- Role permission checks

2. Tenant and Company
- Company profile and base currency setup
- Tenant-level settings and defaults

3. Party Management
- Customer and vendor masters
- B2B relationship mapping
- Private B2C ownership boundaries

4. Order Lifecycle
- Quote -> Order -> Confirm/Cancel -> Invoice/Refund/Void transitions
- Transition validation and status history

5. GDS Parsing
- Manual paste input endpoint
- Sabre/Galileo parsing rules
- Structured output attached to order items
- Uses airline.json and airports.json for code-to-data lookups and enrichment
- Airline pictures/logos retrieved via IATA code from airline.json
- Airport details resolved via airport IATA code from airports.json

6. Finance
- Invoice creation and posting
- Receipt/payment vouchers (cash and bank)
- Refund handling
- Advance balance tracking and manual application

7. Statements and Reporting
- Invoice report with filters
- Customer statement with filters
- Vendor statement with filters
- Internal consolidated totals in base currency

8. Audit Logging
- Sign-in, create/edit/delete, lifecycle transitions
- Who/what/when storage model

## 8. Data Contract Principles

- Immutable posting snapshots for financial documents.
- Status histories are append-only.
- Externally exposed entity references use UID (ULID), not numeric ids.
- Amount precision uses decimal fixed-point fields.
- Currency code stored per document and per line item where needed.
- Converted base amounts stored for reporting efficiency.
- JSON columns used for GDS parsing results, audit change logs, and report snapshots.
- JSON querying kept at app layer in v1; no complex DB JSON functions.
- Eloquent models use casts for automatic JSON encoding/decoding.

## 9. API Style

- REST-style module endpoints under /api/v1.
- Consistent pagination and filter contracts for table views.
- Standard response envelope for list/detail/error.
- Idempotency key support for payment posting endpoints.
- Route/resource identifiers are UID-based for security and non-enumerability.
- Web routes and SPA shell are served from the same Laravel app.

## 10. Build-First Priority

1. Docker baseline (Dockerfile, compose, Traefik labels, env templates)
2. Tenant context and role permissions
3. Company/customer/vendor masters
4. Order lifecycle + audit trail
5. Invoice/payment/refund/advance flows
6. Statements and reports
7. GDS parser integration
8. Deployment hardening and UAT
