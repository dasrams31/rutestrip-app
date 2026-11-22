<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SsoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** JSON API the chat signs users in with: /api/sso/{login,register,me} (docs/SSO.md). */
class SsoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.sso.secret'          => 'test-sso-secret',
            'services.sso.internal_secret' => 'test-internal-secret',
            'services.altcha.max_number'   => 50,
            'services.altcha.hmac_key'     => 'test-hmac-key',
        ]);
        // The test client sends 'en-us,en;q=0.5' by default; the chat may send nothing.
        $this->withHeader('Accept-Language', '');
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Rama', 'email' => 'rama@example.com', 'password' => 'rahasia123', 'role' => 'user',
        ], $attributes))->refresh();
    }

    private function solvedAltcha(): string
    {
        $challenge = $this->getJson(route('altcha.challenge'))->json();
        $number    = collect(range(0, 50))->first(fn ($n) => hash('sha256', $challenge['salt'] . $n) === $challenge['challenge']);

        return base64_encode(json_encode([
            'algorithm' => 'SHA-256', 'challenge' => $challenge['challenge'], 'number' => $number,
            'salt' => $challenge['salt'], 'signature' => $challenge['signature'],
        ]));
    }

    private function registerBody(array $extra = []): array
    {
        return array_merge([
            'name' => 'Pendaki Baru', 'email' => 'pendaki@example.com',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123', 'website' => '',
        ], $extra);
    }

    // --- login -------------------------------------------------------------

    public function test_login_returns_token_and_user(): void
    {
        $this->freezeTime();
        $user = $this->user();

        $response = $this->postJson('/api/sso/login', ['email' => 'rama@example.com', 'password' => 'rahasia123'])
            ->assertOk()
            ->assertExactJson([
                'token' => $this->app->make(SsoService::class)->issue($user),
                'user'  => ['id' => $user->id, 'name' => 'Rama', 'email' => 'rama@example.com', 'role' => 'user', 'locale' => 'id'],
            ]);

        $this->assertTrue(app(SsoService::class)->user($response->json('token'))->is($user));
        $this->assertGuest(); // stateless: no session login
    }

    public function test_login_with_wrong_password_is_401_in_request_locale(): void
    {
        $this->user();

        $this->postJson('/api/sso/login', ['email' => 'rama@example.com', 'password' => 'salah-sekali'])
            ->assertStatus(401)
            ->assertExactJson(['error' => 'Email atau kata sandi salah.']);

        $this->postJson('/api/sso/login', ['email' => 'nobody@example.com', 'password' => 'salah-sekali'], ['Accept-Language' => 'en-US,en;q=0.9'])
            ->assertStatus(401)
            ->assertExactJson(['error' => 'Wrong email or password.']);
    }

    public function test_login_validation_is_422_with_field_errors(): void
    {
        $response = $this->postJson('/api/sso/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'errors' => ['email', 'password']]);

        $this->assertSame('Email harus berupa alamat email yang valid.', $response->json('error'));
    }

    public function test_login_works_with_form_body_and_without_csrf(): void
    {
        $this->user();

        $this->post('/api/sso/login', ['email' => 'rama@example.com', 'password' => 'rahasia123'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'locale']]);
    }

    public function test_login_is_throttled_per_ip_at_ten_per_minute(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/sso/login', ['email' => 'x@example.com', 'password' => 'salah'])->assertStatus(401);
        }

        $this->postJson('/api/sso/login', ['email' => 'x@example.com', 'password' => 'salah'])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['error']);
    }

    public function test_forwarded_client_ip_is_used_only_with_the_internal_secret(): void
    {
        $trusted = fn (string $ip) => ['X-RuteStrip-Internal' => 'test-internal-secret', 'X-RuteStrip-Client-IP' => $ip];

        // A different email per try, so only the per-IP limit is in play (accounts have their own limit).
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/sso/login', ['email' => "x$i@example.com", 'password' => 'salah'], $trusted('203.0.113.7'))->assertStatus(401);
        }
        $this->postJson('/api/sso/login', ['email' => 'x10@example.com', 'password' => 'salah'], $trusted('203.0.113.7'))->assertStatus(429);

        // Another visitor behind the same chat server is not affected.
        $this->postJson('/api/sso/login', ['email' => 'y@example.com', 'password' => 'salah'], $trusted('203.0.113.8'))->assertStatus(401);

        // Without (or with a wrong) secret the header is ignored: the bucket is the real IP.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/sso/login', ['email' => "z$i@example.com", 'password' => 'salah'], [
                'X-RuteStrip-Internal' => 'wrong', 'X-RuteStrip-Client-IP' => '198.51.100.' . $i,
            ])->assertStatus(401);
        }
        $this->postJson('/api/sso/login', ['email' => 'z99@example.com', 'password' => 'salah'], [
            'X-RuteStrip-Client-IP' => '198.51.100.99',
        ])->assertStatus(429);
    }

    // --- register ----------------------------------------------------------

    public function test_register_creates_user_and_returns_201(): void
    {
        $response = $this->postJson('/api/sso/register', $this->registerBody(['altcha' => $this->solvedAltcha()]), ['Accept-Language' => 'en'])
            ->assertStatus(201)
            ->assertJsonPath('user.name', 'Pendaki Baru')
            ->assertJsonPath('user.email', 'pendaki@example.com')
            ->assertJsonPath('user.role', 'user')
            ->assertJsonPath('user.locale', 'en');

        $user = User::where('email', 'pendaki@example.com')->firstOrFail();
        $this->assertSame($user->id, $response->json('user.id'));
        $this->assertTrue(app(SsoService::class)->user($response->json('token'))->is($user));
    }

    public function test_register_without_altcha_or_with_honeypot_is_rejected(): void
    {
        $this->postJson('/api/sso/register', $this->registerBody())
            ->assertStatus(422)
            ->assertExactJson([
                'error'  => 'Cek bukan robot belum lolos. Centang kotaknya lagi, lalu kirim ulang.',
                'errors' => ['altcha' => ['Cek bukan robot belum lolos. Centang kotaknya lagi, lalu kirim ulang.']],
            ]);

        $this->postJson('/api/sso/register', $this->registerBody(['altcha' => $this->solvedAltcha(), 'website' => 'https://spam.example']), ['Accept-Language' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'The robot check did not pass. Tick the box again and send the form once more.');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_replayed_altcha_is_rejected(): void
    {
        $payload = $this->solvedAltcha();
        $this->postJson('/api/sso/register', $this->registerBody(['altcha' => $payload]))->assertStatus(201);

        $this->postJson('/api/sso/register', $this->registerBody(['email' => 'lain@example.com', 'altcha' => $payload]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('altcha');
    }

    public function test_register_validation_errors(): void
    {
        $this->user(['email' => 'pendaki@example.com']);

        $response = $this->postJson('/api/sso/register', $this->registerBody([
            'name' => '', 'password' => 'pendek', 'password_confirmation' => 'beda', 'altcha' => $this->solvedAltcha(),
        ]))->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'password']);

        $this->assertSame('Email sudah terdaftar.', $response->json('errors.email.0'));

        $response = $this->postJson('/api/sso/register', $this->registerBody([
            'email' => 'baru@example.com', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia124', 'altcha' => $this->solvedAltcha(),
        ]), ['Accept-Language' => 'en'])->assertStatus(422);

        $this->assertSame('The password confirmation does not match.', $response->json('error'));
    }

    public function test_register_is_throttled_at_five_per_minute(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/sso/register', $this->registerBody())->assertStatus(422);
        }

        $this->postJson('/api/sso/register', $this->registerBody())->assertStatus(429);
    }

    // --- me ----------------------------------------------------------------

    public function test_me_returns_the_user_for_a_valid_token(): void
    {
        $user  = $this->user(['locale' => 'en', 'role' => 'admin']);
        $token = app(SsoService::class)->issue($user);

        $this->getJson('/api/sso/me', ['Authorization' => 'Bearer ' . $token])
            ->assertOk()
            ->assertExactJson(['user' => ['id' => $user->id, 'name' => 'Rama', 'email' => 'rama@example.com', 'role' => 'admin', 'locale' => 'en']]);
    }

    public function test_me_rejects_missing_invalid_expired_and_stale_tokens(): void
    {
        $user  = $this->user();
        $token = app(SsoService::class)->issue($user);

        $this->getJson('/api/sso/me')->assertStatus(401)->assertJsonStructure(['error']);
        $this->getJson('/api/sso/me', ['Authorization' => 'Bearer garbage'])->assertStatus(401);
        $this->getJson('/api/sso/me', ['Authorization' => 'Bearer ' . $token . 'x'])->assertStatus(401);

        $user->forceFill(['sso_version' => 1])->save();
        $this->getJson('/api/sso/me', ['Authorization' => 'Bearer ' . $token, 'Accept-Language' => 'en'])
            ->assertStatus(401);

        $fresh = app(SsoService::class)->issue($user->refresh());
        $this->getJson('/api/sso/me', ['Authorization' => 'Bearer ' . $fresh])->assertOk();

        $this->travel(31)->days();
        $this->getJson('/api/sso/me', ['Authorization' => 'Bearer ' . $fresh, 'Accept-Language' => 'en'])
            ->assertStatus(401)
            ->assertExactJson(['error' => 'Your session is invalid or has expired. Please log in again.']);
    }
}
