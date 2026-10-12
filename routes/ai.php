<?php

use App\Http\Controllers\AgencyOAuthController;
use App\Http\Middleware\AgencyOAuthSession;
use App\Http\Middleware\AuthenticateAgencyMcp;
use App\Http\Middleware\OAuthNoStore;
use App\Mcp\OAuth\Metadata;
use App\Mcp\Servers\AgencyServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use App\Http\Controllers\AgencyClientRegistrationController;
use App\Http\Middleware\OAuthAuthorizationIssuer;

Route::get('/.well-known/oauth-protected-resource', [Metadata::class, 'resourceMetadata'])->name('mcp.oauth.protected-resource');
Route::get('/.well-known/oauth-protected-resource/{path}', [Metadata::class, 'resourceMetadata'])
    ->where('path', 'mcp/agency')->name('mcp.oauth.protected-resource.nested');
Route::get('/.well-known/oauth-authorization-server', [Metadata::class, 'serverMetadata'])->name('mcp.oauth.authorization-server');

Route::middleware([OAuthNoStore::class, 'throttle:public-api'])->group(function () {
    Route::post('/oauth/register', AgencyClientRegistrationController::class)->name('mcp.oauth.register');
    Route::post('/oauth/token', [AgencyOAuthController::class, 'token'])->name('passport.token');
});

Route::middleware(['web', OAuthNoStore::class])->group(function () {
    Route::get('/connect/chatgpt/login', [AgencyOAuthController::class, 'loginForm'])->name('mcp.login');
    Route::post('/connect/chatgpt/login', [AgencyOAuthController::class, 'login'])->middleware('throttle:signin')->name('mcp.login.submit');

    Route::middleware([AgencyOAuthSession::class, OAuthAuthorizationIssuer::class])->group(function () {
        Route::get('/oauth/authorize', [AgencyOAuthController::class, 'authorize'])->name('passport.authorizations.authorize');
        Route::post('/oauth/authorize', [AgencyOAuthController::class, 'approve'])->name('passport.authorizations.approve');
        Route::delete('/oauth/authorize', [AgencyOAuthController::class, 'deny'])->name('passport.authorizations.deny');
        Route::get('/connect/chatgpt', [AgencyOAuthController::class, 'connections'])->name('mcp.connections');
        Route::delete('/connect/chatgpt/{clientId}', [AgencyOAuthController::class, 'disconnect'])->name('mcp.disconnect');
        Route::post('/connect/chatgpt/logout', [AgencyOAuthController::class, 'logout'])->name('mcp.logout');
    });
});

Mcp::web('/mcp/agency', AgencyServer::class)
    ->middleware([AuthenticateAgencyMcp::class, 'throttle:60,1'])
    ->name('mcp.agency');
