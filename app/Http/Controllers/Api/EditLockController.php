<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EditLock;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EditLockController extends Controller
{
    private const TTL_SECONDS = 90;

    public function acquire(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $companyId = (int) $user->company_id;
        $type = $validated['type'];
        $uid = $validated['uid'];

        $this->ensureResourceExists($type, $uid, $tenantId, $companyId);

        $result = DB::transaction(function () use ($type, $uid, $user, $tenantId, $companyId): array {
            EditLock::query()
                ->where('expires_at', '<', now())
                ->delete();

            $lock = EditLock::query()
                ->with('user:id,name,email')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->where('lockable_type', $type)
                ->where('lockable_uid', $uid)
                ->lockForUpdate()
                ->first();

            if ($lock && (int) $lock->user_id !== (int) $user->id && $lock->expires_at?->isFuture()) {
                return ['locked' => true, 'lock' => $lock];
            }

            $lock ??= new EditLock([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'lockable_type' => $type,
                'lockable_uid' => $uid,
            ]);

            $lock->fill([
                'user_id' => (int) $user->id,
                'expires_at' => now()->addSeconds(self::TTL_SECONDS),
            ])->save();

            return ['locked' => false, 'lock' => $lock->fresh('user:id,name,email')];
        });

        if ($result['locked']) {
            return response()->json([
                'locked' => true,
                'message' => ($result['lock']->user?->name ?: 'Another user') . ' is working on this ' . $type . '.',
                'locked_by' => $this->userPayload($result['lock']),
                'expires_at' => optional($result['lock']->expires_at)->toISOString(),
            ], 423);
        }

        return response()->json([
            'locked' => false,
            'expires_at' => optional($result['lock']->expires_at)->toISOString(),
            'locked_by' => $this->userPayload($result['lock']),
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $user = $request->user();

        $updated = EditLock::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->where('lockable_type', $validated['type'])
            ->where('lockable_uid', $validated['uid'])
            ->where('user_id', $user->id)
            ->update(['expires_at' => now()->addSeconds(self::TTL_SECONDS), 'updated_at' => now()]);

        if (!$updated) {
            return response()->json(['message' => 'Edit lock is no longer active.'], 409);
        }

        return response()->json(['success' => true]);
    }

    public function release(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $user = $request->user();

        EditLock::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->where('lockable_type', $validated['type'])
            ->where('lockable_uid', $validated['uid'])
            ->where('user_id', $user->id)
            ->delete();

        return response()->json(['success' => true]);
    }

    private function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['order', 'invoice'])],
            'uid' => ['required', 'string', 'max:64'],
        ];
    }

    private function ensureResourceExists(string $type, string $uid, int $tenantId, int $companyId): void
    {
        $model = $type === 'order' ? Order::class : Invoice::class;

        $model::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('uid', $uid)
            ->firstOrFail();
    }

    private function userPayload(EditLock $lock): array
    {
        return [
            'id' => $lock->user?->id,
            'name' => $lock->user?->name,
            'email' => $lock->user?->email,
        ];
    }
}
