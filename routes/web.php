<?php

use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\SupportController;
use App\Http\Middleware\EnsureRegistrationSession;
use App\Mcp\OAuth\Metadata;
use Illuminate\Support\Facades\Route;

Route::get('/registration/session', [RegistrationController::class, 'session'])
    ->middleware('throttle:public-api');
Route::post('/registration/register', [RegistrationController::class, 'register'])
    ->middleware(['throttle:signup', EnsureRegistrationSession::class]);

Route::get('/llms.txt', [Metadata::class, 'instructions']);
Route::get('/.well-known/openai-apps-challenge', [Metadata::class, 'verifyDomain']);
Route::get('/connect/ai', [Metadata::class, 'guide']);

Route::get('/support', [SupportController::class, 'show'])->name('support.show');
Route::post('/support', [SupportController::class, 'store'])
    ->middleware('throttle:3,10')->name('support.store');

Route::get('/shared/{document}/{token}', [\App\Http\Controllers\SharedDocumentController::class, 'show'])
    ->where('document', 'quotations|vouchers|invoices|itineraries')
    ->middleware('throttle:public-api');

Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');
