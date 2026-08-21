<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CompanyUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $users = User::query()
            ->with('role:id,code,name')
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'uid', 'role_id', 'name', 'email', 'is_active', 'created_at']);

        return response()->json([
            'users' => $users->map(fn (User $companyUser) => $this->serializeUser($companyUser))->values(),
            'roles' => $this->availableRoles((int) $user->tenant_id),
            'limits' => $this->limits((int) $user->tenant_id, (int) $user->company_id, $this->effectiveUserLimit($user->company?->user_limit)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $roles = $this->availableRoles((int) $user->tenant_id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:200', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in($roles->pluck('code')->all())],
        ]);

        return DB::transaction(function () use ($user, $validated): JsonResponse {
            $limits = $this->limits((int) $user->tenant_id, (int) $user->company_id, $this->effectiveUserLimit($user->company?->user_limit));

            if ($limits['remaining'] < 1) {
                return response()->json([
                    'error' => 'This company already has the maximum number of users.',
                    'limits' => $limits,
                ], 422);
            }

            $role = Role::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('code', $validated['role'])
                ->lockForUpdate()
                ->firstOrFail();

            $companyUser = User::create([
                'uid' => (string) Str::ulid(),
                'tenant_id' => $user->tenant_id,
                'company_id' => $user->company_id,
                'role_id' => $role->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'is_active' => true,
            ]);

            $companyUser->load('role:id,code,name');

            return response()->json([
                'success' => true,
                'message' => 'Company user created successfully.',
                'user' => $this->serializeUser($companyUser),
                'limits' => $this->limits((int) $user->tenant_id, (int) $user->company_id, $this->effectiveUserLimit($user->company?->user_limit)),
            ], 201);
        });
    }

    public function update(string $uid, Request $request): JsonResponse
    {
        $user = $request->user();
        $roles = $this->availableRoles((int) $user->tenant_id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:200'],
            'role' => ['required', Rule::in($roles->pluck('code')->all())],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        return DB::transaction(function () use ($uid, $user, $validated): JsonResponse {
            $companyUser = $this->companyUser($uid, $user, true);

            if ($companyUser->hasRole('owner')) {
                return response()->json(['error' => 'Owner users cannot be edited here.'], 422);
            }

            validator($validated, [
                'email' => [Rule::unique('users', 'email')->ignore($companyUser->id)],
            ])->validate();

            $role = Role::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('code', $validated['role'])
                ->lockForUpdate()
                ->firstOrFail();

            $companyUser->fill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'role_id' => $role->id,
                'is_active' => (bool) $validated['is_active'],
            ]);

            if (!empty($validated['password'])) {
                $companyUser->password = $validated['password'];
                $companyUser->tokens()->delete();
            }

            if (!$companyUser->is_active) {
                $companyUser->tokens()->delete();
            }

            $companyUser->save();
            $companyUser->load('role:id,code,name');

            return response()->json([
                'success' => true,
                'message' => 'Company user updated successfully.',
                'user' => $this->serializeUser($companyUser),
            ]);
        });
    }

    public function destroy(string $uid, Request $request): JsonResponse
    {
        $user = $request->user();
        return DB::transaction(function () use ($uid, $user): JsonResponse {
            $companyUser = $this->companyUser($uid, $user, true);

            if ($companyUser->id === $user->id) {
                return response()->json(['error' => 'You cannot delete your own user account.'], 422);
            }

            if ($companyUser->hasRole('owner')) {
                return response()->json(['error' => 'Owner users cannot be deleted.'], 422);
            }

            $companyUser->tokens()->delete();
            $companyUser->delete();

            return response()->json([
                'success' => true,
                'message' => 'Company user deleted successfully.',
                'limits' => $this->limits((int) $user->tenant_id, (int) $user->company_id, $this->effectiveUserLimit($user->company?->user_limit)),
            ]);
        });
    }

    private function effectiveUserLimit(?int $configuredLimit): int
    {
        return max((int) ($configuredLimit ?: Company::DEFAULT_USER_LIMIT), 1);
    }

    private function availableRoles(int $tenantId)
    {
        return Role::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('code', ['admin', 'sales', 'accounts'])
            ->orderByRaw("case code when 'admin' then 1 when 'sales' then 2 when 'accounts' then 3 else 4 end")
            ->get(['code', 'name']);
    }

    private function limits(int $tenantId, int $companyId, int $maxUsers): array
    {
        $current = User::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->count();

        return [
            'current' => $current,
            'max' => $maxUsers,
            'remaining' => max($maxUsers - $current, 0),
        ];
    }

    private function companyUser(string $uid, User $user, bool $lock = false): User
    {
        $query = User::query()
            ->with('role:id,code,name')
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $user->company_id)
            ->where('uid', $uid);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'uid' => $user->uid,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->code,
            'role_name' => $user->role?->name,
            'is_active' => (bool) $user->is_active,
            'created_at' => optional($user->created_at)->toISOString(),
        ];
    }
}
