<?php

namespace App\Mcp\OAuth;

class Metadata
{
    public static function issuer(): string
    {
        return rtrim(config('app.url'), '/');
    }

    public static function resource(): string
    {
        return self::issuer().'/mcp/agency';
    }

    public function guide()
    {
        return view('mcp.discovery', ['issuer' => self::issuer(), 'resource' => self::resource()]);
    }

    public function verifyDomain()
    {
        $token = config('mcp.openai_verification_token');
        abort_unless(is_string($token) && trim($token) !== '', 404);

        return response($token)->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    public function instructions()
    {
        return response()->view('mcp.instructions', ['issuer' => self::issuer(), 'resource' => self::resource()])
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function resourceMetadata()
    {
        return response()->json([
            'resource' => self::resource(),
            'authorization_servers' => [self::issuer()],
            'scopes_supported' => ['mcp:use'],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'InYice Agency Accounts',
        ]);
    }

    public function serverMetadata()
    {
        return response()->json([
            'issuer' => self::issuer(),
            'authorization_response_iss_parameter_supported' => true,
            'authorization_endpoint' => self::issuer().'/oauth/authorize',
            'token_endpoint' => self::issuer().'/oauth/token',
            'registration_endpoint' => self::issuer().'/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => ['mcp:use'],
        ]);
    }
}
