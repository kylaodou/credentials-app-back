<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Credential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CredentialController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()->credentials()->orderByDesc('created_at')->get(['id', 'name', 'type', 'url', 'description', 'validity_date', 'created_at']);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'type'          => 'required|in:password,token',
            'username'      => 'nullable|string',
            'password'      => 'nullable|string',
            'token'         => 'nullable|string',
            'url'           => 'nullable|url',
            'description'   => 'nullable|string',
            'validity_date' => 'nullable|date',
        ]);

        $credential = $request->user()->credentials()->create($data);

        return response()->json($credential->only(['id', 'name', 'type', 'url', 'description', 'validity_date', 'created_at']), 201);
    }

    public function show(Request $request, Credential $credential)
    {
        $this->authorize($request->user(), $credential);
        return response()->json($credential->only(['id', 'name', 'type', 'url', 'description', 'validity_date', 'created_at']));
    }

    public function reveal(Request $request, Credential $credential)
    {
        $this->authorize($request->user(), $credential);

        // Les secrets ne sont renvoyés qu'après confirmation récente du mot de passe applicatif.
        if (!Cache::has(AuthController::verifiedKey($request))) {
            return response()->json(['message' => 'Password confirmation required'], 423);
        }

        return response()->json([
            'username' => $credential->username,
            'password' => $credential->password,
            'token'    => $credential->token,
        ]);
    }

    public function update(Request $request, Credential $credential)
    {
        $this->authorize($request->user(), $credential);

        $data = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'type'          => 'sometimes|in:password,token',
            'username'      => 'nullable|string',
            'password'      => 'nullable|string',
            'token'         => 'nullable|string',
            'url'           => 'nullable|url',
            'description'   => 'nullable|string',
            'validity_date' => 'nullable|date',
        ]);

        $credential->update($data);

        return response()->json($credential->only(['id', 'name', 'type', 'url', 'description', 'validity_date', 'created_at']));
    }

    public function destroy(Request $request, Credential $credential)
    {
        $this->authorize($request->user(), $credential);
        $credential->delete();
        return response()->json(null, 204);
    }

    private function authorize(mixed $user, Credential $credential): void
    {
        abort_if((string) $credential->user_id !== (string) $user->id, 403);
    }
}
