# Database Relations

This document describes the current database shape from the Laravel models and migrations. `tenant_id` is the main isolation key for operational tables, and company-owned workflows also use `company_id`.

## Core Tenant Structure

### tenants

Represents one agency workspace or the internal provider workspace.

Important fields:

- `id`
- `uid`
- `code`
- `name`
- `is_active`

Relations:

- `Tenant hasMany Company`
- `Tenant hasMany User`
- Tenant-owned operational tables reference `tenant_id`

The internal provider tenant uses code `INYICE` when internal portal records are created.

### companies

Represents an agency company profile inside a tenant.

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
- `monthly_invoice_limit`
- `user_limit`
- `logo_path`
- `footer_logo_path`
- `is_active`

Relations:

- `Company belongsTo Tenant`
- `Company hasMany User`
- `Company hasMany Customer`
- `Company hasMany Vendor`
- `Company hasMany Order`
- `Company hasMany Invoice`
- Referenced by receipts, payments, cash accounts, and bank accounts

Usage:

- Monthly invoice limits are enforced during invoice creation.
- User limits are enforced during company user creation.
- Internal portal users can update status and block/unblock non-internal companies.

### roles

Defines tenant and system permissions by role code.

Tenant roles:

- `owner`
- `admin`
- `sales`
- `accounts`

System roles:

- `super-admin`
- `inyice-admin`
- `support-executive`

Important fields:

- `tenant_id`
- `uid`
- `code`
- `name`
- `is_system`

Relations:

- `Role belongsTo Tenant`
- `Role hasMany User`

### users

Represents agency staff and internal provider users.

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

- Laravel Sanctum tokens are stored in `personal_access_tokens`.
- `password` is cast as hashed.
- Inactive users cannot log in.
- Blocking a company or user deletes affected tokens where implemented.

## Master Data

### customers

Represents B2B or B2C customers.

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
- `Customer belongsTo Tenant as b2bAgency`
- `Customer hasMany Order`
- `Customer hasMany Invoice`

Scopes:

- `b2b`
- `b2c`
- `active`

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
- `Vendor belongsTo Tenant as b2bAgency`
- `Vendor hasMany Order`

Usage:

- Vendors can be selected on order creation.
- Flight pricing and service rows can reference vendors.
- Vendor costs drive vendor payables and profit reporting.

## Order And Voucher Flow

### orders

Represents a quote/order lifecycle record. Orders use soft deletes.

Important fields:

- `tenant_id`
- `company_id`
- `customer_id`
- `vendor_id`
- `created_by_user_id`
- `updated_by_user_id`
- `uid`
- `share_token`
- `order_number`
- `voucher_no`
- `issue_date`
- `package_type`
- `active_sections`
- `emergency_contact`
- `booking_reference`
- `status`
- `currency_code`
- `total_amount`
- `notes`
- `gds_source`
- `gds_parsed_record_id`
- `meta`
- `deleted_at`

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
- `Order hasOne latest Invoice`
- `Order hasMany Invoice`
- `Order hasMany VendorPaymentAllocation`

Current model transitions:

- `quote -> order|cancel|invoice`
- `order -> cancel|invoice`
- `invoice -> void|refund_request|refund|partial_refund|paid|partial_paid`
- `refund_request -> partial_refund|refund|cancel`
- `partial_refund -> refund`
- `partial_paid -> paid`

Voucher payload:

- Stored in `orders.meta`
- Includes voucher header fields, contact, active sections, flights, passengers, pricing, hotels, transfers, city tours, visa, and other services
- Search-friendly values are copied to `voucher_no`, `issue_date`, `package_type`, `active_sections`, and `emergency_contact`
- Generated order items keep original row data in `order_items.gds_data`

Sharing:

- `share_token` creates public voucher access through `/api/v1/shared-vouchers/{token}`.
- Public responses hide tenant/company/internal identifiers and share tokens.

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

- Flight reference rows create zero-amount traceability items.
- Passenger pricing rows create priced items.
- Hotels, transfers, ziarat, visa, and other services create order items from sales fields, falling back to legacy `amount` where needed.
- A fallback `Voucher Booking` item is created if no items are generated.

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

- Flight pricing rows create `flight` costs from `flight_cost` when a vendor is selected.
- Visa, hotel, transfer, city tour/ziarat, and other service rows create supplier costs from `cost` when a vendor is selected.
- Legacy service rows without `cost` can fall back to `amount`.
- Vendor-wise profit allocates invoiced order revenue by vendor cost share.

