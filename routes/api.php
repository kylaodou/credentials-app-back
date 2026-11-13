<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CredentialController;
use Illuminate\Support\Facades\Route;

// Routes publiques d'authentification, avec limitation du nombre de requêtes
// (limiteurs définis dans App\Providers\AppServiceProvider)
Route::prefix('auth')->group(function () {
    Route::middleware('throttle:oauth')->group(function () {
        Route::get('google/redirect', [AuthController::class, 'redirectToGoogle']);
        Route::get('google/callback', [AuthController::class, 'handleGoogleCallback']);
    });

    Route::post('forgot-password/send-otp', [AuthController::class, 'sendOtp'])->middleware('throttle:otp-send');
    Route::post('forgot-password/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify');
    Route::post('forgot-password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');
});

// Routes protégées
Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/verify-password', [AuthController::class, 'verifyAppPassword'])->middleware('throttle:app-password');
    Route::get('credentials/{credential}/reveal', [CredentialController::class, 'reveal'])->middleware('throttle:reveal');

    Route::middleware('throttle:api')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('auth/set-password', [AuthController::class, 'setPassword']);
        Route::apiResource('credentials', CredentialController::class);
    });
});
