<?php

namespace App\Providers;

use App\Mcp\OAuth\AccessToken;
use App\Mcp\OAuth\RevokeConnections;
use App\Mcp\OAuth\ScopeRepository;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AgencyOAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register only the authorization-code endpoints needed by this server.
        Passport::ignoreRoutes();
        $this->app->bind(\Laravel\Passport\Bridge\ScopeRepository::class, ScopeRepository::class);
    }

    public function boot(): void
    {
        Passport::tokensCan(['mcp:use' => 'Read your agency customers, invoices, statements, balances and profit reports']);
        Passport::defaultScopes(['mcp:use']);
        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::useAccessTokenEntity(AccessToken::class);
        Passport::authorizationView('mcp.authorize');

        User::updated(function (User $user): void {
            if ($user->wasChanged(['password', 'is_active', 'email_verified_at', 'role_id', 'tenant_id', 'company_id'])
                && Schema::hasTable('oauth_access_tokens')) {
                app(RevokeConnections::class)->forUser($user->id);
            }
        });

        Company::updated(function (Company $company): void {
            if ($company->wasChanged('is_paid') && ! $company->hasIntegrationAccess()
                && Schema::hasTable('oauth_access_tokens')) {
                User::withoutGlobalScopes()->where('tenant_id', $company->tenant_id)->where('company_id', $company->id)
                    ->each(fn (User $user) => app(RevokeConnections::class)->forUser($user->id));
            }
        });
    }
}
