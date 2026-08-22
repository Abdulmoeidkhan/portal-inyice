<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Receiving;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReceivingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canManage = $user->hasAnyRole(['admin']);
        $query = Receiving::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->with(['receivedBy:id,name', 'referenceCustomer:id,name']);

        if ($canManage) {
            $query->withTrashed();
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('uid', 'like', "%{$search}%")
                    ->orWhere('paid_by', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('referenceCustomer', fn ($customerQuery) => $customerQuery->where('name', 'like', "%{$search}%"));
            });
        }

        $receivings = $query->orderByDesc('received_at')->orderByDesc('id')->paginate(50);
        $receivings->getCollection()->transform(fn (Receiving $receiving): array => $this->serializeReceiving($receiving));

        return response()->json([
            ...$receivings->toArray(),
            'can_manage' => $canManage,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $user = $request->user();
        $customerId = $this->validatedCustomerId($validated['reference_customer_id'] ?? null);

        $receiving = DB::transaction(function () use ($user, $validated, $customerId): Receiving {
            $receiving = Receiving::create([
                'tenant_id' => $user->tenant_id,
                'uid' => (string) Str::ulid(),
                'company_id' => $user->company_id,
                'amount' => $validated['amount'],
                'paid_by' => trim((string) $validated['paid_by']),
                'received_by_user_id' => $user->id,
                'reference_customer_id' => $customerId,
                'notes' => $validated['notes'] ?? null,
                'received_at' => now(),
            ]);

            $this->recordAudit('receiving_created', $receiving, null, $this->receivingSnapshot($receiving));

            return $receiving;
        });

        return response()->json([
            'success' => true,
            'receiving' => $this->serializeReceiving($receiving->load(['receivedBy:id,name', 'referenceCustomer:id,name'])),
        ], 201);
    }

    public function update(Request $request, string $uid): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $customerId = $this->validatedCustomerId($validated['reference_customer_id'] ?? null);

        $receiving = Receiving::where('tenant_id', $request->user()->tenant_id)
            ->where('company_id', $request->user()->company_id)
            ->where('uid', $uid)
            ->firstOrFail();

        DB::transaction(function () use ($receiving, $validated, $customerId): void {
            $oldValues = $this->receivingSnapshot($receiving);

            $receiving->update([
                'amount' => $validated['amount'],
                'paid_by' => trim((string) $validated['paid_by']),
                'reference_customer_id' => $customerId,
                'notes' => $validated['notes'] ?? null,
            ]);

            $this->recordAudit('receiving_updated', $receiving->fresh(), $oldValues, $this->receivingSnapshot($receiving->fresh()));
        });

        return response()->json([
            'success' => true,
            'receiving' => $this->serializeReceiving($receiving->fresh()->load(['receivedBy:id,name', 'referenceCustomer:id,name'])),
        ]);
    }

    public function destroy(Request $request, string $uid): JsonResponse
    {
        $receiving = Receiving::where('tenant_id', $request->user()->tenant_id)
            ->where('company_id', $request->user()->company_id)
            ->where('uid', $uid)
            ->firstOrFail();

        DB::transaction(function () use ($receiving, $request): void {
            $oldValues = $this->receivingSnapshot($receiving);
            $receiving->forceFill(['deleted_by_user_id' => $request->user()->id])->save();
            $receiving->delete();

            $this->recordAudit('receiving_deleted', $receiving, $oldValues, $this->receivingSnapshot($receiving));
        });

        return response()->json(['success' => true]);
    }

    private function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01',
            'paid_by' => 'required|string|max:190',
            'reference_customer_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    private function validatedCustomerId(?int $customerId): ?int
    {
        if (!$customerId) {
            return null;
        }

        $scopedCustomerId = Customer::where('tenant_id', auth()->user()->tenant_id)
            ->where('company_id', auth()->user()->company_id)
            ->where('is_active', true)
            ->where('id', $customerId)
            ->value('id');

        if (!$scopedCustomerId) {
            throw ValidationException::withMessages([
                'reference_customer_id' => 'The selected customer is unavailable.',
            ]);
        }

        return $scopedCustomerId;
    }

    private function serializeReceiving(Receiving $receiving): array
    {
        return [
            'id' => $receiving->id,
            'uid' => $receiving->uid,
            'amount' => $receiving->amount,
            'paid_by' => $receiving->paid_by,
            'received_at' => $receiving->received_at?->toISOString(),
            'received_by_user_id' => $receiving->received_by_user_id,
            'received_by' => $receiving->receivedBy?->only(['id', 'name']),
            'reference_customer_id' => $receiving->reference_customer_id,
            'reference_customer' => $receiving->referenceCustomer?->only(['id', 'name']),
            'notes' => $receiving->notes,
            'deleted_at' => $receiving->deleted_at?->toISOString(),
            'is_deleted' => $receiving->trashed(),
        ];
    }

    private function receivingSnapshot(?Receiving $receiving): ?array
    {
        if (!$receiving) {
            return null;
        }

        return [
            'uid' => $receiving->uid,
            'amount' => $receiving->amount,
            'paid_by' => $receiving->paid_by,
            'received_by_user_id' => $receiving->received_by_user_id,
            'reference_customer_id' => $receiving->reference_customer_id,
            'notes' => $receiving->notes,
            'received_at' => $receiving->received_at?->toISOString(),
            'deleted_at' => $receiving->deleted_at?->toISOString(),
        ];
    }

    private function recordAudit(string $action, Receiving $receiving, ?array $oldValues, ?array $newValues): void
    {
        AuditLog::create([
            'tenant_id' => $receiving->tenant_id,
            'uid' => (string) Str::ulid(),
            'user_id' => auth()->id(),
            'action' => $action,
            'model_type' => Receiving::class,
            'model_id' => $receiving->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'description' => str_replace('_', ' ', $action),
            'created_at' => now(),
        ]);
    }
}
