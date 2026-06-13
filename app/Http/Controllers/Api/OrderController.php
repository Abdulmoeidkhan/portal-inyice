<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\GdsParserService;
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
            'status' => 'nullable|in:quote,order,confirm,cancel,invoice,refund,void,paid,partial_paid',
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

        $order = DB::transaction(function () use ($validated, $voucher, $user, $tenantId, $companyId, $currencyCode) {
            $order = Order::create([
                'tenant_id' => $tenantId,
                'uid' => (string) Str::ulid(),
                'company_id' => $companyId,
                'customer_id' => (int) $validated['customer_id'],
                'vendor_id' => $validated['vendor_id'] ?? null,
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

        $orders = Order::where('tenant_id', $user->tenant_id)
            ->with(['customer:id,name', 'items:id,order_id,description,total_price'])
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
                'gds_data' => empty($payload) ? null : $payload,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        };

        foreach (($voucher['flights'] ?? []) as $flight) {
            $description = trim(sprintf(
                'Flight %s %s-%s %s %s-%s',
                $flight['flight_no'] ?? '-',
                strtoupper($flight['from'] ?? '-'),
                strtoupper($flight['to'] ?? '-'),
                $flight['date'] ?? '-',
                $flight['departure'] ?? '-',
                $flight['arrival'] ?? '-'
            ));

            $pushItem($description, 0, ['type' => 'flight', 'row' => $flight]);
        }

        $pricingRows = $voucher['pricing'] ?? [];
        foreach ($pricingRows as $pricingRow) {
            $pax = trim((string) ($pricingRow['pax_name'] ?? 'Passenger'));
            $componentMap = [
                'flight_fare' => 'Flight Fare',
                'hotel_price' => 'Hotel',
                'visa_price' => 'Visa',
                'transfer_price' => 'Transfer',
                'city_tour_ziarat_price' => 'City Tour/Ziarat',
            ];

            foreach ($componentMap as $key => $label) {
                $amount = $this->toAmount($pricingRow[$key] ?? null);
                if ($amount <= 0) {
                    continue;
                }

                $pushItem($label . ' - ' . $pax, $amount, ['type' => 'pricing', 'component' => $key, 'row' => $pricingRow]);
            }

            $manualTotal = $this->toAmount($pricingRow['total'] ?? null);
            if ($manualTotal > 0 && array_sum(array_map(fn ($k) => $this->toAmount($pricingRow[$k] ?? null), array_keys($componentMap))) <= 0) {
                $pushItem('Package Total - ' . $pax, $manualTotal, ['type' => 'pricing_total', 'row' => $pricingRow]);
            }
        }

        foreach (($voucher['hotels'] ?? []) as $hotel) {
            $description = trim(sprintf(
                'Hotel %s %s (%s to %s)',
                $hotel['hotel_name'] ?? '-',
                $hotel['city'] ?? '-',
                $hotel['check_in'] ?? '-',
                $hotel['check_out'] ?? '-'
            ));

            $amount = $this->toAmount($hotel['amount'] ?? null);
            $pushItem($description, $amount, ['type' => 'hotel', 'row' => $hotel]);
        }

        foreach (($voucher['transfers'] ?? []) as $transfer) {
            $description = trim(sprintf(
                'Transfer %s %s to %s',
                $transfer['service'] ?? '-',
                $transfer['from_city'] ?? '-',
                $transfer['to_city'] ?? '-'
            ));

            $amount = $this->toAmount($transfer['amount'] ?? null);
            $pushItem($description, $amount, ['type' => 'transfer', 'row' => $transfer]);
        }

        foreach (($voucher['city_tours'] ?? []) as $cityTour) {
            $description = trim(sprintf(
                'City Tour %s - %s (%s)',
                $cityTour['city'] ?? '-',
                $cityTour['title'] ?? '-',
                $cityTour['date'] ?? '-'
            ));

            $amount = $this->toAmount($cityTour['amount'] ?? null);
            $pushItem($description, $amount, ['type' => 'city_tour', 'row' => $cityTour]);
        }

        foreach (($voucher['visa'] ?? []) as $visaRow) {
            $description = trim(sprintf(
                'Visa %s %s',
                $visaRow['passenger_name'] ?? '-',
                $visaRow['visa_no'] ?? ''
            ));

            $amount = $this->toAmount($visaRow['amount'] ?? null);
            $pushItem($description, $amount, ['type' => 'visa', 'row' => $visaRow]);
        }

        foreach (($voucher['other_services'] ?? []) as $otherService) {
            $description = (string) ($otherService['description'] ?? 'Other Service');
            $amount = $this->toAmount($otherService['amount'] ?? null);
            $pushItem($description, $amount, ['type' => 'other_service', 'row' => $otherService]);
        }

        // Keep at least one line item for traceability even when all prices are empty.
        if (empty($items)) {
            $pushItem('Voucher Booking', 0, ['type' => 'voucher']);
        }

        return $items;
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
