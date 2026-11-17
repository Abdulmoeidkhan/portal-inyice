# Read-only MCP and API reference

## Coverage audit

The original five MCP tools covered customer search, outstanding invoices, customer statements, cash/bank balances and profit only. Server 1.1.0 keeps those tools and adds `list_agency_resources`, `read_agency_data` and `get_agency_report`.

The shared retrieval API covers 31 record types and 16 report/statement types. These are additive read endpoints; existing portal API routes and writes remain unchanged.

| Data area | Record resources / tools |
| --- | --- |
| Agency and staff | companies, users, roles |
| Customers and suppliers | customers, vendors |
| Orders, vouchers and travel | orders (meta contains passengers/services/flights), order_items, order_vendor_costs, order_internal_notes, order_status_histories, gds_parsed_records |
| Invoicing | invoices (all statuses), invoice_lines, invoice_discounts, invoice_settlements |
| Cash movement | payments, receipts, receivings, refund_allocations, vendor_payment_allocations |
| Accounts | cash_accounts, bank_accounts, ledger_entries; use existing get_account_balance for calculated balances |
| Profit allocation | profit_shares |
| References | currencies (global), exchange_rates and non-system roles (tenant) |
| Manual accounting | accounting_books (includes period lock), accounting_accounts, accounting_journals, accounting_journal_lines, accounting_audit_events |
| Reports | aging, party_balances, revenue, performance, profit, tax, discounts, payments, receipts, customer_summary, dashboard_upcoming, cancelled |
| Statements | customer_statement, vendor_statement, all_customer_statements, accounting_statements |

Manual accounting is separate from travel accounting. Journal line debit_units/credit_units are integers scaled by 10,000. Generic record reads return stored values, including JSON columns as encoded strings; use report/balance tools for calculated financial totals and preserve separate currencies.

Credentials, sessions, OAuth/token tables, document share tokens, bank account numbers, raw GDS input, provider administration, tenant-wide integration/security audit records and soft-deleted records are intentionally not exposed. GDS history is scoped through its current, non-deleted company user; orphaned records are excluded. Tenant-level exchange rates are explicitly shared reference data. This is business retrieval, not arbitrary SQL/database access.

## Live endpoints

- `GET /api/v1/data`: discover resources, fields, role restrictions and report parameters.
- `GET /api/v1/data/{resource}`: paginated records. Accepts `page` (1–100000), `per_page` (1–100, default 25), `id`, `uid` where supported, `search` on supported text fields, and paired `filter_field`/`filter_value` for an exact field match. All parameters are optional. An unknown parameter is a 422, unknown resource a 404. A foreign/missing record filter returns an empty page.
- `GET /api/v1/data-reports/{report}`: calculated report using the existing portal services. Only that report's catalog parameters are accepted. Required date ranges use YYYY-MM-DD, inclusive, at most 366 days.

Example child-row read: `/api/v1/data/order_items?filter_field=order_id&filter_value=123&page=1&per_page=25`. Obtain the order ID from an authorized order lookup first. Follow `has_more` until false when a complete list is required. Live responses use `Cache-Control: private, no-store`.

MCP equivalents:

```json
{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"list_agency_resources","arguments":{}}}
```

```json
{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"read_agency_data","arguments":{"resource":"orders","search":"EXAMPLE","page":1,"per_page":25}}}
```

```json
{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"get_agency_report","arguments":{"report":"tax","from_date":"2026-01-01","to_date":"2026-01-31"}}}
```

MCP requires the existing paid, active, verified owner/admin/accounts OAuth connection and `mcp:use`. Sales, dev and provider users do not gain MCP eligibility. Company-user/role data and the cancelled report exclude accounts users. Manual accounting requires a paid owner/admin/accounts user and is unavailable to dev. Catalogs filter unavailable resources; direct requests enforce the same rules.

REST uses the existing Sanctum/portal middleware, not MCP OAuth tokens. External API sign-in currently issues paid dev-only `data:read` tokens through `/api/v1/auth/login` with `access_mode: "api"`. Dev can retrieve allowed business records but cannot use manual accounting. Owner/admin/accounts may use their authorized portal session (bearer plus matching portal cookie); MCP is their external OAuth read interface. No new token issuance or permissions bypass was introduced.

## Public examples and mocks

Anyone can visit these URLs without signing in:

- `/api-examples`: human-readable index.
- `/api/v1/api-examples`: machine-readable catalog and authentication guidance.
- `/api/v1/api-examples/data.orders`: complete request, query parameters, example response and equivalent MCP request/response.
- `/api/v1/api-examples/report.tax`: tax-report example.
- `/api/v1/api-mocks/data.orders`: only the synthetic order-list response body.
- `/api/v1/api-mocks/report.tax`: only the synthetic tax-report response body.

Use any catalog ID in the final path segment. Mocks support GET/HEAD only, never execute the example request, use a fixed fixture response, and return `X-InYice-Mock: true`. All public endpoints are throttled. They query no business tables. The examples cover the shared read interface; the existing authenticated `/api-documentation` page remains the reference for legacy portal routes and writes.

Record samples are synthetic illustrations, not valid create-request templates. Report samples were captured from an isolated SQLite test agency with no travel transactions. They are not current company balances. Change the request dates and IDs for live calls.

## Maintenance and release

`AgencyDataService` owns the resource field allowlists, filters and scope; it reuses `DeveloperDataService` scope for existing business tables. `AgencyReportService` explicitly maps allowed reports to existing read controllers/services. It never dynamically executes arbitrary methods or forwards arbitrary URLs. `ApiExampleService` owns the public examples; report fixture file is `resources/api/report-examples.json`.

Validation: `php artisan test --filter="AgencyDataApiTest|AgencyMcpTest|AgencyOAuthTest|DeveloperAccessTest"`. To intentionally regenerate report fixtures, set `UPDATE_API_EXAMPLES=1` for `php artisan test --filter=test_every_report_executes_and_public_report_examples_are_synthetic`, then clear the environment variable and review the JSON diff. The generator asserts the SQLite database is `:memory:`. Normal tests do not rewrite fixtures.

Deploy all new PHP, Blade and fixture files together and refresh route/application caches using the normal release process. No migration is added. The API documentation UI link needs the normal approved frontend asset release; no production build was run during implementation. Refresh/reconnect the MCP client after deployment if it caches the previous five-tool schema. Local verification does not publish new tools to the hosted server or installed connector automatically.
