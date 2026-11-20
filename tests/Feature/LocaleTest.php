<?php

namespace Tests\Feature;

use App\Models\HikingRoute;
use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // rs_lang is shared with the chat and stays unencrypted (docs/SSO.md).
        EncryptCookies::except(['rs_lang']);
    }

    private function seedRoute(): HikingRoute
    {
        return HikingRoute::create([
            'name'                   => 'Mt. Merbabu via Selo',
            'gpx_file_path'          => 'gpx_files/selo.gpx',
            'distance_km'            => 11.1,
            'elevation_gain_m'       => 1424,
            'naismith_duration_hour' => 5.9,
            'average_grade_pct'      => 12.8,
            'narrative_text'         => 'Total kenaikan elevasi 1424 meter dengan ketinggian dari 1811m hingga 3128m.',
            // A winding 48-point track: the landing only features tracks that do not
            // draw as a straight line or an up-and-back line (SearchController).
            'route_coordinates'      => array_map(
                fn($i) => [round(-7.4884 + 0.0007 * $i, 5), round(110.4475 - 0.012 * sin($i / 47 * M_PI), 5)],
                range(0, 47)
            ),
        ]);
    }

    public function test_indonesian_is_the_default(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<html lang="id"', false)
            ->assertSee('Cari Rute')
            ->assertSee('<meta property="og:locale" content="id_ID">', false)
            ->assertSee('hreflang="en" href="' . url('/') . '?lang=en"', false);
    }

    public function test_lang_query_switches_to_english_and_sets_the_shared_cookie(): void
    {
        $response = $this->get('/?lang=en');

        $response->assertOk()
            ->assertSee('<html lang="en"', false)
            ->assertSee('Find Routes')
            ->assertSee('<meta property="og:locale" content="en_US">', false)
            ->assertPlainCookie('rs_lang', 'en');

        $cookie = $response->getCookie('rs_lang', false);
        $this->assertFalse($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertGreaterThan(now()->addDays(360)->getTimestamp(), $cookie->getExpiresTime());
    }

    public function test_rs_lang_cookie_is_used_and_query_wins_over_it(): void
    {
        $this->withUnencryptedCookie('rs_lang', 'en')->get('/routes')->assertSee('<html lang="en"', false)->assertSee('All Hiking Routes');
        $this->withUnencryptedCookie('rs_lang', 'en')->get('/routes?lang=id')->assertSee('<html lang="id"', false);
        $this->withUnencryptedCookie('rs_lang', 'xx')->get('/routes')->assertSee('<html lang="id"', false);
    }

    public function test_user_locale_is_used_when_there_is_no_cookie(): void
    {
        if (! Schema::hasColumn('users', 'locale')) {
            $this->markTestSkipped('users.locale is added by the SSO migration.');
        }

        $user = User::factory()->create();
        $user->forceFill(['locale' => 'en'])->save();

        $this->actingAs($user)->get('/routes')->assertSee('<html lang="en"', false);
    }

    public function test_lang_route_sets_cookie_and_redirects_back_without_lang_param(): void
    {
        $route = $this->seedRoute();
        $back  = route('routes.show', $route);

        $this->get('/lang/en', ['referer' => $back . '?lang=id&tab=1'])
            ->assertRedirect($back . '?tab=1')
            ->assertPlainCookie('rs_lang', 'en');

        $this->get('/lang/id', ['referer' => 'https://evil.example/phish'])->assertRedirect(url('/'));
        $this->get('/lang/de')->assertNotFound();
    }

    public function test_lang_route_saves_the_logged_in_users_locale(): void
    {
        if (! Schema::hasColumn('users', 'locale')) {
            $this->markTestSkipped('users.locale is added by the SSO migration.');
        }

        $user = User::factory()->create();
        $this->actingAs($user)->get('/lang/en')->assertRedirect();

        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_landing_page_shows_stats_featured_routes_and_research_in_both_languages(): void
    {
        $this->seedRoute();

        $this->get('/')->assertOk()
            ->assertSee('jalur dengan data GPX')
            ->assertSee('Mt. Merbabu via Selo')
            ->assertSee('RuteStrip AI Chat')
            ->assertSee('10.32520/stmsi.v15i4.6087')
            ->assertSee(config('services.webchat.url'), false);

        $this->get('/?lang=en')->assertOk()
            ->assertSee('trails with GPX data')
            ->assertSee('Publication')
            ->assertSee('Mt. Merbabu via Selo');
    }

    public function test_nav_has_settings_link_language_switch_and_mobile_tab_bar(): void
    {
        $this->get('/routes')->assertOk()
            ->assertSee(route('lang.switch', 'en'), false)
            ->assertSee('aria-label="Navigasi utama"', false)
            ->assertSee('/manifest.webmanifest', false);

        if (! \Illuminate\Support\Facades\Route::has('settings')) {
            $this->markTestSkipped('settings route is added by the SSO work.');
        }

        $this->actingAs(User::factory()->create())->get('/routes?lang=en')->assertOk()
            ->assertSee('href="' . route('settings') . '"', false)
            ->assertSee('Settings')
            ->assertSee('Account');
    }

    public function test_route_list_card_shows_mountain_info_instead_of_gpx_hash_box(): void
    {
        $this->seedRoute();

        $this->get('/routes')->assertOk()
            ->assertSee('3.128')
            ->assertSee('Basecamp Selo')
            ->assertSee('Tanjakan panjang dan curam, untuk pendaki berpengalaman.')
            ->assertDontSee('cocok dengan hash');
        $this->get('/routes?lang=en')->assertSee('Long, steep climbs, for experienced hikers.')->assertSee('Hard');
    }
}
