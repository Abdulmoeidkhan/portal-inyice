# Database Relations

This document describes the current database shape from the Laravel models and migrations. `tenant_id` is the main isolation key for operational tables, and most business models use the `TenantAware` trait.

## Core Tenant Structure

### tenants

Represents one agency workspace.

Important fields:

- `id`
- `uid`
- `code`
- `name`
- `is_active`

Relations:

- `Tenant hasMany Company`
- `Tenant hasMany User`
- Many operational tables belong to a tenant through `tenant_id`

### companies

Represents the agency company profile inside a tenant.

Important fields:

- `tenant_id`
- `uid`
- `legal_name`
- `display_name`
- `email`
- `phone`
- `address`
- `country_code`
- `base_currency_code`
- `default_timezone`
- `is_active`

Relations:

- `Company belongsTo Tenant`
- `Company hasMany User`
- Referenced by orders, invoices, receipts, payments, cash accounts, and bank accounts

### roles

Defines user permissions by role code.

Important fields:

- `tenant_id`
- `uid`
- `code`
- `name`
- `is_system`

Relations:

- `Role belongsTo Tenant`
- `Role hasMany User`

Current tenant roles created during registration:

- `owner`
- `admin`
- `sales`
- `accounts`

The initial signup user is assigned `owner`. Owner users satisfy admin-level permission checks in the `User` model. System role support exists through `is_system`, including provider-level `super-admin` behavior.

### users

Represents staff accounts.

Important fields:

- `tenant_id`
- `company_id`
- `role_id`
- `uid`
- `name`
- `email`
- `password`
- `auth_provider`
- `auth_provider_id`
- `is_active`

Relations:

- `User belongsTo Tenant`
- `User belongsTo Company`
- `User belongsTo Role`
- Referenced by orders as creator/updater
- Referenced by GDS parsed records as parser
- Referenced by audit logs
- Referenced by receipts and payments as creator

Auth:

- Laravel Sanctum tokens are stored in `personal_access_tokens`
- `password` is cast as hashed
- inactive users cannot log in

## Master Data

### customers

Represents customers for B2B or B2C business.

Important fields:

- `tenant_id`
- `company_id`
- `uid`
- `type`
- `name`
- `email`
- `phone`
- `address`
- `city`
- `country_code`
- `postal_code`
- `tax_id`
- `currency_code`
- `b2b_agency_id`
- `is_active`

Relations:

- `Customer belongsTo Tenant`
- `Customer belongsTo Company`
- `Customer belongsTo Tenant as b2bAgency` through `b2b_agency_id`
- `Customer hasMany Order`
- `Customer hasMany Invoice`

Scopes:

- `b2b`
- `b2c`
- `active`

API:

- `GET /api/v1/customers` lists active tenant customers for selectors.
- `POST /api/v1/customers` creates a customer inside the authenticated user's tenant/company.

### vendors

Represents suppliers or agency counterparties.

Important fields:

- `tenant_id`
- `company_id`
- `uid`
- `type`
- `name`
- `email`
- `phone`
- `address`
- `city`
- `country_code`
- `postal_code`
- `tax_id`
- `currency_code`
- `b2b_agency_id`
- `payment_terms`
- `is_active`

Relations:

- `Vendor belongsTo Tenant`
- `Vendor belongsTo Company`
- `Vendor belongsTo Tenant as b2bAgency` through `b2b_agency_id`
- `Vendor hasMany Order`

Usage:

- Vendors can be selected on order creation.
- Flight voucher rows can store `vendor_id` and `vendor_name` in voucher metadata.
- Visa voucher rows can store `vendor_id` and `visa_vendor` in voucher metadata.
- The vendor table is the reusable supplier list for flight, visa, and upcoming service modules.

Scopes:

- `b2b`
- `b2c`
- `active`

API:

- `GET /api/v1/vendors` lists active tenant vendors for selectors.
- `POST /api/v1/vendors` creates a vendor inside the authenticated user's tenant/company.

## Order And Voucher Flow

### orders

Represents a quote/order/confirmed booking.

Important fields:

- `tenant_id`
- `company_id`
- `customer_id`
- `vendor_id`
- `created_by_user_id`
- `updated_by_user_id`
- `uid`
- `order_number`
- `booking_reference`
- `status`
- `currency_code`
- `total_amount`
- `notes`
- `gds_source`
- `gds_parsed_record_id`
- `meta`

Relations:

- `Order belongsTo Tenant`
- `Order belongsTo Company`
- `Order belongsTo Customer`
- `Order belongsTo Vendor`
- `Order belongsTo User as createdBy`
- `Order belongsTo User as updatedBy`
- `Order belongsTo GdsParsedRecord`
- `Order hasMany OrderItem`
- `Order hasMany OrderVendorCost`
- `Order hasMany OrderStatusHistory`
- `Invoice belongsTo Order`

Status transitions in model:

