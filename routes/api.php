<?php

use App\Http\Controllers\Api\V1\HelloController;
use Illuminate\Support\Facades\Route;

// API routes are prefixed with /api and documented by Scramble at /docs/api.

Route::prefix('v1')->group(function () {
    Route::get('hello', HelloController::class);
});
