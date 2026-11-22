<?php

namespace Tests\Feature;

use App\Models\CommunityMessage;
use App\Models\HikingRoute;
use App\Models\User;
use App\Services\PythonProcessorService;
use App\Services\SsoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Mobile API endpoints that need the SSO bearer token: GPX upload, community chat, assistant (docs/API.md). */
class MobileApiSignedInTest extends TestCase
{
    use RefreshDatabase;

    private const GPX = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">
  <trk><name>Test</name><trkseg>
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
    }

    private function signedIn(?User $user = null): User
    {
        $user ??= User::factory()->create(['name' => 'Rama']);
        $this->withToken(app(SsoService::class)->issue($user->refresh()));

        return $user;
    }

    private function fakeIngest(): void
    {
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

    public function test_signed_in_endpoints_reject_missing_or_bad_tokens(): void
    {
        $this->postJson('/api/v1/routes')->assertStatus(401)->assertJsonStructure(['error']);
        $this->getJson('/api/v1/community/messages')->assertStatus(401);
        $this->withToken('nope')->postJson('/api/v1/assistant', ['message' => 'halo'])->assertStatus(401);
    }

    // --- GPX upload -----------------------------------------------------------

    public function test_upload_creates_a_route_with_the_uploader_and_a_first_version(): void
    {
        Storage::fake('public');
        $this->fakeIngest();
        $user = $this->signedIn();

        $response = $this->post('/api/v1/routes', [
            'name'        => 'Gunung Merbabu via Wekas',
            'description' => 'Rekaman pendakian Juni.',
            'gpx_file'    => UploadedFile::fake()->createWithContent('wekas.gpx', self::GPX),
            'clean_track' => '0',
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Gunung Merbabu via Wekas')
            ->assertJsonPath('data.distance_km', 3.2)
            ->assertJsonStructure(['data' => ['id', 'web_url'], 'message']);

        $route = HikingRoute::firstOrFail();
        $this->assertSame($user->id, (int) $route->uploaded_by);
        $this->assertSame(1, $route->gpxVersions()->count());
        Storage::disk('public')->assertExists($route->gpx_file_path);
        $this->assertStringEndsWith('.gpx', $route->gpx_file_path);
    }

    public function test_upload_rejects_files_that_are_not_gpx(): void
    {
        Storage::fake('public');
        $this->fakeIngest();
        $this->signedIn();

        $this->post('/api/v1/routes', [
            'name'     => 'Bukan GPX',
            'gpx_file' => UploadedFile::fake()->createWithContent('notes.gpx', '<html><body>hi</body></html>'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Isi file bukan GPX yang valid.');

        $this->post('/api/v1/routes', ['name' => 'Tanpa file'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('gpx_file');

        $this->assertSame(0, HikingRoute::count());
    }

    // --- community chat -------------------------------------------------------

    public function test_community_chat_send_read_delete_and_report(): void
    {
        $other = User::factory()->create(['name' => 'Sari']);
        $theirs = CommunityMessage::create(['user_id' => $other->id, 'room' => 'umum', 'body' => 'Ada yang ke Prau minggu ini?']);
        $this->signedIn();

        $sent = $this->postJson('/api/v1/community/messages', ['body' => 'Saya ke Prau hari Sabtu.'])
            ->assertCreated()
            ->assertJsonPath('message.mine', true)
            ->assertJsonPath('message.name', 'Rama')
            ->json('message.id');

        $this->getJson('/api/v1/community/messages')
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.name', 'Sari')
            ->assertJsonPath('messages.0.mine', false)
            ->assertJsonPath('messages.1.mine', true);

        $this->getJson('/api/v1/community/messages?after=' . $theirs->id)->assertJsonCount(1, 'messages');
        $this->getJson('/api/v1/community/latest')->assertJsonPath('last_id', $theirs->id);

        $this->postJson("/api/v1/community/messages/{$theirs->id}/report")->assertOk()->assertJsonPath('reported', true);
        $this->deleteJson("/api/v1/community/messages/{$theirs->id}")->assertForbidden();
        $this->deleteJson("/api/v1/community/messages/{$sent}")->assertOk()->assertJsonPath('message.deleted', true);
    }

    public function test_community_chat_validates_the_message(): void
    {
        $this->signedIn();

        $this->postJson('/api/v1/community/messages', ['body' => "   \n "])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->postJson('/api/v1/community/messages', ['body' => str_repeat('a', 501)])->assertStatus(422);
    }

    // --- assistant --------------------------------------------------------------

    public function test_assistant_answers_signed_in_users(): void
    {
        Http::preventStrayRequests();
        config(['services.gemini.key' => 'test-key', 'services.gemini.models' => ['model-a']]);
        Http::fake(['*generativelanguage.googleapis.com/*' => Http::response(
            'data: ' . json_encode(['candidates' => [['content' => ['parts' => [['text' => 'Merbabu via Selo cocok untuk pemula.']]]]]]) . "\r\n\r\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        )]);
        $this->signedIn();

        $this->postJson('/api/v1/assistant', ['message' => 'Jalur Merbabu untuk pemula?', 'history' => []])
            ->assertOk()
            ->assertJsonPath('reply', 'Merbabu via Selo cocok untuk pemula.');
    }
}
