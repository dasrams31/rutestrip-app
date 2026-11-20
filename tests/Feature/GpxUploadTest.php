<?php

namespace Tests\Feature;

use App\Models\HikingRoute;
use App\Models\User;
use App\Services\PythonProcessorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GpxUploadTest extends TestCase
{
    use RefreshDatabase;

    private const GPX = '<?xml version="1.0" encoding="UTF-8"?><gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1"><trk><name>T</name><trkseg>'
        . '<trkpt lat="-7.45" lon="110.43"><ele>1800</ele></trkpt><trkpt lat="-7.44" lon="110.43"><ele>2100</ele></trkpt>'
        . '</trkseg></trk></gpx>';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->app->instance(PythonProcessorService::class, new class extends PythonProcessorService {
            public function ingest(string $gpxFilePath): array
            {
                return [
                    'success' => true,
                    'distance_km' => 3.2, 'elevation_gain_m' => 866, 'naismith_duration_hour' => 2.08,
                    'average_grade_pct' => 27.4, 'route_coordinates' => [[-7.45, 110.43], [-7.44, 110.43]],
                    'narrative_text' => 'Jalur pendakian dengan jarak sedang sekitar 3.2 kilometer. Total kenaikan elevasi 866 meter dengan ketinggian dari 1300m hingga 1745m.',
                    'embedding' => [0.1, 0.2, 0.3],
                ];
            }
        });
    }

    private function gpx(string $name = 'Gunung Andong.gpx', ?string $content = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content ?? self::GPX);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_guests_and_regular_users_cannot_upload(): void
    {
        $this->get(route('routes.create'))->assertRedirect(route('login'));
        $this->post(route('routes.store'), ['name' => 'X', 'gpx_file' => $this->gpx()])->assertRedirect(route('login'));
        $this->post(route('routes.batch.store'), ['gpx_files' => [$this->gpx()]])->assertRedirect(route('login'));

        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user)->get(route('routes.batch'))->assertForbidden();
        $this->actingAs($user)->post(route('routes.store'), ['name' => 'X', 'gpx_file' => $this->gpx()])->assertForbidden();

        $this->assertSame(0, HikingRoute::count());
    }

    public function test_admin_single_upload_creates_route_version_and_safe_file(): void
    {
        $this->actingAs($this->admin())
            ->post(route('routes.store'), ['name' => 'Gunung Andong', 'gpx_file' => $this->gpx('Andong <script>.xml')])
            ->assertRedirect(route('routes.index'));

        $route = HikingRoute::firstOrFail();
        $this->assertSame('Gunung Andong', $route->name);
        $this->assertSame(1, $route->gpxVersions()->count());
        $this->assertMatchesRegularExpression('#^gpx_files/\d+_[A-Za-z0-9]{8}_andong-script\.gpx$#', $route->gpx_file_path);
        Storage::disk('public')->assertExists($route->gpx_file_path);
    }

    public function test_upload_rejects_files_that_are_not_gpx(): void
    {
        $xhtml = '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><body><script>alert(1)</script></body></html>';

        $this->actingAs($this->admin())
            ->post(route('routes.store'), ['name' => 'Bad', 'gpx_file' => $this->gpx('evil.xml', $xhtml)])
            ->assertSessionHasErrors('gpx_file');

        $this->actingAs($this->admin())
            ->post(route('routes.store'), ['name' => 'Bad', 'gpx_file' => $this->gpx('notes.txt', 'hello')])
            ->assertSessionHasErrors('gpx_file');

        $this->assertSame(0, HikingRoute::count());
        $this->assertSame([], Storage::disk('public')->allFiles('gpx_files'));
    }

    public function test_admin_batch_upload_creates_all_routes(): void
    {
        $this->actingAs($this->admin())
            ->post(route('routes.batch.store'), ['gpx_files' => [$this->gpx('Merbabu Selo.gpx'), $this->gpx('Merbabu Wekas.gpx')]])
            ->assertRedirect(route('routes.index'))
            ->assertSessionHas('success');

        $this->assertSame(['Merbabu Selo', 'Merbabu Wekas'], HikingRoute::orderBy('name')->pluck('name')->all());
        $this->assertCount(2, Storage::disk('public')->allFiles('gpx_files'));
    }

    public function test_batch_rejects_a_non_gpx_file_before_storing_anything(): void
    {
        $this->actingAs($this->admin())
            ->post(route('routes.batch.store'), ['gpx_files' => [$this->gpx('ok.gpx'), $this->gpx('bad.gpx', '<html></html>')]])
            ->assertSessionHasErrors('gpx_files');

        $this->assertSame(0, HikingRoute::count());
    }
}
