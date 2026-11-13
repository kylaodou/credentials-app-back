<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Mail\WelcomeMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    /** Durée de validité de la confirmation du mot de passe applicatif. */
    public const CONFIRMATION_MINUTES = 5;

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

        $token = $user->createToken('api-token')->plainTextToken;
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

        return response()->json(['message' => 'Password set successfully']);
    }

    // Verify app password (mini-session for sensitive data)
    public function verifyAppPassword(Request $request)
    {
        $request->validate(['password' => 'required']);

        $user = $request->user();
        if (!$user->app_password || !Hash::check($request->password, $user->app_password)) {
            return response()->json(['message' => 'Invalid password'], 401);
        }

        // Ouvre une courte session de confirmation, liée au jeton courant,
        // exigée par le serveur pour afficher les secrets en clair.
        Cache::put(self::verifiedKey($request), true, now()->addMinutes(self::CONFIRMATION_MINUTES));

        return response()->json(['verified' => true, 'expires_in' => self::CONFIRMATION_MINUTES * 60]);
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

        return response()->json(['message' => 'Password reset successfully']);
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
        return response()->json($request->user());
    }

    public function logout(Request $request)
    {
        Cache::forget(self::verifiedKey($request));
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
