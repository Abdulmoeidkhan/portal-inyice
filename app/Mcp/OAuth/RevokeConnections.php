<?php

namespace App\Mcp\OAuth;

use Illuminate\Support\Facades\DB;

class RevokeConnections
{
    public function forUser(int $userId, ?string $clientId = null): void
    {
        DB::transaction(function () use ($userId, $clientId): void {
            $tokens = DB::table('oauth_access_tokens')->where('user_id', $userId)
                ->when($clientId, fn ($q) => $q->where('client_id', $clientId));
            DB::table('oauth_refresh_tokens')->whereIn('access_token_id', (clone $tokens)->select('id'))->update(['revoked' => true]);
            $tokens->update(['revoked' => true]);
            DB::table('oauth_auth_codes')->where('user_id', $userId)
                ->when($clientId, fn ($q) => $q->where('client_id', $clientId))->update(['revoked' => true]);
        });
    }
}
