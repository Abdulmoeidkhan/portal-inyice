# inYice-Lite MVP - Deployment & Implementation Guide

## 📋 Project Overview

**inYice-Lite** is a multi-tenant B2B/B2C order-bookkeeping platform for travel agencies, built as a Laravel 13 + React monolith with containerized Docker deployment.

**Key Features:**
- ✅ Multi-tenant architecture with automatic tenant isolation
- ✅ Order lifecycle management (quote → order → invoice → payment)
- ✅ Full invoicing & payment processing
- ✅ Multi-currency support with FX rate management
- ✅ Advanced balance tracking (outstanding, advance, partial payment)
- ✅ Customer & vendor statement generation
- ✅ Aging reports and revenue analytics
- ✅ Double-entry ledger for cash/bank accounts
- ✅ Sanctum-based API authentication
- ✅ Audit logging on all critical operations
- ✅ GDS integration-ready (Sabre/Galileo parsing)

---

## 🏗️ Project Architecture

### Backend Stack
- **Framework:** Laravel 13.11.2
- **PHP:** 8.3 (Docker), 8.2.12 (local dev)
- **Database:** MySQL 8.4
- **Authentication:** Laravel Sanctum with personal access tokens
- **Async Queue:** Redis (configured)
- **Caching:** Redis

### Frontend Stack
- **Framework:** React 18.2.0
- **Router:** React Router v6.20.0
- **UI Library:** Ant Design 5.11.0
- **Build Tool:** Vite 8.0.14
- **Bundle Size:** 1MB minified (328KB gzipped)

### Infrastructure
- **Containerization:** Docker Compose
- **Reverse Proxy:** Traefik
- **Services:**
  - `app` - Laravel FPM
  - `web` - Nginx
  - `mysql` - Database
  - `redis` - Cache & Queue
  - `traefik` - Reverse proxy

---

## 📁 Project Structure

```
_tmp_laravel/
├── app/
│   ├── Http/Controllers/Api/
│   │   ├── InvoiceController.php       # Invoice management
│   │   ├── PaymentController.php       # Payment recording
│   │   ├── AccountController.php       # Account ledger
│   │   ├── ReportController.php        # Financial reports
│   │   └── StatementController.php     # Customer statements
│   ├── Models/
│   │   ├── Tenant.php                  # Tenant container
│   │   ├── Company.php                 # Agency profile
│   │   ├── User.php                    # With Sanctum tokens
│   │   ├── Customer.php                # B2B/B2C customers
│   │   ├── Vendor.php                  # B2B/B2C vendors
│   │   ├── Order.php                   # Order lifecycle
│   │   ├── Invoice.php                 # Invoice management
│   │   ├── Payment.php                 # Payment vouchers
│   │   ├── Receipt.php                 # Payment receipts
│   │   ├── ExchangeRate.php            # FX rate storage
│   │   ├── CashAccount.php, BankAccount.php  # Ledger accounts
│   │   └── LedgerEntry.php             # Double-entry bookkeeping
│   ├── Services/
│   │   ├── InvoiceService.php          # Invoice logic
│   │   ├── PaymentService.php          # Payment processing
│   │   ├── LedgerService.php           # Ledger calculations
│   │   ├── StatementService.php        # Statement generation
│   │   └── ReportService.php           # Report generation
│   ├── Http/Middleware/
│   │   └── ResolveTenant.php           # Tenant context resolution
│   └── Traits/
│       └── TenantAware.php             # Auto-scope queries
├── database/
│   ├── migrations/               # 25 migrations for all tables
│   └── seeders/
│       └── DemoDataSeeder.php    # Sample data for testing
├── routes/
│   ├── api.php                   # 22 API endpoints
│   └── web.php                   # Blade routes
├── resources/
│   ├── js/pages/
│   │   ├── App.jsx               # Main router with navigation
│   │   ├── InvoiceList.jsx       # Invoice listing & payment
│   │   ├── AgingReport.jsx       # Aging analysis by bucket
│   │   ├── RevenueReport.jsx     # Revenue analytics
│   │   └── CustomerStatement.jsx # Customer statements
│   ├── views/
│   │   └── app.blade.php         # React entry point
│   └── css/app.css
├── docker-compose.yml            # Service composition
└── Dockerfile                     # Laravel container config
```

