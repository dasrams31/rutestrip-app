<?php

namespace Tests\Feature;

use App\Models\HikingRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cookie policy page, the bottom banners and the landing's featured-route filter.
 */
class SiteChromeTest extends TestCase
{
    use RefreshDatabase;

    private function route(string $name, array $coords): HikingRoute
    {
        return HikingRoute::create([
            'name'                   => $name,
            'gpx_file_path'          => 'gpx_files/' . md5($name) . '.gpx',
            'distance_km'            => 8.4,
            'elevation_gain_m'       => 1100,
            'naismith_duration_hour' => 3.5,
            'average_grade_pct'      => 13.1,
            'narrative_text'         => 'Total kenaikan elevasi 1100 meter dengan ketinggian dari 1500m hingga 2600m.',
            'route_coordinates'      => $coords,
        ]);
    }

    public function test_cookie_policy_lists_every_cookie_in_both_languages(): void
    {
        $this->get(route('cookies.policy'))->assertOk()
            ->assertSee('Kebijakan Cookie')
            ->assertSee(config('session.cookie'))
            ->assertSee('XSRF-TOKEN')
            ->assertSee('rs_sso')
            ->assertSee('rs_lang')
            ->assertSee('rs_vid')
            ->assertSee('rs_cookie_ok');

        $this->get(route('cookies.policy', ['lang' => 'en']))->assertOk()->assertSee('Cookie Policy');
    }

    public function test_layout_has_cookie_notice_install_banner_and_publication_but_no_footer_language_switch(): void
    {
        $html = $this->get('/routes')->assertOk()
            ->assertSee('id="rs-cookie"', false)
            ->assertSee('id="rs-install"', false)
            ->assertSee(route('cookies.policy'), false)
            ->assertSee('Download aplikasi')
            ->assertSee('Publikasi')
            ->assertSee('10.32520/stmsi.v15i4.6087')
            ->assertDontSee('Precision@5')
            ->getContent();

        $footer = substr($html, strpos($html, '<footer'), strpos($html, '</footer>') - strpos($html, '<footer'));
        $this->assertStringNotContainsString(route('lang.switch', 'en'), $footer);
        $this->assertStringContainsString(route('lang.switch', 'en'), $html); // still in the navbar

        $this->get(route('login'))->assertSee('data-no-install="1"', false);
    }

    public function test_landing_skips_straight_and_up_and_back_tracks(): void
    {
        $winding = array_map(fn($i) => [-7.30 + 0.0007 * $i, 110.10 - 0.012 * sin($i / 47 * M_PI)], range(0, 47));
        $straight = array_map(fn($i) => [-7.40 + 0.0008 * $i, 110.20 + 0.00002 * $i], range(0, 47));
        $outAndBack = array_merge($winding, array_reverse($winding));

        $this->route('Mt. Lawu via Cetho', $winding);
        $this->route('Mt. Sindoro via Kledung', $straight);
        $this->route('Mt. Sumbing via Butuh', $outAndBack);
        $this->route('Mt. Slamet via Guci', array_slice($winding, 0, 12));

        $this->get('/')->assertOk()
            ->assertSee('Mt. Lawu via Cetho')
            ->assertDontSee('Mt. Sindoro via Kledung')
            ->assertDontSee('Mt. Sumbing via Butuh')
            ->assertDontSee('Mt. Slamet via Guci')
            ->assertDontSee('Contoh dari database');
    }
}
