<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\HikingRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuestLikeTest extends TestCase
{
    use RefreshDatabase;

    private function route(): HikingRoute
    {
        return HikingRoute::create([
            'name'                   => 'Mt. Merbabu via Selo',
            'gpx_file_path'          => 'gpx_files/selo.gpx',
            'distance_km'            => 11.1,
            'elevation_gain_m'       => 1424,
            'naismith_duration_hour' => 5.9,
            'average_grade_pct'      => 12.8,
            'narrative_text'         => 'Total kenaikan elevasi 1424 meter dengan ketinggian dari 1811m hingga 3128m.',
        ]);
    }

    private function like(HikingRoute $route, ?string $visitor = null, ?int $liked = null)
    {
        // JSON test requests only carry cookies with credentials (like fetch's same-origin).
        $request = $visitor ? $this->withCredentials()->withCookie('rs_vid', $visitor) : $this;

        return $request->postJson(route('routes.like.toggle', $route), $liked === null ? [] : ['liked' => $liked]);
    }

    public function test_guest_can_like_without_login_and_gets_a_visitor_cookie(): void
    {
        $route = $this->route();

        $response = $this->like($route);

        $response->assertOk()->assertJson(['liked' => true, 'count' => 1]);
        $visitor = $response->getCookie('rs_vid')?->getValue();
        $this->assertTrue(Str::isUuid($visitor));
        $this->assertTrue($response->getCookie('rs_vid')->isHttpOnly());
        $this->assertDatabaseHas('favorites', ['hiking_route_id' => $route->id, 'user_id' => null, 'visitor_id' => $visitor]);
    }

    public function test_guest_can_unlike_with_the_same_visitor_cookie(): void
    {
        $route   = $this->route();
        $visitor = (string) Str::uuid();

        $this->like($route, $visitor)->assertJson(['liked' => true, 'count' => 1]);
        $this->like($route, $visitor)->assertJson(['liked' => false, 'count' => 0])->assertCookieMissing('rs_vid');

        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_repeated_clicks_with_an_explicit_state_are_idempotent(): void
    {
        $route   = $this->route();
        $visitor = (string) Str::uuid();

        $this->like($route, $visitor, 1)->assertJson(['liked' => true, 'count' => 1]);
        $this->like($route, $visitor, 1)->assertJson(['liked' => true, 'count' => 1]);
        $this->assertSame(1, Favorite::where('visitor_id', $visitor)->count());

        $this->like($route, $visitor, 0)->assertJson(['liked' => false, 'count' => 0]);
        $this->like($route, $visitor, 0)->assertJson(['liked' => false, 'count' => 0]);
        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_logged_in_user_likes_by_user_id(): void
    {
        $route = $this->route();
        $user  = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('routes.like.toggle', $route));

        $response->assertOk()->assertJson(['liked' => true, 'count' => 1])->assertCookieMissing('rs_vid');
        $this->assertDatabaseHas('favorites', ['hiking_route_id' => $route->id, 'user_id' => $user->id, 'visitor_id' => null]);
        $this->assertTrue($route->isLikedBy($user->id));

        $this->actingAs($user)->postJson(route('routes.like.toggle', $route), ['liked' => 1])->assertJson(['count' => 1]);
        $this->actingAs($user)->postJson(route('routes.like.toggle', $route))->assertJson(['liked' => false, 'count' => 0]);
    }

    public function test_total_counts_users_and_visitors_and_is_shown_on_pages(): void
    {
        $route = $this->route();
        $this->like($route, (string) Str::uuid());
        $this->like($route, (string) Str::uuid());
        $this->actingAs(User::factory()->create())->postJson(route('routes.like.toggle', $route))->assertJson(['count' => 3]);
        auth()->logout();

        $this->assertSame(3, $route->fresh()->likes_count);
        $this->get(route('routes.show', $route))->assertOk()
            ->assertSee('x-text="count.toLocaleString(document.documentElement.lang)">3</span>', false)
            ->assertSee('Suka jalur ini');
        $this->get(route('routes.index'))->assertOk()->assertSee('Jumlah suka');
    }

    public function test_show_page_remembers_the_visitor_like_and_form_post_redirects_back(): void
    {
        $route   = $this->route();
        $visitor = (string) Str::uuid();

        $this->withCookie('rs_vid', $visitor)
            ->from(route('routes.show', $route))
            ->post(route('routes.like.toggle', $route), ['liked' => 1])
            ->assertRedirect(route('routes.show', $route))
            ->assertSessionHas('success');

        $this->withCookie('rs_vid', $visitor)->get(route('routes.show', $route))
            ->assertOk()
            ->assertSee('data-liked="true"', false);
        $this->withCookie('rs_vid', (string) Str::uuid())->get(route('routes.show', $route))->assertSee('data-liked="false"', false);
    }

    public function test_likes_are_rate_limited(): void
    {
        $route   = $this->route();
        $visitor = (string) Str::uuid();

        for ($i = 0; $i < 30; $i++) {
            $this->like($route, $visitor)->assertOk();
        }
        $this->like($route, $visitor)->assertStatus(429);
    }
}
