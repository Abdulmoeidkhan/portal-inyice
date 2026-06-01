# inYice-Lite Database Schema Draft (MVP)

Date: May 20, 2026
Database Engine: MySQL 8+
Architecture: Multi-tenant modular monolith

Runtime Packaging Note:
- Database is consumed by a single Laravel project running in Dockerized isolated services.
- API, web UI, worker, and scheduler share the same Laravel codebase/image.

## 1. Schema Conventions

- Primary key type: bigint unsigned auto-increment.
- Security UID: add uid char(26) (ULID) unique on all tenant/business-facing tables.
- API and UI must use uid, not numeric id, for external references.
- Tenant isolation: every business table includes tenant_id.
- Soft delete: use deleted_at where business recovery is needed.
- Timestamps: created_at, updated_at on all mutable tables.
- Timezone: store all datetime in UTC.
- Currency code: char(3), ISO-like uppercase.
- Monetary precision:
  - Document amounts: decimal(18, 4)
  - FX rates: decimal(18, 8)
- Status fields: varchar enums validated at app layer first, optional DB checks where practical.

UID application note:
- Add uid column to: tenants, companies, roles, users, user_sessions, customers, vendors,
  orders, order_status_history, order_items, gds_parsed_records, exchange_rates,
  invoices, invoice_lines, receipts, payments, refunds, invoice_settlements, advances,
  advance_applications, cash_accounts, bank_accounts, ledger_entries, report_snapshots,
  statement_runs, audit_logs, document_links.
- Keep internal FK joins on numeric id for performance.
- Keep unique index on (tenant_id, uid) for tenant-scoped lookup and route binding.

JSON usage note:
- Static JSON files (airline.json, airports.json) are loaded by GDS parser service for code-to-name/detail lookups.
- Do not seed these into database; use them as in-memory lookups by parser.
- Key DB JSON columns: gds_parsed_records.parsed_json, audit_logs.old_values/new_values, report_snapshots.payload.
- Use Laravel casts for automatic JSON encoding/decoding in models.

## 2. Core Identity and Tenant Tables

### tenants
- id (PK)
- uid char(26) unique
- code varchar(50) unique
- name varchar(200)
- is_active tinyint(1)
- created_at, updated_at

Indexes:
- unique(code)

### companies
- id (PK)
- uid char(26)
- tenant_id (FK -> tenants.id)
- legal_name varchar(200)
- display_name varchar(200)
- email varchar(200) nullable
- phone varchar(50) nullable
- address text nullable
- country_code char(2) nullable
- base_currency_code char(3)
- default_timezone varchar(80) default UTC
- is_active tinyint(1)
- created_at, updated_at

Indexes:
- index(tenant_id)
- index(tenant_id, base_currency_code)

Constraints:
- FK tenant_id references tenants(id)
- FK base_currency_code references currencies(code)

### roles
- id (PK)
- uid char(26)
- tenant_id nullable (null means provider-level/system role)
- code varchar(50)
- name varchar(100)
- is_system tinyint(1)
- created_at, updated_at

Indexes:
- unique(tenant_id, code)

### users
- id (PK)
- uid char(26)
- tenant_id (FK -> tenants.id)
- company_id (FK -> companies.id)
- role_id (FK -> roles.id)
- name varchar(150)
- email varchar(200)
- password varchar(255) nullable
- auth_provider varchar(50) nullable
- auth_provider_id varchar(200) nullable
- is_active tinyint(1)
- last_login_at datetime nullable
- created_at, updated_at

Indexes:
- unique(tenant_id, email)
- index(tenant_id, company_id)
- index(tenant_id, role_id)

Business rule note:
- Agency companies are limited to 3 active users by app logic.

### user_sessions
- id (PK)
- uid char(26)
- tenant_id
- user_id
- session_token_hash varchar(255)
- ip_address varchar(64) nullable
- user_agent varchar(500) nullable
- logged_in_at datetime
- logged_out_at datetime nullable
- created_at, updated_at

Indexes:
- index(tenant_id, user_id)
- index(tenant_id, logged_in_at)

## 3. Party and Relationship Tables

### customers
- id (PK)
- tenant_id
- company_id
- customer_type varchar(20) (B2B or B2C)
- code varchar(50)
- name varchar(200)
- email varchar(200) nullable
- phone varchar(50) nullable
- address text nullable
- country_code char(2) nullable
- linked_vendor_tenant_id bigint nullable
- linked_vendor_company_id bigint nullable
- is_active tinyint(1)
- created_by_user_id bigint
- updated_by_user_id bigint nullable
- created_at, updated_at, deleted_at nullable

Indexes:
- unique(tenant_id, code)
- index(tenant_id, customer_type)
- index(tenant_id, name)

