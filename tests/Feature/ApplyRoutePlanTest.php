<?php

namespace Tests\Feature;

use App\Models\HikingRoute;
use App\Services\GpxVersionService;
use App\Services\PythonProcessorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplyRoutePlanTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->app->instance(PythonProcessorService::class, new class extends PythonProcessorService {
            public function ingest(string $gpxFilePath): array
            {
                return [
                    'success' => true, 'distance_km' => 5.4, 'elevation_gain_m' => 1300,
                    'naismith_duration_hour' => 3.25, 'average_grade_pct' => 24.1, 'steepest_grade_pct' => 38.0,
                    'route_coordinates' => [[-7.45, 110.43], [-7.44, 110.43]],
                    'narrative_text' => 'Jalur pendakian dengan jarak sedang sekitar 5.4 kilometer.',
                    'embedding' => [0.1, 0.2, 0.3],
                ];
            }
        });

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rs-plan-' . uniqid();
        File::ensureDirectoryExists($this->dir . '/replace');
        File::put($this->dir . '/replace/001.gpx', '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg><trkpt lat="-7.45" lon="110.43"><ele>1800</ele></trkpt><trkpt lat="-7.44" lon="110.43"><ele>3100</ele></trkpt></trkseg></trk></gpx>');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function routeWithVersion(): HikingRoute
    {
        Storage::disk('public')->put('gpx_files/old.gpx', '<gpx/>');
        $route = HikingRoute::create(['name' => 'Gunung Merbabu via Cuntel', 'gpx_file_path' => 'gpx_files/old.gpx', 'distance_km' => 3.6, 'elevation_gain_m' => 1081]);
        app(GpxVersionService::class)->createInitialVersion($route, 'gpx_files/old.gpx');

        return $route;
    }

    private function plan(int $routeId): string
    {
        $path = $this->dir . '/plan.json';
        File::put($path, json_encode([[
            'route_id' => $routeId, 'action' => 'replace', 'status' => 'COMPLETE', 'file' => 'replace/001.gpx',
            'km_added' => 1.83, 'new_max_ele' => 3125, 'peak_name' => 'Kenteng Songo', 'peak_ele' => 3145,
        ]]));

        return $path;
    }

    public function test_dry_run_changes_nothing(): void
    {
        $route = $this->routeWithVersion();

        $this->artisan('routes:apply-plan', ['plan' => $this->plan($route->id)])->assertSuccessful();

        $this->assertSame('gpx_files/old.gpx', $route->refresh()->gpx_file_path);
        $this->assertSame(1, $route->gpxVersions()->count());
    }

    public function test_apply_creates_a_new_version_and_keeps_the_old_one(): void
    {
        $route = $this->routeWithVersion();

        $this->artisan('routes:apply-plan', ['plan' => $this->plan($route->id), '--apply' => true])->assertSuccessful();

        $route->refresh();
        $this->assertNotSame('gpx_files/old.gpx', $route->gpx_file_path);
        $this->assertEquals(5.4, $route->distance_km);
        $this->assertEquals(38.0, $route->steepest_grade_pct);
        $this->assertSame(2, $route->gpxVersions()->count());
        $this->assertStringContainsString('Kenteng Songo', $route->activeGpxVersion->change_log);
        Storage::disk('public')->assertExists($route->gpx_file_path);
    }
}
