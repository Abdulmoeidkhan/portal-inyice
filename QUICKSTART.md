# Quick Start Guide - inYice-Lite

## 🚀 Get Running in 5 Minutes

### Option 1: Local Development

```bash
# Navigate to project
cd g:\HERD\inyice-portal-full

# Install PHP dependencies
composer install

# Install Node dependencies
npm install

# Create environment file
copy .env.example .env

# Generate app key
php artisan key:generate

# Run migrations
php artisan migrate:fresh

# Seed demo data
php artisan db:seed --class=DemoDataSeeder

# Build React assets
npm run build

# Start development server
php artisan serve
```

**Access:** http://localhost:8000

**Demo Credentials:**
- Email: `admin@demoagency.com`
- Password: `password123`

---

### Option 2: Docker Deployment

```bash
# Build Docker images
docker-compose build

# Start all services
docker-compose up -d

# Run migrations
docker-compose exec app php artisan migrate:fresh

# Seed demo data
docker-compose exec app php artisan db:seed --class=DemoDataSeeder

# View logs
docker-compose logs -f app
```

**Services:**
- App: http://localhost:8000 (or configured domain via Traefik)
- MySQL: localhost:3306
- Redis: localhost:6379

---

## 📝 First Steps After Launch

### 1. Access Dashboard
```
URL: http://localhost:8000
Email: admin@demoagency.com
Password: password123
```

### 2. Create an Invoice
1. Navigate to **Invoicing → Invoices**
2. Create an invoice from existing demo order
3. View invoice details

### 3. Record a Payment
1. In invoice detail, click **Pay**
2. Enter amount (e.g., 500000)
3. Select payment method (cash/bank_transfer/check/card)
4. Submit payment

### 4. View Reports
1. **Reports → Aging Report** - See overdue invoices by bucket
2. **Reports → Revenue Report** - Select date range and group by period

---

## 🔗 API Quick Test

### Get Auth Token
```bash
curl -X POST http://localhost:8000/login \
  -d '{"email":"admin@demoagency.com","password":"password123"}' \
  -H "Content-Type: application/json"
```

### List Invoices
```bash
curl -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  http://localhost:8000/api/v1/invoices
```

### See [API_REFERENCE.md](API_REFERENCE.md) for all endpoints

---

## 📂 Important Files

| File | Purpose |
|------|---------|
| `DEPLOYMENT_GUIDE.md` | Complete deployment & architecture guide |
| `API_REFERENCE.md` | All 22 API endpoints with examples |
| `PROJECT_COMPLETION_SUMMARY.md` | What's delivered and status |
| `.env.example` | Environment configuration template |
| `docker-compose.yml` | Docker service composition |
| `routes/api.php` | API routes definition |
| `routes/web.php` | Web routes (Blade/React) |

---

## 🛠️ Common Commands

```bash
# Generate new API token for user
php artisan tinker
>>> User::first()->createToken('mobile-app')->plainTextToken

# Run database migrations
php artisan migrate

# Refresh database with fresh migrations
php artisan migrate:fresh

# Seed demo data
php artisan db:seed

# List all routes
php artisan route:list

# Compile React assets
npm run build

# Watch for changes (dev)
npm run dev

# Clear cache
php artisan cache:clear

# View application logs
tail -f storage/logs/laravel.log
```

---

## 📊 Key Entities

### Tenant
- Container for multi-tenancy
- Demo: "Demo Travel Agency"

### Company
- Agency profile under tenant
- Demo: "Demo Agency Limited"
- Has base currency (PKR)

### Customer
- B2B (agency to agency) or B2C (private)
- Demo: "John Smith", "ABC Travel Co"

### Order
- Travel booking order
- Lifecycle: quote → order → confirm/cancel → invoice
- Demo: 5 sample orders

### Invoice
- Generated from order
- Statuses: draft, issued, sent, partial_paid, paid, overdue, void
- Full payment tracking with outstanding balance

### Payment
- Records customer payment
- Supports partial payments
- Tracks advance/overpayment

---

## 🔐 Security Notes

- All endpoints require Sanctum auth token
- Tenant context auto-resolved per request
- No cross-tenant data leakage
- ULID used for external API references
- Audit logs tracked immutably

---

## 🐛 Troubleshooting

### "SQLSTATE[HY000]: General error: 1030 Got error..."
→ Check MySQL is running: `docker-compose ps mysql`

### "Undefined variable: token"
→ Make sure you passed Bearer token to API calls

### React page shows 404
→ Check routes are registered in `resources/js/pages/App.jsx`

### Invoice won't mark as paid
→ Ensure payment amount >= outstanding_amount

---

## 📞 Support

See documentation files:
- **DEPLOYMENT_GUIDE.md** - Architecture, deployment, troubleshooting
- **API_REFERENCE.md** - All endpoints with curl examples
- **PROJECT_BRIEF.txt** - Business requirements
- **SYSTEM_ARCHITECTURE.md** - Technical design

---

**Last Updated:** May 22, 2026
**Version:** 1.0.0-MVP
**Status:** Ready for Production ✅
