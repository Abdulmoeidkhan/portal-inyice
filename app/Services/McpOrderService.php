<?php

namespace App\Services;

use App\Http\Controllers\Api\OrderController;
use App\Mcp\AgencyAccess;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class McpOrderService
{
    public function create(User $user, array $data): array
    {
        AgencyAccess::authorizeOrderCreation($user);
        ksort($data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $data, $hash) {
            // Serialize retries for this agency, including concurrent first attempts.
            Company::where('tenant_id', $user->tenant_id)->whereKey($user->company_id)->lockForUpdate()->firstOrFail();
            $previous = DB::table('mcp_order_requests')->where('tenant_id', $user->tenant_id)
                ->where('company_id', $user->company_id)->where('request_id', $data['request_id'])->first();
            if ($previous) {
                if ((int) $previous->user_id !== (int) $user->id || ! hash_equals($previous->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_id' => 'This request ID was already used. Retry with the original details or use a new ID for a new order.']);
                }
                $order = Order::withTrashed()->where('tenant_id', $user->tenant_id)
                    ->where('company_id', $user->company_id)->findOrFail($previous->order_id);
                if ($order->trashed()) {
                    throw ValidationException::withMessages(['request_id' => 'The previously created order was deleted. It will not be recreated by a retry.']);
                }

                return $this->result($order, true);
            }

            Customer::where('tenant_id', $user->tenant_id)->where('company_id', $user->company_id)
                ->where('is_active', true)->findOrFail($data['customer_id']);
            $request = Request::create('/mcp/agency', 'POST', [
                'customer_id' => $data['customer_id'],
                'currency_code' => $data['currency_code'] ?? $user->company->base_currency_code,
                'status' => 'order',
                'notes' => $data['notes'] ?? null,
                'voucher' => [
                    'issue_date' => $data['issue_date'] ?? now($user->company->default_timezone ?: 'UTC')->toDateString(),
                    'booking_reference' => $data['booking_reference'] ?? null,
                    'passengers' => isset($data['passenger_name']) ? [['name' => $data['passenger_name']]] : [],
                    'active_sections' => ['other_services'],
                    'other_services' => [['description' => $data['description'], 'quantity' => 1, 'sales' => $data['amount']]],
                ],
            ]);
            $request->setUserResolver(fn () => $user);
            // Reuse portal validation, numbering, item totals and its transaction.
            $response = app(OrderController::class)->createFromVoucher($request)->getData(true);
            $order = Order::where('tenant_id', $user->tenant_id)->where('company_id', $user->company_id)
                ->where('uid', $response['order']['uid'])->firstOrFail();
            DB::table('mcp_order_requests')->insert([
                'uid' => (string) Str::ulid(), 'tenant_id' => $user->tenant_id, 'company_id' => $user->company_id,
                'user_id' => $user->id, 'request_id' => $data['request_id'], 'payload_hash' => $hash,
                'order_id' => $order->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $this->result($order, false);
        }, 3);
    }

    private function result(Order $order, bool $replayed): array
    {
        return ['success' => true, 'replayed' => $replayed, 'order' => $order->only([
            'uid', 'order_number', 'customer_id', 'status', 'currency_code', 'total_amount', 'issue_date', 'booking_reference',
        ]), 'order_url' => url('/orders/'.$order->uid.'/voucher')];
    }
}