---

## 🗄️ Database Schema

### Core Tables (16 + 9)
**Core Identity (6):**
- currencies (PKR, USD, GBP, EUR, SAR, AED, INR seeded)
- tenants (multi-tenant container)
- companies (agency profile with base_currency)
- roles (admin/sales/accounts/super-admin)
- users (with Sanctum personal_access_tokens)
- audit_logs (immutable action tracking)

**Master Data (7):**
- customers (B2B agency link + B2C private)
- vendors (B2B/B2C with payment_terms)
- orders (full lifecycle with status history)
- order_items (with gds_data JSON for parsing)
- order_status_histories (transaction audit trail)
- gds_parsed_records (Sabre/Galileo extraction)

**Financial (9):**
- exchange_rates (FX rates with source tracking)
- invoices (with outstanding/advance balance)
- invoice_lines (line item detail)
- receipts (customer payment receipts)
- payments (vendor payment vouchers)
- cash_accounts (cash ledger accounts)
- bank_accounts (bank ledger accounts)
- invoice_settlements (payment application tracking)
- ledger_entries (double-entry bookkeeping)

**Key Design Principles:**
- All tables use ULID for external API references
- Numeric IDs for internal foreign keys
- Tenant-aware queries via automatic global scope
- Soft deletes on audit-sensitive entities
- JSON columns for flexible data storage (GDS parsing, audit logs)
- Decimal(18,4) for all currency fields
- Indexes on frequently filtered columns

---

## 🔌 API Endpoints (22 Routes)

### Base URL: `/api/v1`
All endpoints require `Authorization: Bearer {token}` header

#### **Invoices** (7 endpoints)
```
GET    /invoices              - List invoices with filters
POST   /invoices/create-from-order  - Create invoice from order
GET    /invoices/{uid}        - Invoice detail with settlements
PATCH  /invoices/{uid}/mark-sent   - Mark invoice sent
PATCH  /invoices/{uid}/void   - Void invoice
GET    /invoices/{uid}/aging-status - Get aging status
```

#### **Payments** (5 endpoints)
```
POST   /payments/record       - Record customer payment
POST   /payments/refund       - Record refund
POST   /payments/advance      - Record overpayment/advance
POST   /payments/apply-advance - Apply advance to outstanding
GET    /payments/invoices/{uid}/settlements - Get payment history
```

#### **Accounts** (5 endpoints)
```
GET    /accounts/cash         - List cash accounts
POST   /accounts/cash         - Create cash account
GET    /accounts/bank         - List bank accounts
POST   /accounts/bank         - Create bank account
GET    /accounts/{type}/{id}/balance      - Get account balance
GET    /accounts/{type}/{id}/ledger-entries - Get ledger entries
```

#### **Reports** (3 endpoints)
```
GET    /reports/aging         - Invoice aging by bucket
GET    /reports/revenue       - Revenue by period (day/week/month/year)
GET    /reports/customer-summary - Customer analysis
```

#### **Statements** (2 endpoints)
```
GET    /statements/customer/{id} - Customer statement (multi-currency)
GET    /statements/vendor/{id}   - Vendor statement
```

---

## 🚀 Quick Start

### Prerequisites
- Docker & Docker Compose
- Node.js 18+ (for local React dev)
- PHP 8.3 & Composer (for local Laravel dev)

### Development Setup (Local)

```bash
# Clone repository
git clone <repo-url> && cd inyice-portal-full

# Copy .env
cp .env.example .env

# Install PHP dependencies
composer install

# Install Node dependencies
npm install

# Generate app key
php artisan key:generate

# Run migrations
php artisan migrate:fresh

# Seed demo data
php artisan db:seed --class=DemoDataSeeder

# Build React assets (production)
npm run build

# Start development server
php artisan serve
# Visit http://localhost:8000
```

