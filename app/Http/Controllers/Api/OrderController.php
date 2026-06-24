<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Vendor;
use App\Services\GdsParserService;
use App\Services\InvoiceService;
use App\Services\OrderNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function __construct(
        private readonly GdsParserService $gdsParserService,
        private readonly OrderNumberService $orderNumberService,
        private readonly InvoiceService $invoiceService,
    ) {
    }

    /**
     * Parse raw GDS text and store parsed record for later order creation.
     */
    public function parseGds(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gds_source' => 'required|in:sabre,galileo',
            'raw_text' => 'required|string|min:10',
        ]);

        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $gdsSource = strtolower($validated['gds_source']);

        $parsed = $gdsSource === 'sabre'
            ? $this->gdsParserService->parseSabreText($validated['raw_text'], (int) $user->id, $tenantId)
            : $this->gdsParserService->parseGalileoText($validated['raw_text'], (int) $user->id, $tenantId);

        $record = $this->gdsParserService->storeRecord(
            $validated['raw_text'],
            $gdsSource,
            $parsed,
            (int) $user->id,
            $tenantId,
        );

        return response()->json([
            'success' => true,
            'gds_record' => [
                'id' => $record->id,
                'uid' => $record->uid,
                'booking_reference' => $record->booking_reference,
                'gds_source' => $record->gds_source,
            ],
            'parsed' => $record->getExtractedData(),
        ]);
    }

    /**
     * Create a full order from voucher-style payload.
     */
    public function createFromVoucher(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'nullable|integer|exists:companies,id',
            'customer_id' => 'required|integer|exists:customers,id',
            'vendor_id' => 'nullable|integer|exists:vendors,id',
            'currency_code' => 'nullable|string|size:3',
            'status' => 'nullable|in:quote,order,confirm,cancel,invoice,void,refund,partial_refund,paid,partial_paid',
            'notes' => 'nullable|string',
            'voucher' => 'required|array',
            'voucher.voucher_no' => 'nullable|string|max:100',
            'voucher.issue_date' => 'nullable|date',
            'voucher.travel_type' => 'nullable|string|max:100',
            'voucher.package_type' => 'nullable|string|max:100',
            'voucher.booking_reference' => 'nullable|string|max:100',
            'voucher.gds_source' => 'nullable|in:sabre,galileo,amadeus,other',
            'voucher.gds_parsed_record_id' => 'nullable|integer|exists:gds_parsed_records,id',
            'voucher.emergency_contact' => 'nullable|string',
            'voucher.contact' => 'nullable|array',
            'voucher.contact.company_name' => 'nullable|string|max:255',
            'voucher.contact.executive_name' => 'nullable|string|max:255',
            'voucher.contact.email' => 'nullable|string|max:255',
            'voucher.contact.phone' => 'nullable|string|max:100',
            'voucher.contact.address' => 'nullable|string',
            'voucher.active_sections' => 'nullable|array',
            'voucher.flights' => 'nullable|array',
            'voucher.passengers' => 'nullable|array',
            'voucher.pricing' => 'nullable|array',
            'voucher.hotels' => 'nullable|array',
            'voucher.transfers' => 'nullable|array',
            'voucher.city_tours' => 'nullable|array',
            'voucher.visa' => 'nullable|array',
            'voucher.other_services' => 'nullable|array',
        ]);

        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        $companyId = (int) ($validated['company_id'] ?? $user->company_id);
        $company = Company::where('tenant_id', $tenantId)->findOrFail($companyId);
        $customer = Customer::where('tenant_id', $tenantId)->findOrFail((int) $validated['customer_id']);
        $vendorId = null;

        if (!empty($validated['vendor_id'])) {
            $vendorId = Vendor::where('tenant_id', $tenantId)->findOrFail((int) $validated['vendor_id'])->id;
        }

        $voucher = $validated['voucher'];
        $currencyCode = strtoupper($validated['currency_code'] ?? $company->base_currency_code);

        $profileContact = array_filter([
            'company_name' => $company->display_name,
            'executive_name' => $user->name,
            'email' => $company->email ?: $user->email,
            'phone' => $company->phone,
            'address' => $company->address,
        ], fn ($value) => $value !== null && $value !== '');

        $voucherContact = array_filter(
            $voucher['contact'] ?? [],
            fn ($value) => $value !== null && $value !== ''
        );

        $voucher['contact'] = array_merge($profileContact, $voucherContact);

        $order = DB::transaction(function () use ($validated, $voucher, $user, $tenantId, $companyId, $currencyCode, $customer, $vendorId) {
            $order = Order::create([
                'tenant_id' => $tenantId,
                'uid' => (string) Str::ulid(),
                'company_id' => $companyId,
                'customer_id' => $customer->id,
                'vendor_id' => $vendorId,
                'created_by_user_id' => (int) $user->id,
                'updated_by_user_id' => (int) $user->id,
                'order_number' => $this->orderNumberService->generateOrderNumber($companyId, $tenantId),
                'booking_reference' => $voucher['booking_reference'] ?? null,
                'status' => $validated['status'] ?? 'order',
                'currency_code' => $currencyCode,
                'total_amount' => 0,
                'notes' => $validated['notes'] ?? null,
                'gds_source' => $voucher['gds_source'] ?? null,
                'gds_parsed_record_id' => $voucher['gds_parsed_record_id'] ?? null,
                'meta' => [
                    'voucher_no' => $voucher['voucher_no'] ?? null,
                    'issue_date' => $voucher['issue_date'] ?? null,
                    'travel_type' => $voucher['travel_type'] ?? null,
                    'package_type' => $voucher['package_type'] ?? null,
                    'emergency_contact' => $voucher['emergency_contact'] ?? null,
                    'contact' => $voucher['contact'] ?? [],
                    'active_sections' => $voucher['active_sections'] ?? [],
                    'flights' => $voucher['flights'] ?? [],
                    'passengers' => $voucher['passengers'] ?? [],
                    'pricing' => $voucher['pricing'] ?? [],
                    'hotels' => $voucher['hotels'] ?? [],
                    'transfers' => $voucher['transfers'] ?? [],
                    'city_tours' => $voucher['city_tours'] ?? [],
                    'visa' => $voucher['visa'] ?? [],
                    'other_services' => $voucher['other_services'] ?? [],
                ],
            ]);

            $items = $this->buildVoucherOrderItems($voucher, $order->id, $tenantId);

            if (!empty($items)) {
                OrderItem::insert($items);
            }

            $total = OrderItem::where('order_id', $order->id)->sum('total_price');
            $order->update(['total_amount' => $total]);

            return $order;
        });

        return response()->json([
            'success' => true,
            'order' => $order->load('items'),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $search = trim((string) $request->query('search', ''));

        $orders = Order::where('tenant_id', $user->tenant_id)
            ->with(['customer:id,name', 'items:id,order_id,description,total_price', 'invoice:id,order_id,uid,invoice_number,status,outstanding_amount'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery->where('order_number', 'like', "%{$search}%")
                        ->orWhere('booking_reference', 'like', "%{$search}%")
                        ->orWhere('gds_source', 'like', "%{$search}%")
                        ->orWhere('meta->voucher_no', 'like', "%{$search}%")
                        ->orWhere('meta->issue_date', 'like', "%{$search}%")
                        ->orWhere('meta->package_type', 'like', "%{$search}%")
                        ->orWhere('meta->gds_source', 'like', "%{$search}%")
                        ->orWhere('meta->active_sections', 'like', "%{$search}%")
                        ->orWhere('meta->emergency_contact', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($customerQuery) use ($search) {
                            $customerQuery->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 20));

        return response()->json($orders);
    }

    public function show(string $uid, Request $request): JsonResponse
    {
        $order = Order::where('tenant_id', $request->user()->tenant_id)
            ->where('uid', $uid)
            ->with(['customer', 'vendor', 'items', 'gdsParsedRecord'])
            ->firstOrFail();

        return response()->json($order);
    }

    public function update(string $uid, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'vendor_id' => 'nullable|integer|exists:vendors,id',
            'booking_reference' => 'nullable|string|max:50',
            'status' => 'required|in:quote,order,confirm,cancel,invoice,void,refund,partial_refund,paid,partial_paid',
            'currency_code' => 'required|string|size:3|exists:currencies,code',
            'total_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'voucher' => 'nullable|array',
            'voucher.voucher_no' => 'nullable|string|max:100',
            'voucher.issue_date' => 'nullable|date',
            'voucher.travel_type' => 'nullable|string|max:100',
            'voucher.package_type' => 'nullable|string|max:100',
            'voucher.booking_reference' => 'nullable|string|max:100',
            'voucher.gds_source' => 'nullable|in:sabre,galileo,amadeus,other',
            'voucher.gds_parsed_record_id' => 'nullable|integer|exists:gds_parsed_records,id',
            'voucher.emergency_contact' => 'nullable|string',
            'voucher.contact' => 'nullable|array',
            'voucher.contact.company_name' => 'nullable|string|max:255',
            'voucher.contact.executive_name' => 'nullable|string|max:255',
            'voucher.contact.email' => 'nullable|string|max:255',
            'voucher.contact.phone' => 'nullable|string|max:100',
            'voucher.contact.address' => 'nullable|string',
            'voucher.active_sections' => 'nullable|array',
            'voucher.flights' => 'nullable|array',
            'voucher.passengers' => 'nullable|array',
            'voucher.pricing' => 'nullable|array',
            'voucher.hotels' => 'nullable|array',
            'voucher.transfers' => 'nullable|array',
            'voucher.city_tours' => 'nullable|array',
            'voucher.visa' => 'nullable|array',
            'voucher.other_services' => 'nullable|array',
        ]);

        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        $order = Order::where('tenant_id', $tenantId)
            ->where('uid', $uid)
            ->firstOrFail();

        $customer = Customer::where('tenant_id', $tenantId)->findOrFail((int) $validated['customer_id']);
        $vendorId = null;

        if (!empty($validated['vendor_id'])) {
            $vendorId = Vendor::where('tenant_id', $tenantId)->findOrFail((int) $validated['vendor_id'])->id;
        }

        DB::transaction(function () use ($order, $validated, $customer, $vendorId, $user, $tenantId): void {
            $voucher = $validated['voucher'] ?? null;
            $totalAmount = $validated['total_amount'] ?? $order->total_amount;
            $meta = $order->meta ?? [];

            if ($voucher) {
                $items = $this->buildVoucherOrderItems($voucher, $order->id, $tenantId);

                OrderItem::where('order_id', $order->id)->delete();

                if (!empty($items)) {
                    OrderItem::insert($items);
                }

                $totalAmount = OrderItem::where('order_id', $order->id)->sum('total_price');
                $meta = array_merge($meta, [
                    'voucher_no' => $voucher['voucher_no'] ?? null,
                    'issue_date' => $voucher['issue_date'] ?? null,
                    'travel_type' => $voucher['travel_type'] ?? null,
                    'package_type' => $voucher['package_type'] ?? null,
                    'emergency_contact' => $voucher['emergency_contact'] ?? null,
                    'contact' => $voucher['contact'] ?? [],
                    'active_sections' => $voucher['active_sections'] ?? [],
                    'flights' => $voucher['flights'] ?? [],
                    'passengers' => $voucher['passengers'] ?? [],
                    'pricing' => $voucher['pricing'] ?? [],
                    'hotels' => $voucher['hotels'] ?? [],
                    'transfers' => $voucher['transfers'] ?? [],
                    'city_tours' => $voucher['city_tours'] ?? [],
                    'visa' => $voucher['visa'] ?? [],
                    'other_services' => $voucher['other_services'] ?? [],
                ]);
            }

            $order->update([
                'customer_id' => $customer->id,
                'vendor_id' => $vendorId,
                'booking_reference' => $validated['booking_reference'] ?? $voucher['booking_reference'] ?? null,
                'status' => $validated['status'],
                'currency_code' => strtoupper($validated['currency_code']),
                'total_amount' => $totalAmount,
                'notes' => $validated['notes'] ?? null,
                'gds_source' => $voucher['gds_source'] ?? $order->gds_source,
                'gds_parsed_record_id' => $voucher['gds_parsed_record_id'] ?? $order->gds_parsed_record_id,
                'meta' => $meta,
                'updated_by_user_id' => (int) $user->id,
            ]);

            if ($validated['status'] === 'invoice') {
                $this->invoiceService->createFromOrder($order->fresh());
            }
        });

        return response()->json([
            'success' => true,
            'order' => $order->fresh(['customer:id,name', 'vendor:id,name', 'items:id,order_id,description,total_price', 'invoice']),
        ]);
    }

    public function destroy(string $uid, Request $request): JsonResponse
    {
        $order = Order::where('tenant_id', $request->user()->tenant_id)
            ->where('uid', $uid)
            ->firstOrFail();

        if (Invoice::where('order_id', $order->id)->exists()) {
            return response()->json([
                'error' => 'Cannot delete an order that already has an invoice.',
            ], 422);
        }

        $order->delete();

        return response()->json([
            'success' => true,
            'message' => 'Order deleted successfully',
        ]);
    }

    private function buildVoucherOrderItems(array $voucher, int $orderId, int $tenantId): array
    {
        $items = [];

        $pushItem = function (string $description, float $amount, array $payload = []) use (&$items, $orderId, $tenantId): void {
            $items[] = [
                'uid' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'order_id' => $orderId,
                'description' => $description,
                'quantity' => 1,
                'unit_price' => round($amount, 4),
                'total_price' => round($amount, 4),
                'gds_data' => empty($payload) ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        };

        $activeSections = $voucher['active_sections'] ?? [];
        $hasActiveSection = fn (string $section): bool => empty($activeSections) || in_array($section, $activeSections, true);

        if ($hasActiveSection('flights')) {
            foreach (($voucher['flights'] ?? []) as $flight) {
                if (!$this->hasFilledValue($flight, ['gds_pnr', 'pnr', 'flight_no', 'from', 'to', 'date', 'departure', 'arrival'])) {
                    continue;
                }

                $description = trim(sprintf(
                    'Flight %s %s-%s %s %s-%s%s',
                    $flight['flight_no'] ?? '-',
                    strtoupper($flight['from'] ?? '-'),
                    strtoupper($flight['to'] ?? '-'),
                    $flight['date'] ?? '-',
                    $flight['departure'] ?? '-',
                    $flight['arrival'] ?? '-',
                    !empty($flight['vendor_name']) ? ' Vendor: ' . $flight['vendor_name'] : ''
                ));

                $pushItem($description, 0, ['type' => 'flight', 'row' => $flight]);
            }
        }

        if ($hasActiveSection('flights')) {
            $pricingRows = $voucher['pricing'] ?? [];
            foreach ($pricingRows as $pricingRow) {
                $pax = trim((string) ($pricingRow['pax_name'] ?? 'Passenger'));
                $componentMap = [
                    'flight_sales' => ['label' => 'Flight Fare', 'legacy' => 'flight_fare'],
                ];

                foreach ($componentMap as $key => $component) {
                    $amount = $this->toAmount($pricingRow[$key] ?? $pricingRow[$component['legacy']] ?? null);
                    if ($amount <= 0) {
                        continue;
                    }

                    $label = $component['label'];
                    if ($key === 'flight_sales' && !empty($pricingRow['flight_ticket_no'])) {
                        $label .= ' Ticket: ' . $pricingRow['flight_ticket_no'];
                    }

                    $pushItem($label . ' - ' . $pax, $amount, ['type' => 'pricing', 'component' => $key, 'row' => $pricingRow]);
                }

                $manualTotal = $this->toAmount($pricingRow['total'] ?? null);
                $componentTotal = array_sum(array_map(
                    fn ($key, $component) => $this->toAmount($pricingRow[$key] ?? $pricingRow[$component['legacy']] ?? null),
                    array_keys($componentMap),
                    $componentMap
                ));
                if ($manualTotal > 0 && $componentTotal <= 0) {
                    $pushItem('Package Total - ' . $pax, $manualTotal, ['type' => 'pricing_total', 'row' => $pricingRow]);
                }
            }
        }

        if ($hasActiveSection('hotels')) {
            foreach (($voucher['hotels'] ?? []) as $hotel) {
                $amount = $this->toAmount($hotel['amount'] ?? null);
                if ($amount <= 0 && !$this->hasFilledValue($hotel, ['hcn', 'city', 'hotel_name', 'room_type', 'check_in', 'check_out', 'lead_passenger', 'notes'])) {
                    continue;
                }

                $description = trim(sprintf(
                    'Hotel %s %s (%s to %s)',
                    $hotel['hotel_name'] ?? '-',
                    $hotel['city'] ?? '-',
                    $hotel['check_in'] ?? '-',
                    $hotel['check_out'] ?? '-'
                ));

                $pushItem($description, $amount, ['type' => 'hotel', 'row' => $hotel]);
            }
        }

        if ($hasActiveSection('transfers')) {
            foreach (($voucher['transfers'] ?? []) as $transfer) {
                $amount = $this->toAmount($transfer['amount'] ?? null);
                if ($amount <= 0 && !$this->hasFilledValue($transfer, ['tn', 'service', 'from_city', 'to_city', 'vehicle', 'contact_person', 'notes'])) {
                    continue;
                }

                $description = trim(sprintf(
                    'Transfer %s %s to %s',
                    $transfer['service'] ?? '-',
                    $transfer['from_city'] ?? '-',
                    $transfer['to_city'] ?? '-'
                ));

                $pushItem($description, $amount, ['type' => 'transfer', 'row' => $transfer]);
            }
        }

        if ($hasActiveSection('city_tours')) {
            foreach (($voucher['city_tours'] ?? []) as $cityTour) {
                $amount = $this->toAmount($cityTour['amount'] ?? null);
                if ($amount <= 0 && !$this->hasFilledValue($cityTour, ['city', 'title', 'attractions', 'date', 'notes'])) {
                    continue;
                }

                $description = trim(sprintf(
                    'City Tour %s - %s (%s)',
                    $cityTour['city'] ?? '-',
                    $cityTour['title'] ?? '-',
                    $cityTour['date'] ?? '-'
                ));

                $pushItem($description, $amount, ['type' => 'city_tour', 'row' => $cityTour]);
            }
        }

        if ($hasActiveSection('visa')) {
            foreach (($voucher['visa'] ?? []) as $visaRow) {
                $amount = $this->toAmount($visaRow['amount'] ?? null);
                if ($amount <= 0 && !$this->hasFilledValue($visaRow, ['passenger_name', 'validity', 'visa_no', 'vendor_id', 'visa_vendor', 'notes'])) {
                    continue;
                }

                $description = trim(sprintf(
                    'Visa %s %s %s%s%s',
                    $visaRow['passenger_name'] ?? '-',
                    $visaRow['visa_type'] ?? '',
                    $visaRow['visa_no'] ?? '',
                    !empty($visaRow['validity']) ? ' Validity: ' . $visaRow['validity'] : '',
                    !empty($visaRow['visa_vendor']) ? ' Vendor: ' . $visaRow['visa_vendor'] : ''
                ));

                $pushItem($description, $amount, ['type' => 'visa', 'row' => $visaRow]);
            }
        }

        if ($hasActiveSection('other_services')) {
            foreach (($voucher['other_services'] ?? []) as $otherService) {
                $amount = $this->toAmount($otherService['amount'] ?? null);
                if ($amount <= 0 && !$this->hasFilledValue($otherService, ['description'])) {
                    continue;
                }

                $description = (string) ($otherService['description'] ?? 'Other Service');
                $pushItem($description, $amount, ['type' => 'other_service', 'row' => $otherService]);
            }
        }

        // Keep at least one line item for traceability even when all prices are empty.
        if (empty($items)) {
            $pushItem('Voucher Booking', 0, ['type' => 'voucher']);
        }

        return $items;
    }

    private function hasFilledValue(array $row, array $keys): bool
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;

            if ($value !== null && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function toAmount(mixed $value): float
    {
        if ($value === null) {
            return 0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = preg_replace('/[^0-9.\-]/', '', $value) ?: '0';
            return is_numeric($normalized) ? (float) $normalized : 0;
        }

        return 0;
    }
}
