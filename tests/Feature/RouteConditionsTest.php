<?php

namespace Tests\Feature;

use App\Models\HikingRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RouteConditionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function route(array $attributes = []): HikingRoute
    {
        return HikingRoute::create($attributes + [
            'name'              => 'Mt. Merbabu via Selo',
            'gpx_file_path'     => 'gpx_files/selo.gpx',
            'distance_km'       => 11.1,
            'elevation_gain_m'  => 1424,
            'naismith_duration_hour' => 5.9,
            'average_grade_pct' => 12.8,
            'narrative_text'    => 'Total kenaikan elevasi 1424 meter dengan ketinggian dari 1811m hingga 3128m.',
            // Trailhead -> summit -> a bit down the other side (summit is not the last point).
            'route_coordinates' => [[-7.4884, 110.4475], [-7.47, 110.444], [-7.4545, 110.44], [-7.452, 110.438]],
        ]);
    }

    private function fakeApis(): void
    {
        Http::fake([
            'api.open-meteo.com/v1/elevation*' => Http::response(['elevation' => [1820.0, 2500.0, 3123.0, 3050.0]]),
            'api.open-meteo.com/v1/forecast*'  => Http::response([
                $this->forecast(['temperature_2m' => 17.4, 'apparent_temperature' => 16.2, 'relative_humidity_2m' => 88, 'precipitation' => 0, 'weather_code' => 2, 'wind_speed_10m' => 8.3, 'wind_direction_10m' => 135, 'wind_gusts_10m' => 15.1]),
                $this->forecast(['temperature_2m' => 6.1, 'apparent_temperature' => 1.4, 'relative_humidity_2m' => 95, 'precipitation' => 0, 'weather_code' => 45, 'wind_speed_10m' => 34.6, 'wind_direction_10m' => 290, 'wind_gusts_10m' => 52.0]),
            ]),
            'nominatim.openstreetmap.org/*' => Http::response([
                'display_name' => 'Ngaglik, Samiran, Selo, Boyolali, Jawa Tengah, Indonesia',
                'address'      => ['hamlet' => 'Ngaglik', 'village' => 'Samiran', 'town' => 'Selo', 'county' => 'Boyolali', 'state' => 'Jawa Tengah', 'country' => 'Indonesia'],
            ]),
        ]);
    }

    private function forecast(array $current): array
    {
        return [
            'elevation' => 1800,
            'current'   => $current + ['time' => '2026-11-21T08:00', 'is_day' => 1],
            'daily'     => ['sunrise' => ['2026-11-21T05:12'], 'sunset' => ['2026-11-21T17:41']],
        ];
    }

    public function test_conditions_endpoint_returns_points_location_weather_and_safety(): void
    {
        $this->fakeApis();
        $route = $this->route();

        $response = $this->getJson(route('routes.conditions', $route))->assertOk();

        $response->assertJsonPath('points.basecamp.name', 'Basecamp Selo')
            ->assertJsonPath('points.basecamp.lat', -7.4884)
            ->assertJsonPath('points.basecamp.elevation_m', 1820)
            ->assertJsonPath('points.summit.lat', -7.4545)          // highest DEM point, not the last one
            ->assertJsonPath('points.summit.elevation_m', 3128)     // GPX maximum from the narrative
            ->assertJsonPath('points.elevation_source', 'dem')
            // Elevation profile: [km, m] per sampled point, last km scaled to the route distance.
            ->assertJsonCount(4, 'points.profile')
            ->assertJsonPath('points.profile.0', [0, 1820])
            ->assertJsonPath('points.profile.3.0', 11.1)
            ->assertJsonPath('points.profile.2.1', 3123)
            ->assertJsonPath('location.village', 'Samiran')
            ->assertJsonPath('location.district', 'Selo')
            ->assertJsonPath('location.regency', 'Boyolali')
            ->assertJsonPath('location.province', 'Jawa Tengah')
            ->assertJsonPath('weather.basecamp.temperature_c', 17.4)
            ->assertJsonPath('weather.basecamp.weather_text', 'Berawan sebagian')
            ->assertJsonPath('weather.basecamp.wind_direction', 'Tenggara')
            ->assertJsonPath('weather.summit.apparent_temperature_c', 1.4)
            ->assertJsonPath('weather.summit.weather_text', 'Berkabut')
            ->assertJsonPath('weather.summit.wind_direction', 'Barat')
            ->assertJsonPath('weather.sunrise', '05:12')
            ->assertJsonPath('weather.sunset', '17:41')
            ->assertJsonPath('safety.level', 'caution')
            ->assertJsonPath('sources.weather', 'Open-Meteo');

        $this->assertMatchesRegularExpression('/^\d{2}:\d{2} WIB$/', $response->json('weather.updated_label'));
        $messages = implode(' ', $response->json('safety.messages'));
        $this->assertStringContainsString('Angin kencang', $messages);
        $this->assertStringContainsString('sangat dingin', $messages);
        $this->assertStringContainsString('Kabut', $messages);

        Http::assertSent(fn(Request $r) => str_contains($r->url(), 'nominatim.openstreetmap.org/reverse')
            && $r->hasHeader('User-Agent', 'RuteStrip/1.0 (+https://rutestrip.ai.id)')
            && $r['accept-language'] === 'id');
        Http::assertSent(fn(Request $r) => str_contains($r->url(), '/v1/forecast')
            && $r['elevation'] === '1820,3128'
            && $r['timezone'] === 'Asia/Jakarta'
            && $r['latitude'] === '-7.4884,-7.4545');
    }

    public function test_results_are_cached_between_requests(): void
    {
        $this->fakeApis();
        $route = $this->route();

        $this->getJson(route('routes.conditions', $route))->assertOk();
        $this->getJson(route('routes.conditions', $route))->assertOk()->assertJsonPath('location.regency', 'Boyolali');

        Http::assertSentCount(3); // elevation + nominatim + forecast, once each

        // Weather expires after 15 minutes; elevations and location are kept forever.
        $this->travel(16)->minutes();
        $this->getJson(route('routes.conditions', $route))->assertOk();

        Http::assertSentCount(4);
        Http::assertSent(fn(Request $r) => str_contains($r->url(), '/v1/forecast'));
    }

    public function test_failures_fall_back_gracefully(): void
    {
        Http::fake([
            'api.open-meteo.com/*'          => Http::response('upstream error', 500),
            'nominatim.openstreetmap.org/*' => Http::failedConnection('timeout'),
        ]);
        $route = $this->route();

        $this->getJson(route('routes.conditions', $route))
            ->assertOk()
            ->assertJsonPath('weather', null)
            ->assertJsonPath('safety', null)
            ->assertJsonPath('location', null)
            // Without the elevation model: first point + narrative heights, last point as summit.
            ->assertJsonPath('points.basecamp.lat', -7.4884)
            ->assertJsonPath('points.basecamp.elevation_m', 1811)
            ->assertJsonPath('points.summit.lat', -7.452)
            ->assertJsonPath('points.summit.elevation_m', 3128)
            ->assertJsonPath('points.elevation_source', 'narrative');

        // Failures are not retried on every request.
        $sent = count(Http::recorded());
        $this->getJson(route('routes.conditions', $route))->assertOk();
        $this->assertCount($sent, Http::recorded());
    }

    public function test_route_without_track_returns_empty_conditions_without_calling_apis(): void
    {
        Http::fake();
        $route = $this->route(['route_coordinates' => null]);

        $this->getJson(route('routes.conditions', $route))
            ->assertOk()
            ->assertJsonPath('points', null)
            ->assertJsonPath('weather', null);

        Http::assertNothingSent();
    }

    public function test_track_elevations_are_used_without_the_elevation_model(): void
    {
        Http::fake([
            'api.open-meteo.com/v1/forecast*' => Http::response([$this->forecast(['temperature_2m' => 20]), $this->forecast(['temperature_2m' => 9])]),
            'nominatim.openstreetmap.org/*'   => Http::response(['address' => ['village' => 'Samiran', 'state' => 'Jawa Tengah']]),
        ]);
        $route = $this->route(['route_coordinates' => [[-7.49, 110.45, 1830.4], [-7.455, 110.44, 3141.6], [-7.46, 110.43, 3000]]]);

        $this->getJson(route('routes.conditions', $route))
            ->assertOk()
            ->assertJsonPath('points.basecamp.elevation_m', 1830)
            ->assertJsonPath('points.summit.lat', -7.455)
            ->assertJsonPath('points.summit.elevation_m', 3142)
            ->assertJsonPath('safety.level', 'ok');

        Http::assertNotSent(fn(Request $r) => str_contains($r->url(), '/v1/elevation'));
    }

    public function test_basecamp_name_is_derived_from_the_route_name(): void
    {
        $service = app(\App\Services\RouteConditionsService::class);
        $name    = fn(string $n, ?string $manual = null) => $service->basecampName(new HikingRoute(['name' => $n, 'basecamp_name' => $manual]));

        $this->assertSame('Basecamp Butuh Kaliangkrik', $name('Mt. Sumbing via Butuh Kaliangkrik', 'Basecamp Garung'));
        $this->assertSame('Basecamp Baderan', $name('Mt. Argopuro Baderan to Bremi', 'Basecamp Bremi'));
        $this->assertSame('Basecamp Ranu Pani', $name('Mt. Semeru', 'Basecamp Ranu Pani'));
        $this->assertNull($name('Gunung Andong'));
    }

    public function test_conditions_endpoint_is_throttled(): void
    {
        $this->fakeApis();
        $route = $this->route();

        for ($i = 0; $i < 30; $i++) {
            $this->getJson(route('routes.conditions', $route))->assertOk();
        }
        $this->getJson(route('routes.conditions', $route))->assertStatus(429);
    }

    public function test_page_renders_basecamp_info_without_calling_external_apis(): void
    {
        Http::fake();
        $route = $this->route();

        $this->get(route('routes.show', $route))
            ->assertOk()
            ->assertSee('Info Basecamp &amp; Lokasi', false)
            ->assertSee('Basecamp Selo')
            ->assertSee('-7.48840, 110.44750')
            ->assertSee('Petunjuk arah ke basecamp')
            ->assertSee('https://www.google.com/maps/dir/?api=1&amp;destination=-7.4884,110.4475', false)
            ->assertSee('Lihat di OpenStreetMap')
            ->assertSee('Kondisi Terkini')
            ->assertSee('Data cuaca tidak tersedia')
            ->assertSee(trim(json_encode(route('routes.conditions', $route)), '"'), false); // embedded via @js

        Http::assertNothingSent();
    }

    public function test_guest_sees_review_reminder_instead_of_form(): void
    {
        $route = $this->route();

        $this->get(route('routes.show', $route))
            ->assertOk()
            ->assertSee('Masuk atau daftar dulu untuk memberi ulasan')
            ->assertSee(route('routes.review.login', $route), false)
            ->assertSee(route('routes.review.login', [$route, 'to' => 'register']), false)
            ->assertDontSee('action="' . route('routes.review.store', $route) . '"', false);
    }

    public function test_review_login_link_returns_the_guest_to_the_route_after_login(): void
    {
        $route = $this->route();
        $user  = User::factory()->create(['password' => bcrypt('rahasia123')]);

        $this->get(route('routes.review.login', [$route, 'to' => 'register']))->assertRedirect(route('register'));
        $this->get(route('routes.review.login', $route))->assertRedirect(route('login'));

        $this->post(route('login.submit'), ['email' => $user->email, 'password' => 'rahasia123'])
            ->assertRedirect(route('routes.show', $route) . '#ulasan');
    }

    public function test_logged_in_user_sees_review_form(): void
    {
        $route = $this->route();

        $this->actingAs(User::factory()->create())
            ->get(route('routes.show', $route))
            ->assertOk()
            ->assertSee('action="' . route('routes.review.store', $route) . '"', false)
            ->assertDontSee('Masuk atau daftar dulu untuk memberi ulasan');
    }

    public function test_forecast_gives_three_hour_slots_and_a_daily_cycle(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-21 08:20', 'Asia/Jakarta'));
        $times = [];
        for ($d = 0; $d < 3; $d++) {
            for ($h = 0; $h < 24; $h++) {
                $times[] = sprintf('2026-11-%02dT%02d:00', 21 + $d, $h);
            }
        }
        $n = count($times);
        $hourly = fn(float $temp) => [
            'time'                      => $times,
            'temperature_2m'            => array_fill(0, $n, $temp),
            // Rain likely from 14:00 today at the summit.
            'precipitation_probability' => array_map(fn($t) => $t >= '2026-11-21T14:00' && $t <= '2026-11-21T17:00' ? 80 : 10, $times),
            'precipitation'             => array_fill(0, $n, 0.0),
            'weather_code'              => array_fill(0, $n, 3),
            'wind_speed_10m'            => array_fill(0, $n, 12.0),
            'wind_gusts_10m'            => array_fill(0, $n, 20.0),
            'is_day'                    => array_map(fn($t) => (int) (substr($t, 11, 2) >= '06' && substr($t, 11, 2) < '18'), $times),
        ];
        $daily = [
            'time' => ['2026-11-21', '2026-11-22', '2026-11-23'],
            'sunrise' => ['2026-11-21T05:12', '2026-11-22T05:12', '2026-11-23T05:12'],
            'sunset' => ['2026-11-21T17:41', '2026-11-22T17:41', '2026-11-23T17:42'],
            'temperature_2m_max' => [18, 19, 17], 'temperature_2m_min' => [9, 10, 8],
            'precipitation_probability_max' => [80, 40, 65], 'precipitation_sum' => [6.2, 0.4, 3.1],
            'wind_speed_10m_max' => [20, 15, 25], 'weather_code' => [61, 3, 80],
        ];
        $calm = ['temperature_2m' => 15, 'apparent_temperature' => 15, 'relative_humidity_2m' => 70, 'precipitation' => 0, 'weather_code' => 2, 'wind_speed_10m' => 5, 'wind_direction_10m' => 90, 'wind_gusts_10m' => 8];
        Http::fake([
            'api.open-meteo.com/v1/elevation*' => Http::response(['elevation' => [1820.0, 2500.0, 3123.0, 3050.0]]),
            'api.open-meteo.com/v1/forecast*'  => Http::response([
                ['current' => $calm + ['time' => '2026-11-21T08:15'], 'hourly' => $hourly(16.0), 'daily' => $daily],
                ['current' => $calm + ['time' => '2026-11-21T08:15'], 'hourly' => $hourly(7.0), 'daily' => $daily],
            ]),
            'nominatim.openstreetmap.org/*' => Http::response([]),
        ]);

        $r = $this->getJson(route('routes.conditions', $this->route()))->assertOk();

        $r->assertJsonPath('weather.forecast.step_hours', 3)
            ->assertJsonPath('weather.forecast.summit.0.hour', '08:00')
            ->assertJsonPath('weather.forecast.summit.1.hour', '11:00')
            ->assertJsonPath('weather.forecast.summit.2.rain_probability', 80) // 14:00
            ->assertJsonPath('weather.forecast.summit.0.temperature_c', 7)
            ->assertJsonPath('weather.forecast.basecamp.0.temperature_c', 16)
            ->assertJsonPath('weather.forecast.summit.6.day_offset', 1)        // 02:00 tomorrow
            ->assertJsonPath('weather.forecast.summit.6.is_day', false)
            ->assertJsonCount(17, 'weather.forecast.summit')                   // 48 h every 3 h, both ends
            ->assertJsonCount(3, 'weather.days.summit')
            ->assertJsonPath('weather.days.summit.2.rain_probability', 65)
            ->assertJsonPath('weather.days.summit.0.weather_text', 'Hujan ringan')
            ->assertJsonPath('weather.days.basecamp.1.sunset', '17:41');
        $this->assertStringContainsString('Peluang hujan di puncak 80% sekitar pukul 14:00', implode(' ', $r->json('safety.messages')));
        Http::assertSent(fn(Request $q) => str_contains($q->url(), '/v1/forecast') && $q['forecast_days'] === 3 && str_contains($q['hourly'], 'precipitation_probability'));
    }
}