- `quote -> order`
- `order -> confirm`
- `order -> cancel`
- `confirm -> invoice`
- `confirm -> refund`
- `invoice -> paid`
- `invoice -> partial_paid`
- `partial_paid -> paid`

Voucher payload:

- Stored in `orders.meta`
- Includes voucher header fields, contact, active sections, flights, passengers, pricing, hotels, transfers, city tours, visa, and other services
- Flight rows can include `vendor_id` and `vendor_name`
- Visa rows can include `visa_type`, `validity`, `visa_no`, `vendor_id`, `visa_vendor`, `cost`, `profit`, `sales`, legacy `amount`, and notes
- Hotel, transfer, city tour/ziarat, and other service rows can include `vendor_id`, `vendor_name`, `cost`, `profit`, `sales`, and legacy `amount`
- Generated order items keep original row data in `order_items.gds_data`

### order_vendor_costs

Represents supplier cost rows used by vendor payments and profit reporting.

Important fields:

- `tenant_id`
- `order_id`
- `vendor_id`
- `service_type`
- `service_index`
- `amount`

Relations:

- `OrderVendorCost belongsTo Order`
- `OrderVendorCost belongsTo Vendor`

Usage:

- Flight pricing rows create `flight` costs from `voucher.pricing.*.flight_cost` when a vendor is selected.
- Visa, hotel, transfer, city tour/ziarat, and other service rows create supplier costs from their `cost` field when a vendor is selected. Legacy rows without `cost` fall back to `amount`.
- Profit Report subtracts these costs from invoiced order revenue.
- Vendor-wise profit allocates an invoiced order's revenue proportionally across vendor cost rows to avoid double-counting multi-vendor orders.

### order_items

Represents sellable or traceability lines under an order.

Important fields:

- `tenant_id`
- `order_id`
- `uid`
- `description`
- `quantity`
- `unit_price`
- `total_price`
- `gds_data`

Relations:

- `OrderItem belongsTo Tenant`
- `OrderItem belongsTo Order`

How voucher rows become order items:

- Flight reference rows create zero-amount traceability items
- Pricing rows create priced items per passenger and component
- Hotels, transfers, ziarat, visa, and other services can also create order items from their sales fields. Legacy rows without `sales` fall back to `amount`.
- A fallback `Voucher Booking` item is created if no items exist

### order_status_histories

Stores order lifecycle changes.

Important fields:

- `tenant_id`
- `order_id`
- `uid`
- `from_status`
- `to_status`
- `user_id`
- `notes`
- `created_at`

Relations:

- `OrderStatusHistory belongsTo Tenant`
- `OrderStatusHistory belongsTo Order`
- `OrderStatusHistory belongsTo User`

### gds_parsed_records

Stores pasted GDS text and extracted data.

Important fields:

- `tenant_id`
- `uid`
- `raw_text`
- `gds_source`
- `booking_reference`
- `parsed_json`
- `parsed_by_user_id`

Relations:

- `GdsParsedRecord belongsTo Tenant`
- `GdsParsedRecord belongsTo User as parsedBy`
- `GdsParsedRecord hasMany Order`

Current source handling:

- Backend endpoint accepts `sabre` and `galileo`
- Frontend local parser also supports source labeling for `amadeus` and `other` payloads

## Invoicing

### invoices

Represents customer invoices created from orders.

Important fields:

- `tenant_id`
- `company_id`
- `order_id`
- `customer_id`
- `uid`
- `invoice_number`
- `invoice_date`
- `due_date`
- `currency_code`
- `subtotal`
- `tax_amount`
- `total_amount`
- `outstanding_amount`
- `advance_balance`
- `status`
- `fx_rate_to_base`
- `notes`

Relations:

- `Invoice belongsTo Tenant`
- `Invoice belongsTo Company`
- `Invoice belongsTo Order`
- `Invoice belongsTo Customer`
- `Invoice hasMany InvoiceLine`
- `Invoice hasMany InvoiceSettlement`

Statuses:

- `draft`
- `issued`
- `sent`
- `partial_paid`
- `paid`
- `overdue`
- `void`

### invoice_lines

Represents invoice detail lines.

Important fields:

- `tenant_id`
- `invoice_id`
- `uid`
- `description`
- `quantity`
- `unit_price`
- `total_price`

Relations:

- `InvoiceLine belongsTo Tenant`
- `InvoiceLine belongsTo Invoice`

### invoice_settlements

Represents invoice payment/refund/advance applications.

Important fields:

- `tenant_id`
- `invoice_id`
- `uid`
- `amount_received`
- `amount_refunded`
- `amount_to_advance`
- `settlement_date`
- `settlement_type`
- `reference_document_id`
- `reference_document_type`
- `status`
- `notes`

Relations:

- `InvoiceSettlement belongsTo Tenant`
- `InvoiceSettlement belongsTo Invoice`

## Payments And Accounts

### receipts

Represents money received from customers.

Important fields:

