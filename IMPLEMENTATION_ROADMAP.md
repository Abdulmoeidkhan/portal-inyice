# inYice-Lite Implementation Roadmap

Date: May 20, 2026
Planning Mode: AI-assisted rapid delivery
Deployment Constraint: Docker on VPS with Traefik

## 1. Delivery Strategy

Goal: launch usable MVP fast, then harden and expand.

Approach:
- Phase A (Immediate Foundation): set up architecture and core security/data boundaries.
- Phase B (Core Operations): tenant, users, parties, orders, lifecycle, audit.
- Phase C (Financial and Reporting): invoice-focused tables, statements, payments.
- Phase D (Stabilize and Release): QA pass, deployment hardening, go-live checks.

## 2. MVP Scope Locked

Functional:
- Multi-tenant agency isolation
- Company profile
- Fixed agency staff roles (Admin, Sales, Accounts)
- Customer and vendor management (B2B and private B2C)
- Order lifecycle: Quote -> Order -> Confirm/Cancel -> Invoice/Refund/Void
- GDS manual paste parser (Sabre/Galileo text)
- Audit logging for all required actions
- Reporting for all major modules
- Table-first UI with filters
- Invoice report
- Customer statement
- Vendor statement
- Payment module:
  - Receipt vouchers
  - Payment vouchers
  - Cash transactions
  - Bank transactions
  - Partial payment handling
  - Partial refund handling
  - Overpayment handling as advance balance
  - Advance application to invoices: manual only
- Multi-currency support: PKR, USD, GBP, EUR, SAR, AED, INR
- FX rate strategy: free API integration if available, with mandatory admin manual entry fallback
- Base currency is selected per company during setup and used for tenant-level consolidated reporting
- Customer-facing documents are presented in original document currency
- Internal reporting totals are presented in company base currency for v1 simplicity

Technical:
- Single Laravel project (modular monolith) for API + web app
- React + Ant Design UI integrated in Laravel build pipeline (Vite)
- MySQL as primary store
- Redis for queue/cache
- Sanctum auth + Google social login
- Dockerized runtime via one Laravel Dockerfile and docker-compose services
- Traefik-compatible routing labels on web service

## 3. Module Build Order (Fastest Path)

1. Platform foundation
- Dockerfile + docker-compose + env templates + container entrypoint
- Tenant resolver and tenant-safe middleware
- Auth and role permissions (Admin, Sales, Accounts, provider super-admin)
- Base audit logging hook

2. Master data
- Company profile
- Customer and vendor masters (B2B links + private B2C)
- Company-level base currency configuration

3. Order engine
- Order numbering and booking references
- Lifecycle transitions with strict transition rules
- Notes and status history
- Ownership tracking (created by and updated by)

4. GDS parsing
- Raw text input UI + parser endpoint
- Structured extraction for PNR, passenger, segments, source, ticket data
- Attach parsed result to order items
- Load airline.json and airports.json as static lookup references
- Enrich parsed segments with airline name/picture and airport details from JSON lookups

5. Financial records
- Invoice generation and status tracking
- Receipt and payment vouchers
- Cash and bank account ledgers (basic)
- Currency selection and per-document FX rate locking
- Exchange-rate source management (API-fed rates + manual admin overrides)
- Outstanding balance tracking with partial settlement/refund support
- Advance balance ledger handling for overpayments
- Manual advance-application workflow during invoice settlement

6. Statements and reports
- Invoice report table with filters
- Customer statement table with filters
- Vendor statement table with filters
- Common export-ready report query layer
- Base-currency-only statement output for v1

Customer document rule:
- Invoice/receipt/payment print/share views must render in original document currency.
- Customer/vendor statements shared externally must render in original transaction currency.

7. Release prep
- Basic smoke tests for top flows
- Docker Compose validation and Traefik labels
- VPS environment config and backup plan

## 4. Suggested Fast Timeline From Today

Day 0 to Day 1:
- Single Laravel repository structure and Docker baseline
- Laravel app bootstrap with React + Ant Design frontend shell
- Auth, tenant context, role skeleton

Day 2 to Day 4:
- Company, customer, vendor modules
- Core order lifecycle and audit events

Day 5 to Day 6:
- GDS manual parser v1 (Sabre/Galileo patterns)
- Invoice + payment voucher entities

Day 7 to Day 8:
- Invoice/customer/vendor statement reports
- Table filters and usability pass

Day 9:
- End-to-end testing, bug fixes, demo data

Day 10:
- VPS deployment via Docker manager + Traefik
- UAT and production checklist

## 5. Data Model Priorities

Must-have entities first:
- tenants
- companies
- users
- roles
- customers
- vendors
- orders
- order_status_history
- order_items
- invoices
- receipts
- payments
- cash_accounts
- bank_accounts
- currencies
- exchange_rates
- audit_logs

Statement and reporting support:
- ledger_entries
- document_links
- statement_balances_by_currency

## 6. Non-Functional Baseline

- Tenant isolation enforced in every query path.
- Audit trail is immutable at app layer.
- Role-based access control for all actions.
- Pagination and server-side filtering for large tables.
- Timezone-safe date storage and display strategy.

## 7. Immediate Next Documents

1. System architecture and module boundaries
2. Database schema draft (tables, keys, constraints)
3. API contract draft for each module
4. UI route map and page-level feature matrix
5. Deployment templates and runtime files:
  - Dockerfile
  - docker-compose.yml
  - .env.example.docker
  - Traefik labels for web service