### vendors
- id (PK)
- tenant_id
- company_id
- vendor_type varchar(20) (B2B or Local)
- code varchar(50)
- name varchar(200)
- email varchar(200) nullable
- phone varchar(50) nullable
- address text nullable
- country_code char(2) nullable
- linked_customer_tenant_id bigint nullable
- linked_customer_company_id bigint nullable
- is_active tinyint(1)
- created_by_user_id bigint
- updated_by_user_id bigint nullable
- created_at, updated_at, deleted_at nullable

Indexes:
- unique(tenant_id, code)
- index(tenant_id, vendor_type)
- index(tenant_id, name)

## 4. Order and GDS Tables

### orders
- id (PK)
- tenant_id
- company_id
- order_no varchar(50)
- booking_reference varchar(30) nullable
- customer_id bigint
- vendor_id bigint nullable
- current_status varchar(20)
- document_currency_code char(3)
- subtotal_amount decimal(18,4) default 0
- tax_amount decimal(18,4) default 0
- total_amount decimal(18,4) default 0
- fx_rate_to_base decimal(18,8) nullable
- total_base_amount decimal(18,4) nullable
- note text nullable
- created_by_user_id bigint
- updated_by_user_id bigint nullable
- created_at, updated_at, deleted_at nullable

Indexes:
- unique(tenant_id, order_no)
- index(tenant_id, booking_reference)
- index(tenant_id, customer_id)
- index(tenant_id, vendor_id)
- index(tenant_id, current_status)

### order_status_history
- id (PK)
- tenant_id
- order_id bigint
- from_status varchar(20) nullable
- to_status varchar(20)
- transition_note text nullable
- changed_by_user_id bigint
- changed_at datetime
- created_at, updated_at

Indexes:
- index(tenant_id, order_id, changed_at)

### order_items
- id (PK)
- tenant_id
- order_id bigint
- line_no int
- item_type varchar(30) (Flight, Service, Fee)
- description varchar(500)
- quantity decimal(12,4) default 1
- unit_price decimal(18,4)
- line_total decimal(18,4)
- created_at, updated_at

Indexes:
- unique(tenant_id, order_id, line_no)

### gds_parsed_records
- id (PK)
- tenant_id
- order_id bigint
- source varchar(20) (Sabre, Galileo)
- raw_text longtext
- parsed_json json
- pnr_code varchar(20) nullable
- parsed_at datetime
- parsed_by_user_id bigint
- created_at, updated_at

Indexes:
- index(tenant_id, order_id)
- index(tenant_id, source)
- index(tenant_id, pnr_code)

## 5. Currency and FX Tables

### currencies
- code char(3) PK
- name varchar(100)
- symbol varchar(10) nullable
- decimal_places tinyint default 2
- is_active tinyint(1)
- created_at, updated_at

Seed values:
- PKR, USD, GBP, EUR, SAR, AED, INR

### exchange_rates
- id (PK)
- tenant_id
- base_currency_code char(3)
- quote_currency_code char(3)
- rate_date date
- rate_value decimal(18,8)
- source_type varchar(20) (API or MANUAL)
- source_name varchar(100) nullable
- is_override tinyint(1) default 0
- entered_by_user_id bigint nullable
- created_at, updated_at

Indexes:
- unique(tenant_id, base_currency_code, quote_currency_code, rate_date)
- index(tenant_id, rate_date)

Constraints:
- FK base_currency_code -> currencies(code)
- FK quote_currency_code -> currencies(code)

## 6. Financial Document Tables

### invoices
- id (PK)
- tenant_id
- company_id
- invoice_no varchar(50)
- order_id bigint nullable
- customer_id bigint
- vendor_id bigint nullable
- issue_date date
- due_date date nullable
- status varchar(20) (Draft, Posted, PartiallyPaid, Paid, Cancelled, Refunded, Void)
- document_currency_code char(3)
- subtotal_amount decimal(18,4)
- tax_amount decimal(18,4)
- total_amount decimal(18,4)
- fx_rate_to_base decimal(18,8)
- total_base_amount decimal(18,4)
- paid_amount decimal(18,4) default 0
- paid_base_amount decimal(18,4) default 0
- balance_amount decimal(18,4)
- balance_base_amount decimal(18,4)
- notes text nullable
- created_by_user_id bigint
- posted_by_user_id bigint nullable
- posted_at datetime nullable
- created_at, updated_at, deleted_at nullable

Indexes:
- unique(tenant_id, invoice_no)
- index(tenant_id, customer_id)
- index(tenant_id, issue_date)
- index(tenant_id, status)

### invoice_lines
- id (PK)
- tenant_id
- invoice_id bigint
- line_no int
- description varchar(500)
- quantity decimal(12,4) default 1
- unit_price decimal(18,4)
- line_total decimal(18,4)
- created_at, updated_at