- `tenant_id`
- `company_id`
- `customer_id`
- `uid`
- `receipt_number`
- `receipt_date`
- `amount`
- `currency_code`
- `payment_method`
- `reference_number`
- `description`
- `created_by_user_id`

Relations:

- `Receipt belongsTo Tenant`
- `Receipt belongsTo Company`
- `Receipt belongsTo Customer`
- `Receipt belongsTo User as createdBy`
- `Receipt hasMany InvoiceSettlement` through the settlement reference document fields

### payments

Represents money paid to vendors.

Important fields:

- `tenant_id`
- `company_id`
- `vendor_id`
- `uid`
- `payment_number`
- `payment_date`
- `amount`
- `currency_code`
- `payment_method`
- `account_id`
- `account_type`
- `reference_number`
- `description`
- `created_by_user_id`

Relations:

- `Payment belongsTo Tenant`
- `Payment belongsTo Company`
- `Payment belongsTo Vendor`
- `Payment belongsTo User as createdBy`
- `Payment hasMany VendorPaymentAllocation`

### vendor_payment_allocations

Allocates one vendor payment across one or more payable orders. This keeps the payment header (method, account, date, reference, and total) separate from its document-level distribution.

Important fields:

- `tenant_id`
- `uid`
- `payment_id`
- `order_id`
- `amount`

Constraints and relations:

- One row per payment/order pair (`payment_id`, `order_id` is unique).
- `VendorPaymentAllocation belongsTo Payment`.
- `VendorPaymentAllocation belongsTo Order`.
- `Payment hasMany VendorPaymentAllocation`.
- `Order hasMany VendorPaymentAllocation`.
- Deleting a payment cascades to its allocations; deleting an allocated order is restricted.

### cash_accounts

Represents cash ledgers.

Important fields:

- `tenant_id`
- `company_id`
- `uid`
- `account_code`
- `account_name`
- `currency_code`
- `opening_balance`
- `current_balance`
- `is_active`

Relations:

- `CashAccount belongsTo Tenant`
- `CashAccount belongsTo Company`
- `CashAccount hasMany LedgerEntry` where `account_type = cash`

Registration creates a default cash account named `Main Cash Box`.

### bank_accounts

Represents bank ledgers.

Important fields:

- `tenant_id`
- `company_id`
- `uid`
- `bank_name`
- `account_number`
- `account_holder`
- `currency_code`
- `opening_balance`
- `current_balance`
- `is_active`

Relations:

- `BankAccount belongsTo Tenant`
- `BankAccount belongsTo Company`
- `BankAccount hasMany LedgerEntry` where `account_type = bank`

### ledger_entries

Represents account movement rows.

Important fields:

- `tenant_id`
- `uid`
- `account_id`
- `account_type`
- `debit`
- `credit`
- `entry_date`
- `reference_type`
- `reference_id`
- `description`
- `created_at`

Relations:

- `LedgerEntry belongsTo Tenant`
- Linked to cash or bank account by `account_id` and `account_type`
- Linked to source document by `reference_type` and `reference_id`

## Currency And Audit

### currencies

Reference table for currency codes used by companies, customers, vendors, orders, invoices, receipts, payments, cash accounts, bank accounts, and exchange rates.

### exchange_rates

Stores tenant-level FX rates.

Important fields:

- `tenant_id`
- `uid`
- `from_currency_code`
- `to_currency_code`
- `rate`
- `rate_date`
- `source`
- `api_source_name`
- `is_active`

Relations:

- `ExchangeRate belongsTo Tenant`

Lookup behavior:

- `ExchangeRate::getRate()` returns `1.0` when source and target currencies are the same
- Otherwise it returns the latest active rate on or before the requested date

### audit_logs

Stores user actions and before/after values.

Important fields:

- `tenant_id`
- `uid`
- `user_id`
- `action`
- `model_type`
- `model_id`
- `old_values`
- `new_values`
- `description`
- `created_at`

Relations:

- `AuditLog belongsTo User`
- Tenant scoping is available through `tenant_id`

Scopes:

- `recent`
- `byAction`
- `byModel`

## High-Level Relationship Map

```text
Tenant
  -> Company
  -> Role
  -> User
  -> Customer
  -> Vendor
  -> Order
       -> OrderVendorCost
       -> OrderItem
       -> OrderStatusHistory
       -> Invoice
            -> InvoiceLine
            -> InvoiceSettlement
  -> GdsParsedRecord
       -> Order
  -> Receipt
  -> Payment
  -> CashAccount
       -> LedgerEntry(account_type=cash)
  -> BankAccount
       -> LedgerEntry(account_type=bank)
  -> ExchangeRate
  -> AuditLog
```

## Tenant Safety Rules

- Always filter business data by authenticated user's `tenant_id`.
- Prefer model scopes/traits already present in the app.
- Do not expose records by raw `id` without tenant checks.
- Public registration and auth routes are the only intentionally unauthenticated flows.
- API detail routes should resolve records by `uid` where controllers currently expect it.
