<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Mail\WelcomeMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Support\Tokens;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    /** Durée de validité de la confirmation du mot de passe applicatif (application web). */
    public const CONFIRMATION_MINUTES = 5;

    /** Durée pendant laquelle l'extension reste déverrouillée après la saisie du mot de passe. */
    public const EXTENSION_UNLOCK_MINUTES = 15;

    /** Nombre d'essais autorisés pour un même code OTP. */
    private const MAX_OTP_ATTEMPTS = 5;

    // Redirect to Google directly (browser redirect)
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    // Handle Google callback — redirige vers le frontend avec le token
    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception $e) {
            return redirect(rtrim(config('app.frontend_url'), '/') . '/login?error=google_failed');
        }

        $isNew = false;
        $user = User::where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        if (!$user) {
            $user = User::create([
                'name'      => $googleUser->getName(),
                'email'     => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'avatar'    => $googleUser->getAvatar(),
            ]);
            $isNew = true;
            Mail::to($user->email)->send(new WelcomeMail($user));
        } else {
            $user->update(['google_id' => $googleUser->getId(), 'avatar' => $googleUser->getAvatar()]);
        }

        // Jeton limité : il ne donne accès aux identifiants qu'après la saisie du mot de passe applicatif.
        $token = Tokens::pending($user);
        $needsPassword = !$user->app_password ? 'true' : 'false';

        // Le jeton est transmis dans le fragment (#) : il n'est jamais envoyé à un serveur,
        // n'apparaît pas dans les journaux et n'est pas transmis dans l'en-tête Referer.
        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $fragment = http_build_query(['token' => $token, 'needs_password' => $needsPassword]);

        return redirect("{$frontendUrl}/auth/callback#{$fragment}");
    }

    // Set app password after first login
    public function setPassword(Request $request)
    {
        $request->validate([
            'password' => ['required', 'confirmed', 'min:8', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[@$!%*?&#+]/'],
        ], [
            'password.min'   => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.regex' => 'Le mot de passe doit contenir une majuscule, un chiffre et un caractère spécial (@$!%*?&#+).',
        ]);

        $user = $request->user();

        // Uniquement à la première connexion : pour changer un mot de passe existant,
        // il faut passer par la procédure « mot de passe oublié » (code envoyé par e-mail).
        if ($user->app_password) {
            return response()->json(['message' => 'Password already set'], 409);
        }

        $user->update(['app_password' => Hash::make($request->password)]);

        return response()->json(['message' => 'Password set successfully'] + $this->unlock($request));
    }

    // Verify app password (mini-session for sensitive data)
    public function verifyAppPassword(Request $request)
    {
        $request->validate(['password' => 'required']);

        $user = $request->user();
        if (!$user->app_password || !Hash::check($request->password, $user->app_password)) {
            return response()->json(['message' => 'Invalid password'], 401);
        }

        return response()->json(['verified' => true] + $this->unlock($request));
    }

    // Send OTP for forgot password
    public function sendOtp(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $email = mb_strtolower($request->email);

        // Même réponse que le compte existe ou non : on ne révèle pas les adresses inscrites.
        if (User::where('email', $email)->exists()) {
            OtpCode::where('email', $email)->delete();

            $code = (string) random_int(100000, 999999);

            OtpCode::create([
                'email'      => $email,
                'code'       => Hash::make($code),
                'type'       => 'forgot_password',
                'attempts'   => 0,
                'expires_at' => now()->addMinutes(5),
            ]);

            Mail::to($email)->send(new OtpMail($code));
        }

        return response()->json(['message' => 'If the address exists, a code has been sent.']);
    }

    // Verify OTP
    public function verifyOtp(Request $request)
    {
        $request->validate(['email' => 'required|email', 'code' => 'required|digits:6']);
        $email = mb_strtolower($request->email);

        $otp = OtpCode::where('email', $email)
            ->where('type', 'forgot_password')
            ->latest()
            ->first();

        if (!$otp || $otp->isExpired() || $otp->attempts >= self::MAX_OTP_ATTEMPTS) {
            $otp?->delete();
            return response()->json(['message' => 'Invalid or expired OTP'], 422);
        }

        if (!Hash::check($request->code, $otp->code)) {
            $otp->increment('attempts');
            return response()->json(['message' => 'Invalid or expired OTP'], 422);
        }

        $otp->delete();

        // Jeton à usage unique, valable 10 minutes, exigé pour changer le mot de passe.
        $resetToken = Str::random(64);
        Cache::put(self::resetKey($email), hash('sha256', $resetToken), now()->addMinutes(10));

        return response()->json(['message' => 'OTP verified', 'reset_token' => $resetToken]);
    }

    // Reset password after OTP verification
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'       => 'required|email',
            'reset_token' => 'required|string',
            'password'    => ['required', 'confirmed', 'min:8', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[@$!%*?&#+]/'],
        ]);
        $email = mb_strtolower($request->email);

        $expected = Cache::pull(self::resetKey($email));
        if (!$expected || !hash_equals($expected, hash('sha256', $request->reset_token))) {
            return response()->json(['message' => 'Invalid or expired reset token'], 422);
        }

        $user = User::where('email', $email)->firstOrFail();
        $user->update(['app_password' => Hash::make($request->password)]);

        // Nouveau mot de passe : toutes les sessions ouvertes (web et extension) sont fermées.
        $user->tokens()->delete();

        return response()->json(['message' => 'Password reset successfully']);
    }

    /**
     * Après la saisie du mot de passe applicatif :
     * - un jeton « pending » est remplacé par un vrai jeton de session (renvoyé au client) ;
     * - une courte fenêtre est ouverte pour afficher les secrets en clair.
     */
    private function unlock(Request $request): array
    {
        $current = $request->user()->currentAccessToken();
        $extra = [];

        if ($current->can(Tokens::PENDING) && ! $current->can('*')) {
            $plain = Tokens::app($request->user());
            $current->delete();
            $tokenId = explode('|', $plain, 2)[0];
            $extra['token'] = $plain;
        } else {
            $tokenId = $current->getKey();
        }

        $minutes = $current->can(Tokens::EXTENSION) && ! $current->can('*')
            ? self::EXTENSION_UNLOCK_MINUTES
            : self::CONFIRMATION_MINUTES;

        Cache::put('app-password-verified:' . $tokenId, true, now()->addMinutes($minutes));

        return $extra + ['expires_in' => $minutes * 60];
    }

    public static function verifiedKey(Request $request): string
    {
        return 'app-password-verified:' . $request->user()->currentAccessToken()->getKey();
    }

    private static function resetKey(string $email): string
    {
        return 'password-reset:' . hash('sha256', $email);
    }

    public function me(Request $request)
    {
        $token = $request->user()->currentAccessToken();

        return response()->json($request->user()->toArray() + [
            'session' => [
                'locked'       => $token->can(Tokens::PENDING) && ! $token->can('*'),
                'idle_minutes' => Tokens::IDLE_MINUTES,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        Cache::forget(self::verifiedKey($request));
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