Indexes:
- unique(tenant_id, invoice_id, line_no)

### receipts
- id (PK)
- tenant_id
- company_id
- receipt_no varchar(50)
- customer_id bigint
- receipt_date date
- payment_mode varchar(20) (Cash, Bank)
- cash_account_id bigint nullable
- bank_account_id bigint nullable
- reference_no varchar(100) nullable
- status varchar(20) (Draft, Posted, Reversed)
- document_currency_code char(3)
- amount decimal(18,4)
- fx_rate_to_base decimal(18,8)
- amount_base decimal(18,4)
- unapplied_amount decimal(18,4) default 0
- unapplied_base_amount decimal(18,4) default 0
- notes text nullable
- created_by_user_id bigint
- posted_by_user_id bigint nullable
- posted_at datetime nullable
- created_at, updated_at, deleted_at nullable

Indexes:
- unique(tenant_id, receipt_no)
- index(tenant_id, customer_id)
- index(tenant_id, receipt_date)
- index(tenant_id, payment_mode)

### payments
- id (PK)
- tenant_id
- company_id
- payment_no varchar(50)
- vendor_id bigint
- payment_date date
- payment_mode varchar(20) (Cash, Bank)
- cash_account_id bigint nullable
- bank_account_id bigint nullable
- reference_no varchar(100) nullable
- status varchar(20) (Draft, Posted, Reversed)
- document_currency_code char(3)
- amount decimal(18,4)
- fx_rate_to_base decimal(18,8)
- amount_base decimal(18,4)
- notes text nullable
- created_by_user_id bigint
- posted_by_user_id bigint nullable
- posted_at datetime nullable
- created_at, updated_at, deleted_at nullable

Indexes:
- unique(tenant_id, payment_no)
- index(tenant_id, vendor_id)
- index(tenant_id, payment_date)
- index(tenant_id, payment_mode)

### refunds
- id (PK)
- tenant_id
- company_id
- refund_no varchar(50)
- invoice_id bigint
- customer_id bigint
- refund_date date
- status varchar(20) (Draft, Posted, Reversed)
- document_currency_code char(3)
- amount decimal(18,4)
- fx_rate_to_base decimal(18,8)
- amount_base decimal(18,4)
- notes text nullable
- created_by_user_id bigint
- posted_by_user_id bigint nullable
- posted_at datetime nullable
- created_at, updated_at, deleted_at nullable

Indexes:
- unique(tenant_id, refund_no)
- index(tenant_id, invoice_id)
- index(tenant_id, customer_id)
- index(tenant_id, refund_date)

## 7. Settlement and Advance Tables

### invoice_settlements
- id (PK)
- tenant_id
- invoice_id bigint
- source_type varchar(20) (RECEIPT, ADVANCE, REFUND_ADJUST)
- source_id bigint
- applied_date date
- applied_amount decimal(18,4)
- applied_base_amount decimal(18,4)
- created_by_user_id bigint
- created_at, updated_at

Indexes:
- index(tenant_id, invoice_id)
- index(tenant_id, source_type, source_id)

### advances
- id (PK)
- tenant_id
- party_type varchar(20) (Customer, Vendor)
- party_id bigint
- source_type varchar(20) (RECEIPT_OVERPAYMENT, MANUAL, ADJUSTMENT)
- source_id bigint nullable
- document_currency_code char(3)
- total_amount decimal(18,4)
- used_amount decimal(18,4) default 0
- balance_amount decimal(18,4)
- fx_rate_to_base decimal(18,8)
- total_base_amount decimal(18,4)
- used_base_amount decimal(18,4) default 0
- balance_base_amount decimal(18,4)
- status varchar(20) (Open, PartiallyUsed, Closed)
- created_at, updated_at

Indexes:
- index(tenant_id, party_type, party_id)
- index(tenant_id, status)

### advance_applications
- id (PK)
- tenant_id
- advance_id bigint
- invoice_id bigint
- applied_date date
- applied_amount decimal(18,4)
- applied_base_amount decimal(18,4)
- applied_by_user_id bigint
- created_at, updated_at

Indexes:
- index(tenant_id, advance_id)
- index(tenant_id, invoice_id)

Business rule note:
- Applications are manual only. No auto-apply process in v1.

## 8. Cash and Bank Tables

### cash_accounts
- id (PK)
- tenant_id
- code varchar(50)
- name varchar(120)
- currency_code char(3)
- is_active tinyint(1)
- created_at, updated_at

Indexes:
- unique(tenant_id, code)
- index(tenant_id, currency_code)

### bank_accounts
- id (PK)
- tenant_id
- bank_name varchar(150)
- account_title varchar(150)
- account_no varchar(100)
- iban varchar(80) nullable
- currency_code char(3)
- is_active tinyint(1)
- created_at, updated_at

