<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CredentialController;
use App\Http\Controllers\Api\ExtensionController;
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

// L'extension échange un code à usage unique contre son jeton.
Route::post('extension/connect', [ExtensionController::class, 'connect'])->middleware('throttle:extension-connect');

// Routes protégées. Trois sortes de jetons (voir App\Support\Tokens) :
// - « pending » (juste après Google) : profil, saisie du mot de passe applicatif, déconnexion ;
// - « app » (application web déverrouillée) : tout ;
// - « extension » : liste, recherche par site, ajout et affichage d'un secret (après déverrouillage).
Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/verify-password', [AuthController::class, 'verifyAppPassword'])->middleware('throttle:app-password');
    Route::post('auth/set-password', [AuthController::class, 'setPassword'])->middleware('throttle:app-password');

    Route::middleware('throttle:api')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    Route::middleware('ability:app,extension')->group(function () {
        Route::get('credentials/{credential}/reveal', [CredentialController::class, 'reveal'])->middleware('throttle:reveal');

        Route::middleware('throttle:api')->group(function () {
            Route::get('credentials', [CredentialController::class, 'index']);
            Route::post('credentials', [CredentialController::class, 'store']);
            Route::get('extension/matches', [ExtensionController::class, 'matches']);
        });
    });

    Route::middleware(['abilities:app', 'throttle:api'])->group(function () {
        Route::get('credentials/{credential}', [CredentialController::class, 'show']);
        Route::match(['put', 'patch'], 'credentials/{credential}', [CredentialController::class, 'update']);
        Route::delete('credentials/{credential}', [CredentialController::class, 'destroy']);
        Route::post('extension/code', [ExtensionController::class, 'code']);
    });
});