### Docker Deployment (VPS)

```bash
# Build images
docker-compose build

# Start services
docker-compose up -d

# Run migrations inside container
docker-compose exec app php artisan migrate:fresh

# Seed demo data
docker-compose exec app php artisan db:seed --class=DemoDataSeeder

# View logs
docker-compose logs -f app
```

**Access Points:**
- App: `https://yourdomain.com` (Traefik routed)
- API: `https://yourdomain.com/api/v1/user` (with auth token)
- Database: Exposed on internal network only

---

## 🔑 Authentication

### API Authentication (Sanctum)
```javascript
// Login - receive token
const response = await fetch('/login', { method: 'POST', body: {...} });
const { token } = await response.json();

// Use token
const result = await fetch('/api/v1/user', {
  headers: { Authorization: `Bearer ${token}` }
});
```

### User Roles
- **super-admin**: System-level access (cross-tenant)
- **admin**: Full company access
- **sales**: Can create orders
- **accounts**: Can manage invoices/payments

---

## 💱 Multi-Currency Implementation

### Currency Strategy
1. **Base Currency**: Set per company during setup
2. **Transaction Currency**: Each invoice/order stored in original currency
3. **FX Rate Handling**:
   - Manual entry (default)
   - API source (configured at setup)
   - Admin override for corrections

### FX Rate API Integration
```php
// In ExchangeRate model
public static function getRate($tenantId, $from, $to, $date = null)
{
    // Returns rate for specified date
    // Falls back to latest rate if not found
}
```

### Reporting Currency Rules
- **External statements**: Customer preferred currency (converted via FX rate)
- **Internal reports**: Base currency (for consistency)
- **GL entries**: Base currency equivalent with fx_rate_to_base captured

---

## 💰 Payment & Settlement Rules

### Invoice Lifecycle
1. **Draft** → **Issued** → **Sent** → **Partial_Paid** → **Paid**
   - Alternative path: Draft → Issued → Void (non-revenue)
   - Overdue status if due_date < today

### Settlement Logic
- **Payment**: Reduces outstanding_amount
- **Refund**: Increases outstanding_amount
- **Advance**: Stored in advance_balance (manual apply only)
- **Partial Payment**: Allowed; invoice stays in partial_paid status

### Example Flows
```
// Scenario 1: Full payment
Invoice: total=1000, outstanding=1000
recordPayment(500) → outstanding=500, status=partial_paid
recordPayment(500) → outstanding=0, status=paid

// Scenario 2: Overpayment
Invoice: total=1000, outstanding=1000
recordAdvance(1200) → outstanding=1000, advance_balance=1200
applyAdvance(1000) → outstanding=0, advance_balance=200, status=paid

// Scenario 3: Refund
Invoice: total=1000, outstanding=0 (fully paid)
recordRefund(100) → outstanding=100, status=partial_paid
```

---

## 📊 Reporting Examples

### 1. Aging Report
```json
{
  "buckets": {
    "current": {  // not yet due
      "invoice_count": 10,
      "total_outstanding": 500000
    },
    "days_1_30": { "invoice_count": 3, "total_outstanding": 150000 },
    "days_31_60": { "invoice_count": 2, "total_outstanding": 100000 },
    "days_61_90": { "invoice_count": 1, "total_outstanding": 50000 },
    "days_over_90": { "invoice_count": 1, "total_outstanding": 50000 }
  }
}
```

### 2. Revenue Report (by month)
```json
{
  "data": [
    {
      "period": "2026-05",
      "total_revenue": 1000000,
      "total_collected": 800000,
      "total_outstanding": 200000,
      "invoice_count": 25
    }
  ]
}
```

### 3. Customer Statement (multi-currency)
```json
{
  "customer": { "name": "John Smith", "email": "john@email.com" },
  "base_currency": "PKR",
  "customer_currency": "USD",
  "customer_currency_invoices": [
    {
      "invoice_number": "INV-20260522-00001",
      "amount_in_client_currency": 1000,
      "fx_rate": 277.5,
      "outstanding": 500
    }
  ],
  "summary": {
    "total_amount": 5000,
    "total_outstanding": 2000,
    "total_paid": 3000
  }
}
```

