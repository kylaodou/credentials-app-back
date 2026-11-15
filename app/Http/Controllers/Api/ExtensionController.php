<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tokens;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Connexion de l'extension de navigateur.
 *
 * L'application web (session déverrouillée) affiche un code à usage unique valable 5 minutes ;
 * l'extension l'échange contre son propre jeton. Le code n'est stocké que sous forme d'empreinte.
 */
class ExtensionController extends Controller
{
    private const CODE_MINUTES = 5;
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sans 0/O ni 1/I

    public function code(Request $request)
    {
        if (! Cache::has(AuthController::verifiedKey($request))) {
            return response()->json(['message' => 'Password confirmation required'], 423);
        }

        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        Cache::put(self::key($code), $request->user()->getKey(), now()->addMinutes(self::CODE_MINUTES));

        return response()->json(['code' => $code, 'expires_in' => self::CODE_MINUTES * 60]);
    }

    public function connect(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:20']);
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $data['code']));

        $userId = Cache::pull(self::key($code));
        $user = $userId ? User::find($userId) : null;

        if (! $user) {
            return response()->json(['message' => 'Invalid or expired code'], 422);
        }

        return response()->json([
            'token' => Tokens::extension($user),
            'user'  => $user->only(['name', 'email']),
        ]);
    }

    /** Identifiants dont l'adresse correspond au site ouvert dans le navigateur. */
    public function matches(Request $request)
    {
        $data = $request->validate(['url' => 'required|string|max:2048']);
        $host = self::host($data['url']);

        if (! $host) {
            return response()->json([]);
        }

        $matches = $request->user()->credentials()
            ->whereNotNull('url')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'url'])
            ->filter(function ($credential) use ($host) {
                $saved = self::host($credential->url);

                return $saved && ($saved === $host || str_ends_with($host, '.' . $saved) || str_ends_with($saved, '.' . $host));
            })
            ->values();

        return response()->json($matches);
    }

    /** Nom d'hôte sans « www. », en minuscules. */
    private static function host(?string $url): ?string
    {
        $host = parse_url((string) $url, PHP_URL_HOST);

        return $host ? preg_replace('/^www\./', '', mb_strtolower($host)) : null;
    }

    private static function key(string $code): string
    {
        return 'extension-code:' . hash('sha256', $code);
    }
}
