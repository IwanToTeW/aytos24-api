<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

Route::get('/', function () {
    return view('welcome');
});

// Sanctum's CSRF initialisation for the web app, rate limited (config/sanctum.php disables
// Sanctum's own unthrottled route). Not part of the versioned API.
Route::get('sanctum/csrf-cookie', [CsrfCookieController::class, 'show'])
    ->middleware('throttle:csrf-cookie')
    ->name('sanctum.csrf-cookie');
