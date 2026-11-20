<?php

namespace Tests\Feature;

use App\Http\Middleware\SsoBridge;
use App\Models\User;
use App\Services\SsoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/** Token format, cookies on login/register/logout and the SsoBridge middleware (docs/SSO.md). */
class SsoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.sso.secret'        => 'test-sso-secret',
            'services.sso.cookie_domain' => '.rutestrip.test',
            'services.sso.cookie_secure' => true,
        ]);
    }

    private function sso(): SsoService
    {
        return app(SsoService::class);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => 'user', 'password' => 'rahasia123'], $attributes))->refresh();
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function sign(array $header, array $claims, string $secret = 'test-sso-secret'): string
    {
        $h = $this->b64(json_encode($header));
        $p = $this->b64(json_encode($claims));

        return $h . '.' . $p . '.' . $this->b64(hash_hmac('sha256', "$h.$p", $secret, true));
    }

    private function ssoCookie(TestResponse $response): ?Cookie
    {
        return $response->getCookie(SsoService::COOKIE, false);
    }

    // --- token -----------------------------------------------------------

    public function test_issued_token_has_contract_claims_and_verifies(): void
    {
        $user  = $this->user(['name' => 'Rama', 'email' => 'rama@example.com']);
        $token = $this->sso()->issue($user);

        [$header] = explode('.', $token);
        $this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], json_decode(base64_decode(strtr($header, '-_', '+/')), true));

        $claims = $this->sso()->decode($token);
        $this->assertSame('rutestrip.ai.id', $claims['iss']);
        $this->assertSame((string) $user->id, $claims['sub']);
        $this->assertSame('Rama', $claims['name']);
        $this->assertSame('rama@example.com', $claims['email']);
        $this->assertSame('user', $claims['role']);
        $this->assertSame('id', $claims['locale']);
        $this->assertSame(0, $claims['sv']);
        $this->assertSame(30 * 86400, $claims['exp'] - $claims['iat']);
        $this->assertTrue($this->sso()->user($token)->is($user));
    }

    public function test_token_signed_like_a_standard_jwt_library_is_accepted(): void
    {
        // What webchat-api produces/expects: plain HS256 over base64url(header).base64url(payload).
        $user  = $this->user();
        $token = $this->sign(['typ' => 'JWT', 'alg' => 'HS256'], [
            'iss' => 'rutestrip.ai.id', 'sub' => (string) $user->id, 'sv' => 0,
            'iat' => time(), 'exp' => time() + 60,
        ]);

        $this->assertTrue($this->sso()->user($token)?->is($user));
    }

    public function test_tampered_token_is_rejected(): void
    {
        $user  = $this->user();
        $other = $this->user();
        [$h, $p, $s] = explode('.', $this->sso()->issue($user));

        $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        $claims['sub'] = (string) $other->id;

        $this->assertNull($this->sso()->decode($h . '.' . $this->b64(json_encode($claims)) . '.' . $s));
        $this->assertNull($this->sso()->decode($h . '.' . $p . '.' . $this->b64('x')));
        $this->assertNull($this->sso()->decode('not-a-token'));
        $this->assertNull($this->sso()->decode(''));
    }

    public function test_token_signed_with_other_secret_is_rejected(): void
    {
        $user = $this->user();
        config(['services.sso.secret' => 'another-secret']);
        $token = $this->sso()->issue($user);
        config(['services.sso.secret' => 'test-sso-secret']);

        $this->assertNull($this->sso()->user($token));
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = $this->sso()->issue($this->user());

        $this->travel(31)->days();

        $this->assertNull($this->sso()->decode($token));
    }

    public function test_wrong_algorithm_is_rejected(): void
    {
        $user   = $this->user();
        $claims = ['iss' => 'rutestrip.ai.id', 'sub' => (string) $user->id, 'sv' => 0, 'iat' => time(), 'exp' => time() + 600];

        $none = $this->b64(json_encode(['alg' => 'none', 'typ' => 'JWT'])) . '.' . $this->b64(json_encode($claims)) . '.';
        $this->assertNull($this->sso()->decode($none));
        $this->assertNull($this->sso()->decode($this->sign(['alg' => 'HS512', 'typ' => 'JWT'], $claims)));
        $this->assertNull($this->sso()->decode($this->sign(['alg' => 'none', 'typ' => 'JWT'], $claims)));
    }

    public function test_stale_sso_version_and_deleted_user_are_rejected(): void
    {
        $user  = $this->user();
        $token = $this->sso()->issue($user);

        $user->forceFill(['sso_version' => 1])->save();
        $this->assertNotNull($this->sso()->decode($token), 'signature is still fine');
        $this->assertNull($this->sso()->user($token));

        $fresh = $this->sso()->issue($user->refresh());
        $user->delete();
        $this->assertNull($this->sso()->user($fresh));
    }

    public function test_fallback_secret_is_derived_from_app_key(): void
    {
        config(['services.sso.secret' => null]);
        $user  = $this->user();
        $token = $this->sso()->issue($user);

        $this->assertTrue($this->sso()->user($token)->is($user));
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        $this->assertNull($this->sso()->user($token));
    }

    // --- cookies on login / register / logout ----------------------------

    private function assertSsoCookieAttributes(?Cookie $cookie, User $user): void
    {
        $this->assertNotNull($cookie, 'rs_sso cookie should be set');
        $this->assertSame('.rutestrip.test', $cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertEqualsWithDelta(30 * 86400, $cookie->getExpiresTime() - now()->getTimestamp(), 5);
        $this->assertTrue($this->sso()->user($cookie->getValue())->is($user), 'cookie holds a valid, unencrypted token');
    }

    public function test_web_login_sets_sso_cookie(): void
    {
        $user = $this->user(['email' => 'rama@example.com']);

        $response = $this->post(route('login.submit'), ['email' => 'rama@example.com', 'password' => 'rahasia123'])
            ->assertRedirect(route('user.dashboard'));

        $this->assertSsoCookieAttributes($this->ssoCookie($response), $user);
    }

    public function test_failed_login_sets_no_cookie(): void
    {
        $this->user(['email' => 'rama@example.com']);

        $response = $this->from(route('login'))
            ->post(route('login.submit'), ['email' => 'rama@example.com', 'password' => 'salah-sekali'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertNull($this->ssoCookie($response));
        $this->assertGuest();
    }

    public function test_admin_login_sets_sso_cookie_and_non_admin_gets_none(): void
    {
        $admin = $this->user(['email' => 'admin@example.com', 'role' => 'admin']);
        $this->user(['email' => 'user@example.com']);

        $response = $this->from(route('admin.login'))
            ->post(route('admin.login.submit'), ['email' => 'user@example.com', 'password' => 'rahasia123'])
            ->assertRedirect(route('admin.login'));
        $this->assertNull($this->ssoCookie($response));
        $this->assertGuest();

        $response = $this->post(route('admin.login.submit'), ['email' => 'admin@example.com', 'password' => 'rahasia123'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertSsoCookieAttributes($this->ssoCookie($response), $admin);
    }

    private function solvedAltcha(): string
    {
        config(['services.altcha.max_number' => 50]);
        $challenge = $this->getJson(route('altcha.challenge'))->json();
        $number    = collect(range(0, 50))->first(fn ($n) => hash('sha256', $challenge['salt'] . $n) === $challenge['challenge']);

        return base64_encode(json_encode([
            'algorithm' => 'SHA-256', 'challenge' => $challenge['challenge'], 'number' => $number,
            'salt' => $challenge['salt'], 'signature' => $challenge['signature'],
        ]));
    }

    public function test_register_sets_sso_cookie(): void
    {
        $response = $this->post(route('register.submit'), [
            'name' => 'Pendaki', 'email' => 'pendaki@example.com',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123', 'altcha' => $this->solvedAltcha(),
        ])->assertRedirect(route('user.dashboard'));

        $this->assertSsoCookieAttributes($this->ssoCookie($response), User::where('email', 'pendaki@example.com')->first());
    }

    public function test_register_requires_eight_character_password(): void
    {
        $response = $this->from(route('register'))->post(route('register.submit'), [
            'name' => 'Pendaki', 'email' => 'pendaki@example.com',
            'password' => 'pendek1', 'password_confirmation' => 'pendek1', 'altcha' => $this->solvedAltcha(),
        ])->assertRedirect(route('register'))->assertSessionHasErrors('password');

        $this->assertNull($this->ssoCookie($response));
        $this->assertGuest();
    }

    public function test_logout_deletes_sso_cookie_with_same_domain_and_path(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)
            ->withUnencryptedCookie(SsoService::COOKIE, $this->sso()->issue($user))
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $cookie = $this->ssoCookie($response);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isCleared());
        $this->assertSame('.rutestrip.test', $cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
        $this->assertGuest();
    }

    // --- SsoBridge ---------------------------------------------------------

    public function test_guest_with_valid_cookie_is_logged_in(): void
    {
        $user = $this->user();

        $this->withUnencryptedCookie(SsoService::COOKIE, $this->sso()->issue($user))
            ->get(route('user.dashboard'))
            ->assertOk();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(session(SsoBridge::BOUND));
    }

    public function test_guest_with_valid_cookie_visiting_login_is_sent_to_dashboard(): void
    {
        $user = $this->user();

        $this->withUnencryptedCookie(SsoService::COOKIE, $this->sso()->issue($user))
            ->get(route('login'))
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_guest_with_invalid_cookie_stays_guest_and_cookie_is_cleared(): void
    {
        $user  = $this->user();
        $token = $this->sso()->issue($user);
        $user->forceFill(['sso_version' => 1])->save();

        $response = $this->withUnencryptedCookie(SsoService::COOKIE, $token)
            ->get(route('user.dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertTrue($this->ssoCookie($response)->isCleared());
    }

    public function test_bound_session_is_logged_out_when_cookie_disappears(): void
    {
        $user = $this->user(['email' => 'rama@example.com']);

        $login = $this->post(route('login.submit'), ['email' => 'rama@example.com', 'password' => 'rahasia123']);
        $token = $this->ssoCookie($login)->getValue();

        // The browser sends the cookie back: the session becomes bound to it.
        $this->withUnencryptedCookie(SsoService::COOKIE, $token)->get(route('user.dashboard'))->assertOk();
        $this->assertTrue(session(SsoBridge::BOUND));

        // Logged out on the chat: cookie gone.
        $this->unencryptedCookies = [];
        $this->get(route('user.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_session_is_logged_out_when_cookie_is_for_a_stale_password(): void
    {
        $user  = $this->user();
        $token = $this->sso()->issue($user);
        $this->withUnencryptedCookie(SsoService::COOKIE, $token)->get(route('user.dashboard'))->assertOk();

        $user->forceFill(['sso_version' => 1])->save();

        $this->withUnencryptedCookie(SsoService::COOKIE, $token)->get(route('user.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_session_switches_to_the_user_of_the_cookie(): void
    {
        $first  = $this->user();
        $second = $this->user();

        $this->actingAs($first)
            ->withUnencryptedCookie(SsoService::COOKIE, $this->sso()->issue($second))
            ->get(route('user.dashboard'))
            ->assertOk();

        $this->assertAuthenticatedAs($second);
    }

    public function test_unbound_session_without_cookie_gets_a_fresh_cookie_instead_of_logout(): void
    {
        // Sessions from before the deploy (and actingAs in tests) have no cookie yet.
        $user = $this->user();

        $first = $this->actingAs($user)->get(route('user.dashboard'))->assertOk();
        $this->assertSsoCookieAttributes($this->ssoCookie($first), $user);

        $this->get(route('user.dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertNull(session(SsoBridge::BOUND));
    }

    public function test_old_token_is_refreshed_after_half_its_lifetime(): void
    {
        $user  = $this->user();
        $token = $this->sso()->issue($user);

        $this->travel(16)->days();

        $response = $this->withUnencryptedCookie(SsoService::COOKIE, $token)->get(route('user.dashboard'))->assertOk();
        $this->assertNotSame($token, $this->ssoCookie($response)?->getValue());
        $this->assertSsoCookieAttributes($this->ssoCookie($response), $user);
    }

    public function test_logout_and_login_requests_are_not_touched_by_the_bridge(): void
    {
        $user = $this->user(['email' => 'rama@example.com']);
        $other = $this->user();

        // A valid cookie for another account must not hijack an explicit login.
        $this->withUnencryptedCookie(SsoService::COOKIE, $this->sso()->issue($other))
            ->post(route('login.submit'), ['email' => 'rama@example.com', 'password' => 'rahasia123'])
            ->assertRedirect(route('user.dashboard'));

        $this->assertAuthenticatedAs($user);
    }
}
