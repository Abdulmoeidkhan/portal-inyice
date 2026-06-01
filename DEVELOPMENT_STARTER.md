# inYice-Lite Development Starter

Date: May 20, 2026
Source: PROJECT_BRIEF.txt

## 1. Confirmed Product Scope

inYice-Lite is a multi-tenant B2B and B2C order-bookkeeping platform for travel agencies.

Included:
- Company profile management
- Limited agency staff management (3 roles/users per agency)
- Customer and vendor network management
- Order bookkeeping with lifecycle tracking
- Activity logging and auditability
- Reporting module across all major records
- Table-first data presentation with filters
- Invoice and customer statement reporting
- Vendor statement reporting
- Payment module (receipt and payment vouchers for cash and bank)

Explicitly excluded:
- Expense management
- Attendance management
- Shift management

## 2. Tenant and Access Rules

- Agency data is isolated by tenant.
- Agency A cannot access Agency B data.
- Only platform provider has global cross-tenant visibility.

## 3. Company and Staff Rules

- Agency has one company profile.
- Agency users are limited to three role-based accounts:
  - Admin
  - Sales
  - Accounts
- Platform provider account can create unlimited employees.
- All staff actions must be audit-logged.

## 4. Customer and Vendor Model

B2B:
- Agencies can be customer/vendor counterparts of other agencies.
- Relationship is explicit and business-record driven.

B2C:
- Each agency owns its own customer records.
- B2C customer records are private to the owning tenant.

## 5. Order Lifecycle (Core)

Lifecycle:
- Quote -> Order -> Confirm or Cancel -> Invoice or Refund or Void

Each order must track:
- Order number and booking reference (PNR)
- Customer and vendor links
- Staff ownership (created by and updated by)
- Notes and status history

## 6. GDS Parsing Requirement

From GDS PNR text, system should capture:
- Booking reference (PNR)
- Passenger details and PTC
- Segment flight details (airline, route, dates/times, status)
- GDS source marker (for example Sabre or Galileo)
- Ticket-related details for bookkeeping

## 7. Audit Logging (Mandatory)

Track at minimum:
- User sign-in
- Order creation
- Lifecycle transitions (Quote, Order, Confirm, Cancel, Invoice, Refund, Void)
- Order edits
- Order deletes

Audit fields:
- Who acted
- What changed
- When changed

## 8. Development Decisions Needed (Open)

Status: Closed.

No open blocking decisions for MVP kickoff.

## 8.1 Confirmed Deployment Constraint

- Application must run on Docker.
- Deployment target uses a VPS Docker manager.
- Domain routing is handled by Traefik.
- System design should produce containerized services with Traefik-compatible labels/routing.
- Runtime startup must be supported via Dockerfile and docker-compose for isolated environments.

## 8.2 Confirmed Architecture Decision

- v1 architecture: Modular Monolith.
- Reason: lower VPS/ops cost like a monolith, with cleaner module boundaries for future growth.
- Packaging approach: single Laravel project repository.

## 8.3 Confirmed Application Stack

- Backend: Laravel.
- Frontend: React + Ant Design integrated in Laravel via Vite build.
- UI library: Ant Design.
- Data model direction: RDBMS-first design.

## 8.4 Confirmed Database Decision

- Database engine for v1: MySQL.

## 8.5 Confirmed Authentication Decision

- Primary auth: Laravel Sanctum.
- Include social login in v1 (Google as first provider; architecture should allow adding more providers later).

## 8.6 Confirmed Logging and Audit Storage Decision

- v1 logging and audit storage: MySQL only.
- App/system logs can be expanded later if operational complexity increases.

## 8.7 Confirmed GDS Decision

- v1 GDS approach: manual paste parser only.
- Source formats in scope for parser rules: Sabre and Galileo pasted PNR text.
- Live GDS API integration is out of v1 scope.

## 8.8 Confirmed Delivery Mode

- Development mode: AI-assisted implementation.
- Planning assumption: tighter iteration cycles and frequent review checkpoints.

## 8.9 Confirmed Timeline Decision

- Target start: today (immediate execution).
- Delivery style: fast phased rollout, with must-have modules first.

## 8.10 Confirmed Reporting and Payment Add-ons

- System should include reporting for all major operational and financial records.
- Data views should be table-based with filter controls as default UX.
- Must include invoice reports.
- Must include customer statement reports.
- Must include vendor statement reports.
- Must include payment module:
  - Receipt entries
  - Payment entries
  - Cash mode support
  - Bank mode support
  - Partial payment support
  - Partial refund support
  - Overpayment allowed and tracked as advance balance
  - Advance balance application: manual only

## 8.11 Confirmed Multi-Currency Decision

- v1 must support multi-currency operations.
- Currencies in scope: PKR, USD, GBP, EUR, SAR, AED, INR.
- This applies to orders, invoices, receipts, payments, and statements.

## 8.12 Confirmed FX Rate Strategy

- Preferred approach: use a free exchange-rate API if reliable and available.
- Fallback approach: Admin can manually create/update exchange rates.
- System should always allow manual override to protect operations if API is down.

## 8.13 Confirmed Base Currency Rule

- Base currency is not global.
- Each company defines its own base currency during company setup.
- Consolidated totals and internal reporting for that tenant use the company base currency.

## 8.14 Confirmed Statement Display Rule

- Customer-facing financial documents must stay in document currency.
  - Example: invoice created in SAR must be shown/sent in SAR, even if customer/company base is PKR.
- External customer and vendor statements must also stay in original transaction currency.
- Internal reporting and consolidated totals should be shown in company base currency.
- Keep v1 simple: no dual-display on the same customer-facing document.

## 9. Initial MVP Modules

- Dockerized runtime baseline (single Laravel image reused by app, worker, scheduler)
- Tenant and company setup
- Role-based staff management (Admin, Sales, Accounts)
- Customer/vendor master data
- Order lifecycle engine
- GDS text parser service
- Audit log service
- Basic dashboards and search
- Reporting engine and filtered table views
- Statements (customer and vendor)
- Payments module (receipt/payment for cash and bank)
- Multi-currency support (currency masters, document currency, FX rate capture)
- Settlement engine for partial payment and partial refund tracking

## 10. Working Notes

This document is the baseline for converting scope into:
- Product requirement document (PRD)
- System architecture document
- Data model and API specs
- Sprint-wise implementation plan

## 11. Isolated Docker Runtime (Single Laravel Project)

Project runtime files:
- Dockerfile
- docker-compose.yml
- docker-compose.traefik.yml
- .env.docker.example
- .docker/nginx/default.conf

Startup flow (isolated/local):
1. Copy .env.docker.example to .env.docker.
2. Set APP_KEY and any environment-specific values.
3. Run docker compose build.
4. Run docker compose up -d.
5. Run migrations inside app container once Laravel codebase is present.

Startup flow (VPS Docker manager with Traefik):
1. Copy .env.docker.example to .env.docker.
2. Set APP_DOMAIN to your production domain.
3. Ensure TRAEFIK_NETWORK matches your manager's Traefik network name.
4. Deploy using both compose files:
  docker compose -f docker-compose.yml -f docker-compose.traefik.yml up -d --build

Service model:
- app, worker, scheduler all use the same Laravel image/codebase.
- web (nginx) fronts Laravel; Traefik labels/network are provided by docker-compose.traefik.yml.
- mysql and redis provide isolated stateful dependencies.
