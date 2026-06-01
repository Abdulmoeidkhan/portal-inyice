<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RegistrationController;

// Public routes (outside React app for registration processing)
Route::post('/register', [RegistrationController::class, 'register'])->middleware('throttle:signup');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:signin');


// Serve React app for all routes (client-side routing handled by React Router)
Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');