Indexes:
- unique(tenant_id, account_no)
- index(tenant_id, currency_code)

### ledger_entries
- id (PK)
- tenant_id
- entry_date date
- account_type varchar(20) (Cash, Bank, Receivable, Payable, Advance)
- account_ref_id bigint
- party_type varchar(20) nullable
- party_id bigint nullable
- document_type varchar(20)
- document_id bigint
- direction varchar(10) (Dr, Cr)
- document_currency_code char(3)
- amount decimal(18,4)
- base_currency_code char(3)
- fx_rate_to_base decimal(18,8)
- amount_base decimal(18,4)
- memo varchar(500) nullable
- created_at, updated_at

Indexes:
- index(tenant_id, entry_date)
- index(tenant_id, account_type, account_ref_id)
- index(tenant_id, party_type, party_id)
- index(tenant_id, document_type, document_id)

## 9. Reporting Support Tables

### report_snapshots
- id (PK)
- tenant_id
- snapshot_type varchar(50)
- snapshot_date date
- payload json
- created_at

Indexes:
- index(tenant_id, snapshot_type, snapshot_date)

### statement_runs
- id (PK)
- tenant_id
- statement_type varchar(20) (Customer, Vendor)
- party_id bigint
- from_date date
- to_date date
- currency_mode varchar(30) (ORIGINAL_TRANSACTION_CURRENCY)
- generated_by_user_id bigint
- generated_at datetime
- created_at, updated_at

Indexes:
- index(tenant_id, statement_type, party_id)
- index(tenant_id, generated_at)

## 10. Audit and Document Link Tables

### audit_logs
- id (PK)
- tenant_id
- user_id bigint nullable
- event_type varchar(80)
- module_name varchar(80)
- entity_type varchar(80)
- entity_id bigint nullable
- action varchar(30)
- old_values json nullable
- new_values json nullable
- ip_address varchar(64) nullable
- user_agent varchar(500) nullable
- occurred_at datetime
- created_at

Indexes:
- index(tenant_id, occurred_at)
- index(tenant_id, module_name, occurred_at)
- index(tenant_id, entity_type, entity_id)
- index(tenant_id, user_id, occurred_at)

### document_links
- id (PK)
- tenant_id
- from_type varchar(40)
- from_id bigint
- to_type varchar(40)
- to_id bigint
- relation_type varchar(40)
- created_at

Indexes:
- index(tenant_id, from_type, from_id)
- index(tenant_id, to_type, to_id)

## 11. Key Foreign Key Matrix (Summary)

- companies.tenant_id -> tenants.id
- users.tenant_id -> tenants.id
- users.company_id -> companies.id
- users.role_id -> roles.id
- customers.tenant_id -> tenants.id
- vendors.tenant_id -> tenants.id
- orders.customer_id -> customers.id
- orders.vendor_id -> vendors.id
- order_status_history.order_id -> orders.id
- order_items.order_id -> orders.id
- gds_parsed_records.order_id -> orders.id
- invoices.order_id -> orders.id
- invoices.customer_id -> customers.id
- receipts.customer_id -> customers.id
- payments.vendor_id -> vendors.id
- refunds.invoice_id -> invoices.id
- invoice_settlements.invoice_id -> invoices.id
- advances.party_id -> customers.id or vendors.id by party_type (polymorphic rule)
- advance_applications.advance_id -> advances.id
- advance_applications.invoice_id -> invoices.id

## 12. Critical Business Constraints to Enforce

- Tenant safety:
  - No cross-tenant references in write operations.
- Identifier safety:
  - API must accept/return uid for entity identification.
  - Numeric id must not be exposed in public endpoints.
- User count:
  - Agency tenant max 3 active users in Admin/Sales/Accounts roles.
- Order lifecycle:
  - Only valid transitions allowed.
- Currency:
  - Document currency must be one of enabled currencies.
  - Company base currency must always be present.
- Settlement:
  - Sum(invoice_settlements.applied_amount) <= invoice.total_amount.
  - Overpayment creates advance balance, not invoice negative balance.
  - Advance apply is manual workflow only.
- Statement output:
  - External statement rendering uses original transaction currency.

## 13. Suggested Migration Order

1. tenants, currencies, roles
2. companies, users, user_sessions
3. customers, vendors
4. cash_accounts, bank_accounts, exchange_rates
5. orders, order_items, order_status_history, gds_parsed_records
6. invoices, invoice_lines
7. receipts, payments, refunds
8. advances, advance_applications, invoice_settlements
9. ledger_entries, document_links, audit_logs
10. report_snapshots, statement_runs

## 14. Next Schema Outputs

- Laravel migration blueprint per table group
- Seed plan for currencies and system roles
- Example reporting SQL for invoice table filters and statements
