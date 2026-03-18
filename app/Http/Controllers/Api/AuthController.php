<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Mail\WelcomeMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
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
            return redirect(env('FRONTEND_URL', 'http://localhost:4200') . '/login?error=google_failed');
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

        // Redirection vers Angular avec le token
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:4200');
        return redirect("{$frontendUrl}/auth/callback?token={$token}&needs_password={$needsPassword}");
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
        $user->update(['app_password' => Hash::make($request->password)]);

        return response()->json(['message' => 'Password set successfully']);
    }

    // Verify app password (mini-session for sensitive data)
    public function verifyAppPassword(Request $request)
    {
        $request->validate(['password' => 'required']);

        $user = $request->user();
        if (!Hash::check($request->password, $user->app_password)) {
            return response()->json(['message' => 'Invalid password'], 401);
        }

        return response()->json(['verified' => true]);
    }

    // Send OTP for forgot password
    public function sendOtp(Request $request)
    {
        $request->validate(['email' => 'required|email|exists:users,email']);

        OtpCode::where('email', $request->email)->delete();

        $code = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        OtpCode::create([
            'email'      => $request->email,
            'code'       => $code,
            'type'       => 'forgot_password',
            'expires_at' => now()->addMinutes(5),
        ]);

        Mail::to($request->email)->send(new OtpMail($code));

        return response()->json(['message' => 'OTP sent']);
    }

    // Verify OTP
    public function verifyOtp(Request $request)
    {
        $request->validate(['email' => 'required|email', 'code' => 'required|size:4']);

        $otp = OtpCode::where('email', $request->email)
            ->where('code', $request->code)
            ->where('type', 'forgot_password')
            ->latest()
            ->first();

        if (!$otp || $otp->isExpired()) {
            return response()->json(['message' => 'Invalid or expired OTP'], 422);
        }

        $otp->delete();

        return response()->json(['message' => 'OTP verified', 'email' => $request->email]);
    }

    // Reset password after OTP verification
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'    => 'required|email|exists:users,email',
            'password' => ['required', 'confirmed', 'min:8', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[@$!%*?&#+]/'],
        ]);

        $user = User::where('email', $request->email)->firstOrFail();
        $user->update(['app_password' => Hash::make($request->password)]);

        return response()->json(['message' => 'Password reset successfully']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
