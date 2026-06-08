# API Quick Reference - inYice-Lite

## Authentication

**Get Token (Login)**
```bash
curl -X POST http://localhost:8000/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@demoagency.com",
    "password": "password123"
  }'
# Response: { "token": "1|abc123..." }
```

**Get User Info**
```bash
curl -X GET http://localhost:8000/api/v1/user \
  -H "Authorization: Bearer 1|abc123..."
```

---

## Order / Quotation Management

**Parse GDS Text (Sabre/Galileo)**
```bash
curl -X POST http://localhost:8000/api/v1/orders/parse-gds \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "gds_source": "sabre",
    "raw_text": "PASTE COMPLETE GDS TEXT"
  }'
```

**Create Quotation/Order from Voucher Pattern**
```bash
curl -X POST http://localhost:8000/api/v1/orders/create-from-voucher \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_id": 1,
    "company_id": 1,
    "vendor_id": 1,
    "currency_code": "PKR",
    "status": "quote",
    "notes": "Created from voucher",
    "voucher": {
      "voucher_no": "VCH-1001",
      "issue_date": "2026-06-08",
      "travel_type": "UMRAH",
      "package_type": "HOTEL+TRANSFER",
      "booking_reference": "ABC123",
      "gds_source": "sabre",
      "gds_parsed_record_id": 10,
      "emergency_contact": "24/7 Help: +92-300-0000000",
      "contact": {
        "company_name": "inYice Travels",
        "executive_name": "Ali Khan",
        "email": "ops@inyice.com",
        "phone": "+92-300-0000000",
        "address": "Karachi, Pakistan"
      },
      "active_sections": [
        "flights",
        "passengers",
        "pricing",
        "hotels",
        "transfers",
        "city_tours",
        "visa",
        "other_services"
      ],
      "flights": [
        {
          "gds_pnr": "ABC123",
          "pnr": "ABC123",
          "date": "2026-07-01",
          "flight_no": "SV701",
          "from": "KHI",
          "to": "JED",
          "departure": "10:10",
          "arrival": "12:45",
          "cabin": "Economy",
          "booking_class": "T",
          "baggage": "30KG"
        }
      ],
      "passengers": [
        {
          "name": "MUHAMMAD ALI",
          "passport_no": "AB123456",
          "ticket_no": "1761234567890",
          "visa_publisher": "KSA",
          "visa_no": "VISA123",
          "notes": ""
        }
      ],
      "pricing": [
        {
          "pax_name": "MUHAMMAD ALI",
          "flight_fare": "120000",
          "hotel_price": "85000",
          "visa_price": "25000",
          "transfer_price": "15000",
          "city_tour_ziarat_price": "10000",
          "total": ""
        }
      ],
      "hotels": [
        {
          "hcn": "HCN-1001",
          "city": "Makkah",
          "hotel_name": "Hotel A",
          "room_type": "QUAD",
          "check_in": "2026-07-01",
          "check_out": "2026-07-10",
          "lead_passenger": "MUHAMMAD ALI",
          "notes": "Near Haram",
          "amount": "85000"
        }
      ],
      "transfers": [
        {
          "tn": "TN-1001",
          "service": "Airport Pickup",
          "from_city": "JED",
          "to_city": "Makkah",
          "vehicle": "GMC",
          "contact_person": "Driver 1",
          "notes": "",
          "amount": "15000"
        }
      ],
      "city_tours": [
        {
          "city": "Madinah",
          "title": "Ziarat Tour",
          "attractions": "Quba, Uhud",
          "date": "2026-07-05",
          "notes": "",
          "amount": "10000"
        }
      ],
      "visa": [
        {
          "passenger_name": "MUHAMMAD ALI",
          "visa_no": "VISA123",
          "publisher": "KSA",
          "notes": "",
          "amount": "25000"
        }
      ],
      "other_services": [
        {
          "description": "Zam Zam",
          "amount": "5000"
        }
      ]
    }
  }'
```

**List Orders / Quotations**
```bash
curl -X GET "http://localhost:8000/api/v1/orders?per_page=20" \
  -H "Authorization: Bearer {token}"
```

**Get Order / Quotation Detail**
```bash
curl -X GET http://localhost:8000/api/v1/orders/{uid} \
  -H "Authorization: Bearer {token}"
```

Flow:
1. Create record with `status: quote` or `status: order`.
2. Convert to invoice with `/api/v1/invoices/create-from-order` using `order_id`.

---

## Invoice Management

**Create Invoice from Order**
```bash
curl -X POST http://localhost:8000/api/v1/invoices/create-from-order \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": 1
  }'
```

**List Invoices**
```bash
curl -X GET "http://localhost:8000/api/v1/invoices?status=issued&per_page=20" \
  -H "Authorization: Bearer {token}"
```

**Get Invoice Detail**
```bash
curl -X GET http://localhost:8000/api/v1/invoices/{uid} \
  -H "Authorization: Bearer {token}"
```

**Mark Invoice as Sent**
```bash
curl -X PATCH http://localhost:8000/api/v1/invoices/{uid}/mark-sent \
  -H "Authorization: Bearer {token}"
```

---

## Payment Recording

