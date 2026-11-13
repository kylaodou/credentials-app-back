<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name'         => 'Awa',
            'email'        => 'awa@example.com',
            'google_id'    => 'g-1',
            'app_password' => Hash::make('Secret#123'),
        ]);
    }

    private function sentCode(): string
    {
        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $mail) use (&$code) {
            $code = $mail->code;
            return true;
        });

        return $code;
    }

    public function test_reset_password_requires_a_valid_reset_token(): void
    {
        $user = $this->user();

        $this->postJson('/api/auth/forgot-password/reset', [
            'email'                 => $user->email,
            'reset_token'           => 'faux',
            'password'              => 'Nouveau#123',
            'password_confirmation' => 'Nouveau#123',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('Secret#123', $user->fresh()->app_password));
    }

    public function test_full_reset_flow_with_otp(): void
    {
        Mail::fake();
        $user = $this->user();

        $this->postJson('/api/auth/forgot-password/send-otp', ['email' => $user->email])->assertOk();
        $code = $this->sentCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $token = $this->postJson('/api/auth/forgot-password/verify-otp', [
            'email' => $user->email,
            'code'  => $code,
        ])->assertOk()->json('reset_token');

        $this->postJson('/api/auth/forgot-password/reset', [
            'email'                 => $user->email,
            'reset_token'           => $token,
            'password'              => 'Nouveau#123',
            'password_confirmation' => 'Nouveau#123',
        ])->assertOk();

        $this->assertTrue(Hash::check('Nouveau#123', $user->fresh()->app_password));

        // Le jeton ne peut servir qu'une fois.
        $this->postJson('/api/auth/forgot-password/reset', [
            'email'                 => $user->email,
            'reset_token'           => $token,
            'password'              => 'Autre#1234',
            'password_confirmation' => 'Autre#1234',
        ])->assertStatus(422);
    }

    public function test_otp_is_invalidated_after_too_many_wrong_attempts(): void
    {
        Mail::fake();
        $user = $this->user();

        $this->postJson('/api/auth/forgot-password/send-otp', ['email' => $user->email]);
        $code = $this->sentCode();
        $wrong = $code === '111111' ? '222222' : '111111';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/forgot-password/verify-otp', ['email' => $user->email, 'code' => $wrong])
                ->assertStatus(422);
        }

        // Même le bon code est refusé une fois la limite atteinte.
        $this->postJson('/api/auth/forgot-password/verify-otp', ['email' => $user->email, 'code' => $code])
            ->assertStatus(422);
    }

    public function test_send_otp_does_not_reveal_unknown_addresses(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password/send-otp', ['email' => 'inconnu@example.com'])->assertOk();
        Mail::assertNothingSent();
    }

    public function test_reveal_requires_recent_password_confirmation(): void
    {
        $user = $this->user();
        $credential = $user->credentials()->create([
            'name'     => 'Banque',
            'type'     => 'password',
            'username' => 'awa',
            'password' => 'motdepasse',
        ]);
        $token = $user->createToken('api-token')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->getJson("/api/credentials/{$credential->id}/reveal", $headers)->assertStatus(423);

        $this->postJson('/api/auth/verify-password', ['password' => 'mauvais'], $headers)->assertStatus(401);
        $this->postJson('/api/auth/verify-password', ['password' => 'Secret#123'], $headers)->assertOk();

        $this->getJson("/api/credentials/{$credential->id}/reveal", $headers)
            ->assertOk()
            ->assertJson(['username' => 'awa', 'password' => 'motdepasse']);
    }

    public function test_existing_app_password_cannot_be_overwritten_with_a_token(): void
    {
        $user = $this->user();
        $token = $user->createToken('api-token')->plainTextToken;

        $this->postJson('/api/auth/set-password', [
            'password'              => 'Pirate#123',
            'password_confirmation' => 'Pirate#123',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(409);

        $this->assertTrue(Hash::check('Secret#123', $user->fresh()->app_password));
    }

    public function test_users_cannot_access_credentials_of_others(): void
    {
        $owner = $this->user();
        $credential = $owner->credentials()->create(['name' => 'Banque', 'type' => 'password', 'password' => 'x']);

        $other = User::create(['name' => 'Ali', 'email' => 'ali@example.com', 'google_id' => 'g-2']);
        $token = $other->createToken('api-token')->plainTextToken;

        $this->getJson("/api/credentials/{$credential->id}", ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403);
    }

    public function test_secrets_are_encrypted_in_database(): void
    {
        $user = $this->user();
        $credential = $user->credentials()->create(['name' => 'Banque', 'type' => 'password', 'password' => 'motdepasse']);

        $raw = \DB::table('credentials')->where('id', $credential->id)->value('password');
        $this->assertNotSame('motdepasse', $raw);
    }
}