### order_status_histories

Stores lifecycle changes.

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

- Backend endpoint accepts `sabre` and `galileo`.
- Frontend local parsing can label `amadeus` and `other` payloads.

## Invoicing

### invoices

Represents customer invoices created from orders.

Important fields:

- `tenant_id`
- `company_id`
- `order_id`
- `customer_id`
- `uid`
- `share_token`
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
- `cancel`

Usage:

- `share_token` creates public invoice access through `/api/v1/shared-invoices/{token}`.
- Discounts are represented as negative invoice lines.
- Cancelled invoices have status `cancel` and are excluded from the active invoice list.
- Invoice creation enforces `companies.monthly_invoice_limit`.

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

Represents invoice receipt/refund/advance applications.

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

Represents money received.

Important fields:

- `tenant_id`
- `company_id`
- `customer_id`
- `vendor_id`
- `uid`
- `receipt_number`
- `receipt_date`
- `amount`
- `currency_code`
- `payment_method`
- `account_id`
- `account_type`
- `reference_number`
- `description`
- `created_by_user_id`

Relations:

- `Receipt belongsTo Tenant`
- `Receipt belongsTo Company`
- `Receipt belongsTo Customer`
- `Receipt belongsTo Vendor`
- `Receipt belongsTo User as createdBy`
- Customer receipts link to `invoice_settlements` by settlement reference fields.

### payments

Represents money paid.

Important fields:

- `tenant_id`
- `company_id`
- `customer_id`
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
- `Payment belongsTo Customer`
- `Payment belongsTo Vendor`
- `Payment belongsTo User as createdBy`
- `Payment hasMany VendorPaymentAllocation`

### vendor_payment_allocations

Allocates one vendor payment across one or more payable orders.

Important fields:

- `tenant_id`
- `uid`
- `payment_id`
- `order_id`
- `amount`

Constraints and relations:

- One row per payment/order pair.
- `VendorPaymentAllocation belongsTo Payment`.
- `VendorPaymentAllocation belongsTo Order`.
- Deleting a payment cascades to its allocations; deleting an allocated order is restricted by the schema.

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

Registration creates `Main Cash Box`.

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
- Linked to cash or bank by `account_id` and `account_type`
- Linked to source document by `reference_type` and `reference_id`

## Currency And Audit

### currencies

Reference table for currency codes used by companies, counterparties, orders, invoices, receipts, payments, accounts, and exchange rates.

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

- `ExchangeRate::getRate()` returns `1.0` when source and target currencies match.
- Otherwise it returns the latest active rate on or before the requested date.

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

## Reporting And Search Data Paths

- Revenue and aging reports read invoices and settlements.
- Profit report reads invoiced order revenue and `order_vendor_costs`.
- Receipt report reads `receipts`.
- Payment report reads `payments`.
- Cancelled report reads invoices with `status = cancel`.
- Customer/vendor statements read invoices, settlements, receipts, payments, and vendor allocation/payable data.
- Reference search queries orders, invoices, customers, vendors, receipts, and payments by tenant/company scope.

## High-Level Relationship Map

```text
Tenant
  -> Company
       -> User
       -> Customer
       -> Vendor
       -> Order
            -> OrderItem
            -> OrderVendorCost
            -> OrderStatusHistory
            -> Invoice
                 -> InvoiceLine
                 -> InvoiceSettlement
            -> VendorPaymentAllocation
       -> Receipt
       -> Payment
            -> VendorPaymentAllocation
       -> CashAccount
            -> LedgerEntry(account_type=cash)
       -> BankAccount
            -> LedgerEntry(account_type=bank)
  -> Role
  -> GdsParsedRecord
       -> Order
  -> ExchangeRate
  -> AuditLog
```

## Tenant Safety Rules

- Filter business data by authenticated `tenant_id`.
- Filter company-specific views by authenticated `company_id`.
- Prefer model scopes/traits already present in the app.
- Do not expose records by raw `id` without tenant/company checks.
- API detail routes should resolve records by `uid` where controllers currently expect it.
- Public registration/auth and shared token reads are the deliberate unauthenticated exceptions.
- Internal portal cross-tenant queries must use system-role middleware and narrow `withoutGlobalScopes()` usage.
