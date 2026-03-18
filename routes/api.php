<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CredentialController;
use Illuminate\Support\Facades\Route;

// Public auth routes
Route::prefix('auth')->group(function () {
    Route::get('google/redirect', [AuthController::class, 'redirectToGoogle']);
    Route::get('google/callback', [AuthController::class, 'handleGoogleCallback']);
    Route::post('forgot-password/send-otp', [AuthController::class, 'sendOtp']);
    Route::post('forgot-password/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('forgot-password/reset', [AuthController::class, 'resetPassword']);
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('auth/set-password', [AuthController::class, 'setPassword']);
    Route::post('auth/verify-password', [AuthController::class, 'verifyAppPassword']);

    Route::apiResource('credentials', CredentialController::class);
    Route::get('credentials/{credential}/reveal', [CredentialController::class, 'reveal']);
});
