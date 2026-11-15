<?php

namespace App\Providers;

use App\Support\Tokens;

use App\Models\PersonalAccessToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Déconnexion automatique : un jeton web inutilisé depuis 30 minutes est refusé puis supprimé.
        Sanctum::authenticateAccessTokensUsing(function ($token, bool $isValid) {
            if ($isValid && Tokens::isIdle($token)) {
                $token->delete();
                return false;
            }

            return $isValid;
        });

        $this->configureRateLimiting();
    }

    /**
     * Chaque limiteur a son propre compteur, par utilisateur connecté ou par adresse IP.
     */
    private function configureRateLimiting(): void
    {
        $by = fn (Request $request) => $request->user()?->id ?: $request->ip();

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($by($request)));

        RateLimiter::for('oauth', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('otp-send', fn (Request $request) => [
            Limit::perMinutes(10, 3)->by('ip:' . $request->ip()),
            Limit::perMinutes(10, 3)->by('email:' . mb_strtolower((string) $request->input('email'))),
        ]);

        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinutes(10, 10)->by($request->ip()));

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinutes(10, 5)->by($request->ip()));

        RateLimiter::for('app-password', fn (Request $request) => Limit::perMinute(5)->by($by($request)));

        RateLimiter::for('reveal', fn (Request $request) => Limit::perMinute(30)->by($by($request)));

        // Codes de connexion de l'extension : peu d'essais, par adresse IP.
        RateLimiter::for('extension-connect', fn (Request $request) => Limit::perMinutes(10, 10)->by($request->ip()));
    }
}
