<?php

namespace Tests\Feature;

use App\Models\HikingRoute;
use App\Models\User;
use App\Services\PythonProcessorService;
use App\Services\SsoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Hikes recorded in the app: processed at once, hidden until an admin approves them. */
class RecordingSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private const GPX = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="RuteStrip" xmlns="http://www.topografix.com/GPX/1/1">
  <trk><name>Rekaman</name><trkseg>
    <trkpt lat="-7.45" lon="110.43"><ele>1811</ele></trkpt>
    <trkpt lat="-7.44" lon="110.435"><ele>2400</ele></trkpt>
    <trkpt lat="-7.43" lon="110.44"><ele>3128</ele></trkpt>
  </trkseg></trk>
</gpx>
XML;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sso.secret' => 'test-sso-secret']);
        $this->withHeader('Accept-Language', 'id');
        Storage::fake('public');
        $this->app->instance(PythonProcessorService::class, new class extends PythonProcessorService {
            public function ingest(string $gpxFilePath): array
            {
                return [
                    'route_coordinates'      => [[-7.45, 110.43, 1811.0], [-7.43, 110.44, 3128.0]],
                    'distance_km'            => 3.2,
                    'elevation_gain_m'       => 1317,
                    'naismith_duration_hour' => 2.8,
                    'average_grade_pct'      => 25.1,
                    'steepest_grade_pct'     => 40.2,
                    'narrative_text'         => 'Jalur pendakian dengan ketinggian dari 1811m hingga 3128m.',
                    'embedding'              => [0.1, 0.2, 0.3],
                ];
            }
        });
    }

    private function send(User $user, string $source = 'recording'): \Illuminate\Testing\TestResponse
    {
        return $this->withToken(app(SsoService::class)->issue($user->refresh()))->post('/api/v1/routes', [
            'name'        => 'Merbabu via Selo (rekaman)',
            'gpx_file'    => UploadedFile::fake()->createWithContent('rekaman.gpx', self::GPX),
            'clean_track' => '0',
            'source'      => $source,
        ], ['Accept' => 'application/json']);
    }

    private function pending(): HikingRoute
    {
        return HikingRoute::withoutGlobalScope('published')->firstOrFail();
    }

    public function test_a_recording_is_processed_but_hidden_until_approved(): void
    {
        $user = User::factory()->create();

        $this->send($user)->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.source', 'recording')
            ->assertJsonPath('data.distance_km', 3.2);

        $route = $this->pending();
        $this->assertSame(HikingRoute::PENDING, $route->review_status);
        $this->assertNotNull($route->sbert_embedding, 'processed at once');

        // Nowhere public: list, detail, model lookups.
        $this->assertSame(0, HikingRoute::count());
        $this->getJson('/api/v1/routes')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/routes/' . $route->id)->assertNotFound();
        $this->get('/routes/' . $route->id)->assertNotFound();

        // The sender sees it with its status.
        $this->withToken(app(SsoService::class)->issue($user->refresh()))
            ->getJson('/api/v1/me/submissions')->assertOk()
            ->assertJsonPath('data.0.id', $route->id)
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_a_normal_upload_is_still_published_at_once(): void
    {
        $this->send(User::factory()->create(), 'upload')->assertCreated()->assertJsonStructure(['data' => ['web_url']]);
        $this->assertSame(1, HikingRoute::count());
    }

    public function test_admin_approves_and_the_route_goes_public(): void
    {
        $this->send(User::factory()->create())->assertCreated();
        $route = $this->pending();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/admin/kiriman')->assertOk()->assertSee('Merbabu via Selo (rekaman)');
        $this->actingAs($admin)->post('/admin/kiriman/' . $route->id . '/setujui')->assertRedirect();

        $route = HikingRoute::findOrFail($route->id);
        $this->assertSame(HikingRoute::PUBLISHED, $route->review_status);
        $this->assertSame($admin->id, (int) $route->reviewed_by);
        $this->getJson('/api/v1/routes/' . $route->id)->assertOk();
    }

    public function test_admin_rejects_with_a_note_the_sender_can_read(): void
    {
        $user = User::factory()->create();
        $this->send($user)->assertCreated();
        $route = $this->pending();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/admin/kiriman/' . $route->id . '/tolak', ['note' => 'Rekaman terputus di tengah jalan.'])->assertRedirect();

        $this->assertSame(0, HikingRoute::count());
        $this->withToken(app(SsoService::class)->issue($user->refresh()))
            ->getJson('/api/v1/me/submissions')->assertOk()
            ->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.note', 'Rekaman terputus di tengah jalan.');

        // A decided submission cannot be decided again.
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/admin/kiriman/' . $route->id . '/setujui')->assertNotFound();
    }

    public function test_only_admins_can_review_and_only_the_sender_sees_a_submission(): void
    {
        $sender = User::factory()->create();
        $this->send($sender)->assertCreated();
        $route = $this->pending();
        $other = User::factory()->create();

        $this->actingAs($other)->get('/admin/kiriman')->assertForbidden();
        $this->actingAs($other)->post('/admin/kiriman/' . $route->id . '/setujui')->assertForbidden();
        $this->assertSame(HikingRoute::PENDING, $this->pending()->review_status);

        $this->withToken(app(SsoService::class)->issue($other->refresh()))
            ->getJson('/api/v1/me/submissions')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unknown_source_is_refused(): void
    {
        $this->send(User::factory()->create(), 'admin')->assertStatus(422)->assertJsonValidationErrors('source');
        $this->assertSame(0, HikingRoute::withoutGlobalScope('published')->count());
    }
}
