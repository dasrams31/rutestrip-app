<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\HikingRoute;
use App\Models\User;
use App\Services\PythonProcessorService;
use App\Services\SsoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Mobile app JSON API under /api/v1 (docs/API.md). */
class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sso.secret' => 'test-sso-secret']);
        $this->withHeader('Accept-Language', 'id');
    }

    private function route(string $name, array $attributes = []): HikingRoute
    {
        $route = HikingRoute::create(array_merge([
            'name'                   => $name,
            'gpx_file_path'          => 'gpx_files/test.gpx',
            'distance_km'            => 11.1,
            'elevation_gain_m'       => 1424,
            'naismith_duration_hour' => 5.9,
            'average_grade_pct'      => 12.8,
            'steepest_grade_pct'     => 31.4,
            'narrative_text'         => 'Total kenaikan elevasi 1424 meter dengan ketinggian dari 1811m hingga 3128m.',
            'route_coordinates'      => [[-7.45, 110.43, 1811.0], [-7.44, 110.435, 2400.0], [-7.43, 110.44, 3128.0]],
            'sbert_embedding'        => [1.0, 0.0, 0.0],
            'sbert_aspect_embeddings' => [[1.0, 0.0, 0.0]],
        ], array_diff_key($attributes, ['province' => 1])));

        // province is filled from the trailhead location, not mass assignable.
        if (isset($attributes['province'])) {
            $route->forceFill(['province' => $attributes['province']])->save();
        }

        return $route;
    }

    private function fakeEmbedding(array $embedding = [1.0, 0.0, 0.0]): void
    {
        $this->app->instance(PythonProcessorService::class, new class($embedding) extends PythonProcessorService {
            public function __construct(private array $embedding)
            {
            }

            public function getQueryEmbedding(string $query): array
            {
                return $this->embedding;
            }
        });
    }

    private function token(User $user): string
    {
        return app(SsoService::class)->issue($user->refresh());
    }

    // --- list and filters ---------------------------------------------------

    public function test_route_list_pages_and_filters_by_mountain_and_province(): void
    {
        $this->route('Mt. Merbabu via Selo', ['province' => 'Jawa Tengah']);
        $this->route('Mt. Merbabu via Suwanting', ['province' => 'Jawa Tengah']);
        $this->route('Gunung Gede via Cibodas', ['province' => 'Jawa Barat']);

        $this->getJson('/api/v1/routes?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'mountain', 'basecamp', 'distance_km', 'duration_label',
                'summit_route', 'steepest_grade_pct', 'difficulty_level', 'likes_count', 'track', 'web_url']]]);

        $this->getJson('/api/v1/routes?mountain=gunung%20merbabu')
            ->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.mountain', 'Gunung Merbabu');

        $this->getJson('/api/v1/routes?province=Jawa%20Barat')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Gunung Gede via Cibodas');

        $this->getJson('/api/v1/routes?per_page=500')->assertStatus(422);
    }

    public function test_list_never_sends_embeddings(): void
    {
        $this->route('Mt. Merbabu via Selo');

        $json = $this->getJson('/api/v1/routes')->assertOk()->getContent();

        $this->assertStringNotContainsString('sbert', $json);
        $this->assertStringNotContainsString('embedding', $json);
    }

    public function test_filters_list_mountains_provinces_and_examples(): void
    {
        $this->route('Mt. Merbabu via Selo', ['province' => 'Jawa Tengah']);
        $this->route('Mt. Merbabu via Suwanting', ['province' => 'Jawa Tengah']);
        $this->route('Mt. Argopuro Baderan to Bremi', ['province' => 'Jawa Timur']);

        $this->getJson('/api/v1/filters')
            ->assertOk()
            ->assertJsonPath('total_routes', 3)
            ->assertJsonFragment(['name' => 'Gunung Merbabu', 'count' => 2])
            ->assertJsonFragment(['name' => 'Gunung Argopuro', 'count' => 1])
            ->assertJsonFragment(['name' => 'Jawa Tengah', 'count' => 2])
            ->assertJsonPath('examples.0', 'Jalur landai untuk pemula');

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/filters')
            ->assertJsonPath('examples.0', 'Gentle trail for beginners');
    }

    // --- detail -------------------------------------------------------------

    public function test_route_detail_has_stats_points_profile_and_similar_routes(): void
    {
        $selo = $this->route('Mt. Merbabu via Selo');
        $this->route('Mt. Merbabu via Suwanting');
        $this->route('Mt. Lawu via Cetho', ['summit_route' => false, 'sbert_embedding' => [0.0, 1.0, 0.0]]);

        $response = $this->getJson('/api/v1/routes/' . $selo->id)->assertOk();

        $response->assertJsonPath('data.name', 'Mt. Merbabu via Selo')
            ->assertJsonPath('data.duration_label', 'Waktu ke puncak')
            ->assertJsonPath('data.summit_route', true)
            ->assertJsonPath('data.steepest_grade_pct', 31.4)
            ->assertJsonPath('data.basecamp', 'Basecamp Selo')
            ->assertJsonPath('data.basecamp_point.elevation_m', 1811)
            ->assertJsonPath('data.summit_point.elevation_m', 3128)
            ->assertJsonPath('data.liked', false)
            ->assertJsonPath('data.similar.0.name', 'Mt. Merbabu via Suwanting')
            ->assertJsonCount(3, 'data.coordinates')
            ->assertJsonCount(2, 'data.similar');
        $this->assertStringContainsString('beban carrier', $response->json('data.naismith_note'));
        $this->assertEquals([0, 1811], $response->json('data.elevation_profile.0'));

        $this->getJson('/api/v1/routes/999999')->assertNotFound();
    }

    public function test_not_to_summit_route_uses_walking_time_label(): void
    {
        $route = $this->route('Mt. Lawu via Cetho', ['summit_route' => false]);

        $this->getJson('/api/v1/routes/' . $route->id)->assertJsonPath('data.duration_label', 'Waktu jalan')
            ->assertJsonPath('data.summit_route', false);
    }

    // --- search -------------------------------------------------------------

    public function test_search_uses_the_website_ranking_and_explains_the_plan(): void
    {
        $this->fakeEmbedding();
        $this->route('Mt. Merbabu via Selo', ['average_grade_pct' => 12.8, 'steepest_grade_pct' => 25]);
        $this->route('Mt. Merbabu via Suwanting', ['average_grade_pct' => 18.0, 'steepest_grade_pct' => 40]);
        $this->route('Gunung Gede via Cibodas', ['average_grade_pct' => 9.0, 'steepest_grade_pct' => 15]);

        $this->postJson('/api/v1/search', ['query' => 'jalur landai merbabu'])
            ->assertOk()
            ->assertJsonPath('plan.sort', 'gentle')
            ->assertJsonPath('plan.mountain_filter', true)
            ->assertJsonPath('plan.mountains.0', 'Gunung Merbabu')
            ->assertJsonCount(2, 'results')
            ->assertJsonPath('results.0.name', 'Mt. Merbabu via Selo')
            ->assertJsonPath('explanation.filter_line', 'Hanya jalur Gunung Merbabu.')
            ->assertJsonPath('explanation.sort_line', 'Diurutkan dari yang paling landai.')
            ->assertJsonPath('explanation.can_search_all', true)
            ->assertJsonPath('results.0.why', 'Kemiringan rata-rata 12,8%, total naik 1.424 m.')
            ->assertJsonStructure(['results' => [['similarity_score', 'match_label', 'track']], 'search_time_ms']);

        // "Cari di semua jalur" skips the mountain filter; the named mountain still ranks first.
        $this->postJson('/api/v1/search', ['query' => 'jalur landai merbabu', 'all_routes' => true])
            ->assertOk()
            ->assertJsonPath('plan.mountain_filter', false)
            ->assertJsonPath('explanation.filter_line', null)
            ->assertJsonCount(3, 'results')
            ->assertJsonPath('results.2.name', 'Gunung Gede via Cibodas');
    }

    public function test_search_answers_in_english_when_asked(): void
    {
        $this->fakeEmbedding();
        $this->route('Mt. Merbabu via Selo');

        $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/v1/search', ['query' => 'jalur landai'])
            ->assertOk()
            ->assertJsonPath('results.0.why', 'Average slope 12.8%, total climb 1,424 m.');
    }

    public function test_search_validates_the_query(): void
    {
        $this->fakeEmbedding();

        $this->postJson('/api/v1/search', ['query' => 'ab'])->assertStatus(422)->assertJsonValidationErrors('query');
        $this->postJson('/api/v1/search', [])->assertStatus(422)->assertJsonValidationErrors('query');
    }

    public function test_search_reports_an_unavailable_model(): void
    {
        $this->route('Mt. Merbabu via Selo');
        $this->app->instance(PythonProcessorService::class, new class extends PythonProcessorService {
            public function getQueryEmbedding(string $query): array
            {
                throw new \RuntimeException('model down');
            }
        });

        $this->postJson('/api/v1/search', ['query' => 'jalur landai'])->assertStatus(503)->assertJsonStructure(['error']);
    }

    public function test_search_is_rate_limited(): void
    {
        $this->fakeEmbedding();
        $this->route('Mt. Merbabu via Selo');

        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/search', ['query' => 'jalur landai'])->assertOk();
        }
        $this->postJson('/api/v1/search', ['query' => 'jalur landai'])->assertStatus(429);
    }

    public function test_search_by_a_signed_in_user_is_kept_in_their_history(): void
    {
        $this->fakeEmbedding();
        $this->route('Mt. Merbabu via Selo');
        $user = User::factory()->create();

        $this->withToken($this->token($user))->postJson('/api/v1/search', ['query' => 'jalur landai'])->assertOk();

        $this->assertDatabaseHas('search_histories', ['user_id' => $user->id, 'query' => 'jalur landai']);
    }

    // --- likes --------------------------------------------------------------

    public function test_like_needs_a_valid_token(): void
    {
        $route = $this->route('Mt. Merbabu via Selo');

        $this->postJson("/api/v1/routes/{$route->id}/like")->assertStatus(401);
        $this->withToken('not-a-token')->postJson("/api/v1/routes/{$route->id}/like")->assertStatus(401);
        $this->getJson('/api/v1/likes')->assertStatus(401);
        $this->assertSame(0, Favorite::count());
    }

    public function test_signed_in_user_can_like_unlike_and_list_likes(): void
    {
        $route = $this->route('Mt. Merbabu via Selo');
        $token = $this->token(User::factory()->create());

        $this->withToken($token)->postJson("/api/v1/routes/{$route->id}/like")
            ->assertOk()->assertJson(['liked' => true, 'count' => 1]);
        // Setting the same state again is idempotent.
        $this->withToken($token)->postJson("/api/v1/routes/{$route->id}/like", ['liked' => true])
            ->assertOk()->assertJson(['liked' => true, 'count' => 1]);

        $this->withToken($token)->getJson('/api/v1/routes/' . $route->id)->assertJsonPath('data.liked', true)
            ->assertJsonPath('data.likes_count', 1);
        $this->withToken($token)->getJson('/api/v1/likes')->assertOk()->assertJsonPath('data.0.id', $route->id);

        $this->withToken($token)->postJson("/api/v1/routes/{$route->id}/like")
            ->assertOk()->assertJson(['liked' => false, 'count' => 0]);
    }
}
