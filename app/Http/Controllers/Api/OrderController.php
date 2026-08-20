<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderVendorCost;
use App\Models\User;
use App\Services\GdsParserService;
use App\Services\InvoiceService;
use App\Services\OrderNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    private const ORDER_STATUSES = [
        'quote',
        'order',
        'confirm',
        'cancel',
        'invoice',
        'void',
        'refund_request',
        'refund',
        'partial_refund',
        'paid',
        'partial_paid',
    ];

    private const PRICING_COST_PROFIT_FIELDS = [
        'flight_cost',
        'flight_profit',
        'hotel_cost',
        'hotel_profit',
        'visa_cost',
        'visa_profit',
        'transfer_cost',
        'transfer_profit',
        'city_tour_ziarat_cost',
        'city_tour_ziarat_profit',
        'other_service_cost',
        'other_service_profit',
    ];

    private const SERVICE_COST_PROFIT_FIELDS = [
        'cost',
        'profit',
    ];

    private const INVOICE_SECTION_STATUSES = [
        'invoice',
        'void',
        'refund',
        'partial_refund',
        'paid',
        'partial_paid',
    ];

    private const ORDER_TABLE_EXCLUDED_STATUSES = [
        'cancel',
        'invoice',
        'void',
        'refund',
        'partial_refund',
        'paid',
        'partial_paid',
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
        $companyId = (int) $user->company_id;
        $tenantVendor = Rule::exists('vendors', 'id')->where(
            fn ($query) => $query->where('tenant_id', $tenantId)->where('company_id', $companyId)
        );
        $validated = $request->validate([
            'company_id' => 'nullable|integer|exists:companies,id',
            'customer_id' => 'required|integer|exists:customers,id',
            'currency_code' => 'nullable|string|size:3',
            'status' => ['nullable', Rule::in(self::ORDER_STATUSES)],
            'cancel_password' => 'nullable|string',
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
            'voucher.pricing.*.flight_cost' => 'nullable|numeric',
            'voucher.pricing.*.flight_profit' => 'nullable|numeric',
            'voucher.pricing.*.flight_sales' => 'nullable|numeric',
            'voucher.pricing.*.hotel_cost' => 'nullable|numeric',
            'voucher.pricing.*.hotel_profit' => 'nullable|numeric',
            'voucher.pricing.*.hotel_sales' => 'nullable|numeric',
            'voucher.pricing.*.visa_cost' => 'nullable|numeric',
            'voucher.pricing.*.visa_profit' => 'nullable|numeric',
            'voucher.pricing.*.visa_sales' => 'nullable|numeric',
            'voucher.pricing.*.transfer_cost' => 'nullable|numeric',
            'voucher.pricing.*.transfer_profit' => 'nullable|numeric',
            'voucher.pricing.*.transfer_sales' => 'nullable|numeric',
            'voucher.pricing.*.city_tour_ziarat_cost' => 'nullable|numeric',
            'voucher.pricing.*.city_tour_ziarat_profit' => 'nullable|numeric',
            'voucher.pricing.*.city_tour_ziarat_sales' => 'nullable|numeric',
            'voucher.pricing.*.other_service_cost' => 'nullable|numeric',
            'voucher.pricing.*.other_service_profit' => 'nullable|numeric',
            'voucher.pricing.*.other_service_sales' => 'nullable|numeric',
            'voucher.pricing.*.total' => 'nullable|numeric',
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
            'voucher.hotels.*.cost' => 'nullable|numeric',
            'voucher.hotels.*.profit' => 'nullable|numeric',
            'voucher.hotels.*.sales' => 'nullable|numeric',
            'voucher.hotels.*.amount' => 'nullable|numeric',
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
            'voucher.transfers.*.cost' => 'nullable|numeric',
            'voucher.transfers.*.profit' => 'nullable|numeric',
            'voucher.transfers.*.sales' => 'nullable|numeric',
            'voucher.transfers.*.amount' => 'nullable|numeric',
            'voucher.city_tours' => 'nullable|array',
            'voucher.city_tours.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.city_tours.*.vendor_name' => 'nullable|string|max:255',
            'voucher.city_tours.*.city' => 'nullable|string|max:255',
            'voucher.city_tours.*.title' => 'nullable|string|max:255',
            'voucher.city_tours.*.attractions' => 'nullable|string',
            'voucher.city_tours.*.date' => 'nullable|date_format:Y-m-d',
            'voucher.city_tours.*.notes' => 'nullable|string',
            'voucher.city_tours.*.cost' => 'nullable|numeric',
            'voucher.city_tours.*.profit' => 'nullable|numeric',
            'voucher.city_tours.*.sales' => 'nullable|numeric',
            'voucher.city_tours.*.amount' => 'nullable|numeric',
            'voucher.visa' => 'nullable|array',
            'voucher.visa.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.visa.*.passenger_name' => 'nullable|string|max:255',
            'voucher.visa.*.visa_type' => 'nullable|string|max:50',
            'voucher.visa.*.validity' => 'nullable|string|max:100',
            'voucher.visa.*.visa_no' => 'nullable|string|max:100',
            'voucher.visa.*.visa_publisher' => 'nullable|string|max:255',
            'voucher.visa.*.visa_vendor' => 'nullable|string|max:255',
            'voucher.visa.*.notes' => 'nullable|string',
            'voucher.visa.*.cost' => 'nullable|numeric',
            'voucher.visa.*.profit' => 'nullable|numeric',
            'voucher.visa.*.sales' => 'nullable|numeric',
            'voucher.visa.*.amount' => 'nullable|numeric',
            'voucher.other_services' => 'nullable|array',
            'voucher.other_services.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.other_services.*.vendor_name' => 'nullable|string|max:255',
            'voucher.other_services.*.description' => 'nullable|string|max:255',
            'voucher.other_services.*.quantity' => 'nullable|integer|min:1',
            'voucher.other_services.*.cost' => 'nullable|numeric',
            'voucher.other_services.*.profit' => 'nullable|numeric',
            'voucher.other_services.*.sales' => 'nullable|numeric',
            'voucher.other_services.*.amount' => 'nullable|numeric',
            'voucher.discounts' => 'nullable|array',
            'voucher.discounts.*.discount_type' => ['nullable', 'string', Rule::in(['amount', 'percentage'])],
            'voucher.discounts.*.amount' => 'nullable|numeric|min:0',
            'voucher.discounts.*.percentage' => 'nullable|numeric|min:0|max:100',
            'voucher.discounts.*.reason' => 'nullable|string|max:500',
        ]);

        $company = Company::where('tenant_id', $tenantId)->findOrFail($companyId);

        $customer = Customer::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail((int) $validated['customer_id']);

        $voucher = $this->voucherForUserWrite($validated['voucher'], null, $user);
        $currencyCode = strtoupper($validated['currency_code'] ?? $company->base_currency_code);
        $status = $validated['status'] ?? 'order';

        if ($status === 'cancel') {
            $passwordResponse = $this->validateCancelPassword($user, $validated['cancel_password'] ?? null);
            if ($passwordResponse) {
                return $passwordResponse;
            }
        }

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

        $order = DB::transaction(function () use ($validated, $voucher, $user, $tenantId, $companyId, $currencyCode, $customer, $status) {
            $meta = [
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
                'discounts' => $voucher['discounts'] ?? [],
            ];

            if ($status === 'cancel') {
                $meta = $this->metaWithCancelApproval($meta, $user);
            }

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
                'status' => $status,
                'currency_code' => $currencyCode,
                'total_amount' => 0,
                'notes' => $validated['notes'] ?? null,
                'gds_source' => $voucher['gds_source'] ?? null,
                'gds_parsed_record_id' => $voucher['gds_parsed_record_id'] ?? null,
                'meta' => $meta,
            ]);

            $items = $this->buildVoucherOrderItems($voucher, $order->id, $tenantId);

            if (!empty($items)) {
                OrderItem::insert($items);
            }

            $order->syncVendorCostsFromVoucher($voucher);

            $total = OrderItem::where('order_id', $order->id)->sum('total_price');
            $order->update(['total_amount' => $total]);

            return $order;
        });

        return response()->json([
            'success' => true,
            'order' => $this->orderForUser($order->load('items'), $user),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $orders = Order::where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->with(['customer:id,name', 'items:id,order_id,description,total_price', 'invoice:id,order_id,uid,invoice_number,status,outstanding_amount'])
            ->when(!is_string($status) || $status === '', function ($query) {
                $query->whereNotIn('status', self::ORDER_TABLE_EXCLUDED_STATUSES);
            })
            ->when(is_string($status) && $status !== '', function ($query) use ($status) {
                if (in_array($status, self::ORDER_TABLE_EXCLUDED_STATUSES, true)) {
                    $query->whereRaw('1 = 0');
                    return;
                }

                $query->where('status', $status);
            })
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
            ->paginate(max(1, min(100, (int) $request->query('per_page', 20))));

        if ($this->shouldHideCostProfit($user)) {
            $orders->getCollection()->transform(fn (Order $order) => $this->orderForUser($order, $user));
        }

        return response()->json($orders);
    }

    public function show(string $uid, Request $request): JsonResponse
    {
        $order = Order::where('tenant_id', $request->user()->tenant_id)
            ->where('company_id', $request->user()->company_id)
            ->where('uid', $uid)
            ->with(['customer', 'vendor', 'items', 'gdsParsedRecord', 'company', 'invoices.lines'])
            ->firstOrFail();

        return response()->json($this->orderForUser($order, $request->user()));
    }

    public function share(string $uid, Request $request): JsonResponse
    {
        $order = Order::where('tenant_id', $request->user()->tenant_id)
            ->where('company_id', $request->user()->company_id)
            ->where('uid', $uid)
            ->firstOrFail();

        $shareToken = $order->ensureShareToken();

        return response()->json([
            'share_url' => url('/shared/vouchers/' . $shareToken),
            'share_token' => $shareToken,
        ]);
    }

    public function revokeShare(string $uid, Request $request): JsonResponse
    {
        $order = Order::where('tenant_id', $request->user()->tenant_id)
            ->where('company_id', $request->user()->company_id)
            ->where('uid', $uid)
            ->firstOrFail();

        $order->update(['share_token' => null]);

        return response()->json(['success' => true]);
    }

    public function shared(string $token): JsonResponse
    {
        $order = Order::where('share_token', $token)
            ->with(['customer', 'items', 'company'])
            ->firstOrFail();

        $order->makeHidden([
            'share_token',
            'tenant_id',
            'company_id',
            'customer_id',
            'vendor_id',
            'created_by_user_id',
            'updated_by_user_id',
            'gds_parsed_record_id',
            'deleted_at',
            'updated_at',
        ]);
        $order->customer?->setVisible(['name', 'email', 'phone', 'address', 'city', 'country_code', 'postal_code']);
        $order->company?->setVisible(['legal_name', 'display_name', 'email', 'phone', 'address', 'country_code', 'logo_url', 'footer_logo_url', 'is_paid', 'sales_can_edit_cost']);
        $order->items->each->setVisible(['id', 'description', 'quantity', 'unit_price', 'total_price']);

        return response()->json($this->normalizeResponseText($order->toArray()));
    }

    public function update(string $uid, Request $request): JsonResponse
    {
        $this->normalizeVoucherSections($request);

        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $companyId = (int) $user->company_id;
        $tenantVendor = Rule::exists('vendors', 'id')->where(
            fn ($query) => $query->where('tenant_id', $tenantId)->where('company_id', $companyId)
        );
        $order = Order::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('uid', $uid)
            ->firstOrFail();
        $allowedPackageTypes = array_values(array_unique(array_filter([
            ...self::PACKAGE_TYPES,
            $order->package_type,
        ])));

        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'booking_reference' => 'nullable|string|max:50',
            'status' => ['required', Rule::in(self::ORDER_STATUSES)],
            'currency_code' => 'required|string|size:3|exists:currencies,code',
            'total_amount' => 'nullable|numeric',
            'notes' => 'nullable|string',
            'cancel_password' => 'nullable|string',
            'confirm_invoice_revision' => 'nullable|boolean',
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
            'voucher.pricing.*.flight_cost' => 'nullable|numeric',
            'voucher.pricing.*.flight_profit' => 'nullable|numeric',
            'voucher.pricing.*.flight_sales' => 'nullable|numeric',
            'voucher.pricing.*.hotel_cost' => 'nullable|numeric',
            'voucher.pricing.*.hotel_profit' => 'nullable|numeric',
            'voucher.pricing.*.hotel_sales' => 'nullable|numeric',
            'voucher.pricing.*.visa_cost' => 'nullable|numeric',
            'voucher.pricing.*.visa_profit' => 'nullable|numeric',
            'voucher.pricing.*.visa_sales' => 'nullable|numeric',
            'voucher.pricing.*.transfer_cost' => 'nullable|numeric',
            'voucher.pricing.*.transfer_profit' => 'nullable|numeric',
            'voucher.pricing.*.transfer_sales' => 'nullable|numeric',
            'voucher.pricing.*.city_tour_ziarat_cost' => 'nullable|numeric',
            'voucher.pricing.*.city_tour_ziarat_profit' => 'nullable|numeric',
            'voucher.pricing.*.city_tour_ziarat_sales' => 'nullable|numeric',
            'voucher.pricing.*.other_service_cost' => 'nullable|numeric',
            'voucher.pricing.*.other_service_profit' => 'nullable|numeric',
            'voucher.pricing.*.other_service_sales' => 'nullable|numeric',
            'voucher.pricing.*.total' => 'nullable|numeric',
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
            'voucher.hotels.*.cost' => 'nullable|numeric',
            'voucher.hotels.*.profit' => 'nullable|numeric',
            'voucher.hotels.*.sales' => 'nullable|numeric',
            'voucher.hotels.*.amount' => 'nullable|numeric',
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
            'voucher.transfers.*.cost' => 'nullable|numeric',
            'voucher.transfers.*.profit' => 'nullable|numeric',
            'voucher.transfers.*.sales' => 'nullable|numeric',
            'voucher.transfers.*.amount' => 'nullable|numeric',
            'voucher.city_tours' => 'nullable|array',
            'voucher.city_tours.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.city_tours.*.vendor_name' => 'nullable|string|max:255',
            'voucher.city_tours.*.city' => 'nullable|string|max:255',
            'voucher.city_tours.*.title' => 'nullable|string|max:255',
            'voucher.city_tours.*.attractions' => 'nullable|string',
            'voucher.city_tours.*.date' => 'nullable|date_format:Y-m-d',
            'voucher.city_tours.*.notes' => 'nullable|string',
            'voucher.city_tours.*.cost' => 'nullable|numeric',
            'voucher.city_tours.*.profit' => 'nullable|numeric',
            'voucher.city_tours.*.sales' => 'nullable|numeric',
            'voucher.city_tours.*.amount' => 'nullable|numeric',
            'voucher.visa' => 'nullable|array',
            'voucher.visa.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.visa.*.passenger_name' => 'nullable|string|max:255',
            'voucher.visa.*.visa_type' => 'nullable|string|max:50',
            'voucher.visa.*.validity' => 'nullable|string|max:100',
            'voucher.visa.*.visa_no' => 'nullable|string|max:100',
            'voucher.visa.*.visa_publisher' => 'nullable|string|max:255',
            'voucher.visa.*.visa_vendor' => 'nullable|string|max:255',
            'voucher.visa.*.notes' => 'nullable|string',
            'voucher.visa.*.cost' => 'nullable|numeric',
            'voucher.visa.*.profit' => 'nullable|numeric',
            'voucher.visa.*.sales' => 'nullable|numeric',
            'voucher.visa.*.amount' => 'nullable|numeric',
            'voucher.other_services' => 'nullable|array',
            'voucher.other_services.*.vendor_id' => ['nullable', 'integer', $tenantVendor],
            'voucher.other_services.*.vendor_name' => 'nullable|string|max:255',
            'voucher.other_services.*.description' => 'nullable|string|max:255',
            'voucher.other_services.*.quantity' => 'nullable|integer|min:1',
            'voucher.other_services.*.cost' => 'nullable|numeric',
            'voucher.other_services.*.profit' => 'nullable|numeric',
            'voucher.other_services.*.sales' => 'nullable|numeric',
            'voucher.other_services.*.amount' => 'nullable|numeric',
            'voucher.discounts' => 'nullable|array',
            'voucher.discounts.*.discount_type' => ['nullable', 'string', Rule::in(['amount', 'percentage'])],
            'voucher.discounts.*.amount' => 'nullable|numeric|min:0',
            'voucher.discounts.*.percentage' => 'nullable|numeric|min:0|max:100',
            'voucher.discounts.*.reason' => 'nullable|string|max:500',
        ]);

        $customer = Customer::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail((int) $validated['customer_id']);

        if (
            $this->isSalesStaff($user)
            && $this->isInvoiceSectionStatus((string) $order->status)
            && $validated['status'] !== $order->status
        ) {
            return response()->json([
                'error' => 'Sales staff cannot change order status after it enters the invoice section.',
            ], 403);
        }

        $activeInvoice = Invoice::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('order_id', $order->id)
            ->whereNotIn('status', ['void', 'cancel'])
            ->latest('id')
            ->first();
        $previousStatus = (string) $order->status;
        $isCancellingOrder = $validated['status'] === 'cancel' && $previousStatus !== 'cancel';

        if ($validated['status'] === 'invoice' && !$this->isInvoiceSectionStatus($previousStatus)) {
            return response()->json([
                'error' => 'Create invoices from the order invoicing action so an invoice date can be recorded.',
            ], 422);
        }

        if ($isCancellingOrder) {
            $passwordResponse = $this->validateCancelPassword($user, $validated['cancel_password'] ?? null);
            if ($passwordResponse) {
                return $passwordResponse;
            }
        }

        if ($activeInvoice && !$isCancellingOrder && !$request->boolean('confirm_invoice_revision')) {
            return response()->json([
                'error' => 'This order already has an invoice. Saving changes will cancel the current invoice and create a new order that can be invoiced manually.',
                'requires_invoice_revision' => true,
                'invoice' => $activeInvoice->only(['uid', 'invoice_number', 'status', 'total_amount', 'outstanding_amount']),
            ], 409);
        }

        $createdOrder = null;
        $createdInvoice = null;
        $voidedInvoice = null;

        DB::transaction(function () use ($order, $validated, $customer, $user, $tenantId, $companyId, $activeInvoice, $previousStatus, $isCancellingOrder, &$createdOrder, &$createdInvoice, &$voidedInvoice): void {
            $voucher = isset($validated['voucher'])
                ? $this->voucherForUserWrite($validated['voucher'], $order->meta ?? [], $user)
                : null;
            $totalAmount = $validated['total_amount'] ?? $order->total_amount;
            $meta = $order->meta ?? [];

            if ($voucher) {
                if ($this->hasVoucherItemContent($voucher)) {
                    $items = $this->buildVoucherOrderItems($voucher, $order->id, $tenantId);

                    OrderItem::where('order_id', $order->id)->delete();

                    if (!empty($items)) {
                        OrderItem::insert($items);
                    }

                    $order->syncVendorCostsFromVoucher($voucher);

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
                    'discounts' => $voucher['discounts'] ?? [],
                ]);
            }

            if ($activeInvoice && $isCancellingOrder) {
                $voidedInvoice = $this->cancelInvoiceForCancelledOrder($activeInvoice, $order, $user);
                $order->update([
                    'customer_id' => $customer->id,
                    'voucher_no' => $voucher['voucher_no'] ?? $order->voucher_no,
                    'issue_date' => $voucher['issue_date'] ?? $order->issue_date,
                    'package_type' => $voucher['package_type'] ?? $order->package_type,
                    'active_sections' => $voucher['active_sections'] ?? $order->active_sections,
                    'emergency_contact' => $voucher['emergency_contact'] ?? $order->emergency_contact,
                    'booking_reference' => $validated['booking_reference'] ?? $voucher['booking_reference'] ?? $order->booking_reference,
                    'status' => 'cancel',
                    'currency_code' => strtoupper($validated['currency_code']),
                    'total_amount' => $totalAmount,
                    'notes' => $validated['notes'] ?? null,
                    'gds_source' => $voucher['gds_source'] ?? $order->gds_source,
                    'gds_parsed_record_id' => $voucher['gds_parsed_record_id'] ?? $order->gds_parsed_record_id,
                    'meta' => $this->metaWithCancelApproval($meta, $user),
                    'updated_by_user_id' => (int) $user->id,
                ]);

                return;
            }

            if ($activeInvoice) {
                $createdOrder = Order::create([
                    'tenant_id' => $tenantId,
                    'uid' => (string) Str::ulid(),
                    'company_id' => $companyId,
                    'customer_id' => $customer->id,
                    'vendor_id' => $order->vendor_id,
                    'created_by_user_id' => (int) $user->id,
                    'updated_by_user_id' => (int) $user->id,
                    'order_number' => $this->orderNumberService->generateOrderNumber($companyId, $tenantId),
                    'voucher_no' => $voucher['voucher_no'] ?? $order->voucher_no,
                    'issue_date' => $voucher['issue_date'] ?? $order->issue_date,
                    'package_type' => $voucher['package_type'] ?? $order->package_type,
                    'active_sections' => $voucher['active_sections'] ?? $order->active_sections,
                    'emergency_contact' => $voucher['emergency_contact'] ?? $order->emergency_contact,
                    'booking_reference' => $validated['booking_reference'] ?? $voucher['booking_reference'] ?? $order->booking_reference,
                    'status' => $validated['status'] === 'quote' ? 'quote' : 'order',
                    'currency_code' => strtoupper($validated['currency_code']),
                    'total_amount' => 0,
                    'notes' => $validated['notes'] ?? null,
                    'gds_source' => $voucher['gds_source'] ?? $order->gds_source,
                    'gds_parsed_record_id' => $voucher['gds_parsed_record_id'] ?? $order->gds_parsed_record_id,
                    'meta' => $meta,
                ]);

                if ($voucher && $this->hasVoucherItemContent($voucher)) {
                    $items = $this->buildVoucherOrderItems($voucher, $createdOrder->id, $tenantId);

                    if (!empty($items)) {
                        OrderItem::insert($items);
                    }
                } else {
                    $this->copyOrderItems($order, $createdOrder);
                }

                $createdOrder->syncVendorCostsFromVoucher($voucher ?: $meta);
                $createdOrder->update([
                    'total_amount' => OrderItem::where('order_id', $createdOrder->id)->sum('total_price') ?: $totalAmount,
                ]);

                $voidedInvoice = $this->voidInvoiceForEditedOrder($activeInvoice, $createdOrder);
                $order->update([
                    'status' => 'cancel',
                    'meta' => $this->metaWithCancelApproval($order->meta ?? [], $user, $createdOrder),
                    'updated_by_user_id' => (int) $user->id,
                ]);

                return;
            }

            $order->update([
                'customer_id' => $customer->id,
                'voucher_no' => $voucher['voucher_no'] ?? $order->voucher_no,
                'issue_date' => $voucher['issue_date'] ?? $order->issue_date,
                'package_type' => $voucher['package_type'] ?? $order->package_type,
                'active_sections' => $voucher['active_sections'] ?? $order->active_sections,
                'emergency_contact' => $voucher['emergency_contact'] ?? $order->emergency_contact,
                'booking_reference' => $validated['booking_reference'] ?? $voucher['booking_reference'] ?? $order->booking_reference,
                'status' => $activeInvoice ? 'invoice' : $validated['status'],
                'currency_code' => strtoupper($validated['currency_code']),
                'total_amount' => $totalAmount,
                'notes' => $validated['notes'] ?? null,
                'gds_source' => $voucher['gds_source'] ?? $order->gds_source,
                'gds_parsed_record_id' => $voucher['gds_parsed_record_id'] ?? $order->gds_parsed_record_id,
                'meta' => $isCancellingOrder
                    ? $this->metaWithCancelApproval($meta, $user)
                    : $meta,
                'updated_by_user_id' => (int) $user->id,
            ]);

        });

        $responseOrder = $createdOrder
            ? $createdOrder->fresh(['customer:id,name', 'vendor:id,name', 'items:id,order_id,description,total_price', 'invoice'])
            : $order->fresh(['customer:id,name', 'vendor:id,name', 'items:id,order_id,description,total_price', 'invoice']);

        return response()->json([
            'success' => true,
            'order' => $this->orderForUser($responseOrder, $user),
            'invoice' => $createdInvoice,
            'voided_invoice' => $voidedInvoice,
            'new_order_created' => (bool) ($activeInvoice && $createdOrder),
            'invoice_revised' => false,
            'original_order_uid' => $activeInvoice ? $order->uid : null,
        ]);
    }

    public function recreateCancelled(string $uid, Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $companyId = (int) $user->company_id;

        $sourceOrder = Order::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('uid', $uid)
            ->where('status', 'cancel')
            ->firstOrFail();

        $sourceMeta = is_array($sourceOrder->meta) ? $sourceOrder->meta : [];
        if (!empty($sourceMeta['cancel_approval']['new_order_uid']) || !empty($sourceMeta['cancel_signature']['new_order_uid'])) {
            return response()->json([
                'error' => 'A new order has already been created from this cancelled order.',
            ], 422);
        }

        $newOrder = DB::transaction(function () use ($sourceOrder, $user, $tenantId, $companyId): Order {
            $sourceMeta = is_array($sourceOrder->meta) ? $sourceOrder->meta : [];
            $newMeta = $sourceMeta;
            $newMeta['recreated_from_cancelled_order'] = [
                'order_id' => $sourceOrder->id,
                'order_uid' => $sourceOrder->uid,
                'order_number' => $sourceOrder->order_number,
                'recreated_by_user_id' => (int) $user->id,
                'recreated_by_name' => $user->name,
                'recreated_by_role' => $user->role?->code,
                'recreated_at' => now()->toDateTimeString(),
            ];

            $newOrder = Order::create([
                'tenant_id' => $tenantId,
                'uid' => (string) Str::ulid(),
                'company_id' => $companyId,
                'customer_id' => $sourceOrder->customer_id,
                'vendor_id' => $sourceOrder->vendor_id,
                'created_by_user_id' => (int) $user->id,
                'updated_by_user_id' => (int) $user->id,
                'order_number' => $this->orderNumberService->generateOrderNumber($companyId, $tenantId),
                'voucher_no' => $sourceOrder->voucher_no,
                'issue_date' => now()->toDateString(),
                'package_type' => $sourceOrder->package_type,
                'active_sections' => $sourceOrder->active_sections,
                'emergency_contact' => $sourceOrder->emergency_contact,
                'booking_reference' => $sourceOrder->booking_reference,
                'status' => 'order',
                'currency_code' => $sourceOrder->currency_code,
                'total_amount' => 0,
                'notes' => trim("Recreated from cancelled order {$sourceOrder->order_number}.\n" . (string) $sourceOrder->notes),
                'gds_source' => $sourceOrder->gds_source,
                'gds_parsed_record_id' => $sourceOrder->gds_parsed_record_id,
                'meta' => $newMeta,
            ]);

            $this->copyOrderItems($sourceOrder, $newOrder);
            $newOrder->syncVendorCostsFromVoucher($newMeta);
            $newOrder->update([
                'total_amount' => OrderItem::where('order_id', $newOrder->id)->sum('total_price') ?: (float) $sourceOrder->total_amount,
            ]);

            $sourceMeta['cancel_approval'] = $sourceMeta['cancel_approval'] ?? ($sourceMeta['cancel_signature'] ?? []);
            unset($sourceMeta['cancel_approval']['signature']);
            unset($sourceMeta['cancel_signature']);
            $sourceMeta['cancel_approval']['new_order_id'] = $newOrder->id;
            $sourceMeta['cancel_approval']['new_order_uid'] = $newOrder->uid;
            $sourceMeta['cancel_approval']['new_order_number'] = $newOrder->order_number;

            $sourceOrder->update([
                'meta' => $sourceMeta,
                'updated_by_user_id' => (int) $user->id,
            ]);

            return $newOrder;
        });

        return response()->json([
            'success' => true,
            'message' => "New order {$newOrder->order_number} created from cancelled order {$sourceOrder->order_number}.",
            'order' => $this->orderForUser($newOrder->fresh(['customer:id,name', 'vendor:id,name', 'items:id,order_id,description,total_price', 'invoice']), $user),
            'original_order_uid' => $sourceOrder->uid,
            'original_order_number' => $sourceOrder->order_number,
        ], 201);
    }

    public function duplicate(string $uid, Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $companyId = (int) $user->company_id;

        $sourceOrder = Order::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('uid', $uid)
            ->with(['items', 'vendorCosts'])
            ->firstOrFail();

        $newOrder = DB::transaction(function () use ($sourceOrder, $user, $tenantId, $companyId): Order {
            $sourceMeta = is_array($sourceOrder->meta) ? $sourceOrder->meta : [];
            $newMeta = $sourceMeta;
            unset($newMeta['cancel_approval'], $newMeta['cancel_signature'], $newMeta['recreated_from_cancelled_order']);
            $newMeta['duplicated_from_order'] = [
                'order_id' => $sourceOrder->id,
                'order_uid' => $sourceOrder->uid,
                'order_number' => $sourceOrder->order_number,
                'duplicated_by_user_id' => (int) $user->id,
                'duplicated_by_name' => $user->name,
                'duplicated_at' => now()->toDateTimeString(),
            ];

            $newOrder = Order::create([
                'tenant_id' => $tenantId,
                'uid' => (string) Str::ulid(),
                'company_id' => $companyId,
                'customer_id' => $sourceOrder->customer_id,
                'vendor_id' => $sourceOrder->vendor_id,
                'created_by_user_id' => (int) $user->id,
                'updated_by_user_id' => (int) $user->id,
                'order_number' => $this->orderNumberService->generateOrderNumber($companyId, $tenantId),
                'voucher_no' => null,
                'issue_date' => now()->toDateString(),
                'package_type' => $sourceOrder->package_type,
                'active_sections' => $sourceOrder->active_sections,
                'emergency_contact' => $sourceOrder->emergency_contact,
                'booking_reference' => $sourceOrder->booking_reference,
                'status' => 'order',
                'currency_code' => $sourceOrder->currency_code,
                'total_amount' => 0,
                'notes' => trim("Duplicated from order {$sourceOrder->order_number}.\n" . (string) $sourceOrder->notes),
                'gds_source' => $sourceOrder->gds_source,
                'gds_parsed_record_id' => $sourceOrder->gds_parsed_record_id,
                'meta' => $newMeta,
            ]);

            $this->copyOrderItems($sourceOrder, $newOrder);
            $this->copyVendorCosts($sourceOrder, $newOrder);
            $newOrder->update([
                'total_amount' => OrderItem::where('order_id', $newOrder->id)->sum('total_price') ?: (float) $sourceOrder->total_amount,
            ]);

            return $newOrder;
        });

        return response()->json([
            'success' => true,
            'message' => "Order {$newOrder->order_number} duplicated from {$sourceOrder->order_number}.",
            'order' => $this->orderForUser($newOrder->fresh(['customer:id,name', 'vendor:id,name', 'items:id,order_id,description,total_price', 'invoice']), $user),
            'source_order_uid' => $sourceOrder->uid,
            'source_order_number' => $sourceOrder->order_number,
        ], 201);
    }

    public function updateBookedBy(string $uid, Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $companyId = (int) $user->company_id;

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
        ]);

        $bookedBy = User::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->findOrFail((int) $validated['user_id']);

        $order = Order::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('uid', $uid)
            ->firstOrFail();

        $order->update([
            'created_by_user_id' => $bookedBy->id,
            'updated_by_user_id' => (int) $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Booked by changed to {$bookedBy->name}.",
            'order' => $this->orderForUser($order->fresh(['customer:id,name', 'vendor:id,name', 'items:id,order_id,description,total_price', 'invoice', 'createdBy:id,name,email']), $user),
        ]);
    }

    public function createRefundRequest(string $uid, Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $companyId = (int) $user->company_id;

        $sourceOrder = Order::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('uid', $uid)
            ->with(['items', 'invoice', 'vendorCosts.vendor'])
            ->firstOrFail();

        if ((string) $sourceOrder->status === 'refund_request') {
            return response()->json([
                'error' => 'This order is already a refund request.',
            ], 422);
        }

        $activeInvoice = Invoice::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('order_id', $sourceOrder->id)
            ->whereNotIn('status', ['void', 'cancel'])
            ->latest('id')
            ->first();

        if (!$activeInvoice && !$this->isInvoiceSectionStatus((string) $sourceOrder->status)) {
            return response()->json([
                'error' => 'Create a refund request after the order has moved to the invoice section.',
            ], 422);
        }

        if ($this->refundOrderExistsFor($sourceOrder)) {
            return response()->json([
                'error' => 'A refund request or refund order already exists for this invoice.',
            ], 422);
        }

        $company = Company::where('tenant_id', $tenantId)->findOrFail($companyId);

        $refundOrder = DB::transaction(function () use ($sourceOrder, $user, $tenantId, $companyId): Order {
            $sourceMeta = is_array($sourceOrder->meta) ? $sourceOrder->meta : [];
            $refundMeta = $this->negativeRefundVoucher($sourceMeta);
            $refundMeta['refund_of_order_id'] = $sourceOrder->id;
            $refundMeta['refund_of_order_uid'] = $sourceOrder->uid;
            $refundMeta['refund_of_order_number'] = $sourceOrder->order_number;
            $refundMeta['refund_request_created_at'] = now()->toIso8601String();

            $refundOrder = Order::create([
                'tenant_id' => $tenantId,
                'uid' => (string) Str::ulid(),
                'company_id' => $companyId,
                'customer_id' => $sourceOrder->customer_id,
                'vendor_id' => $sourceOrder->vendor_id,
                'created_by_user_id' => (int) $user->id,
                'updated_by_user_id' => (int) $user->id,
                'order_number' => $this->orderNumberService->generateOrderNumber($companyId, $tenantId),
                'voucher_no' => $sourceOrder->voucher_no,
                'issue_date' => now()->toDateString(),
                'package_type' => $sourceOrder->package_type,
                'active_sections' => $sourceOrder->active_sections,
                'emergency_contact' => $sourceOrder->emergency_contact,
                'booking_reference' => $sourceOrder->order_number,
                'status' => 'refund_request',
                'currency_code' => $sourceOrder->currency_code,
                'total_amount' => 0,
                'notes' => trim('Refund request for actual order ' . $sourceOrder->order_number . "\n" . (string) $sourceOrder->notes),
                'gds_source' => $sourceOrder->gds_source,
                'gds_parsed_record_id' => $sourceOrder->gds_parsed_record_id,
                'meta' => $refundMeta,
            ]);

            $this->copyRefundOrderItems($sourceOrder, $refundOrder);
            $refundOrder->syncVendorCostsFromVoucher($refundMeta);
            $sourceOrder->loadMissing('vendorCosts');
            if ($sourceOrder->vendorCosts->isNotEmpty()) {
                $refundOrder->vendorCosts()->delete();
                $this->copyRefundVendorCosts($sourceOrder, $refundOrder);
            }
            $refundOrder->update([
                'total_amount' => OrderItem::where('order_id', $refundOrder->id)->sum('total_price') ?: -abs((float) $sourceOrder->total_amount),
            ]);

            return $refundOrder;
        });

        return response()->json([
            'success' => true,
            'message' => 'Refund request order created.',
            'order' => $this->orderForUser($refundOrder->fresh(['customer:id,name', 'vendor:id,name', 'items:id,order_id,description,total_price', 'invoice']), $user),
            'original_order_uid' => $sourceOrder->uid,
            'original_order_number' => $sourceOrder->order_number,
        ], 201);
    }

    public function destroy(string $uid, Request $request): JsonResponse
    {
        $order = Order::where('tenant_id', $request->user()->tenant_id)
            ->where('company_id', $request->user()->company_id)
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

    private function copyOrderItems(Order $sourceOrder, Order $targetOrder): void
    {
        $sourceOrder->loadMissing('items');

        $items = $sourceOrder->items->map(fn (OrderItem $item) => [
            'uid' => (string) Str::ulid(),
            'tenant_id' => $targetOrder->tenant_id,
            'order_id' => $targetOrder->id,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'total_price' => $item->total_price,
            'gds_data' => is_array($item->gds_data) ? json_encode($item->gds_data, JSON_UNESCAPED_UNICODE) : $item->gds_data,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($items !== []) {
            OrderItem::insert($items);
        }
    }

    private function copyVendorCosts(Order $sourceOrder, Order $targetOrder): void
    {
        $sourceOrder->loadMissing('vendorCosts');

        $costs = $sourceOrder->vendorCosts->map(fn (OrderVendorCost $cost) => [
            'tenant_id' => $targetOrder->tenant_id,
            'order_id' => $targetOrder->id,
            'vendor_id' => $cost->vendor_id,
            'service_type' => $cost->service_type,
            'service_index' => $cost->service_index,
            'amount' => $cost->amount,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($costs !== []) {
            OrderVendorCost::insert($costs);
        }
    }

    private function copyRefundOrderItems(Order $sourceOrder, Order $targetOrder): void
    {
        $sourceOrder->loadMissing('items');

        $items = $sourceOrder->items->map(fn (OrderItem $item) => [
            'uid' => (string) Str::ulid(),
            'tenant_id' => $targetOrder->tenant_id,
            'order_id' => $targetOrder->id,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => -abs((float) $item->unit_price),
            'total_price' => -abs((float) $item->total_price),
            'gds_data' => is_array($item->gds_data) ? json_encode($item->gds_data, JSON_UNESCAPED_UNICODE) : $item->gds_data,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($items !== []) {
            OrderItem::insert($items);
        }
    }

    private function copyRefundVendorCosts(Order $sourceOrder, Order $targetOrder): void
    {
        $sourceOrder->loadMissing('vendorCosts');

        $costs = $sourceOrder->vendorCosts->map(fn (OrderVendorCost $cost) => [
            'tenant_id' => $targetOrder->tenant_id,
            'order_id' => $targetOrder->id,
            'vendor_id' => $cost->vendor_id,
            'service_type' => $cost->service_type,
            'service_index' => $cost->service_index,
            'amount' => -abs((float) $cost->amount),
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($costs !== []) {
            OrderVendorCost::insert($costs);
        }
    }

    private function refundOrderExistsFor(Order $order): bool
    {
        return Order::where('tenant_id', $order->tenant_id)
            ->where('company_id', $order->company_id)
            ->whereKeyNot($order->id)
            ->whereIn('status', ['refund_request', 'partial_refund', 'refund'])
            ->where(function ($query) use ($order): void {
                $query->where('booking_reference', $order->order_number)
                    ->orWhere('meta->refund_of_order_id', $order->id)
                    ->orWhere('meta->refund_of_order_uid', $order->uid)
                    ->orWhere('meta->refund_of_order_number', $order->order_number);
            })
            ->exists();
    }

    private function negativeRefundVoucher(array $payload): array
    {
        $negativeFields = [
            'amount',
            'cost',
            'profit',
            'sales',
            'total',
            'flight_cost',
            'flight_profit',
            'flight_sales',
            'flight_fare',
            'hotel_cost',
            'hotel_profit',
            'hotel_sales',
            'visa_cost',
            'visa_profit',
            'visa_sales',
            'transfer_cost',
            'transfer_profit',
            'transfer_sales',
            'city_tour_ziarat_cost',
            'city_tour_ziarat_profit',
            'city_tour_ziarat_sales',
            'other_service_cost',
            'other_service_profit',
            'other_service_sales',
        ];

        return $this->negativeRefundVoucherRecursive($payload, array_flip($negativeFields));
    }

    private function negativeRefundVoucherRecursive(array $payload, array $negativeFields): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->negativeRefundVoucherRecursive($value, $negativeFields);
                continue;
            }

            if (isset($negativeFields[$key]) && is_numeric($value)) {
                $payload[$key] = -abs((float) $value);
            }
        }

        return $payload;
    }

    private function voidInvoiceForEditedOrder(Invoice $invoice, Order $newOrder): Invoice
    {
        $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        $timestamp = now()->format('Y-m-d H:i:s');
        $voidNote = $this->cancelledInvoiceNote($invoice, $newOrder, $timestamp);
        $existingNotes = trim((string) $invoice->notes);

        $invoice->lines()->update([
            'unit_price' => 0,
            'total_price' => 0,
        ]);
        $this->invoiceService->releaseReceiptAllocations($invoice);

        $invoice->update([
            'subtotal' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'outstanding_amount' => 0,
            'advance_balance' => 0,
            'status' => 'cancel',
            'notes' => $existingNotes !== '' ? $existingNotes . "\n" . $voidNote : $voidNote,
        ]);

        return $invoice->fresh(['lines']);
    }

    private function cancelInvoiceForCancelledOrder(Invoice $invoice, Order $order, $user): Invoice
    {
        $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        $timestamp = now()->format('Y-m-d H:i:s');
        $existingNotes = trim((string) $invoice->notes);
        $cancelNote = "Canceled on {$timestamp} because order {$order->order_number} was cancelled by {$user->name}.";

        $invoice->lines()->update([
            'unit_price' => 0,
            'total_price' => 0,
        ]);
        $this->invoiceService->releaseReceiptAllocations($invoice);

        $invoice->update([
            'subtotal' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'outstanding_amount' => 0,
            'advance_balance' => 0,
            'status' => 'cancel',
            'notes' => $existingNotes !== '' ? $existingNotes . "\n" . $cancelNote : $cancelNote,
        ]);

        return $invoice->fresh(['lines']);
    }

    private function cancelledInvoiceNote(Invoice $invoice, Order $newOrder, string $timestamp): string
    {
        $invoice->loadMissing(['lines', 'order.vendorCosts.vendor']);
        $order = $invoice->order;
        $currency = $invoice->currency_code ?: $order?->currency_code ?: '';
        $money = fn (mixed $value): string => trim($currency . ' ' . number_format((float) $value, 2, '.', ''));
        $lines = [
            "Canceled on {$timestamp} because order {$order?->order_number} was edited. New order: {$newOrder->order_number}.",
            'Revenue breakup:',
            '- Invoice total: ' . $money($invoice->total_amount),
            '- Outstanding: ' . $money($invoice->outstanding_amount),
        ];

        foreach ($invoice->lines as $line) {
            $lines[] = sprintf(
                '- %s | Qty %s | Unit %s | Total %s',
                trim((string) $line->description),
                (string) $line->quantity,
                $money($line->unit_price),
                $money($line->total_price)
            );
        }

        $costRows = $order ? $this->costBreakupRows($order, $money) : [];
        $lines[] = 'Costing breakup:';

        if ($costRows === []) {
            $lines[] = '- No cost rows recorded';
        } else {
            array_push($lines, ...$costRows);
        }

        return implode("\n", $lines);
    }

    private function costBreakupRows(Order $order, callable $money): array
    {
        $order->loadMissing(['vendorCosts.vendor']);

        if ($order->vendorCosts->isNotEmpty()) {
            return $order->vendorCosts
                ->map(fn (OrderVendorCost $row) => sprintf(
                    '- %s | %s | %s',
                    $row->vendor?->name ?: 'Unassigned vendor',
                    str_replace('_', ' ', $row->service_type),
                    $money($row->amount)
                ))
                ->values()
                ->all();
        }

        $meta = is_array($order->meta) ? $order->meta : [];
        $rows = [];

        foreach (($meta['pricing'] ?? []) as $pricing) {
            if (!is_array($pricing)) {
                continue;
            }

            $amount = OrderVendorCost::toAmount($pricing['flight_cost'] ?? null);
            if ($amount != 0.0) {
                $rows[] = sprintf(
                    '- %s | flight | %s',
                    trim((string) ($pricing['vendor_name'] ?? $pricing['pax_name'] ?? 'Flight vendor')),
                    $money($amount)
                );
            }
        }

        foreach (OrderVendorCost::SERVICE_SECTIONS as $section => $serviceType) {
            foreach (($meta[$section] ?? []) as $serviceRow) {
                if (!is_array($serviceRow)) {
                    continue;
                }

                $amount = OrderVendorCost::amountFromServiceRow($serviceRow);
                if ($amount != 0.0) {
                    $rows[] = sprintf(
                        '- %s | %s | %s',
                        trim((string) ($serviceRow['vendor_name'] ?? $serviceRow['visa_vendor'] ?? ucfirst(str_replace('_', ' ', $serviceType)))),
                        str_replace('_', ' ', $serviceType),
                        $money($amount)
                    );
                }
            }
        }

        return $rows;
    }

    private function buildVoucherOrderItems(array $voucher, int $orderId, int $tenantId): array
    {
        $items = [];

        $pushItem = function (string $description, float $amount, array $payload = [], int $quantity = 1) use (&$items, $orderId, $tenantId): void {
            $quantity = max(1, $quantity);

            $items[] = [
                'uid' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'order_id' => $orderId,
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => round($amount / $quantity, 4),
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
                    if ($amount == 0.0) {
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
                if ($manualTotal != 0.0 && $componentTotal == 0.0) {
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
                $quantity = max(1, (int) ($otherService['quantity'] ?? 1));
                $pushItem($description, $amount, ['type' => 'other_service', 'row' => $otherService], $quantity);
            }
        }

        $discountBase = array_reduce(
            $items,
            fn (float $sum, array $item): float => $sum + max(0, (float) $item['total_price']),
            0.0
        );
        $appliedDiscount = 0.0;

        foreach (($voucher['discounts'] ?? []) as $discount) {
            if (!is_array($discount)) {
                continue;
            }

            $discountType = ($discount['discount_type'] ?? 'amount') === 'percentage' ? 'percentage' : 'amount';
            $value = $discountType === 'percentage'
                ? $this->toAmount($discount['percentage'] ?? null)
                : $this->toAmount($discount['amount'] ?? null);

            if ($value <= 0) {
                continue;
            }

            $remainingBase = max(0, $discountBase - $appliedDiscount);
            $amount = $discountType === 'percentage'
                ? round($remainingBase * (min($value, 100) / 100), 4)
                : round(min($value, $remainingBase), 4);

            if ($amount <= 0) {
                continue;
            }

            $appliedDiscount += $amount;
            $reason = trim((string) ($discount['reason'] ?? ''));
            $label = 'Discount' . ($reason !== '' ? ': ' . $reason : '');
            $pushItem($label, -$amount, [
                'type' => 'discount',
                'discount_type' => $discountType,
                'amount' => $amount,
                'percentage' => $discountType === 'percentage' ? $value : null,
                'reason' => $reason,
            ]);
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

    private function orderForUser(Order $order, $user): array
    {
        if (!$this->shouldHideCostProfit($user)) {
            return $this->normalizeResponseText($order->toArray());
        }

        $order->setAttribute('meta', $this->stripVoucherCostProfit($order->meta ?? []));
        if ($order->relationLoaded('items')) {
            $order->items->each(function (OrderItem $item): void {
                if (is_array($item->gds_data)) {
                    $item->setAttribute('gds_data', $this->stripCostProfitKeysRecursive($item->gds_data));
                }
            });
        }

        return $this->normalizeResponseText($order->toArray());
    }

    private function voucherForUserWrite(array $voucher, ?array $existingMeta, $user): array
    {
        if (!$this->shouldHideCostProfit($user)) {
            return $voucher;
        }

        $voucher = $this->stripVoucherCostProfit($voucher);

        if (!$existingMeta) {
            return $voucher;
        }

        return $this->preserveExistingCostProfit($voucher, $existingMeta);
    }

    private function stripVoucherCostProfit(array $voucher): array
    {
        foreach (($voucher['pricing'] ?? []) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach (self::PRICING_COST_PROFIT_FIELDS as $field) {
                unset($row[$field]);
            }

            $voucher['pricing'][$index] = $row;
        }

        foreach (['hotels', 'transfers', 'city_tours', 'visa', 'other_services'] as $section) {
            foreach (($voucher[$section] ?? []) as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                foreach (self::SERVICE_COST_PROFIT_FIELDS as $field) {
                    unset($row[$field]);
                }

                $voucher[$section][$index] = $row;
            }
        }

        return $voucher;
    }

    private function preserveExistingCostProfit(array $voucher, array $existingMeta): array
    {
        foreach (($voucher['pricing'] ?? []) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $existingRow = $existingMeta['pricing'][$index] ?? [];
            if (!is_array($existingRow)) {
                continue;
            }

            foreach (self::PRICING_COST_PROFIT_FIELDS as $field) {
                if (array_key_exists($field, $existingRow)) {
                    $row[$field] = $existingRow[$field];
                }
            }

            $voucher['pricing'][$index] = $row;
        }

        foreach (['hotels', 'transfers', 'city_tours', 'visa', 'other_services'] as $section) {
            foreach (($voucher[$section] ?? []) as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $existingRow = $existingMeta[$section][$index] ?? [];
                if (!is_array($existingRow)) {
                    continue;
                }

                foreach (self::SERVICE_COST_PROFIT_FIELDS as $field) {
                    if (array_key_exists($field, $existingRow)) {
                        $row[$field] = $existingRow[$field];
                    }
                }

                $voucher[$section][$index] = $row;
            }
        }

        return $voucher;
    }

    private function stripCostProfitKeysRecursive(array $payload): array
    {
        $blockedFields = array_flip([...self::PRICING_COST_PROFIT_FIELDS, ...self::SERVICE_COST_PROFIT_FIELDS]);

        foreach ($payload as $key => $value) {
            if (isset($blockedFields[$key])) {
                unset($payload[$key]);
                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->stripCostProfitKeysRecursive($value);
            }
        }

        return $payload;
    }

    private function isSalesStaff($user): bool
    {
        return $user?->hasRole('sales') === true;
    }

    private function shouldHideCostProfit($user): bool
    {
        return $this->isSalesStaff($user) && $user?->company()->value('sales_can_edit_cost') !== true;
    }

    private function validateCancelPassword($user, ?string $password): ?JsonResponse
    {
        if (!is_string($password) || $password === '') {
            return response()->json([
                'message' => 'Your login password is required to cancel an order.',
                'errors' => [
                    'cancel_password' => ['Your login password is required to cancel an order.'],
                ],
            ], 422);
        }

        if (!Hash::check($password, (string) $user->password)) {
            return response()->json([
                'message' => 'The password is incorrect.',
                'errors' => [
                    'cancel_password' => ['The password is incorrect.'],
                ],
            ], 422);
        }

        return null;
    }

    private function metaWithCancelApproval(array $meta, $user, ?Order $newOrder = null): array
    {
        unset($meta['cancel_signature']);

        $meta['cancel_approval'] = [
            'signed_by_user_id' => (int) $user->id,
            'signed_by_name' => $user->name,
            'signed_by_role' => $user->role?->code,
            'signed_at' => now()->toDateTimeString(),
        ];

        if ($newOrder) {
            $meta['cancel_approval']['new_order_id'] = $newOrder->id;
            $meta['cancel_approval']['new_order_uid'] = $newOrder->uid;
            $meta['cancel_approval']['new_order_number'] = $newOrder->order_number;
        }

        return $meta;
    }

    private function isInvoiceSectionStatus(string $status): bool
    {
        return in_array($status, self::INVOICE_SECTION_STATUSES, true);
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
                    $this->toAmount($pricingRow['flight_sales'] ?? $pricingRow['flight_fare'] ?? null) != 0.0 ||
                    $this->toAmount($pricingRow['total'] ?? null) != 0.0
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
            'other_services' => ['description', 'quantity', 'vendor_id', 'vendor_name', 'cost', 'profit', 'sales', 'amount'],
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
