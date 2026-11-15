<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\Credential;
use App\Models\User;
use App\Support\Tokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email = 'awa@example.com'): User
    {
        return User::create([
            'name'         => 'Awa',
            'email'        => $email,
            'google_id'    => 'g-' . $email,
            'app_password' => Hash::make('Secret#123'),
        ]);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    public function test_unauthenticated_api_call_returns_json_401_instead_of_500(): void
    {
        $this->get('/api/me')->assertStatus(401)->assertJson(['message' => 'Unauthenticated.']);
        $this->get('/api/credentials')->assertStatus(401);
    }

    public function test_pending_token_cannot_reach_credentials_until_app_password_is_entered(): void
    {
        $user = $this->user();
        $pending = Tokens::pending($user);

        $this->getJson('/api/me', $this->bearer($pending))->assertOk()->assertJsonPath('session.locked', true);
        $this->getJson('/api/credentials', $this->bearer($pending))->assertForbidden();
        $this->postJson('/api/credentials', ['name' => 'X', 'type' => 'password'], $this->bearer($pending))->assertForbidden();

        $this->postJson('/api/auth/verify-password', ['password' => 'mauvais'], $this->bearer($pending))->assertStatus(401);

        $session = $this->postJson('/api/auth/verify-password', ['password' => 'Secret#123'], $this->bearer($pending))
            ->assertOk()->json('token');
        $this->assertNotEmpty($session);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/credentials', $this->bearer($session))->assertOk();
        $this->getJson('/api/me', $this->bearer($session))->assertJsonPath('session.locked', false);

        // Le jeton limité a été remplacé : il ne sert plus.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me', $this->bearer($pending))->assertStatus(401);
    }

    public function test_session_ends_after_30_minutes_without_activity(): void
    {
        $user = $this->user();
        $token = Tokens::app($user);
        $id = explode('|', $token)[0];

        PersonalAccessToken::find($id)->forceFill(['last_used_at' => now()->subMinutes(29)])->save();
        $this->getJson('/api/credentials', $this->bearer($token))->assertOk();

        PersonalAccessToken::find($id)->forceFill(['last_used_at' => now()->subMinutes(31)])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/credentials', $this->bearer($token))->assertStatus(401);
        $this->assertNull(PersonalAccessToken::find($id), 'le jeton inactif doit être supprimé');
    }

    public function test_web_session_cannot_last_more_than_12_hours(): void
    {
        $token = Tokens::app($this->user());
        $this->travel(13)->hours();
        $this->getJson('/api/me', $this->bearer($token))->assertStatus(401);
    }

    public function test_password_reset_closes_every_open_session(): void
    {
        $user = $this->user();
        Tokens::app($user);
        Tokens::extension($user);
        $this->assertSame(2, $user->tokens()->count());

        Mail::fake();
        $this->postJson('/api/auth/forgot-password/send-otp', ['email' => $user->email])->assertOk();
        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $mail) use (&$code) {
            $code = $mail->code;
            return true;
        });
        $reset = $this->postJson('/api/auth/forgot-password/verify-otp', ['email' => $user->email, 'code' => $code])->json('reset_token');
        $this->postJson('/api/auth/forgot-password/reset', [
            'email' => $user->email, 'reset_token' => $reset,
            'password' => 'Nouveau#456', 'password_confirmation' => 'Nouveau#456',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_extension_connects_with_a_single_use_code_and_has_limited_rights(): void
    {
        $user = $this->user();
        $credential = $user->credentials()->create([
            'name' => 'GitHub', 'type' => 'password', 'username' => 'awa', 'password' => 'S3cret!', 'url' => 'https://github.com/login',
        ]);
        $app = Tokens::app($user);

        // Le code exige un déverrouillage récent de l'application web.
        $this->postJson('/api/extension/code', [], $this->bearer($app))->assertStatus(423);
        $this->postJson('/api/auth/verify-password', ['password' => 'Secret#123'], $this->bearer($app))->assertOk();
        $code = $this->postJson('/api/extension/code', [], $this->bearer($app))->assertOk()->json('code');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $code);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/extension/connect', ['code' => 'FAUXCODE'])->assertStatus(422);
        $ext = $this->postJson('/api/extension/connect', ['code' => strtolower($code)])->assertOk()->json('token');
        $this->postJson('/api/extension/connect', ['code' => $code])->assertStatus(422); // usage unique

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/extension/matches?url=https://www.github.com/session', $this->bearer($ext))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'GitHub')->assertJsonMissingPath('0.password');
        $this->getJson('/api/extension/matches?url=https://example.com', $this->bearer($ext))->assertOk()->assertJsonCount(0);

        // Secrets verrouillés tant que le mot de passe n'est pas saisi dans l'extension.
        $this->getJson("/api/credentials/{$credential->id}/reveal", $this->bearer($ext))->assertStatus(423);
        $this->postJson('/api/auth/verify-password', ['password' => 'Secret#123'], $this->bearer($ext))->assertOk()->assertJsonMissingPath('token');
        $this->getJson("/api/credentials/{$credential->id}/reveal", $this->bearer($ext))->assertOk()->assertJsonPath('password', 'S3cret!');

        // L'extension peut enregistrer un nouvel identifiant, pas modifier ni supprimer.
        $this->postJson('/api/credentials', ['name' => 'Gmail', 'type' => 'password', 'username' => 'a', 'password' => 'b', 'url' => 'https://mail.google.com'], $this->bearer($ext))->assertCreated();
        $this->putJson("/api/credentials/{$credential->id}", ['name' => 'X'], $this->bearer($ext))->assertForbidden();
        $this->deleteJson("/api/credentials/{$credential->id}", [], $this->bearer($ext))->assertForbidden();
        $this->postJson('/api/extension/code', [], $this->bearer($ext))->assertForbidden();

        // L'extension n'est pas déconnectée après 30 minutes (elle se reverrouille seulement).
        $id = explode('|', $ext)[0];
        PersonalAccessToken::find($id)->forceFill(['last_used_at' => now()->subHours(3)])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me', $this->bearer($ext))->assertOk();
    }

    public function test_extension_cannot_read_matches_of_another_user(): void
    {
        $other = $this->user('autre@example.com');
        $other->credentials()->create(['name' => 'Banque', 'type' => 'password', 'url' => 'https://banque.example']);
        $ext = Tokens::extension($this->user());

        $this->getJson('/api/extension/matches?url=https://banque.example', $this->bearer($ext))->assertOk()->assertJsonCount(0);
    }

    public function test_connecting_a_new_extension_disconnects_the_previous_one(): void
    {
        $user = $this->user();
        $first = Tokens::extension($user);
        Tokens::extension($user);

        $this->getJson('/api/me', $this->bearer($first))->assertStatus(401);
    }

    public function test_security_headers_and_no_cache_on_api(): void
    {
        $response = $this->getJson('/api/me');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_oversized_or_unsafe_values_are_rejected(): void
    {
        $token = Tokens::app($this->user());

        $this->postJson('/api/credentials', ['name' => 'A', 'type' => 'password', 'password' => str_repeat('x', 1001)], $this->bearer($token))
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/credentials', ['name' => 'A', 'type' => 'password', 'url' => 'javascript:alert(1)'], $this->bearer($token))
            ->assertStatus(422)->assertJsonValidationErrors('url');
    }

    public function test_updating_without_secret_fields_keeps_existing_secrets(): void
    {
        $user = $this->user();
        $token = Tokens::app($user);
        $credential = $user->credentials()->create(['name' => 'Wifi', 'type' => 'password', 'username' => 'u', 'password' => 'p@ss']);

        $this->putJson("/api/credentials/{$credential->id}", ['name' => 'Wifi maison'], $this->bearer($token))->assertOk();

        $fresh = Credential::find($credential->id);
        $this->assertSame('Wifi maison', $fresh->name);
        $this->assertSame('p@ss', $fresh->password);
    }
}
