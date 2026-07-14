<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderVendorCost;
use App\Services\GdsParserService;
use App\Services\InvoiceService;
use App\Services\OrderNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    private const PACKAGE_TYPES = [
        'Ticket Only',
        'Visa Only',
        'Hotel Only',
        'Transfer Only',
        'Partial Package',
        'Full Package',
        'Holiday Package',
        'Umrah Package',
    ];

    private const OPTIONAL_SECTIONS = [
        'flights',
        'hotels',
        'transfers',
        'city_tours',
        'visa',
        'other_services',
    ];

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
        $this->normalizeVoucherSections($request);

        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $tenantVendor = Rule::exists('vendors', 'id')->where(
            fn ($query) => $query->where('tenant_id', $tenantId)
        );
        $validated = $request->validate([
            'company_id' => 'nullable|integer|exists:companies,id',
            'customer_id' => 'required|integer|exists:customers,id',
            'currency_code' => 'nullable|string|size:3',
            'status' => 'nullable|in:quote,order,confirm,cancel,invoice,void,refund,partial_refund,paid,partial_paid',
            'notes' => 'nullable|string',
            'voucher' => 'required|array',
            'voucher.voucher_no' => 'nullable|string|max:100',
            'voucher.issue_date' => 'nullable|date',
            'voucher.package_type' => ['nullable', 'string', Rule::in(self::PACKAGE_TYPES)],
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
            'voucher.active_sections.*' => ['string', Rule::in(self::OPTIONAL_SECTIONS)],
            'voucher.flights' => 'nullable|array',
            'voucher.flights.*.gds_pnr' => 'nullable|string|max:100',
            'voucher.flights.*.pnr' => 'nullable|string|max:100',
            'voucher.flights.*.flight_no' => 'nullable|string|max:50',
            'voucher.flights.*.from' => 'nullable|string|max:10',
            'voucher.flights.*.to' => 'nullable|string|max:10',
            'voucher.flights.*.date' => 'nullable|date_format:Y-m-d',
            'voucher.flights.*.departure' => 'nullable|date_format:H:i',
            'voucher.flights.*.arrival' => 'nullable|date_format:H:i',
            'voucher.flights.*.cabin' => 'nullable|string|max:50',
            'voucher.flights.*.booking_class' => ['nullable', 'regex:/^[A-Z]$/'],
            'voucher.flights.*.baggage' => 'nullable|string|max:50',
            'voucher.flights.*.notes' => 'nullable|string',
            'voucher.passengers' => 'nullable|array',
            'voucher.passengers.*.name' => 'nullable|string|max:255',
            'voucher.passengers.*.passport_no' => 'nullable|string|max:100',
            'voucher.passengers.*.ticket_no' => 'nullable|string|max:100',
            'voucher.passengers.*.visa_publisher' => 'nullable|string|max:255',
            'voucher.passengers.*.visa_no' => 'nullable|string|max:100',
            'voucher.passengers.*.notes' => 'nullable|string',
            'voucher.pricing' => 'nullable|array',
            'voucher.pricing.*.pax_name' => 'nullable|string|max:255',
            'voucher.pricing.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.pricing.*.vendor_name' => 'nullable|string|max:255',
            'voucher.pricing.*.flight_ticket_no' => ['nullable', 'regex:/^\d+$/'],
            'voucher.pricing.*.flight_cost' => 'nullable|numeric|min:0',
            'voucher.pricing.*.flight_profit' => 'nullable|numeric',
            'voucher.pricing.*.flight_sales' => 'nullable|numeric|min:0',
            'voucher.pricing.*.hotel_cost' => 'nullable|numeric|min:0',
            'voucher.pricing.*.hotel_profit' => 'nullable|numeric',
            'voucher.pricing.*.hotel_sales' => 'nullable|numeric|min:0',
            'voucher.pricing.*.visa_cost' => 'nullable|numeric|min:0',
            'voucher.pricing.*.visa_profit' => 'nullable|numeric',
            'voucher.pricing.*.visa_sales' => 'nullable|numeric|min:0',
            'voucher.pricing.*.transfer_cost' => 'nullable|numeric|min:0',
            'voucher.pricing.*.transfer_profit' => 'nullable|numeric',
            'voucher.pricing.*.transfer_sales' => 'nullable|numeric|min:0',
            'voucher.pricing.*.city_tour_ziarat_cost' => 'nullable|numeric|min:0',
            'voucher.pricing.*.city_tour_ziarat_profit' => 'nullable|numeric',
            'voucher.pricing.*.city_tour_ziarat_sales' => 'nullable|numeric|min:0',
            'voucher.pricing.*.other_service_cost' => 'nullable|numeric|min:0',
            'voucher.pricing.*.other_service_profit' => 'nullable|numeric',
            'voucher.pricing.*.other_service_sales' => 'nullable|numeric|min:0',
            'voucher.pricing.*.total' => 'nullable|numeric|min:0',
            'voucher.hotels' => 'nullable|array',
            'voucher.hotels.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.hotels.*.vendor_name' => 'nullable|string|max:255',
            'voucher.hotels.*.hcn' => 'nullable|string|max:100',
            'voucher.hotels.*.city' => 'nullable|string|max:255',
            'voucher.hotels.*.hotel_name' => 'nullable|string|max:255',
            'voucher.hotels.*.room_type' => 'nullable|string|max:255',
            'voucher.hotels.*.check_in' => 'nullable|date_format:Y-m-d',
            'voucher.hotels.*.check_out' => 'nullable|date_format:Y-m-d',
            'voucher.hotels.*.lead_passenger' => 'nullable|string|max:255',
            'voucher.hotels.*.notes' => 'nullable|string',
            'voucher.hotels.*.cost' => 'nullable|numeric|min:0',
            'voucher.hotels.*.profit' => 'nullable|numeric',
            'voucher.hotels.*.sales' => 'nullable|numeric|min:0',
            'voucher.hotels.*.amount' => 'nullable|numeric|min:0',
            'voucher.transfers' => 'nullable|array',
            'voucher.transfers.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.transfers.*.vendor_name' => 'nullable|string|max:255',
            'voucher.transfers.*.tn' => 'nullable|string|max:100',
            'voucher.transfers.*.service' => 'nullable|string|max:255',
            'voucher.transfers.*.from_city' => 'nullable|string|max:255',
            'voucher.transfers.*.to_city' => 'nullable|string|max:255',
            'voucher.transfers.*.vehicle' => 'nullable|string|max:255',
            'voucher.transfers.*.contact_person' => 'nullable|string|max:255',
            'voucher.transfers.*.notes' => 'nullable|string',
            'voucher.transfers.*.cost' => 'nullable|numeric|min:0',
            'voucher.transfers.*.profit' => 'nullable|numeric',
            'voucher.transfers.*.sales' => 'nullable|numeric|min:0',
            'voucher.transfers.*.amount' => 'nullable|numeric|min:0',
            'voucher.city_tours' => 'nullable|array',
            'voucher.city_tours.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.city_tours.*.vendor_name' => 'nullable|string|max:255',
            'voucher.city_tours.*.city' => 'nullable|string|max:255',
            'voucher.city_tours.*.title' => 'nullable|string|max:255',
            'voucher.city_tours.*.attractions' => 'nullable|string',
            'voucher.city_tours.*.date' => 'nullable|date_format:Y-m-d',
            'voucher.city_tours.*.notes' => 'nullable|string',
            'voucher.city_tours.*.cost' => 'nullable|numeric|min:0',
            'voucher.city_tours.*.profit' => 'nullable|numeric',
            'voucher.city_tours.*.sales' => 'nullable|numeric|min:0',
            'voucher.city_tours.*.amount' => 'nullable|numeric|min:0',
            'voucher.visa' => 'nullable|array',
            'voucher.visa.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.visa.*.passenger_name' => 'nullable|string|max:255',
            'voucher.visa.*.visa_type' => 'nullable|string|max:50',
            'voucher.visa.*.validity' => 'nullable|string|max:100',
            'voucher.visa.*.visa_no' => 'nullable|string|max:100',
            'voucher.visa.*.visa_publisher' => 'nullable|string|max:255',
            'voucher.visa.*.visa_vendor' => 'nullable|string|max:255',
            'voucher.visa.*.notes' => 'nullable|string',
            'voucher.visa.*.cost' => 'nullable|numeric|min:0',
            'voucher.visa.*.profit' => 'nullable|numeric',
            'voucher.visa.*.sales' => 'nullable|numeric|min:0',
            'voucher.visa.*.amount' => 'nullable|numeric|min:0',
            'voucher.other_services' => 'nullable|array',
            'voucher.other_services.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.other_services.*.vendor_name' => 'nullable|string|max:255',
            'voucher.other_services.*.description' => 'nullable|string|max:255',
            'voucher.other_services.*.cost' => 'nullable|numeric|min:0',
            'voucher.other_services.*.profit' => 'nullable|numeric',
            'voucher.other_services.*.sales' => 'nullable|numeric|min:0',
            'voucher.other_services.*.amount' => 'nullable|numeric|min:0',
        ]);

        $companyId = (int) ($validated['company_id'] ?? $user->company_id);
        $company = Company::where('tenant_id', $tenantId)->findOrFail($companyId);
        $customer = Customer::where('tenant_id', $tenantId)->findOrFail((int) $validated['customer_id']);

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

        $order = DB::transaction(function () use ($validated, $voucher, $user, $tenantId, $companyId, $currencyCode, $customer) {
            $order = Order::create([
                'tenant_id' => $tenantId,
                'uid' => (string) Str::ulid(),
                'company_id' => $companyId,
                'customer_id' => $customer->id,
                'created_by_user_id' => (int) $user->id,
                'updated_by_user_id' => (int) $user->id,
                'order_number' => $this->orderNumberService->generateOrderNumber($companyId, $tenantId),
                'voucher_no' => $voucher['voucher_no'] ?? null,
                'issue_date' => $voucher['issue_date'] ?? null,
                'package_type' => $voucher['package_type'] ?? null,
                'active_sections' => $voucher['active_sections'] ?? [],
                'emergency_contact' => $voucher['emergency_contact'] ?? null,
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

            $this->syncVendorCosts($order, $voucher, $tenantId);

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
                        ->orWhere('voucher_no', 'like', "%{$search}%")
                        ->orWhere('issue_date', 'like', "%{$search}%")
                        ->orWhere('package_type', 'like', "%{$search}%")
                        ->orWhere('active_sections', 'like', "%{$search}%")
                        ->orWhere('emergency_contact', 'like', "%{$search}%")
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
        $this->normalizeVoucherSections($request);

        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $tenantVendor = Rule::exists('vendors', 'id')->where(
            fn ($query) => $query->where('tenant_id', $tenantId)
        );
        $order = Order::where('tenant_id', $tenantId)
            ->where('uid', $uid)
            ->firstOrFail();
        $allowedPackageTypes = array_values(array_unique(array_filter([
            ...self::PACKAGE_TYPES,
            $order->package_type,
        ])));

        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'booking_reference' => 'nullable|string|max:50',
            'status' => 'required|in:quote,order,confirm,cancel,invoice,void,refund,partial_refund,paid,partial_paid',
            'currency_code' => 'required|string|size:3|exists:currencies,code',
            'total_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'voucher' => 'nullable|array',
            'voucher.voucher_no' => 'nullable|string|max:100',
            'voucher.issue_date' => 'nullable|date',
            'voucher.package_type' => ['nullable', 'string', Rule::in($allowedPackageTypes)],
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
            'voucher.active_sections.*' => ['string', Rule::in(self::OPTIONAL_SECTIONS)],
            'voucher.flights' => 'nullable|array',
            'voucher.flights.*.gds_pnr' => 'nullable|string|max:100',
            'voucher.flights.*.pnr' => 'nullable|string|max:100',
            'voucher.flights.*.flight_no' => 'nullable|string|max:50',
            'voucher.flights.*.from' => 'nullable|string|max:10',
            'voucher.flights.*.to' => 'nullable|string|max:10',
            'voucher.flights.*.date' => 'nullable|date_format:Y-m-d',
            'voucher.flights.*.departure' => 'nullable|date_format:H:i',
            'voucher.flights.*.arrival' => 'nullable|date_format:H:i',
            'voucher.flights.*.cabin' => 'nullable|string|max:50',
            'voucher.flights.*.booking_class' => ['nullable', 'regex:/^[A-Z]$/'],
            'voucher.flights.*.baggage' => 'nullable|string|max:50',
            'voucher.flights.*.notes' => 'nullable|string',
            'voucher.passengers' => 'nullable|array',
            'voucher.passengers.*.name' => 'nullable|string|max:255',
            'voucher.passengers.*.passport_no' => 'nullable|string|max:100',
            'voucher.passengers.*.ticket_no' => 'nullable|string|max:100',
            'voucher.passengers.*.visa_publisher' => 'nullable|string|max:255',
            'voucher.passengers.*.visa_no' => 'nullable|string|max:100',
            'voucher.passengers.*.notes' => 'nullable|string',
            'voucher.pricing' => 'nullable|array',
            'voucher.pricing.*.pax_name' => 'nullable|string|max:255',
            'voucher.pricing.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.pricing.*.vendor_name' => 'nullable|string|max:255',
            'voucher.pricing.*.flight_ticket_no' => ['nullable', 'regex:/^\d+$/'],
            'voucher.pricing.*.flight_cost' => 'nullable|numeric|min:0',
            'voucher.pricing.*.flight_profit' => 'nullable|numeric',
            'voucher.pricing.*.flight_sales' => 'nullable|numeric|min:0',
            'voucher.pricing.*.hotel_cost' => 'nullable|numeric|min:0',
            'voucher.pricing.*.hotel_profit' => 'nullable|numeric',
            'voucher.pricing.*.hotel_sales' => 'nullable|numeric|min:0',
            'voucher.pricing.*.visa_cost' => 'nullable|numeric|min:0',
            'voucher.pricing.*.visa_profit' => 'nullable|numeric',
            'voucher.pricing.*.visa_sales' => 'nullable|numeric|min:0',
            'voucher.pricing.*.transfer_cost' => 'nullable|numeric|min:0',
            'voucher.pricing.*.transfer_profit' => 'nullable|numeric',
            'voucher.pricing.*.transfer_sales' => 'nullable|numeric|min:0',
            'voucher.pricing.*.city_tour_ziarat_cost' => 'nullable|numeric|min:0',
            'voucher.pricing.*.city_tour_ziarat_profit' => 'nullable|numeric',
            'voucher.pricing.*.city_tour_ziarat_sales' => 'nullable|numeric|min:0',
            'voucher.pricing.*.other_service_cost' => 'nullable|numeric|min:0',
            'voucher.pricing.*.other_service_profit' => 'nullable|numeric',
            'voucher.pricing.*.other_service_sales' => 'nullable|numeric|min:0',
            'voucher.pricing.*.total' => 'nullable|numeric|min:0',
            'voucher.hotels' => 'nullable|array',
            'voucher.hotels.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.hotels.*.vendor_name' => 'nullable|string|max:255',
            'voucher.hotels.*.hcn' => 'nullable|string|max:100',
            'voucher.hotels.*.city' => 'nullable|string|max:255',
            'voucher.hotels.*.hotel_name' => 'nullable|string|max:255',
            'voucher.hotels.*.room_type' => 'nullable|string|max:255',
            'voucher.hotels.*.check_in' => 'nullable|date_format:Y-m-d',
            'voucher.hotels.*.check_out' => 'nullable|date_format:Y-m-d',
            'voucher.hotels.*.lead_passenger' => 'nullable|string|max:255',
            'voucher.hotels.*.notes' => 'nullable|string',
            'voucher.hotels.*.cost' => 'nullable|numeric|min:0',
            'voucher.hotels.*.profit' => 'nullable|numeric',
            'voucher.hotels.*.sales' => 'nullable|numeric|min:0',
            'voucher.hotels.*.amount' => 'nullable|numeric|min:0',
            'voucher.transfers' => 'nullable|array',
            'voucher.transfers.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.transfers.*.vendor_name' => 'nullable|string|max:255',
            'voucher.transfers.*.tn' => 'nullable|string|max:100',
            'voucher.transfers.*.service' => 'nullable|string|max:255',
            'voucher.transfers.*.from_city' => 'nullable|string|max:255',
            'voucher.transfers.*.to_city' => 'nullable|string|max:255',
            'voucher.transfers.*.vehicle' => 'nullable|string|max:255',
            'voucher.transfers.*.contact_person' => 'nullable|string|max:255',
            'voucher.transfers.*.notes' => 'nullable|string',
            'voucher.transfers.*.cost' => 'nullable|numeric|min:0',
            'voucher.transfers.*.profit' => 'nullable|numeric',
            'voucher.transfers.*.sales' => 'nullable|numeric|min:0',
            'voucher.transfers.*.amount' => 'nullable|numeric|min:0',
            'voucher.city_tours' => 'nullable|array',
            'voucher.city_tours.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.city_tours.*.vendor_name' => 'nullable|string|max:255',
            'voucher.city_tours.*.city' => 'nullable|string|max:255',
            'voucher.city_tours.*.title' => 'nullable|string|max:255',
            'voucher.city_tours.*.attractions' => 'nullable|string',
            'voucher.city_tours.*.date' => 'nullable|date_format:Y-m-d',
            'voucher.city_tours.*.notes' => 'nullable|string',
            'voucher.city_tours.*.cost' => 'nullable|numeric|min:0',
            'voucher.city_tours.*.profit' => 'nullable|numeric',
            'voucher.city_tours.*.sales' => 'nullable|numeric|min:0',
            'voucher.city_tours.*.amount' => 'nullable|numeric|min:0',
            'voucher.visa' => 'nullable|array',
            'voucher.visa.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.visa.*.passenger_name' => 'nullable|string|max:255',
            'voucher.visa.*.visa_type' => 'nullable|string|max:50',
            'voucher.visa.*.validity' => 'nullable|string|max:100',
            'voucher.visa.*.visa_no' => 'nullable|string|max:100',
            'voucher.visa.*.visa_publisher' => 'nullable|string|max:255',
            'voucher.visa.*.visa_vendor' => 'nullable|string|max:255',
            'voucher.visa.*.notes' => 'nullable|string',
            'voucher.visa.*.cost' => 'nullable|numeric|min:0',
            'voucher.visa.*.profit' => 'nullable|numeric',
            'voucher.visa.*.sales' => 'nullable|numeric|min:0',
            'voucher.visa.*.amount' => 'nullable|numeric|min:0',
            'voucher.other_services' => 'nullable|array',
            'voucher.other_services.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.other_services.*.vendor_name' => 'nullable|string|max:255',
            'voucher.other_services.*.description' => 'nullable|string|max:255',
            'voucher.other_services.*.cost' => 'nullable|numeric|min:0',
            'voucher.other_services.*.profit' => 'nullable|numeric',
            'voucher.other_services.*.sales' => 'nullable|numeric|min:0',
            'voucher.other_services.*.amount' => 'nullable|numeric|min:0',
        ]);

        $customer = Customer::where('tenant_id', $tenantId)->findOrFail((int) $validated['customer_id']);
        DB::transaction(function () use ($order, $validated, $customer, $user, $tenantId): void {
            $voucher = $validated['voucher'] ?? null;
            $totalAmount = $validated['total_amount'] ?? $order->total_amount;
            $meta = $order->meta ?? [];

            if ($voucher) {
                if ($this->hasVoucherItemContent($voucher)) {
                    $items = $this->buildVoucherOrderItems($voucher, $order->id, $tenantId);

                    OrderItem::where('order_id', $order->id)->delete();

                    if (!empty($items)) {
                        OrderItem::insert($items);
                    }

                    $this->syncVendorCosts($order, $voucher, $tenantId);

                    $totalAmount = OrderItem::where('order_id', $order->id)->sum('total_price');
                }

                $meta = array_merge($meta, [
                    'voucher_no' => $voucher['voucher_no'] ?? null,
                    'issue_date' => $voucher['issue_date'] ?? null,
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
                'voucher_no' => $voucher['voucher_no'] ?? $order->voucher_no,
                'issue_date' => $voucher['issue_date'] ?? $order->issue_date,
                'package_type' => $voucher['package_type'] ?? $order->package_type,
                'active_sections' => $voucher['active_sections'] ?? $order->active_sections,
                'emergency_contact' => $voucher['emergency_contact'] ?? $order->emergency_contact,
                'booking_reference' => $validated['booking_reference'] ?? $voucher['booking_reference'] ?? $order->booking_reference,
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
                $amount = $this->salesAmount($hotel);
                if ($amount <= 0 && !$this->hasFilledValue($hotel, ['hcn', 'city', 'hotel_name', 'room_type', 'check_in', 'check_out', 'lead_passenger', 'vendor_id', 'vendor_name', 'notes'])) {
                    continue;
                }

                $description = trim(sprintf(
                    'Hotel %s %s (%s to %s)%s',
                    $hotel['hotel_name'] ?? '-',
                    $hotel['city'] ?? '-',
                    $hotel['check_in'] ?? '-',
                    $hotel['check_out'] ?? '-',
                    $this->vendorDescription($hotel)
                ));

                $pushItem($description, $amount, ['type' => 'hotel', 'row' => $hotel]);
            }
        }

        if ($hasActiveSection('transfers')) {
            foreach (($voucher['transfers'] ?? []) as $transfer) {
                $amount = $this->salesAmount($transfer);
                if ($amount <= 0 && !$this->hasFilledValue($transfer, ['tn', 'service', 'from_city', 'to_city', 'vehicle', 'contact_person', 'vendor_id', 'vendor_name', 'notes'])) {
                    continue;
                }

                $description = trim(sprintf(
                    'Transfer %s %s to %s%s',
                    $transfer['service'] ?? '-',
                    $transfer['from_city'] ?? '-',
                    $transfer['to_city'] ?? '-',
                    $this->vendorDescription($transfer)
                ));

                $pushItem($description, $amount, ['type' => 'transfer', 'row' => $transfer]);
            }
        }

        if ($hasActiveSection('city_tours')) {
            foreach (($voucher['city_tours'] ?? []) as $cityTour) {
                $amount = $this->salesAmount($cityTour);
                if ($amount <= 0 && !$this->hasFilledValue($cityTour, ['city', 'title', 'attractions', 'date', 'vendor_id', 'vendor_name', 'notes'])) {
                    continue;
                }

                $description = trim(sprintf(
                    'City Tour %s - %s (%s)%s',
                    $cityTour['city'] ?? '-',
                    $cityTour['title'] ?? '-',
                    $cityTour['date'] ?? '-',
                    $this->vendorDescription($cityTour)
                ));

                $pushItem($description, $amount, ['type' => 'city_tour', 'row' => $cityTour]);
            }
        }

        if ($hasActiveSection('visa')) {
            foreach (($voucher['visa'] ?? []) as $visaRow) {
                $amount = $this->salesAmount($visaRow);
                if ($amount <= 0 && !$this->hasFilledValue($visaRow, ['passenger_name', 'validity', 'visa_no', 'visa_publisher', 'vendor_id', 'visa_vendor', 'notes'])) {
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
                $amount = $this->salesAmount($otherService);
                if ($amount <= 0 && !$this->hasFilledValue($otherService, ['description', 'vendor_id', 'vendor_name'])) {
                    continue;
                }

                $description = trim((string) ($otherService['description'] ?? 'Other Service') . $this->vendorDescription($otherService));
                $pushItem($description, $amount, ['type' => 'other_service', 'row' => $otherService]);
            }
        }

        // Keep at least one line item for traceability even when all prices are empty.
        if (empty($items)) {
            $pushItem('Voucher Booking', 0, ['type' => 'voucher']);
        }

        return $items;
    }

    private function normalizeVoucherSections(Request $request): void
    {
        $voucher = $request->input('voucher');

        if (!is_array($voucher) || !array_key_exists('active_sections', $voucher)) {
            return;
        }

        $activeSections = array_values(array_intersect(
            array_filter((array) $voucher['active_sections'], fn ($section): bool => is_string($section)),
            self::OPTIONAL_SECTIONS
        ));
        $hasActiveSection = fn (string $section): bool => in_array($section, $activeSections, true);

        $voucher['active_sections'] = $activeSections;

        foreach (self::OPTIONAL_SECTIONS as $section) {
            if (!$hasActiveSection($section)) {
                $voucher[$section] = [];
            }
        }

        if (!$hasActiveSection('flights')) {
            $voucher['pricing'] = [];
        }

        $request->merge(['voucher' => $voucher]);
    }

    private function hasVoucherItemContent(array $voucher): bool
    {
        $activeSections = $voucher['active_sections'] ?? [];
        $hasActiveSection = fn (string $section): bool => empty($activeSections) || in_array($section, $activeSections, true);

        if ($hasActiveSection('flights')) {
            foreach (($voucher['flights'] ?? []) as $flight) {
                if ($this->hasFilledValue($flight, ['gds_pnr', 'pnr', 'flight_no', 'from', 'to', 'date', 'departure', 'arrival'])) {
                    return true;
                }
            }

            foreach (($voucher['pricing'] ?? []) as $pricingRow) {
                if (
                    $this->toAmount($pricingRow['flight_sales'] ?? $pricingRow['flight_fare'] ?? null) > 0 ||
                    $this->toAmount($pricingRow['total'] ?? null) > 0
                ) {
                    return true;
                }
            }
        }

        $serviceRows = [
            'hotels' => ['hcn', 'city', 'hotel_name', 'room_type', 'check_in', 'check_out', 'lead_passenger', 'vendor_id', 'vendor_name', 'notes', 'cost', 'profit', 'sales', 'amount'],
            'transfers' => ['tn', 'service', 'from_city', 'to_city', 'vehicle', 'contact_person', 'vendor_id', 'vendor_name', 'notes', 'cost', 'profit', 'sales', 'amount'],
            'city_tours' => ['city', 'title', 'attractions', 'date', 'vendor_id', 'vendor_name', 'notes', 'cost', 'profit', 'sales', 'amount'],
            'visa' => ['passenger_name', 'validity', 'visa_no', 'visa_publisher', 'vendor_id', 'visa_vendor', 'notes', 'cost', 'profit', 'sales', 'amount'],
            'other_services' => ['description', 'vendor_id', 'vendor_name', 'cost', 'profit', 'sales', 'amount'],
        ];

        foreach ($serviceRows as $section => $keys) {
            if (!$hasActiveSection($section)) {
                continue;
            }

            foreach (($voucher[$section] ?? []) as $row) {
                if ($this->hasFilledValue($row, $keys)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function syncVendorCosts(Order $order, array $voucher, int $tenantId): void
    {
        $rows = [];
        $timestamp = now();

        foreach (($voucher['pricing'] ?? []) as $index => $pricing) {
            $vendorId = (int) ($pricing['vendor_id'] ?? 0);
            $amount = $this->toAmount($pricing['flight_cost'] ?? null);
            if ($vendorId <= 0 || $amount <= 0) {
                continue;
            }

            $rows[] = [
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'vendor_id' => $vendorId,
                'service_type' => 'flight',
                'service_index' => (int) $index,
                'amount' => $amount,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        $costSections = [
            'hotels' => 'hotel',
            'transfers' => 'transfer',
            'city_tours' => 'city_tour',
            'visa' => 'visa',
            'other_services' => 'other_service',
        ];

        foreach ($costSections as $section => $serviceType) {
            foreach (($voucher[$section] ?? []) as $index => $serviceRow) {
                $vendorId = (int) ($serviceRow['vendor_id'] ?? 0);
                $amount = $this->costAmount($serviceRow);
                if ($vendorId <= 0 || $amount <= 0) {
                    continue;
                }

                $rows[] = [
                    'tenant_id' => $tenantId,
                    'order_id' => $order->id,
                    'vendor_id' => $vendorId,
                    'service_type' => $serviceType,
                    'service_index' => (int) $index,
                    'amount' => $amount,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        OrderVendorCost::where('order_id', $order->id)->delete();
        if ($rows !== []) {
            OrderVendorCost::insert($rows);
        }
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

    private function salesAmount(array $row): float
    {
        return $this->toAmount($this->firstFilledValue($row['sales'] ?? null, $row['amount'] ?? null));
    }

    private function costAmount(array $row): float
    {
        return $this->toAmount($this->firstFilledValue($row['cost'] ?? null, $row['amount'] ?? null));
    }

    private function vendorDescription(array $row): string
    {
        $vendorName = trim((string) ($row['vendor_name'] ?? $row['visa_vendor'] ?? ''));

        return $vendorName !== '' ? ' Vendor: ' . $vendorName : '';
    }

    private function firstFilledValue(mixed ...$values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
