<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferenceSearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'pnr' => ['nullable', 'string', 'max:120'],
            'airline_pnr' => ['nullable', 'string', 'max:120'],
            'internal_ref' => ['nullable', 'string', 'max:120'],
            'order_no' => ['nullable', 'string', 'max:120'],
            'lead_passenger' => ['nullable', 'string', 'max:120'],
            'passenger_name' => ['nullable', 'string', 'max:120'],
            'passenger_phone' => ['nullable', 'string', 'max:120'],
            'passenger_email' => ['nullable', 'string', 'max:120'],
            'passenger_postcode' => ['nullable', 'string', 'max:120'],
            'invoice_no' => ['nullable', 'string', 'max:120'],
            'ticket_no' => ['nullable', 'string', 'max:120'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_postcode' => ['nullable', 'string', 'max:120'],
            'destination' => ['nullable', 'string', 'max:120'],
            'your_ref' => ['nullable', 'string', 'max:120'],
            'booked_by' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:60'],
            'pay_status' => ['nullable', 'string', 'max:60'],
            'invoice_date_from' => ['nullable', 'date'],
            'invoice_date_to' => ['nullable', 'date'],
            'departure_date_from' => ['nullable', 'date'],
            'departure_date_to' => ['nullable', 'date'],
            'creation_date_from' => ['nullable', 'date'],
            'creation_date_to' => ['nullable', 'date'],
            'quote_convert_date_from' => ['nullable', 'date'],
            'quote_convert_date_to' => ['nullable', 'date'],
            'amount_from' => ['nullable', 'numeric'],
            'amount_to' => ['nullable', 'numeric'],
        ]);

        $terms = !empty($validated['q']) ? [trim((string) $validated['q'])] : [];

        $hasAnyFilter = collect($validated)->contains(fn ($value): bool => $value !== null && $value !== '');

        if (!$hasAnyFilter) {
            return response()->json(['data' => [], 'summary' => ['total' => 0]]);
        }

        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $companyId = (int) $user->company_id;

        $orders = $this->searchOrders($validated, $terms, $tenantId, $companyId);
        $invoices = $this->searchInvoices($validated, $terms, $tenantId, $companyId);
        $customers = $this->searchCustomers($validated, $terms, $tenantId, $companyId);
        $vendors = $this->searchVendors($validated, $terms, $tenantId, $companyId);
        $receipts = $this->searchReceipts($validated, $terms, $tenantId, $companyId);
        $payments = $this->searchPayments($validated, $terms, $tenantId, $companyId);

        $rows = collect()
            ->merge($orders)
            ->merge($invoices)
            ->merge($customers)
            ->merge($vendors)
            ->merge($receipts)
            ->merge($payments)
            ->sortByDesc('sort_date')
            ->values()
            ->take(150)
            ->map(fn (array $row): array => array_diff_key($row, ['sort_date' => true]))
            ->all();

        return response()->json([
            'data' => $rows,
            'summary' => [
                'total' => count($rows),
                'capped' => count($rows) === 150,
            ],
        ]);
    }

    private function searchOrders(array $filters, array $terms, int $tenantId, int $companyId): array
    {
        $query = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereDoesntHave('invoice')
            ->with(['customer:id,name,phone,email,postal_code', 'invoice:id,order_id,uid,invoice_number,status,total_amount,outstanding_amount']);

        $this->applyOrderFilters($query, $filters, $terms);

        return $query->latest('id')->limit(80)->get()->map(function (Order $order) use ($filters, $terms): array {
            return [
                'key' => 'order-' . $order->id,
                'type' => 'Order',
                'reference' => $order->order_number,
                'secondary_reference' => $order->booking_reference ?: $order->voucher_no,
                'customer' => $order->customer?->name,
                'status' => $order->status,
                'date' => optional($order->created_at)->toDateString(),
                'amount' => (float) $order->total_amount,
                'currency_code' => $order->currency_code,
                'matched' => $this->matchedText($order, $filters, $terms),
                'order_uid' => $order->uid,
                'invoice_uid' => $order->invoice?->uid,
                'sort_date' => optional($order->created_at)->timestamp ?? 0,
            ];
        })->all();
    }

    private function searchInvoices(array $filters, array $terms, int $tenantId, int $companyId): array
    {
        $query = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with(['customer:id,name,phone,email,postal_code', 'order:id,uid,order_number,booking_reference,status']);

        $this->applyInvoiceFilters($query, $filters, $terms);

        return $query->latest('id')->limit(80)->get()->map(function (Invoice $invoice) use ($filters, $terms): array {
            return [
                'key' => 'invoice-' . $invoice->id,
                'type' => 'Invoice',
                'reference' => $invoice->invoice_number,
                'secondary_reference' => $invoice->order?->order_number ?: $invoice->order?->booking_reference,
                'customer' => $invoice->customer?->name,
                'status' => $this->invoiceSearchStatus($invoice, $filters),
                'date' => optional($invoice->invoice_date)->toDateString(),
                'amount' => (float) $invoice->total_amount,
                'currency_code' => $invoice->currency_code,
                'matched' => $this->matchedText($invoice, $filters, $terms),
                'order_uid' => $invoice->order?->uid,
                'invoice_uid' => $invoice->uid,
                'sort_date' => optional($invoice->invoice_date)->timestamp ?? optional($invoice->created_at)->timestamp ?? 0,
            ];
        })->all();
    }

    private function searchCustomers(array $filters, array $terms, int $tenantId, int $companyId): array
    {
        if (!$this->shouldSearchStandalone($filters, $terms, ['q', 'customer_name', 'customer_postcode', 'passenger_phone', 'passenger_email', 'passenger_postcode'])) {
            return [];
        }

        $query = Customer::query()->where('tenant_id', $tenantId)->where('company_id', $companyId);
        $this->applyPeopleFilters($query, $filters, $terms);

        return $query->latest('id')->limit(40)->get()->map(fn (Customer $customer): array => [
            'key' => 'customer-' . $customer->id,
            'type' => 'Customer',
            'reference' => $customer->name,
            'secondary_reference' => $customer->email ?: $customer->phone,
            'customer' => $customer->name,
            'status' => $customer->is_active ? 'active' : 'inactive',
            'date' => optional($customer->created_at)->toDateString(),
            'amount' => null,
            'currency_code' => $customer->currency_code,
            'matched' => trim(collect([$customer->email, $customer->phone, $customer->postal_code])->filter()->join(' | ')),
            'order_uid' => null,
            'invoice_uid' => null,
            'sort_date' => optional($customer->created_at)->timestamp ?? 0,
        ])->all();
    }

    private function searchVendors(array $filters, array $terms, int $tenantId, int $companyId): array
    {
        if (!$this->shouldSearchStandalone($filters, $terms, ['q', 'destination'])) {
            return [];
        }

        $query = Vendor::query()->where('tenant_id', $tenantId)->where('company_id', $companyId);
        $this->applyTextSearch($query, ['name', 'email', 'phone', 'postal_code', 'city', 'tax_id', 'payment_terms'], $terms);

        return $query->latest('id')->limit(30)->get()->map(fn (Vendor $vendor): array => [
            'key' => 'vendor-' . $vendor->id,
            'type' => 'Vendor',
            'reference' => $vendor->name,
            'secondary_reference' => $vendor->email ?: $vendor->phone,
            'customer' => null,
            'status' => $vendor->is_active ? 'active' : 'inactive',
            'date' => optional($vendor->created_at)->toDateString(),
            'amount' => null,
            'currency_code' => $vendor->currency_code,
            'matched' => trim(collect([$vendor->city, $vendor->phone, $vendor->postal_code])->filter()->join(' | ')),
            'order_uid' => null,
            'invoice_uid' => null,
            'sort_date' => optional($vendor->created_at)->timestamp ?? 0,
        ])->all();
    }

    private function searchReceipts(array $filters, array $terms, int $tenantId, int $companyId): array
    {
        if (!$this->shouldSearchStandalone($filters, $terms, ['q', 'your_ref', 'customer_name'])) {
            return [];
        }

        $query = Receipt::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with('customer:id,name');

        $this->applyTextSearch($query, ['receipt_number', 'reference_number', 'description', 'payment_method'], $terms);
        $this->applyAmountRange($query, $filters, 'amount');

        return $query->latest('id')->limit(30)->get()->map(fn (Receipt $receipt): array => [
            'key' => 'receipt-' . $receipt->id,
            'type' => 'Receipt',
            'reference' => $receipt->receipt_number,
            'secondary_reference' => $receipt->reference_number,
            'customer' => $receipt->customer?->name,
            'status' => $receipt->payment_method,
            'date' => optional($receipt->receipt_date)->toDateString(),
            'amount' => (float) $receipt->amount,
            'currency_code' => $receipt->currency_code,
            'matched' => $receipt->description,
            'order_uid' => null,
            'invoice_uid' => null,
            'sort_date' => optional($receipt->receipt_date)->timestamp ?? 0,
        ])->all();
    }

    private function searchPayments(array $filters, array $terms, int $tenantId, int $companyId): array
    {
        if (!$this->shouldSearchStandalone($filters, $terms, ['q', 'your_ref', 'customer_name'])) {
            return [];
        }

        $query = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with(['customer:id,name', 'vendor:id,name']);

        $this->applyTextSearch($query, ['payment_number', 'reference_number', 'description', 'payment_method'], $terms);
        $this->applyAmountRange($query, $filters, 'amount');

        return $query->latest('id')->limit(30)->get()->map(fn (Payment $payment): array => [
            'key' => 'payment-' . $payment->id,
            'type' => 'Payment',
            'reference' => $payment->payment_number,
            'secondary_reference' => $payment->reference_number,
            'customer' => $payment->customer?->name ?: $payment->vendor?->name,
            'status' => $payment->payment_method,
            'date' => optional($payment->payment_date)->toDateString(),
            'amount' => (float) $payment->amount,
            'currency_code' => $payment->currency_code,
            'matched' => $payment->description,
            'order_uid' => null,
            'invoice_uid' => null,
            'sort_date' => optional($payment->payment_date)->timestamp ?? 0,
        ])->all();
    }

    private function applyOrderFilters(Builder $query, array $filters, array $terms): void
    {
        $this->applyTextSearch($query, [
            'order_number',
            'voucher_no',
            'booking_reference',
            'package_type',
            'emergency_contact',
            'notes',
            'gds_source',
            'status',
            'meta',
        ], $terms);

        $this->whereAnyLike($query, ['booking_reference', 'meta'], $filters['pnr'] ?? null);
        $this->whereLike($query, 'meta', $filters['airline_pnr'] ?? null);
        $this->whereLike($query, 'order_number', $filters['order_no'] ?? null);
        $this->whereAnyLike($query, ['notes', 'booking_reference', 'voucher_no'], $filters['internal_ref'] ?? null);
        $this->whereAnyLike($query, ['notes', 'booking_reference', 'voucher_no'], $filters['your_ref'] ?? null);
        $this->whereLike($query, 'meta', $filters['lead_passenger'] ?? null);
        $this->whereLike($query, 'meta', $filters['passenger_name'] ?? null);
        $this->whereLike($query, 'meta', $filters['passenger_phone'] ?? null);
        $this->whereLike($query, 'meta', $filters['passenger_email'] ?? null);
        $this->whereLike($query, 'meta', $filters['passenger_postcode'] ?? null);
        $this->whereLike($query, 'meta', $filters['ticket_no'] ?? null);
        $this->whereLike($query, 'meta', $filters['destination'] ?? null);
        $this->whereLike($query, 'meta', $filters['departure_date_from'] ?? null);
        $this->whereLike($query, 'meta', $filters['departure_date_to'] ?? null);
        $this->whereLike($query, 'created_by_user_id', $filters['booked_by'] ?? null);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['customer_name']) || !empty($filters['customer_postcode'])) {
            $query->whereHas('customer', function (Builder $customerQuery) use ($filters): void {
                $this->whereLike($customerQuery, 'name', $filters['customer_name'] ?? null);
                $this->whereLike($customerQuery, 'postal_code', $filters['customer_postcode'] ?? null);
            });
        }

        if (!empty($filters['invoice_no']) || !empty($filters['pay_status'])) {
            $query->whereHas('invoices', function (Builder $invoiceQuery) use ($filters): void {
                $this->whereLike($invoiceQuery, 'invoice_number', $filters['invoice_no'] ?? null);
                $this->whereLike($invoiceQuery, 'status', $filters['pay_status'] ?? null);
            });
        }

        if (!empty($filters['ticket_no'])) {
            $query->where(function (Builder $ticketQuery) use ($filters): void {
                $this->orWhereLike($ticketQuery, 'meta', $filters['ticket_no']);
                $ticketQuery->orWhereHas('items', function (Builder $itemQuery) use ($filters): void {
                    $this->whereAnyLike($itemQuery, ['description', 'gds_data'], $filters['ticket_no']);
                });
            });
        }

        $this->applyDateRange($query, $filters, 'created_at', 'creation_date_from', 'creation_date_to');
        $this->applyDateRange($query, $filters, 'updated_at', 'quote_convert_date_from', 'quote_convert_date_to');
        $this->applyAmountRange($query, $filters, 'total_amount');
    }

    private function applyInvoiceFilters(Builder $query, array $filters, array $terms): void
    {
        if ($terms !== []) {
            $query->where(function (Builder $textQuery) use ($terms): void {
                $this->applyTextSearch($textQuery, ['invoice_number', 'status', 'notes'], $terms);

                foreach ($terms as $term) {
                    $textQuery->orWhereHas('order', function (Builder $orderQuery) use ($term): void {
                        $this->whereAnyLike($orderQuery, [
                            'order_number',
                            'voucher_no',
                            'booking_reference',
                            'package_type',
                            'emergency_contact',
                            'notes',
                            'gds_source',
                            'status',
                            'meta',
                        ], $term);
                    });
                }
            });
        }

        $this->whereLike($query, 'invoice_number', $filters['invoice_no'] ?? null);
        $this->applyCombinedStatusFilter($query, $filters);
        $this->whereLike($query, 'status', $filters['pay_status'] ?? null);
        $this->applyDateRange($query, $filters, 'invoice_date', 'invoice_date_from', 'invoice_date_to');
        $this->applyAmountRange($query, $filters, 'total_amount');

        if (!empty($filters['customer_name']) || !empty($filters['customer_postcode'])) {
            $query->whereHas('customer', function (Builder $customerQuery) use ($filters): void {
                $this->whereLike($customerQuery, 'name', $filters['customer_name'] ?? null);
                $this->whereLike($customerQuery, 'postal_code', $filters['customer_postcode'] ?? null);
            });
        }

        $this->applyInvoiceOrderFilters($query, $filters);
    }

    private function applyInvoiceOrderFilters(Builder $query, array $filters): void
    {
        $orderFilters = [
            'pnr',
            'airline_pnr',
            'internal_ref',
            'order_no',
            'lead_passenger',
            'passenger_name',
            'passenger_phone',
            'passenger_email',
            'passenger_postcode',
            'ticket_no',
            'destination',
            'your_ref',
            'booked_by',
            'departure_date_from',
            'departure_date_to',
            'creation_date_from',
            'creation_date_to',
            'quote_convert_date_from',
            'quote_convert_date_to',
        ];

        if (!collect($orderFilters)->contains(fn (string $key): bool => !empty($filters[$key]))) {
            return;
        }

        $query->whereHas('order', function (Builder $orderQuery) use ($filters): void {
            $this->whereAnyLike($orderQuery, ['booking_reference', 'meta'], $filters['pnr'] ?? null);
            $this->whereLike($orderQuery, 'meta', $filters['airline_pnr'] ?? null);
            $this->whereLike($orderQuery, 'order_number', $filters['order_no'] ?? null);
            $this->whereAnyLike($orderQuery, ['notes', 'booking_reference', 'voucher_no'], $filters['internal_ref'] ?? null);
            $this->whereAnyLike($orderQuery, ['notes', 'booking_reference', 'voucher_no'], $filters['your_ref'] ?? null);
            $this->whereLike($orderQuery, 'meta', $filters['lead_passenger'] ?? null);
            $this->whereLike($orderQuery, 'meta', $filters['passenger_name'] ?? null);
            $this->whereLike($orderQuery, 'meta', $filters['passenger_phone'] ?? null);
            $this->whereLike($orderQuery, 'meta', $filters['passenger_email'] ?? null);
            $this->whereLike($orderQuery, 'meta', $filters['passenger_postcode'] ?? null);
            $this->whereLike($orderQuery, 'meta', $filters['destination'] ?? null);
            $this->whereLike($orderQuery, 'meta', $filters['departure_date_from'] ?? null);
            $this->whereLike($orderQuery, 'meta', $filters['departure_date_to'] ?? null);
            $this->whereLike($orderQuery, 'created_by_user_id', $filters['booked_by'] ?? null);

            if (!empty($filters['ticket_no'])) {
                $orderQuery->where(function (Builder $ticketQuery) use ($filters): void {
                    $this->orWhereLike($ticketQuery, 'meta', $filters['ticket_no']);
                    $ticketQuery->orWhereHas('items', function (Builder $itemQuery) use ($filters): void {
                        $this->whereAnyLike($itemQuery, ['description', 'gds_data'], $filters['ticket_no']);
                    });
                });
            }

            $this->applyDateRange($orderQuery, $filters, 'created_at', 'creation_date_from', 'creation_date_to');
            $this->applyDateRange($orderQuery, $filters, 'updated_at', 'quote_convert_date_from', 'quote_convert_date_to');
        });
    }

    private function applyCombinedStatusFilter(Builder $query, array $filters): void
    {
        if (empty($filters['status'])) {
            return;
        }

        $query->where(function (Builder $statusQuery) use ($filters): void {
            $this->whereLike($statusQuery, 'status', $filters['status']);
            $statusQuery->orWhereHas('order', function (Builder $orderQuery) use ($filters): void {
                $this->whereLike($orderQuery, 'status', $filters['status']);
            });
        });
    }

    private function invoiceSearchStatus(Invoice $invoice, array $filters): ?string
    {
        $status = trim((string) ($filters['status'] ?? ''));

        if ($status === '') {
            return $invoice->status;
        }

        if (stripos((string) $invoice->status, $status) !== false) {
            return $invoice->status;
        }

        if ($invoice->order && stripos((string) $invoice->order->status, $status) !== false) {
            return $invoice->order->status;
        }

        return $invoice->status;
    }

    private function applyPeopleFilters(Builder $query, array $filters, array $terms): void
    {
        $this->applyTextSearch($query, ['name', 'email', 'phone', 'postal_code', 'city', 'tax_id'], $terms);
        $this->whereLike($query, 'name', $filters['customer_name'] ?? null);
        $this->whereLike($query, 'postal_code', $filters['customer_postcode'] ?? null);
        $this->whereLike($query, 'phone', $filters['passenger_phone'] ?? null);
        $this->whereLike($query, 'email', $filters['passenger_email'] ?? null);
        $this->whereLike($query, 'postal_code', $filters['passenger_postcode'] ?? null);
    }

    private function applyTextSearch(Builder $query, array $columns, array $terms): void
    {
        if ($terms === []) {
            return;
        }

        $query->where(function (Builder $textQuery) use ($columns, $terms): void {
            foreach ($terms as $term) {
                foreach ($columns as $column) {
                    $this->orWhereLike($textQuery, $column, $term);
                }
            }
        });
    }

    private function applyDateRange(Builder $query, array $filters, string $column, string $fromKey, string $toKey): void
    {
        if (!empty($filters[$fromKey])) {
            $query->whereDate($column, '>=', $filters[$fromKey]);
        }

        if (!empty($filters[$toKey])) {
            $query->whereDate($column, '<=', $filters[$toKey]);
        }
    }

    private function applyAmountRange(Builder $query, array $filters, string $column): void
    {
        if (isset($filters['amount_from']) && $filters['amount_from'] !== '') {
            $query->where($column, '>=', (float) $filters['amount_from']);
        }

        if (isset($filters['amount_to']) && $filters['amount_to'] !== '') {
            $query->where($column, '<=', (float) $filters['amount_to']);
        }
    }

    private function whereLike(Builder $query, string $column, mixed $value): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $query->where($column, 'like', '%' . $this->escapeLike(trim($value)) . '%');
    }

    private function whereAnyLike(Builder $query, array $columns, mixed $value): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $query->where(function (Builder $anyQuery) use ($columns, $value): void {
            foreach ($columns as $column) {
                $this->orWhereLike($anyQuery, $column, $value);
            }
        });
    }

    private function orWhereLike(Builder $query, string $column, mixed $value): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $query->orWhere($column, 'like', '%' . $this->escapeLike(trim($value)) . '%');
    }

    private function shouldSearchStandalone(array $filters, array $terms, array $allowedKeys): bool
    {
        if ($terms !== []) {
            return true;
        }

        return collect($allowedKeys)->contains(fn (string $key): bool => !empty($filters[$key]));
    }

    private function matchedText(mixed $model, array $filters, array $terms): string
    {
        $values = collect([
            $model->booking_reference ?? null,
            $model->voucher_no ?? null,
            $model->invoice_number ?? null,
            $model->notes ?? null,
        ])->filter();

        $needle = collect($filters)->filter()->first() ?: ($terms[0] ?? null);

        if ($needle) {
            $matched = $values->first(fn ($value): bool => stripos((string) $value, (string) $needle) !== false);
            if ($matched) {
                return (string) $matched;
            }
        }

        return $values->take(2)->join(' | ');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
