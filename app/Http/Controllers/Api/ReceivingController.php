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
    private const STATUS_RECEIVED = 'received';

    private const STATUS_CLEAR = 'clear';

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canManage = $user->hasAnyRole(['admin', 'accounts']);
        $canDelete = $user->hasAnyRole(['admin']);
        $query = Receiving::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->with(['receivedBy:id,name', 'createdBy:id,name', 'updatedBy:id,name', 'referenceCustomer:id,name']);

        if ($canManage) {
            $query->withTrashed();
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('receiving_number', 'like', "%{$search}%")
                    ->orWhere('uid', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
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
            'can_delete' => $canDelete,
        ]);
    }

    public function show(Request $request, string $uid): JsonResponse
    {
        $receiving = Receiving::where('tenant_id', $request->user()->tenant_id)
            ->where('company_id', $request->user()->company_id)
            ->where('uid', $uid)
            ->with([
                'company:id,display_name,legal_name,email,phone,address,is_paid,logo_path,footer_logo_path',
                'receivedBy:id,name',
                'createdBy:id,name',
                'updatedBy:id,name',
                'referenceCustomer:id,name,email,phone,address,currency_code',
            ])
            ->firstOrFail();

        return response()->json($this->serializeReceiving($receiving));
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
                'receiving_number' => $this->generateReceivingNumber($user->company_id),
                'company_id' => $user->company_id,
                'amount' => $validated['amount'],
                'status' => self::STATUS_RECEIVED,
                'paid_by' => trim((string) $validated['paid_by']),
                'received_by_user_id' => $user->id,
                'created_by_user_id' => $user->id,
                'reference_customer_id' => $customerId,
                'notes' => $validated['notes'] ?? null,
                'received_at' => now(),
            ]);

            $this->recordAudit('receiving_created', $receiving, null, $this->receivingSnapshot($receiving));

            return $receiving;
        });

        return response()->json([
            'success' => true,
            'receiving' => $this->serializeReceiving($receiving->load(['receivedBy:id,name', 'createdBy:id,name', 'updatedBy:id,name', 'referenceCustomer:id,name'])),
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

        DB::transaction(function () use ($request, $receiving, $validated, $customerId): void {
            $oldValues = $this->receivingSnapshot($receiving);

            $receiving->update([
                'amount' => $validated['amount'],
                'status' => $validated['status'] ?? self::STATUS_RECEIVED,
                'paid_by' => trim((string) $validated['paid_by']),
                'updated_by_user_id' => $request->user()->id,
                'reference_customer_id' => $customerId,
                'notes' => $validated['notes'] ?? null,
            ]);

            $this->recordAudit('receiving_updated', $receiving->fresh(), $oldValues, $this->receivingSnapshot($receiving->fresh()));
        });

        return response()->json([
            'success' => true,
            'receiving' => $this->serializeReceiving($receiving->fresh()->load(['receivedBy:id,name', 'createdBy:id,name', 'updatedBy:id,name', 'referenceCustomer:id,name'])),
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
            'status' => 'nullable|in:' . implode(',', [self::STATUS_RECEIVED, self::STATUS_CLEAR]),
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
            'receiving_number' => $receiving->receiving_number,
            'amount' => $receiving->amount,
            'status' => $receiving->status ?: self::STATUS_RECEIVED,
            'paid_by' => $receiving->paid_by,
            'received_at' => $receiving->received_at?->toISOString(),
            'received_by_user_id' => $receiving->received_by_user_id,
            'received_by' => $receiving->receivedBy?->only(['id', 'name']),
            'created_by_user_id' => $receiving->created_by_user_id,
            'created_by' => $receiving->createdBy?->only(['id', 'name']),
            'updated_by_user_id' => $receiving->updated_by_user_id,
            'updated_by' => $receiving->updatedBy?->only(['id', 'name']),
            'reference_customer_id' => $receiving->reference_customer_id,
            'reference_customer' => $receiving->referenceCustomer?->only(['id', 'name']),
            'notes' => $receiving->notes,
            'company' => $receiving->relationLoaded('company') ? $this->serializeCompany($receiving->company) : null,
            'created_at' => $receiving->created_at?->toISOString(),
            'updated_at' => $receiving->updated_at?->toISOString(),
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
            'receiving_number' => $receiving->receiving_number,
            'amount' => $receiving->amount,
            'status' => $receiving->status,
            'paid_by' => $receiving->paid_by,
            'received_by_user_id' => $receiving->received_by_user_id,
            'created_by_user_id' => $receiving->created_by_user_id,
            'updated_by_user_id' => $receiving->updated_by_user_id,
            'reference_customer_id' => $receiving->reference_customer_id,
            'notes' => $receiving->notes,
            'received_at' => $receiving->received_at?->toISOString(),
            'created_at' => $receiving->created_at?->toISOString(),
            'updated_at' => $receiving->updated_at?->toISOString(),
            'deleted_at' => $receiving->deleted_at?->toISOString(),
        ];
    }

    private function generateReceivingNumber(int $companyId): string
    {
        $prefix = 'REC';
        $lastReceiving = Receiving::where('company_id', $companyId)
            ->where('receiving_number', 'LIKE', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $sequence = $lastReceiving ? ((int) substr((string) $lastReceiving->receiving_number, strlen($prefix))) + 1 : 1;

        do {
            $receivingNumber = $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            $sequence++;
        } while (Receiving::where('company_id', $companyId)->where('receiving_number', $receivingNumber)->exists());

        return $receivingNumber;
    }

    private function serializeCompany($company): ?array
    {
        if (!$company) {
            return null;
        }

        return [
            'id' => $company->id,
            'display_name' => $company->display_name,
            'legal_name' => $company->legal_name,
            'email' => $company->email,
            'phone' => $company->phone,
            'address' => $company->address,
            'is_paid' => (bool) $company->is_paid,
            'logo_url' => $company->logo_url,
            'footer_logo_url' => $company->footer_logo_url,
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