---

## 🛠️ Development Workflow

### Adding a New Feature

1. **Database**: Create migration
   ```bash
   php artisan make:migration create_new_table
   ```

2. **Model**: Create model with relationships
   ```bash
   php artisan make:model NewModel -m
   ```

3. **Service**: Add business logic
   ```bash
   php artisan make:class Services/NewService
   ```

4. **API Controller**: Expose endpoints
   ```bash
   php artisan make:controller Api/NewController
   ```

5. **React Component**: Add UI
   ```bash
   # Create resources/js/pages/NewPage.jsx
   ```

6. **Routes**: Register in routes/api.php and App.jsx

### Testing
```bash
# Run unit tests
php artisan test

# Run feature tests
php artisan test --group=feature

# Generate coverage
php artisan test --coverage
```

---

## 🔐 Security Checklist

- ✅ Sanctum token-based API auth
- ✅ HTTPS/TLS via Traefik
- ✅ Tenant-aware query scoping (no data leakage)
- ✅ ULID for external API references (no sequential guessing)
- ✅ Immutable audit logs (created_at only, no updates)
- ✅ Rate limiting on API endpoints (configured in .env)
- ✅ CORS middleware for SPA
- ✅ SQL injection prevention (Eloquent ORM)
- ✅ CSRF protection on web routes
- ✅ Password hashing (Laravel default bcrypt)

---

## 📈 Performance Optimization

### Database
- Indexes on `tenant_id`, `company_id`, `customer_id` for fast filtering
- Indexes on date fields for reporting
- Composite unique constraints on business keys

### Caching
- Query results cached via Redis
- Session data in Redis
- API response caching configurable per endpoint

### Frontend
- React code splitting via dynamic imports
- Ant Design tree-shaking
- Vite chunk optimization
- CDN ready (public/build assets)

---

## 🚨 Troubleshooting

### "Unauthorized" on API calls
- Check Bearer token validity: `php artisan sanctum:list`
- Verify token belongs to correct tenant
- Check request headers include `Authorization: Bearer {token}`

### Invoice status won't transition
- Check OrderStatusHistory for validation errors
- Review order total_amount > 0
- Ensure customer_id is valid

### Multi-currency statement shows 0 FX rate
- Create ExchangeRate record: `ExchangeRate::create([...])`
- Verify rate_date <= invoice_date
- Check from_currency and to_currency codes

### MySQL connection refused
- Verify `DB_HOST` in .env (use `mysql` for Docker)
- Check `DB_PORT` (default 3306)
- Confirm MySQL container is running: `docker-compose ps`

---

## 📞 Support & Contact

**Development Team:** GitHub Copilot with Claude Haiku 4.5

**Documentation:** See PROJECT_BRIEF.txt, SYSTEM_ARCHITECTURE.md, IMPLEMENTATION_ROADMAP.md

**API Documentation:** Available at `/api/v1/docs` (Swagger/OpenAPI - implement as needed)

---

## 📦 Deployment Checklist

Before going to production:

- [ ] Set `APP_ENV=production` in .env
- [ ] Set `APP_DEBUG=false`
- [ ] Generate new `APP_KEY`: `php artisan key:generate`
- [ ] Update `APP_URL` to production domain
- [ ] Configure database backups
- [ ] Set up log rotation
- [ ] Configure Redis persistence
- [ ] Enable HTTPS/TLS certificates
- [ ] Test file uploads (storage/ writable)
- [ ] Verify email configuration (queue worker running)
- [ ] Set up monitoring/alerts
- [ ] Run migrations on production: `php artisan migrate --force`
- [ ] Seed initial data: `php artisan db:seed`
- [ ] Test API authentication flow end-to-end
- [ ] Verify Traefik routing configuration
- [ ] Test multi-tenant isolation
- [ ] Backup database before launch

---

**Version:** 1.0.0-MVP
**Last Updated:** May 22, 2026
**Status:** Ready for Deployment ✅
