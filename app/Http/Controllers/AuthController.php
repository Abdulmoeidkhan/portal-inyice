<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

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

        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'email_unverified' => true,
                'error' => 'Please verify your email address before signing in.',
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
            'user' => $this->serializeUser($user),
        ]);
    }

    /**
     * Send a password reset link for active accounts.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = $this->passwordResettableUser($validated['email']);

        if ($user) {
            $status = Password::sendResetLink(['email' => $user->email]);

            if ($status === Password::RESET_THROTTLED) {
                return response()->json([
                    'error' => 'A reset link was already sent recently. Please check your inbox or try again shortly.',
                ], 429);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'If the email belongs to an active account, a password reset link has been sent.',
        ]);
    }

    /**
     * Reset a password using Laravel's reset token broker.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!$this->passwordResettableUser($validated['email'])) {
            return response()->json([
                'error' => 'This password reset link is invalid or has expired.',
            ], 422);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'error' => 'This password reset link is invalid or has expired.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. You can now sign in.',
        ]);
    }

    /**
     * Resend the welcome verification email without exposing account existence.
     */
    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = $this->signInEligibleUser($validated['email']);

        if ($user && !$user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'success' => true,
            'message' => 'If the email belongs to an unverified active account, a verification email has been sent.',
        ]);
    }

    /**
     * Verify a signed email link and return the user to the SPA.
     */
    public function verifyEmail(Request $request, string $id, string $hash)
    {
        $user = User::withoutGlobalScopes()->findOrFail($id);

        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            abort(403);
        }

        if (!$user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Email verified successfully.',
            ]);
        }

        return redirect('/login?verified=1');
    }

    /**
     * Update the signed-in user's profile details.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $request->merge([
            'name' => trim((string) $request->input('name', '')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
        ]);

        $user = $request->user();
        $user->forceFill([
            'name' => $validated['name'],
        ])->save();

        return response()->json([
            'success' => true,
            'user' => $this->serializeUser($user->loadMissing(['role', 'company', 'tenant'])),
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

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        return [
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
            'email_verified_at' => optional($user->email_verified_at)->toISOString(),
        ];
    }

    private function signInEligibleUser(string $email): ?User
    {
        $user = User::withoutGlobalScopes()
            ->with(['role:id,code,is_system', 'company:id,is_active'])
            ->where('email', $email)
            ->first();

        if (!$user || !$user->is_active) {
            return null;
        }

        if (!$user->isSystemUser() && $user->company && !$user->company->is_active) {
            return null;
        }

        return $user;
    }

    private function passwordResettableUser(string $email): ?User
    {
        $user = $this->signInEligibleUser($email);

        return $user && $user->password ? $user : null;
    }
}
