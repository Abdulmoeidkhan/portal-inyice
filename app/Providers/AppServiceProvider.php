<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

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
        }

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return URL::to('/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]));
        });

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
            return Limit::perDay(2)
                ->by('registration-network:'.$this->networkKey($request))
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'This network has reached the limit of two registration requests in 24 hours. Please try again later.',
                ], 429, $headers));
        });

        RateLimiter::for('signin-failures', function (Request $request) {
            return Limit::perDay(5)
                ->by('signin-network:'.$this->networkKey($request))
                ->after(fn (Response $response) => $response->getStatusCode() === 422)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'This network has reached the limit of five failed sign-in attempts in 24 hours. Please try again later or reset your password.',
                ], 429, $headers));
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
    private function networkKey(Request $request): string
    {
        $address = inet_pton($request->ip() ?? '0.0.0.0');
        // Group IPv6 privacy addresses by /64; IPv4 uses the public address.
        if ($address !== false && strlen($address) === 16
            && substr($address, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $address = substr($address, 12);
        }

        return $address === false ? 'unknown' : bin2hex(substr($address, 0, 8));
    }
}
