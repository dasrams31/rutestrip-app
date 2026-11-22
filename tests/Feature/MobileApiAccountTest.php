<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\GpxVersion;
use App\Models\HikingRoute;
use App\Models\Rating;
use App\Models\SearchHistory;
use App\Models\User;
use App\Services\SsoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Mobile API: reviews, conditions, GPX versions and the account endpoints (docs/API.md). */
class MobileApiAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sso.secret' => 'test-sso-secret']);
        $this->withHeader('Accept-Language', 'id');
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['name' => 'Rama', 'email' => 'rama@example.com', 'password' => 'rahasia123'], $attributes))->refresh();
    }

    private function token(User $user): string
    {
        return app(SsoService::class)->issue($user->refresh());
    }

    private function route(): HikingRoute
    {
        return HikingRoute::create([
            'name'                   => 'Mt. Merbabu via Selo',
            'gpx_file_path'          => 'gpx_files/selo.gpx',
            'distance_km'            => 11.1,
            'elevation_gain_m'       => 1424,
            'naismith_duration_hour' => 5.9,
            'average_grade_pct'      => 12.8,
            'basecamp_name'          => 'Basecamp Selo',
            'entry_fee'              => 25000,
            'tips'                   => 'Bawa air dari basecamp.',
            'narrative_text'         => 'Total kenaikan elevasi 1424 meter dengan ketinggian dari 1811m hingga 3128m.',
            'route_coordinates'      => [[-7.45, 110.43, 1811.0], [-7.43, 110.44, 3128.0]],
        ]);
    }

    // --- reviews --------------------------------------------------------------

    public function test_reviews_are_public_and_signed_in_users_can_write_one(): void
    {
        $route = $this->route();
        $other = $this->user(['email' => 'sari@example.com', 'name' => 'Sari']);
        Rating::create(['user_id' => $other->id, 'hiking_route_id' => $route->id, 'rating' => 4]);
        Comment::create(['user_id' => $other->id, 'hiking_route_id' => $route->id, 'content' => 'Air ada di pos 2.']);

        $this->getJson("/api/v1/routes/{$route->id}/reviews")
            ->assertOk()
            ->assertJsonPath('average_rating', 4)
            ->assertJsonPath('review_count', 1)
            ->assertJsonPath('my_rating', null)
            ->assertJsonPath('reviews.0.name', 'Sari')
            ->assertJsonPath('reviews.0.rating', 4)
            ->assertJsonPath('reviews.0.comment', 'Air ada di pos 2.');

        $this->postJson("/api/v1/routes/{$route->id}/reviews", ['rating' => 5])->assertStatus(401);

        $token = $this->token($this->user());
        $this->withToken($token)->postJson("/api/v1/routes/{$route->id}/reviews", ['rating' => 6])->assertStatus(422);
        $this->withToken($token)->postJson("/api/v1/routes/{$route->id}/reviews", ['rating' => 2, 'comment' => 'Licin setelah hujan.'])
            ->assertCreated()
            ->assertJsonPath('review_count', 2)
            ->assertJsonPath('average_rating', 3)
            ->assertJsonPath('my_rating', 2)
            ->assertJsonPath('reviews.0.comment', 'Licin setelah hujan.');

        // A second review updates the rating instead of adding one.
        $this->withToken($token)->postJson("/api/v1/routes/{$route->id}/reviews", ['rating' => 4])->assertJsonPath('review_count', 2);
    }

    public function test_route_detail_has_practical_info_and_rating(): void
    {
        $route = $this->route();
        Rating::create(['user_id' => $this->user()->id, 'hiking_route_id' => $route->id, 'rating' => 5]);

        $this->getJson("/api/v1/routes/{$route->id}")
            ->assertOk()
            ->assertJsonPath('data.practical.basecamp_name', 'Basecamp Selo')
            ->assertJsonPath('data.practical.entry_fee', 25000)
            ->assertJsonPath('data.practical.tips', 'Bawa air dari basecamp.')
            ->assertJsonPath('data.average_rating', 5)
            ->assertJsonPath('data.review_count', 1)
            ->assertJsonPath('data.uploader', 'Tim RuteStrip');
    }

    // --- conditions and versions -------------------------------------------------

    public function test_conditions_use_the_cached_service_and_versions_list_the_gpx_history(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([], 500)]);
        $route = $this->route();
        GpxVersion::create([
            'hiking_route_id' => $route->id, 'version_number' => 1, 'gpx_file_path' => 'gpx_files/selo.gpx',
            'file_hash' => str_repeat('a', 64), 'verification_status' => 'verified', 'is_active' => true,
        ]);

        $this->getJson("/api/v1/routes/{$route->id}/conditions")
            ->assertOk()
            ->assertJsonPath('route_id', $route->id)
            ->assertJsonPath('points.basecamp.elevation_m', 1811)
            ->assertJsonPath('weather', null);

        $this->getJson("/api/v1/routes/{$route->id}/versions")
            ->assertOk()
            ->assertJsonPath('data.0.version', 1)
            ->assertJsonPath('data.0.active', true)
            ->assertJsonPath('data.0.status', 'verified')
            ->assertJsonStructure(['data' => [['download_url', 'hash', 'status_label']]]);
    }

    // --- account --------------------------------------------------------------------

    public function test_me_shows_stats_and_history(): void
    {
        $user = $this->user();
        SearchHistory::create(['user_id' => $user->id, 'query' => 'jalur landai', 'results_count' => 10]);

        $this->withToken($this->token($user))->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'rama@example.com')
            ->assertJsonPath('stats.searches', 1)
            ->assertJsonPath('recent_searches.0.query', 'jalur landai');

        $this->withToken($this->token($user))->getJson('/api/v1/me/history')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.results_count', 10);

        $this->flushHeaders()->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_profile_and_language_can_be_changed(): void
    {
        $user = $this->user();
        $this->user(['email' => 'taken@example.com', 'name' => 'Lain']);
        $token = $this->token($user);

        $this->withToken($token)->putJson('/api/v1/me/profile', ['name' => 'Rama D', 'email' => 'taken@example.com'])
            ->assertStatus(422)->assertJsonStructure(['error', 'errors' => ['email']]);
        $this->withToken($token)->putJson('/api/v1/me/profile', ['name' => 'Rama D', 'email' => 'rama.d@example.com'])
            ->assertOk()->assertJsonPath('user.name', 'Rama D');

        $this->withToken($token)->putJson('/api/v1/me/locale', ['locale' => 'fr'])->assertStatus(422);
        $this->withToken($token)->putJson('/api/v1/me/locale', ['locale' => 'en'])->assertOk()->assertJsonPath('user.locale', 'en');
    }

    public function test_password_change_needs_the_current_password_and_ends_old_tokens(): void
    {
        $user = $this->user();
        $old  = $this->token($user);

        $this->withToken($old)->putJson('/api/v1/me/password', [
            'current_password' => 'salah', 'password' => 'baru-rahasia-1', 'password_confirmation' => 'baru-rahasia-1',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['current_password']]);

        $new = $this->withToken($old)->putJson('/api/v1/me/password', [
            'current_password' => 'rahasia123', 'password' => 'baru-rahasia-1', 'password_confirmation' => 'baru-rahasia-1',
        ])->assertOk()->json('token');

        $this->withToken($old)->getJson('/api/v1/me')->assertStatus(401);
        $this->withToken($new)->getJson('/api/v1/me')->assertOk();
    }

    public function test_logout_everywhere_ends_every_token(): void
    {
        $user  = $this->user();
        $token = $this->token($user);

        $this->withToken($token)->postJson('/api/v1/me/logout-all')->assertOk();
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
    }
}
