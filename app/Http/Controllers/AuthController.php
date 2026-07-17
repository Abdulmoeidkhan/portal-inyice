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
