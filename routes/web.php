<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RegistrationController;
use App\Http\Middleware\EnsureRegistrationSession;

Route::get('/registration/session', [RegistrationController::class, 'session'])
    ->middleware('throttle:public-api');
Route::post('/registration/register', [RegistrationController::class, 'register'])
    ->middleware(['throttle:signup', EnsureRegistrationSession::class]);

Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');
