<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Issue Sanctum token for valid credentials.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'force_logout' => 'nullable|boolean',
        ]);

        $user = User::with(['role', 'company', 'tenant'])
            ->where('email', $validated['email'])
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password ?? '')) {
            return response()->json([
                'error' => 'Invalid credentials',
            ], 422);
        }

        if (!$user->is_active) {
            return response()->json([
                'error' => 'Your account is inactive. Contact administrator.',
            ], 403);
        }

        if (!$user->isSystemUser() && $user->company && !$user->company->is_active) {
            return response()->json([
                'error' => 'Your company account is inactive. Contact administrator.',
            ], 403);
        }

        $activeTokens = $user->tokens()
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });

        if ((clone $activeTokens)->exists() && !($validated['force_logout'] ?? false)) {
            return response()->json([
                'session_conflict' => true,
                'message' => 'This user is already logged in on another device. Sign out the old session to continue.',
            ], 409);
        }

        if ($validated['force_logout'] ?? false) {
            $user->tokens()->delete();
        }

        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'uid' => $user->uid,
                'name' => $user->name,
                'email' => $user->email,
                'tenant_id' => $user->tenant_id,
                'company_id' => $user->company_id,
                'role' => $user->role?->code,
                'role_name' => $user->role?->name,
                'is_system_user' => (bool) $user->role?->is_system,
                'tenant_name' => $user->tenant?->name,
                'company_name' => $user->company?->display_name,
                'company_is_paid' => (bool) $user->company?->is_paid,
                'company_sales_can_edit_cost' => (bool) $user->company?->sales_can_edit_cost,
            ],
        ]);
    }

    /**
     * Revoke current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }
}
