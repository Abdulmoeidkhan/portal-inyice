<?php

namespace App\Http\Controllers;

use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

class AgencyClientRegistrationController extends OAuthRegisterController
{
    protected function isValidRedirectUri(string $value): bool
    {
        if (! parent::isValidRedirectUri($value)) {
            return false;
        }

        $parts = parse_url($value);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        if (in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)) {
            return ($parts['scheme'] ?? '') === 'http'
                && ($parts['path'] ?? '') === '/oauth/callback'
                && isset($parts['port']) && $parts['port'] > 0;
        }

        return true;
    }
}
