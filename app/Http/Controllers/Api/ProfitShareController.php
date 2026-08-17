<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderVendorCost;
use App\Models\ProfitShare;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProfitShareController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'currency_code' => ['nullable', 'string', 'size:3', 'exists:currencies,code'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $shares = ProfitShare::query()
            ->with([
                'fromUser:id,name,email',
                'toUser:id,name,email',
                'invoice:id,uid,invoice_number,invoice_date,total_amount,currency_code',
                'createdBy:id,name',
                'updatedBy:id,name',
            ])
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->when($validated['from_date'] ?? null, fn ($query, string $date) => $query->whereDate('share_date', '>=', $date))
            ->when($validated['to_date'] ?? null, fn ($query, string $date) => $query->whereDate('share_date', '<=', $date))
            ->when($validated['user_id'] ?? null, function ($query, int $userId): void {
                $query->where(function ($nested) use ($userId): void {
                    $nested->where('from_user_id', $userId)->orWhere('to_user_id', $userId);
                });
            })
            ->when($validated['currency_code'] ?? null, fn ($query, string $currency) => $query->where('currency_code', strtoupper($currency)))
            ->when(isset($validated['search']), function ($query) use ($validated): void {
                $search = trim((string) $validated['search']);
                if ($search === '') {
                    return;
                }

                $query->where(function ($nested) use ($search): void {
                    $nested->where('notes', 'like', "%{$search}%")
                        ->orWhereHas('fromUser', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                        ->orWhereHas('toUser', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                        ->orWhereHas('invoice', fn ($invoiceQuery) => $invoiceQuery->where('invoice_number', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('share_date')
            ->orderByDesc('id')
            ->get();

        $staff = User::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'uid', 'name', 'email']);

        return response()->json([
            'data' => $shares->map(fn (ProfitShare $share) => $this->serializeShare($share))->values(),
            'source_profits' => $this->sourceProfits($user, $validated),
            'summary' => [
                'by_currency' => $this->currencySummary($shares),
                'by_user' => $this->userSummary($shares),
            ],
            'staff' => $staff,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $share = $this->persistShare(new ProfitShare(), $request);

        return response()->json([
            'success' => true,
            'message' => 'Profit share recorded successfully.',
            'profit_share' => $this->serializeShare($share),
        ], 201);
    }

    public function update(string $uid, Request $request): JsonResponse
    {
        $user = $request->user();
        $share = ProfitShare::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->where('uid', $uid)
            ->firstOrFail();

        $share = $this->persistShare($share, $request);

        return response()->json([
            'success' => true,
            'message' => 'Profit share updated successfully.',
            'profit_share' => $this->serializeShare($share),
        ]);
    }

    public function destroy(string $uid, Request $request): JsonResponse
    {
        $user = $request->user();
        $share = ProfitShare::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->where('uid', $uid)
            ->firstOrFail();

        $share->delete();

        return response()->json([
            'success' => true,
            'message' => 'Profit share deleted successfully.',
        ]);
    }

    private function persistShare(ProfitShare $share, Request $request): ProfitShare
    {
        $user = $request->user();
        $validated = $request->validate([
            'from_user_id' => ['required', 'integer', 'min:1'],
            'to_user_id' => ['required', 'integer', 'min:1', 'different:from_user_id'],
            'invoice_uid' => ['nullable', 'string', 'exists:invoices,uid'],
            'share_date' => ['required', 'date'],
            'currency_code' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.9999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $fromUser = $this->companyUser((int) $validated['from_user_id'], $user);
        $toUser = $this->companyUser((int) $validated['to_user_id'], $user);
        $invoice = $this->invoice($validated['invoice_uid'] ?? null, $user);
        $currencyCode = strtoupper($validated['currency_code']);

        if ($invoice && $invoice->currency_code !== $currencyCode) {
            throw ValidationException::withMessages([
                'currency_code' => ['Profit share currency must match the selected invoice currency.'],
            ]);
        }

        $share->fill([
            'tenant_id' => $user->tenant_id,
            'company_id' => $user->company_id,
            'from_user_id' => $fromUser->id,
            'to_user_id' => $toUser->id,
            'invoice_id' => $invoice?->id,
            'share_date' => $validated['share_date'],
            'currency_code' => $currencyCode,
            'amount' => $validated['amount'],
            'notes' => $validated['notes'] ?? null,
            'updated_by_user_id' => $user->id,
        ]);

        if (!$share->exists) {
            $share->uid = (string) Str::ulid();
            $share->created_by_user_id = $user->id;
        }

        $share->save();

        return $share->fresh(['fromUser:id,name,email', 'toUser:id,name,email', 'invoice:id,uid,invoice_number,invoice_date,total_amount,currency_code', 'createdBy:id,name', 'updatedBy:id,name']);
    }

    private function companyUser(int $userId, User $currentUser): User
    {
        return User::query()
            ->where('tenant_id', $currentUser->tenant_id)
            ->where('company_id', $currentUser->company_id)
            ->where('is_active', true)
            ->findOrFail($userId);
    }

    private function invoice(?string $invoiceUid, User $currentUser): ?Invoice
    {
        if (!$invoiceUid) {
            return null;
        }

        return Invoice::query()
            ->where('tenant_id', $currentUser->tenant_id)
            ->where('company_id', $currentUser->company_id)
            ->where('uid', $invoiceUid)
            ->firstOrFail();
    }

    private function serializeShare(ProfitShare $share): array
    {
        return [
            'uid' => $share->uid,
            'share_date' => optional($share->share_date)->toDateString(),
            'currency_code' => $share->currency_code,
            'amount' => (float) $share->amount,
            'notes' => $share->notes,
            'from_user' => $this->serializeUser($share->fromUser),
            'to_user' => $this->serializeUser($share->toUser),
            'invoice' => $share->invoice ? [
                'uid' => $share->invoice->uid,
                'invoice_number' => $share->invoice->invoice_number,
                'invoice_date' => optional($share->invoice->invoice_date)->toDateString(),
                'total_amount' => (float) $share->invoice->total_amount,
                'currency_code' => $share->invoice->currency_code,
            ] : null,
            'created_by' => $this->serializeUser($share->createdBy),
            'updated_by' => $this->serializeUser($share->updatedBy),
            'created_at' => optional($share->created_at)->toISOString(),
            'updated_at' => optional($share->updated_at)->toISOString(),
        ];
    }

    private function serializeUser(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    private function sourceProfits(User $user, array $filters): array
    {
        $orders = Order::query()
            ->with([
                'customer:id,name',
                'createdBy:id,name,email',
                'vendorCosts',
                'invoice:id,order_id,uid,invoice_number,invoice_date,status,currency_code,total_amount',
            ])
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->whereHas('invoice', function ($invoice) use ($filters): void {
                $invoice->whereNotIn('status', ['void', 'cancel'])
                    ->when($filters['from_date'] ?? null, fn ($query, string $date) => $query->whereDate('invoice_date', '>=', $date))
                    ->when($filters['to_date'] ?? null, fn ($query, string $date) => $query->whereDate('invoice_date', '<=', $date));
            })
            ->when($filters['user_id'] ?? null, fn ($query, int $userId) => $query->where('created_by_user_id', $userId))
            ->when($filters['currency_code'] ?? null, fn ($query, string $currency) => $query->where('currency_code', strtoupper($currency)))
            ->when(isset($filters['search']), function ($query) use ($filters): void {
                $search = trim((string) $filters['search']);
                if ($search === '') {
                    return;
                }

                $query->where(function ($nested) use ($search): void {
                    $nested->where('order_number', 'like', "%{$search}%")
                        ->orWhere('voucher_no', 'like', "%{$search}%")
                        ->orWhere('booking_reference', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('createdBy', fn ($staff) => $staff->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                        ->orWhereHas('invoice', fn ($invoice) => $invoice->where('invoice_number', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc(
                Invoice::select('invoice_date')
                    ->whereColumn('invoices.order_id', 'orders.id')
                    ->limit(1)
            )
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $shareTotals = ProfitShare::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->whereNotNull('invoice_id')
            ->selectRaw('invoice_id, from_user_id, COALESCE(SUM(amount), 0) as shared_out')
            ->groupBy('invoice_id', 'from_user_id')
            ->get()
            ->keyBy(fn (ProfitShare $share) => $share->invoice_id . '|' . $share->from_user_id);

        return $orders
            ->map(function (Order $order) use ($shareTotals): array {
                $revenue = (float) ($order->invoice?->total_amount ?? $order->total_amount);
                $cost = (float) $order->vendorCosts->sum('amount');

                if ($cost == 0.0) {
                    $cost = $this->voucherCostTotal($order);
                }

                $profit = round($revenue - $cost, 4);
                $sharedOut = (float) ($shareTotals->get($order->invoice?->id . '|' . $order->created_by_user_id)?->shared_out ?? 0);

                return [
                    'key' => 'invoice-profit-' . $order->id,
                    'order_uid' => $order->uid,
                    'order_number' => $order->order_number,
                    'voucher_no' => $order->voucher_no,
                    'booking_reference' => $order->booking_reference,
                    'invoice_uid' => $order->invoice?->uid,
                    'invoice_number' => $order->invoice?->invoice_number,
                    'invoice_date' => optional($order->invoice?->invoice_date)->toDateString(),
                    'customer_name' => $order->customer?->name,
                    'staff' => $this->serializeUser($order->createdBy),
                    'currency_code' => $order->currency_code,
                    'revenue' => round($revenue, 4),
                    'cost' => round($cost, 4),
                    'profit' => $profit,
                    'shared_out' => round($sharedOut, 4),
                    'available_profit' => round($profit - $sharedOut, 4),
                ];
            })
            ->filter(fn (array $row) => $row['profit'] != 0.0 || $row['shared_out'] != 0.0)
            ->values()
            ->all();
    }

    private function voucherCostTotal(Order $order): float
    {
        $meta = is_array($order->meta) ? $order->meta : [];
        $total = collect($meta['pricing'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->sum(fn (array $row) => OrderVendorCost::toAmount($row['flight_cost'] ?? null));

        foreach (array_keys(OrderVendorCost::SERVICE_SECTIONS) as $section) {
            $total += collect($meta[$section] ?? [])
                ->filter(fn ($row) => is_array($row))
                ->sum(fn (array $row) => OrderVendorCost::amountFromServiceRow($row));
        }

        return (float) $total;
    }

    private function currencySummary(Collection $shares): array
    {
        return $shares
            ->groupBy('currency_code')
            ->map(fn (Collection $rows, string $currency) => [
                'currency_code' => $currency,
                'amount' => round((float) $rows->sum('amount'), 4),
                'count' => $rows->count(),
            ])
            ->values()
            ->all();
    }

    private function userSummary(Collection $shares): array
    {
        $summary = [];

        foreach ($shares as $share) {
            foreach ([['user' => $share->fromUser, 'out' => (float) $share->amount, 'in' => 0.0], ['user' => $share->toUser, 'out' => 0.0, 'in' => (float) $share->amount]] as $row) {
                $user = $row['user'];
                if (!$user) {
                    continue;
                }

                $key = $user->id . '|' . $share->currency_code;
                $summary[$key] ??= [
                    'key' => $key,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'currency_code' => $share->currency_code,
                    'shared_out' => 0.0,
                    'received' => 0.0,
                    'net' => 0.0,
                ];
                $summary[$key]['shared_out'] += $row['out'];
                $summary[$key]['received'] += $row['in'];
                $summary[$key]['net'] = $summary[$key]['received'] - $summary[$key]['shared_out'];
            }
        }

        return collect($summary)
            ->map(fn (array $row) => [
                ...$row,
                'shared_out' => round($row['shared_out'], 4),
                'received' => round($row['received'], 4),
                'net' => round($row['net'], 4),
            ])
            ->sortBy('user_name')
            ->values()
            ->all();
    }
}
