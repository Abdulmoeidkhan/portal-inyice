<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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
        RateLimiter::for('signup', function (Request $request) {
            $email = (string) $request->input('admin_email', '');

            return Limit::perMinute(5)
                ->by(Str::lower($request->ip() . '|' . $email));
        });

        RateLimiter::for('signin', function (Request $request) {
            $email = (string) $request->input('email', '');

            return Limit::perMinute(6)
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
