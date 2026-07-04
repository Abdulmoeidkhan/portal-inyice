<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ((bool) env('FORCE_HTTPS', false)) {
            URL::forceScheme('https');
            URL::forceRootUrl((string) config('app.url'));
        }

        RateLimiter::for('api', function (Request $request) {
            $userKey = $request->user()?->getAuthIdentifier();
            $routeKey = optional($request->route())->getName() ?: $request->path();

            return Limit::perMinute((int) env('API_RATE_LIMIT_PER_MINUTE', 120))
                ->by(Str::lower(($userKey ? 'user:'.$userKey : 'ip:'.$request->ip()).'|'.$routeKey));
        });

        RateLimiter::for('public-api', function (Request $request) {
            return Limit::perMinute((int) env('PUBLIC_API_RATE_LIMIT_PER_MINUTE', 60))
                ->by(Str::lower('ip:'.$request->ip().'|'.$request->path()));
        });

        RateLimiter::for('sensitive-write', function (Request $request) {
            $userKey = $request->user()?->getAuthIdentifier();

            return Limit::perMinute((int) env('SENSITIVE_WRITE_RATE_LIMIT_PER_MINUTE', 30))
                ->by(Str::lower(($userKey ? 'user:'.$userKey : 'ip:'.$request->ip()).'|'.$request->path()));
        });

        RateLimiter::for('signup', function (Request $request) {
            $email = (string) $request->input('admin_email', '');

            return Limit::perMinute((int) env('SIGNUP_RATE_LIMIT_PER_MINUTE', 5))
                ->by(Str::lower($request->ip() . '|' . $email));
        });

        RateLimiter::for('signin', function (Request $request) {
            $email = (string) $request->input('email', '');

            return Limit::perMinute((int) env('SIGNIN_RATE_LIMIT_PER_MINUTE', 6))
                ->by(Str::lower($request->ip() . '|' . $email));
        });

        Model::creating(function (Model $model): void {
            if ($model->getAttribute('uid')) {
                return;
            }

            if (Schema::hasColumn($model->getTable(), 'uid')) {
                $model->setAttribute('uid', (string) Str::ulid());
            }
        });
    }
}
