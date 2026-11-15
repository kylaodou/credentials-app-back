<?php

namespace App\Support;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Types de jetons et durées de vie.
 *
 * - « pending » : délivré après Google, ne donne accès qu'au profil et à la saisie du mot de passe applicatif.
 * - « app » : session de l'application web, coupée après 30 minutes sans activité et au plus après 12 heures.
 * - « extension » : extension de navigateur, valable 30 jours ; les secrets restent verrouillés
 *   tant que le mot de passe applicatif n'a pas été saisi dans l'extension.
 */
final class Tokens
{
    public const PENDING = 'pending';
    public const APP = 'app';
    public const EXTENSION = 'extension';

    public const IDLE_MINUTES = 30;
    public const PENDING_MINUTES = 10;
    public const APP_MAX_HOURS = 12;
    public const EXTENSION_DAYS = 30;

    public static function pending(User $user): string
    {
        return $user->createToken(self::PENDING, [self::PENDING], now()->addMinutes(self::PENDING_MINUTES))->plainTextToken;
    }

    public static function app(User $user): string
    {
        return $user->createToken(self::APP, [self::APP], now()->addHours(self::APP_MAX_HOURS))->plainTextToken;
    }

    public static function extension(User $user): string
    {
        // Une seule extension connectée à la fois : la précédente est déconnectée.
        $user->tokens()->where('name', self::EXTENSION)->delete();

        return $user->createToken(self::EXTENSION, [self::EXTENSION], now()->addDays(self::EXTENSION_DAYS))->plainTextToken;
    }

    /** Un jeton web inactif depuis plus de 30 minutes n'est plus accepté (l'extension a son propre verrou). */
    public static function isIdle(PersonalAccessToken $token): bool
    {
        if ($token->can(self::EXTENSION) && ! $token->can('*')) {
            return false;
        }

        $last = $token->last_used_at ?? $token->created_at;

        return $last !== null && $last->lt(now()->subMinutes(self::IDLE_MINUTES));
    }
}
