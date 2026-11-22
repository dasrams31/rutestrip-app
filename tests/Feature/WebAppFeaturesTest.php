<?php

namespace Tests\Feature;

use App\Models\HikingRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Copyright line, links to the Android app site, "Kiriman jalurku" (/akun/kiriman)
 * and the lazily loaded 3D terrain view on the trail page.
 */
class WebAppFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SITE = 'https://app.rutestrip.ai.id';

    private function makeRoute(string $name, string $status, ?User $owner = null, array $extra = []): HikingRoute
    {
        $route = HikingRoute::create(array_merge([
            'name'                   => $name,
            'gpx_file_path'          => 'gpx_files/' . \Illuminate\Support\Str::slug($name) . '.gpx',
            'distance_km'            => 8.4,
            'elevation_gain_m'       => 1300,
            'naismith_duration_hour' => 4.8,
            'average_grade_pct'      => 15.5,
            'narrative_text'         => 'Jalur uji.',
            'route_coordinates'      => [[-7.45, 110.43, 1811.0], [-7.44, 110.435, 2400.0], [-7.43, 110.44, 3128.0]],
            'uploaded_by'            => $owner?->id,
            'source'                 => 'recording',
            'review_status'          => $status,
        ], $extra));

        return $route;
    }

    public function test_footer_shows_the_copyright(): void
    {
        $this->get('/routes')->assertOk()
            ->assertSee('© 2026 Aeternum Kreasikan Bersama')
            ->assertSee('OpenStreetMap contributors');
    }

    public function test_settings_page_shows_the_copyright_and_links_to_submissions(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('settings'))->assertOk()
            ->assertSee('href="' . route('account.submissions') . '"', false)
            ->getContent();

        // Inside the page itself, not only in the layout footer.
        $main = substr($html, strpos($html, 'id="konten"'), strpos($html, '<footer') - strpos($html, 'id="konten"'));
        $this->assertStringContainsString('© 2026 Aeternum Kreasikan Bersama', $main);
    }

    public function test_navbar_and_landing_link_to_the_app_site(): void
    {
        $html = $this->get('/routes')->assertOk()->getContent();
        $nav = substr($html, strpos($html, '<nav'), strpos($html, '</nav>') - strpos($html, '<nav'));
        // Desktop row and mobile menu.
        $this->assertSame(2, substr_count($nav, 'href="' . self::APP_SITE . '" rel="noopener" data-nav-app'));
        $this->assertStringNotContainsString('target="_blank" data-nav-app', $nav);

        $this->get('/')->assertOk()
            ->assertSee('href="' . self::APP_SITE . '" rel="noopener" data-app-site', false)
            ->assertSee('Lihat semua fitur aplikasi');
    }

    public function test_submissions_page_requires_login(): void
    {
        $this->get('/akun/kiriman')->assertRedirect(route('login'));
        $this->assertSame(url('/akun/kiriman'), route('account.submissions'));
    }

    public function test_submissions_lists_only_own_items_with_their_status(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $this->makeRoute('Rekaman Lawu Cetho', HikingRoute::PENDING, $me);
        $rejected = $this->makeRoute('Rekaman Sindoro Kledung', HikingRoute::REJECTED, $me);
        $rejected->forceFill(['review_note' => 'Jejak terputus di pos 3.'])->save();
        $published = $this->makeRoute('Rekaman Prau Patak Banteng', HikingRoute::PUBLISHED, $me);
        $this->makeRoute('Rekaman Orang Lain', HikingRoute::PENDING, $other);

        $response = $this->actingAs($me)->get('/akun/kiriman')->assertOk()
            ->assertSee('Kiriman jalurku')
            ->assertSee('Rekaman Lawu Cetho')
            ->assertSee('Rekaman Sindoro Kledung')
            ->assertSee('Rekaman Prau Patak Banteng')
            ->assertDontSee('Rekaman Orang Lain')
            ->assertSee('Menunggu admin')
            ->assertSee('Ditolak')
            ->assertSee('Tampil')
            ->assertSee('Jejak terputus di pos 3.')
            ->assertSee('Rekaman aplikasi')
            ->assertSee('href="' . route('routes.show', $published) . '"', false);

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'data-submission="pending"'));
        $this->assertSame(1, substr_count($html, 'data-submission="rejected"'));
        $this->assertSame(1, substr_count($html, 'data-submission="published"'));
        // Only the published one links to a trail page (the others would 404).
        $this->assertStringNotContainsString('href="' . route('routes.show', $rejected) . '"', $html);
    }

    public function test_submissions_empty_state(): void
    {
        $this->actingAs(User::factory()->create())->get('/akun/kiriman')->assertOk()
            ->assertSee('Belum ada kiriman')
            ->assertSee('href="' . route('routes.create') . '"', false);
    }

    public function test_dashboard_links_to_submissions_and_lists_app_only_features(): void
    {
        $this->actingAs(User::factory()->create())->get(route('user.dashboard'))->assertOk()
            ->assertSee('href="' . route('account.submissions') . '"', false)
            ->assertSee('data-app-only', false)
            ->assertSee('Di aplikasi Android')
            ->assertSee('href="' . self::APP_SITE . '"', false);
    }

    public function test_trail_page_offers_the_3d_view_without_loading_maplibre(): void
    {
        $route = $this->makeRoute('Gunung Uji via Tiga Titik', HikingRoute::PUBLISHED, null, ['source' => 'upload']);

        $html = $this->get(route('routes.show', $route))->assertOk()
            ->assertSee('data-terrain-open', false)
            ->assertSee('Lihat medan 3D')
            ->assertSee('id="terrain-3d"', false)
            ->assertSee('/vendor/maplibre/maplibre-gl.js', false)
            ->assertSee('elevation-tiles-prod/terrarium', false)
            ->getContent();

        // The library is only referenced from the click handler, never as a tag on page load.
        $this->assertStringNotContainsString('<script src="/vendor/maplibre', $html);
        $this->assertStringNotContainsString('<link rel="stylesheet" href="/vendor/maplibre', $html);
        $this->assertFileExists(public_path('vendor/maplibre/maplibre-gl.js'));
        $this->assertFileExists(public_path('vendor/maplibre/maplibre-gl.css'));
        $this->assertFileExists(public_path('vendor/maplibre/LICENSE.txt'));
    }

    public function test_english_strings_exist_for_the_new_features(): void
    {
        $en = json_decode(file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach (['Kiriman jalurku', 'Lihat medan 3D', 'Putar', 'Menunggu admin', 'Tampil', 'Ditolak', 'Lihat semua fitur aplikasi', 'Di aplikasi Android'] as $key) {
            $this->assertArrayHasKey($key, $en, $key);
        }
    }
}
