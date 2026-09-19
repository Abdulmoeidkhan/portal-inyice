<?php

namespace App\Auth;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Cookie;

class PortalSession
{
    public const COOKIE = 'inyice_portal_session';

    public static function cookie(string $token, Request $request): Cookie
    {
        // API routes have no EncryptCookies middleware. Encrypt this binding
        // explicitly so knowing a bearer token cannot recreate its cookie.
        // Construct directly: Laravel's cookie helper substitutes SESSION_DOMAIN
        // for null, which can point at a different host in local/proxy setups.
        return new Cookie(self::COOKIE, Crypt::encryptString(hash('sha256', $token)),
            now()->addDays(30), '/api/v1', null, $request->isSecure() || config('session.secure'), true, false, 'strict');
    }

    public static function expiredCookie(): Cookie
    {
        return new Cookie(self::COOKIE, '', 1, '/api/v1');
    }

    public static function matches(Request $request): bool
    {
        $cookie = $request->cookie(self::COOKIE);
        if (! is_string($cookie) || ! $request->bearerToken()) {
            return false;
        }

        try {
            return hash_equals(Crypt::decryptString($cookie), hash('sha256', $request->bearerToken()));
        } catch (DecryptException) {
            return false;
        }
    }
}
