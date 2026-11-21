<?php

namespace Tests\Feature;

use App\Models\HikingRoute;
use App\Models\User;
use App\Services\GpxTrackCleaner;
use App\Services\PythonProcessorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GpxTrackCleanerTest extends TestCase
{
    use RefreshDatabase;

    private const LAT0 = -7.45;
    private const LON0 = 110.43;
    private const DEG_PER_M = 1 / 111195; // metres to degrees of latitude

    /** Straight climb north: $n points 20 m apart, from $ele0 up by $gainPerPt each. */
    private function climb(int $n, float $ele0 = 1500, float $gainPerPt = 5, float $lonOffsetM = 0, float $lat0 = self::LAT0): array
    {
        $pts = [];
        for ($i = 0; $i < $n; $i++) {
            $pts[] = [$lat0 + $i * 20 * self::DEG_PER_M, self::LON0 + $lonOffsetM * self::DEG_PER_M, $ele0 + $i * $gainPerPt];
        }

        return $pts;
    }

    private function gpx(array ...$tracks): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">'
            . '<wpt lat="-7.45" lon="110.43"><name>Basecamp</name></wpt>';
        foreach ($tracks as $pts) {
            $xml .= '<trk><name>T</name><trkseg>';
            foreach ($pts as [$lat, $lon, $ele]) {
                $xml .= sprintf('<trkpt lat="%.7f" lon="%.7f"><ele>%.1f</ele><time>2026-08-01T00:00:00Z</time></trkpt>', $lat, $lon, $ele);
            }
            $xml .= '</trkseg></trk>';
        }

        return $xml . '</gpx>';
    }

    private function note(array $report): string
    {
        return GpxTrackCleaner::summary($report, 'id') ?? '';
    }

    /** Way down on a parallel trail 400 m east, reached along the summit ridge. */
    private function loop(): array
    {
        $up = $this->climb(150);
        $top = end($up);
        $ridge = [];
        for ($i = 1; $i < 20; $i++) {
            $ridge[] = [$top[0], $top[1] + $i * 20 * self::DEG_PER_M, $top[2] - 1];
        }

        return array_merge($up, $ridge, array_reverse($this->climb(150, 1500, 5, 400)));
    }

    private function clean(string $xml, ?string $name = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'gpx');
        file_put_contents($path, $xml);
        $report = (new GpxTrackCleaner())->clean($path, $name);
        $report['xml'] = file_get_contents($path);
        $report['points'] = substr_count($report['xml'], '<trkpt');
        unlink($path);

        return $report;
    }

    public function test_out_and_back_keeps_only_the_way_up(): void
    {
        $up = $this->climb(200);                // 4 km, +995 m
        $down = array_reverse($up);
        array_shift($down);

        $r = $this->clean($this->gpx(array_merge($up, $down)));

        $this->assertTrue($r['changed']);
        $this->assertStringContainsString('jalur yang sama', $this->note($r));
        $this->assertSame(200, $r['points']);
        $this->assertEqualsWithDelta(3.98, $r['km_after'], 0.05);
        $this->assertStringContainsString('<time>2026-08-01T00:00:00Z</time>', $r['xml'], 'original point data is kept');
        $this->assertStringContainsString('<name>Basecamp</name>', $r['xml'], 'waypoints are kept');
    }

    public function test_loop_down_another_trail_keeps_the_way_up(): void
    {
        $r = $this->clean($this->gpx($this->loop()));

        $this->assertStringContainsString('loop', $this->note($r));
        $this->assertSame(150, $r['points']);
    }

    public function test_route_named_loop_is_kept_whole(): void
    {
        $r = $this->clean($this->gpx($this->loop()), 'Gunung Andong via Pendem Loop');

        $this->assertFalse($r['changed']);
    }

    public function test_traverse_ending_elsewhere_is_kept_whole(): void
    {
        $up = $this->climb(150);
        $top = end($up);
        $down = [];
        for ($i = 1; $i <= 150; $i++) { // down the other side, heading east
            $down[] = [$top[0], $top[1] + $i * 20 * self::DEG_PER_M, $top[2] - $i * 5];
        }
        $r = $this->clean($this->gpx(array_merge($up, $down)));

        $this->assertFalse($r['changed']);
    }

    public function test_separate_track_far_away_is_dropped(): void
    {
        $main = $this->climb(150);
        $stray = $this->climb(30, 300, 1, 0, self::LAT0 - 0.1); // 11 km south, low
        $r = $this->clean($this->gpx($stray, $main));

        $this->assertStringContainsString('potongan rekaman terpisah', $this->note($r));
        $this->assertSame(150, $r['points']);
    }

    public function test_tracks_that_meet_are_joined(): void
    {
        $all = $this->climb(200);
        $r = $this->clean($this->gpx(array_slice($all, 0, 100), array_slice($all, 100)));

        $this->assertStringContainsString('disambung', $this->note($r));
        $this->assertSame(200, $r['points']);
        $this->assertSame(1, substr_count($r['xml'], '<trk>'));
    }

    public function test_side_trip_back_to_the_same_spot_is_removed(): void
    {
        $up = $this->climb(200);
        $junction = $up[100];
        $spur = [];
        for ($i = 1; $i <= 10; $i++) { // 200 m east on the level, then back
            $spur[] = [$junction[0], $junction[1] + $i * 20 * self::DEG_PER_M, $junction[2]];
        }
        $spur = array_merge($spur, array_reverse(array_slice($spur, 0, 9)), [$junction]);
        $track = array_merge(array_slice($up, 0, 101), $spur, array_slice($up, 101));

        $r = $this->clean($this->gpx($track));

        $this->assertStringContainsString('cabang', $this->note($r));
        $this->assertLessThanOrEqual(202, $r['points']);
        $this->assertEqualsWithDelta(3.98, $r['km_after'], 0.05);
    }

    public function test_switchbacks_are_not_mistaken_for_side_trips(): void
    {
        // Legs 15 m apart, each 200 m long and 20 m higher than the last.
        $pts = [];
        $ele = 1500;
        for ($leg = 0; $leg < 12; $leg++) {
            for ($i = 0; $i <= 10; $i++) {
                $x = $leg % 2 ? 10 - $i : $i;
                $pts[] = [self::LAT0 + $leg * 15 * self::DEG_PER_M, self::LON0 + $x * 20 * self::DEG_PER_M, $ele];
                $ele += 2;
            }
        }

        $r = $this->clean($this->gpx($pts));

        $this->assertFalse($r['changed']);
    }

    public function test_one_way_climb_is_left_untouched(): void
    {
        $xml = $this->gpx($this->climb(200));
        $r = $this->clean($xml);

        $this->assertFalse($r['changed']);
        $this->assertSame($xml, $r['xml']);
    }

    public function test_upload_cleans_by_default_and_records_it_in_the_history(): void
    {
        Storage::fake('public');
        $this->fakeProcessor();
        $up = $this->climb(200);
        $file = UploadedFile::fake()->createWithContent('Gunung Andong.gpx', $this->gpx(array_merge($up, array_reverse($up))));

        $this->actingAs(User::factory()->create())
            ->post(route('routes.store'), ['name' => 'Gunung Andong', 'gpx_file' => $file])
            ->assertRedirect(route('routes.index'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '7.96') && str_contains($m, '3.98'));

        $route = HikingRoute::firstOrFail();
        $this->assertStringContainsString('jalur yang sama', $route->gpxVersions()->first()->change_log);
        $this->assertSame(200, substr_count(Storage::disk('public')->get($route->gpx_file_path), '<trkpt'));
    }

    public function test_upload_can_skip_cleaning(): void
    {
        Storage::fake('public');
        $this->fakeProcessor();
        $up = $this->climb(200);
        $file = UploadedFile::fake()->createWithContent('a.gpx', $this->gpx(array_merge($up, array_reverse($up))));

        $this->actingAs(User::factory()->create())
            ->post(route('routes.batch.store'), ['gpx_files' => [$file], 'clean_track' => '0'])
            ->assertRedirect(route('routes.index'));

        $route = HikingRoute::firstOrFail();
        $this->assertSame(400, substr_count(Storage::disk('public')->get($route->gpx_file_path), '<trkpt'));
    }

    private function fakeProcessor(): void
    {
        $this->app->instance(PythonProcessorService::class, new class extends PythonProcessorService {
            public function ingest(string $gpxFilePath): array
            {
                return ['success' => true, 'distance_km' => 4, 'elevation_gain_m' => 995, 'naismith_duration_hour' => 2.5,
                    'route_coordinates' => [], 'narrative_text' => 'x', 'embedding' => [0.1]];
            }
        });
    }
}
