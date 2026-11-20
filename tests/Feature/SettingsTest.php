<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SsoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** GET/POST /settings: profile, password (bumps sso_version) and language (docs/SSO.md). */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sso.secret' => 'test-sso-secret', 'services.sso.cookie_domain' => '.rutestrip.test']);

        $this->user  = User::factory()->create(['name' => 'Rama', 'email' => 'rama@example.com', 'password' => 'rahasia123', 'role' => 'user'])->refresh();
        $this->token = app(SsoService::class)->issue($this->user);
    }

    /** Request as the logged-in browser: session plus its rs_sso cookie. */
    private function browser()
    {
        return $this->actingAs($this->user)->withUnencryptedCookie(SsoService::COOKIE, $this->token);
    }

    private function newToken($response): ?string
    {
        return $response->getCookie(SsoService::COOKIE, false)?->getValue();
    }

    public function test_settings_requires_login(): void
    {
        $this->get('/settings')->assertRedirect(route('login'));
        $this->post('/settings', ['section' => 'profile'])->assertRedirect(route('login'));
    }

    public function test_settings_page_renders_all_sections(): void
    {
        $this->browser()->get(route('settings'))
            ->assertOk()
            ->assertSee('value="Rama"', false)
            ->assertSee('value="rama@example.com"', false)
            ->assertSee('name="current_password"', false)
            ->assertSee('name="locale"', false)
            ->assertSee(__('account.settings.title'));
    }

    public function test_old_profile_page_redirects_to_settings(): void
    {
        $this->browser()->get(route('user.profile'))->assertRedirect(route('settings'))->assertStatus(301);
    }

    public function test_profile_update_changes_name_and_email_and_reissues_token(): void
    {
        $response = $this->browser()->post(route('settings.update'), [
            'section' => 'profile', 'name' => 'Rama Baru', 'email' => 'baru@example.com',
        ])->assertRedirect(route('settings'))->assertSessionHas('success');

        $this->assertDatabaseHas('users', ['id' => $this->user->id, 'name' => 'Rama Baru', 'email' => 'baru@example.com']);
        $claims = app(SsoService::class)->decode($this->newToken($response));
        $this->assertSame('baru@example.com', $claims['email']);
        $this->assertSame('Rama Baru', $claims['name']);
    }

    public function test_profile_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->browser()->from(route('settings'))->post(route('settings.update'), [
            'section' => 'profile', 'name' => 'Rama', 'email' => 'taken@example.com',
        ])->assertRedirect(route('settings'))->assertSessionHasErrors('email');

        // Keeping your own email is fine.
        $this->browser()->post(route('settings.update'), [
            'section' => 'profile', 'name' => 'Rama', 'email' => 'rama@example.com',
        ])->assertSessionHasNoErrors();
    }

    public function test_password_change_requires_correct_current_password(): void
    {
        $this->browser()->from(route('settings'))->post(route('settings.update'), [
            'section' => 'password', 'current_password' => 'salah-sekali',
            'password' => 'passwordbaru1', 'password_confirmation' => 'passwordbaru1',
        ])->assertSessionHasErrors('current_password');

        $this->browser()->from(route('settings'))->post(route('settings.update'), [
            'section' => 'password', 'current_password' => 'rahasia123',
            'password' => 'pendek', 'password_confirmation' => 'pendek',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('rahasia123', $this->user->fresh()->password));
        $this->assertSame(0, $this->user->fresh()->sso_version);
    }

    public function test_password_change_bumps_sso_version_and_kills_old_tokens(): void
    {
        $response = $this->browser()->post(route('settings.update'), [
            'section' => 'password', 'current_password' => 'rahasia123',
            'password' => 'passwordbaru1', 'password_confirmation' => 'passwordbaru1',
        ])->assertRedirect(route('settings'))->assertSessionHasNoErrors();

        $user = $this->user->fresh();
        $this->assertTrue(Hash::check('passwordbaru1', $user->password));
        $this->assertSame(1, $user->sso_version);

        // Other devices and the chat: old token no longer accepted.
        $this->getJson('/api/sso/me', ['Authorization' => 'Bearer ' . $this->token])->assertStatus(401);

        // This browser got a new token and stays logged in with it.
        $newToken = $this->newToken($response);
        $this->assertSame(1, app(SsoService::class)->decode($newToken)['sv']);
        $this->getJson('/api/sso/me', ['Authorization' => 'Bearer ' . $newToken])->assertOk();

        $this->withUnencryptedCookie(SsoService::COOKIE, $newToken)->get(route('settings'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_old_password_route_also_bumps_sso_version(): void
    {
        $this->browser()->put(route('user.password.update'), [
            'current_password' => 'rahasia123', 'password' => 'passwordbaru1', 'password_confirmation' => 'passwordbaru1',
        ])->assertRedirect(route('settings'));

        $this->assertSame(1, $this->user->fresh()->sso_version);
    }

    public function test_locale_update_sets_user_locale_and_lang_cookie(): void
    {
        $response = $this->browser()->post(route('settings.update'), ['section' => 'locale', 'locale' => 'en'])
            ->assertRedirect(route('settings'))
            ->assertSessionHas('success', 'Language updated.');

        $this->assertSame('en', $this->user->fresh()->locale);

        $cookie = $response->getCookie(SsoService::LANG_COOKIE, false);
        $this->assertSame('en', $cookie->getValue());
        $this->assertSame('.rutestrip.test', $cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
        $this->assertFalse($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertEqualsWithDelta(365 * 86400, $cookie->getMaxAge(), 86400 + 5);

        $this->assertSame('en', app(SsoService::class)->decode($this->newToken($response))['locale']);
    }

    public function test_locale_must_be_supported(): void
    {
        $this->browser()->from(route('settings'))->post(route('settings.update'), ['section' => 'locale', 'locale' => 'fr'])
            ->assertSessionHasErrors('locale');

        $this->assertSame('id', $this->user->fresh()->locale);
    }
}