**Record Payment**
```bash
curl -X POST http://localhost:8000/api/v1/payments/record \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "invoice_uid": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    "amount": 500000,
    "payment_method": "bank_transfer",
    "account_id": 1,
    "reference_number": "CHK-001234"
  }'
```

**Record Refund**
```bash
curl -X POST http://localhost:8000/api/v1/payments/refund \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "invoice_uid": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    "amount": 50000,
    "reason": "Customer requested partial refund"
  }'
```

**Record Advance/Overpayment**
```bash
curl -X POST http://localhost:8000/api/v1/payments/advance \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "invoice_uid": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    "amount": 1500000,
    "payment_method": "bank_transfer"
  }'
```

**Apply Advance to Outstanding Amount**
```bash
curl -X POST http://localhost:8000/api/v1/payments/apply-advance \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "invoice_uid": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    "advance_amount": 500000
  }'
```

**Get Payment Settlements**
```bash
curl -X GET http://localhost:8000/api/v1/payments/invoices/{uid}/settlements \
  -H "Authorization: Bearer {token}"
```

---

## Account Management

**List Cash Accounts**
```bash
curl -X GET "http://localhost:8000/api/v1/accounts/cash?company_id=1" \
  -H "Authorization: Bearer {token}"
```

**Create Cash Account**
```bash
curl -X POST http://localhost:8000/api/v1/accounts/cash \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "account_code": "CA-002",
    "account_name": "Secondary Cash Box",
    "currency_code": "USD",
    "opening_balance": 10000
  }'
```

**Get Account Balance**
```bash
curl -X GET http://localhost:8000/api/v1/accounts/cash/1/balance \
  -H "Authorization: Bearer {token}"
```

**Get Ledger Entries**
```bash
curl -X GET "http://localhost:8000/api/v1/accounts/cash/1/ledger-entries?per_page=50" \
  -H "Authorization: Bearer {token}"
```

---

## Reports

**Invoice Aging Report**
```bash
curl -X GET "http://localhost:8000/api/v1/reports/aging?company_id=1" \
  -H "Authorization: Bearer {token}"
```

**Revenue Report (by month)**
```bash
curl -X GET "http://localhost:8000/api/v1/reports/revenue?from_date=2026-01-01&to_date=2026-12-31&group_by=month&company_id=1" \
  -H "Authorization: Bearer {token}"
```

**Revenue Report (by week)**
```bash
curl -X GET "http://localhost:8000/api/v1/reports/revenue?from_date=2026-05-01&to_date=2026-05-31&group_by=week&company_id=1" \
  -H "Authorization: Bearer {token}"
```

**Customer Summary Report**
```bash
curl -X GET "http://localhost:8000/api/v1/reports/customer-summary?company_id=1" \
  -H "Authorization: Bearer {token}"
```

---

## Statements

**Customer Statement**
```bash
curl -X GET "http://localhost:8000/api/v1/statements/customer/1?from_date=2026-01-01&to_date=2026-12-31" \
  -H "Authorization: Bearer {token}"
```

**Vendor Statement**
```bash
curl -X GET "http://localhost:8000/api/v1/statements/vendor/1?from_date=2026-01-01&to_date=2026-12-31" \
  -H "Authorization: Bearer {token}"
```

---

## Common Response Codes

| Code | Meaning |
|------|---------|
| 200 | OK - Request successful |
| 201 | Created - Resource created successfully |
| 400 | Bad Request - Invalid parameters |
| 401 | Unauthorized - Missing or invalid token |
| 403 | Forbidden - Insufficient permissions |
| 404 | Not Found - Resource doesn't exist |
| 422 | Unprocessable Entity - Validation failed |
| 500 | Server Error - Internal issue |

---

## Error Response Format

```json
{
  "error": "Validation error message",
  "details": {
    "field_name": ["Error 1", "Error 2"]
  }
}
```

---

## Pagination

List endpoints support pagination:
- `per_page`: Items per page (default 50, max 100)
- `page`: Page number (default 1)

Response includes:
```json
{
  "data": [...],
  "links": {
    "first": "...",
    "next": "...",
    "prev": null,
    "last": "..."
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "to": 20,
    "total": 150
  }
}
```

---

## Testing with Postman

1. Create environment variable: `base_url = http://localhost:8000`
2. Create environment variable: `token = {paste_bearer_token}`
3. In requests, use `{{base_url}}/api/v1/...` and header `Authorization: Bearer {{token}}`

---

## JavaScript/Fetch Examples

**Setup (React)**
```javascript
const API_BASE = process.env.REACT_APP_API_URL || '/api/v1';
const token = localStorage.getItem('auth_token');

async function apiCall(endpoint, options = {}) {
  const response = await fetch(`${API_BASE}${endpoint}`, {
    ...options,
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json',
      ...options.headers,
    },
  });
  
  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.error || 'API request failed');
  }
  
  return response.json();
}
```

**Usage**
```javascript
// Create invoice
const invoice = await apiCall('/invoices/create-from-order', {
  method: 'POST',
  body: JSON.stringify({ order_id: 1 }),
});

// Record payment
const settlement = await apiCall('/payments/record', {
  method: 'POST',
  body: JSON.stringify({
    invoice_uid: invoice.uid,
    amount: 500000,
    payment_method: 'cash',
  }),
});
```

---

**Last Updated:** May 22, 2026
