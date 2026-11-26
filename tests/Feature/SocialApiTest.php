<?php

namespace Tests\Feature;

use App\Models\HikingRoute;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use App\Services\SocialService;
use App\Services\SsoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Linimasa API (docs/API.md "Linimasa"): posts, feed, stories, likes, saves, comments, follows, reports. */
class SocialApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sso.secret' => 'test-sso-secret']);
        $this->withHeader('Accept-Language', 'id');
        Storage::fake('public');
    }

    private function signedIn(?User $user = null): User
    {
        $user ??= User::factory()->create(['name' => 'Rama']);
        $this->withToken(app(SsoService::class)->issue($user->refresh()));

        return $user;
    }

    private function makePost(User $user, array $attributes = []): Post
    {
        return Post::create(array_merge(['user_id' => $user->id, 'type' => 'status', 'body' => 'Halo dari pos 2'], $attributes));
    }

    private function route(): HikingRoute
    {
        return HikingRoute::create([
            'name' => 'Mt. Merbabu via Selo', 'gpx_file_path' => 'gpx_files/test.gpx', 'distance_km' => 11.1,
            'elevation_gain_m' => 1424, 'naismith_duration_hour' => 5.9, 'route_coordinates' => [[-7.45, 110.43, 1800], [-7.44, 110.44, 3100]],
        ]);
    }

    public function test_every_endpoint_needs_the_token(): void
    {
        $this->getJson('/api/v1/feed')->assertStatus(401)->assertJsonStructure(['error']);
        $this->postJson('/api/v1/posts', ['type' => 'status', 'body' => 'x'])->assertStatus(401);
        $this->getJson('/api/v1/users/1')->assertStatus(401);
        $this->withToken('nope')->getJson('/api/v1/stories')->assertStatus(401);
    }

    public function test_status_post_with_place_and_route_returns_the_post_json(): void
    {
        $user  = $this->signedIn();
        $route = $this->route();

        $res = $this->postJson('/api/v1/posts', [
            'type' => 'status', 'body' => "  Cerah di Sabana 1\u{0007}  ",
            'place' => json_encode(['lat' => -7.4512, 'lng' => 110.4401, 'label' => 'Sumber air']),
            'route_id' => $route->id,
        ])->assertStatus(201);

        $res->assertJsonStructure(['id', 'type', 'body', 'photo_url', 'track', 'stats', 'place', 'route', 'author' => ['id', 'name', 'bio'],
            'likes_count', 'comments_count', 'liked', 'saved', 'is_mine', 'created_at', 'expires_at', 'share_url']);
        $res->assertJson([
            'type' => 'status', 'body' => 'Cerah di Sabana 1', 'photo_url' => null, 'track' => null, 'stats' => null,
            'place' => ['lat' => -7.4512, 'lng' => 110.4401, 'label' => 'Sumber air'],
            'route' => ['id' => $route->id, 'name' => 'Mt. Merbabu via Selo'],
            'author' => ['id' => $user->id, 'name' => 'Rama'],
            'likes_count' => 0, 'liked' => false, 'saved' => false, 'is_mine' => true, 'expires_at' => null,
        ]);
        $this->assertStringEndsWith('/p/' . $res->json('id'), $res->json('share_url'));

        // A place alone is enough for a status.
        $this->postJson('/api/v1/posts', ['type' => 'status', 'place' => '{"lat": -7.4, "lng": 110.4}'])->assertStatus(201)
            ->assertJsonPath('place.label', null);
    }

    public function test_photo_post_is_reencoded_to_a_small_jpeg(): void
    {
        $this->signedIn();

        $res = $this->post('/api/v1/posts', [
            'type' => 'photo', 'body' => 'Puncak Kenteng Songo',
            'photo' => UploadedFile::fake()->image('puncak.png', 3200, 1600),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $path = Post::findOrFail($res->json('id'))->photo_path;
        $this->assertMatchesRegularExpression('#^posts/[A-Za-z0-9]{40}\.jpg$#', $path);
        Storage::disk('public')->assertExists($path);
        $info = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame([1600, 800], [$info[0], $info[1]]);
        $this->assertStringContainsString('/storage/posts/', $res->json('photo_url'));
    }

    public function test_photo_exif_is_stripped(): void
    {
        $this->signedIn();
        // A real JPEG with an APP1 Exif segment carrying a marker string right after SOI.
        $fake = UploadedFile::fake()->image('a.jpg', 40, 30); // the temp file lives as long as the object
        $jpeg = file_get_contents($fake->getRealPath());
        $exif = "Exif\0\0" . 'II*' . "\0" . pack('V', 8) . 'GPSSECRETMARKER';
        $withExif = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
        $file = UploadedFile::fake()->createWithContent('a.jpg', $withExif);

        $res = $this->post('/api/v1/posts', ['type' => 'photo', 'photo' => $file], ['Accept' => 'application/json'])->assertStatus(201);

        $stored = Storage::disk('public')->get(Post::findOrFail($res->json('id'))->photo_path);
        $this->assertStringNotContainsString('GPSSECRETMARKER', $stored);
        $this->assertStringNotContainsString('Exif', $stored);
    }

    public function test_non_images_are_rejected_by_content_not_name(): void
    {
        $this->signedIn();

        $this->post('/api/v1/posts', [
            'type' => 'photo', 'photo' => UploadedFile::fake()->createWithContent('foto.jpg', '<?php echo "hi";'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonStructure(['error', 'errors' => ['photo']]);

        $this->post('/api/v1/posts', [
            'type' => 'photo', 'photo' => UploadedFile::fake()->create('big.jpg', SocialService::PHOTO_MAX_KB + 10, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonStructure(['errors' => ['photo']]);

        $this->assertSame(0, Post::count());
        $this->assertSame([], Storage::disk('public')->allFiles('posts'));
    }

    public function test_recording_post_keeps_track_and_stats_and_thins_long_tracks(): void
    {
        $this->signedIn();
        $track = [];
        for ($i = 0; $i < 1500; $i++) {
            $track[] = [-7.45 + $i * 0.0001, 110.43 + $i * 0.0001];
        }

        $res = $this->postJson('/api/v1/posts', [
            'type' => 'recording', 'body' => 'Merbabu via Selo',
            'track' => json_encode($track),
            'stats' => json_encode(['distance_km' => 9.4, 'gain_m' => 1320, 'moving_s' => 18600]),
        ])->assertStatus(201)->assertJsonPath('stats', ['distance_km' => 9.4, 'gain_m' => 1320, 'moving_s' => 18600]);

        $this->assertCount(SocialService::TRACK_MAX, $res->json('track'));
        $this->assertSame([-7.45, 110.43], $res->json('track.0'));
    }

    public function test_story_expires_after_24_hours(): void
    {
        $this->signedIn();

        $res = $this->postJson('/api/v1/posts', ['type' => 'story', 'body' => 'Kabut tebal di pos 3'])->assertStatus(201);
        $expires = \Illuminate\Support\Carbon::parse($res->json('expires_at'));
        $this->assertEqualsWithDelta(now()->addHours(24)->getTimestamp(), $expires->getTimestamp(), 5);
    }

    public function test_validation_rules_per_type(): void
    {
        $this->signedIn();
        $route = '/api/v1/posts';

        $this->postJson($route, ['type' => 'video'])->assertStatus(422)->assertJsonStructure(['errors' => ['type']]);
        $this->postJson($route, ['type' => 'status', 'body' => "   \n "])->assertStatus(422)->assertJsonStructure(['errors' => ['body']]);
        $this->postJson($route, ['type' => 'status', 'body' => str_repeat('a', 2001)])->assertStatus(422)->assertJsonStructure(['errors' => ['body']]);
        $this->postJson($route, ['type' => 'photo', 'body' => 'tanpa foto'])->assertStatus(422)->assertJsonStructure(['errors' => ['photo']]);
        $this->postJson($route, ['type' => 'recording', 'track' => '[[-7.4, 110.4]]'])->assertStatus(422)->assertJsonStructure(['errors' => ['track']]);
        $this->postJson($route, ['type' => 'recording', 'track' => 'bukan json'])->assertStatus(422)->assertJsonStructure(['errors' => ['track']]);
        $this->postJson($route, ['type' => 'recording', 'track' => '[[-7.4, 110.4], [95, 110.4]]'])->assertStatus(422);
        $this->postJson($route, ['type' => 'story'])->assertStatus(422)->assertJsonStructure(['errors' => ['body']]);
        $this->postJson($route, ['type' => 'status', 'place' => '{"lat": "x"}'])->assertStatus(422)->assertJsonStructure(['errors' => ['place']]);
        $this->postJson($route, ['type' => 'status', 'body' => 'x', 'route_id' => 999])->assertStatus(422)->assertJsonStructure(['errors' => ['route_id']]);
        $this->postJson($route, ['type' => 'status', 'body' => 'x', 'client_id' => 'bad id with spaces'])->assertStatus(422)->assertJsonStructure(['errors' => ['client_id']]);

        $this->assertSame(0, Post::count());
    }

    public function test_same_client_id_returns_the_same_post(): void
    {
        $user = $this->signedIn();
        $payload = ['type' => 'status', 'body' => 'Dari outbox', 'client_id' => 'outbox-7f3a:1'];

        $first  = $this->postJson('/api/v1/posts', $payload)->assertStatus(201);
        $second = $this->postJson('/api/v1/posts', $payload)->assertStatus(200);

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, Post::where('user_id', $user->id)->count());

        // A retry after the post was deleted is refused (the app drops it from the outbox).
        Post::findOrFail($first->json('id'))->delete();
        $this->postJson('/api/v1/posts', $payload)->assertStatus(422)->assertJsonStructure(['errors' => ['client_id']]);

        // Another account may use the same outbox id.
        $this->signedIn(User::factory()->create());
        $this->postJson('/api/v1/posts', $payload)->assertStatus(201);
    }

    public function test_daily_post_limit_and_rate_limit_headers(): void
    {
        $user = $this->signedIn();

        $this->postJson('/api/v1/posts', ['type' => 'status', 'body' => 'satu'])->assertStatus(201)
            ->assertHeader('X-RateLimit-Limit', '10')->assertHeader('X-RateLimit-Remaining', '9');
        $this->getJson('/api/v1/feed')->assertOk()->assertHeader('X-RateLimit-Limit', '60');

        RateLimiter::increment('social-post-day:' . $user->id, 86400, SocialService::POSTS_PER_DAY);
        $this->postJson('/api/v1/posts', ['type' => 'status', 'body' => 'dua'])
            ->assertStatus(429)->assertJsonStructure(['error'])->assertHeader('Retry-After');
    }

    public function test_feed_scopes_and_cursor_pages(): void
    {
        $me       = User::factory()->create();
        $friend   = User::factory()->create();
        $stranger = User::factory()->create();
        DB::table('follows')->insert(['follower_id' => $me->id, 'followed_id' => $friend->id, 'created_at' => now()]);

        $mine     = $this->makePost($me);
        $friends  = $this->makePost($friend);
        $other    = $this->makePost($stranger);
        $this->makePost($friend, ['type' => 'story', 'expires_at' => now()->addHours(5)]);
        $this->makePost($friend, ['body' => 'dihapus'])->delete();

        $this->signedIn($me);
        $ids = collect($this->getJson('/api/v1/feed')->assertOk()->assertJsonPath('next_cursor', null)->json('data'))->pluck('id')->all();
        $this->assertSame([$friends->id, $mine->id], $ids);

        $all = collect($this->getJson('/api/v1/feed?scope=all')->json('data'))->pluck('id')->all();
        $this->assertSame([$other->id, $friends->id, $mine->id], $all);

        // 25 more posts: 20 on the first page, the rest after the cursor, no overlap.
        for ($i = 0; $i < 25; $i++) {
            $this->makePost($me, ['body' => "catatan $i"]);
        }
        $page1 = $this->getJson('/api/v1/feed')->assertOk();
        $this->assertCount(20, $page1->json('data'));
        $this->assertNotNull($cursor = $page1->json('next_cursor'));
        $page2 = $this->getJson('/api/v1/feed?cursor=' . urlencode($cursor))->assertOk()->assertJsonPath('next_cursor', null);
        $this->assertCount(7, $page2->json('data'));
        $this->assertEmpty(array_intersect(collect($page1->json('data'))->pluck('id')->all(), collect($page2->json('data'))->pluck('id')->all()));
    }

    public function test_stories_group_by_author_and_respect_expiry(): void
    {
        $me       = User::factory()->create(['name' => 'Aku']);
        $friend   = User::factory()->create(['name' => 'Teman']);
        $stranger = User::factory()->create();
        DB::table('follows')->insert(['follower_id' => $me->id, 'followed_id' => $friend->id, 'created_at' => now()]);

        $mineStory   = $this->makePost($me, ['type' => 'story', 'expires_at' => now()->addHours(2)]);
        $friendOld   = $this->makePost($friend, ['type' => 'story', 'expires_at' => now()->addHours(3)]);
        $friendNew   = $this->makePost($friend, ['type' => 'story', 'expires_at' => now()->addHours(20)]);
        $this->makePost($friend, ['type' => 'story', 'expires_at' => now()->subMinute()]);
        $this->makePost($stranger, ['type' => 'story', 'expires_at' => now()->addHours(5)]);

        $this->signedIn($me);
        $data = $this->getJson('/api/v1/stories')->assertOk()->json('data');
        $this->assertSame([$me->id, $friend->id], array_column(array_column($data, 'author'), 'id'));
        $this->assertSame([$mineStory->id], array_column($data[0]['items'], 'id'));
        $this->assertSame([$friendOld->id, $friendNew->id], array_column($data[1]['items'], 'id'));

        // After 4 hours only the newest one is left; an expired story is 404 everywhere.
        $this->travel(4)->hours();
        $data = $this->getJson('/api/v1/stories')->json('data');
        $this->assertSame([$friend->id], array_column(array_column($data, 'author'), 'id'));
        $this->getJson('/api/v1/posts/' . $friendOld->id)->assertStatus(404)->assertJsonStructure(['error']);
        $ids = collect($this->getJson('/api/v1/users/' . $friend->id . '/posts')->json('data'))->pluck('id')->all();
        $this->assertSame([$friendNew->id], $ids);
        $this->getJson('/api/v1/feed?scope=all')->assertJsonCount(0, 'data');
    }

    public function test_likes_saves_and_comments_keep_their_counts(): void
    {
        $author = User::factory()->create();
        $post   = $this->makePost($author);
        $me     = $this->signedIn();

        $this->postJson("/api/v1/posts/{$post->id}/like")->assertOk()->assertExactJson(['liked' => true, 'likes_count' => 1]);
        $this->postJson("/api/v1/posts/{$post->id}/like")->assertOk()->assertExactJson(['liked' => true, 'likes_count' => 1]);
        $this->deleteJson("/api/v1/posts/{$post->id}/like")->assertOk()->assertExactJson(['liked' => false, 'likes_count' => 0]);
        $this->deleteJson("/api/v1/posts/{$post->id}/like")->assertOk()->assertExactJson(['liked' => false, 'likes_count' => 0]);
        $this->postJson("/api/v1/posts/{$post->id}/like")->assertOk();
        $this->assertSame(1, $post->fresh()->likes_count);

        $this->postJson("/api/v1/posts/{$post->id}/save")->assertOk()->assertExactJson(['saved' => true]);
        $this->getJson("/api/v1/posts/{$post->id}")->assertOk()->assertJson(['liked' => true, 'saved' => true, 'is_mine' => false, 'likes_count' => 1]);
        $this->getJson('/api/v1/me/saved')->assertOk()->assertJsonPath('data.0.id', $post->id);
        $this->deleteJson("/api/v1/posts/{$post->id}/save")->assertOk()->assertExactJson(['saved' => false]);
        $this->getJson('/api/v1/me/saved')->assertJsonCount(0, 'data');

        $first = $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'Mantap, airnya masih ada?'])->assertStatus(201)
            ->assertJsonStructure(['id', 'body', 'author' => ['id', 'name'], 'created_at', 'is_mine'])
            ->assertJson(['is_mine' => true, 'author' => ['id' => $me->id]]);
        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'Satu lagi'])->assertStatus(201);
        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => '   '])->assertStatus(422);
        $this->postJson("/api/v1/posts/{$post->id}/comments", ['body' => str_repeat('b', 1001)])->assertStatus(422);
        $this->assertSame(2, $post->fresh()->comments_count);

        $list = $this->getJson("/api/v1/posts/{$post->id}/comments")->assertOk();
        $this->assertSame(['Mantap, airnya masih ada?', 'Satu lagi'], array_column($list->json('data'), 'body'));

        $this->deleteJson('/api/v1/comments/' . $first->json('id'))->assertOk();
        $this->deleteJson('/api/v1/comments/' . $first->json('id'))->assertStatus(404);
        $this->assertSame(1, $post->fresh()->comments_count);
    }

    public function test_follow_unfollow_lists_and_no_self_follow(): void
    {
        $other = User::factory()->create(['name' => 'Sari', 'bio' => 'Pendaki Lawu']);
        $me    = $this->signedIn();

        $this->postJson("/api/v1/users/{$other->id}/follow")->assertOk()->assertExactJson(['following' => true, 'followers_count' => 1]);
        $this->postJson("/api/v1/users/{$other->id}/follow")->assertOk()->assertExactJson(['following' => true, 'followers_count' => 1]);
        $this->postJson("/api/v1/users/{$me->id}/follow")->assertStatus(422)->assertJsonStructure(['error']);

        $this->getJson("/api/v1/users/{$other->id}")->assertOk()->assertExactJson([
            'id' => $other->id, 'name' => 'Sari', 'bio' => 'Pendaki Lawu', 'followers_count' => 1, 'following_count' => 0,
            'posts_count' => 0, 'is_following' => true, 'follows_me' => false, 'is_me' => false,
        ]);
        $this->getJson("/api/v1/users/{$me->id}")->assertJson(['is_me' => true, 'following_count' => 1]);
        $this->getJson("/api/v1/users/{$other->id}/followers")->assertOk()
            ->assertJsonPath('data.0.id', $me->id)->assertJsonPath('data.0.is_following', false)->assertJsonPath('next_cursor', null);
        $this->getJson("/api/v1/users/{$me->id}/following")->assertJsonPath('data.0', ['id' => $other->id, 'name' => 'Sari', 'bio' => 'Pendaki Lawu', 'is_following' => true]);

        $this->deleteJson("/api/v1/users/{$other->id}/follow")->assertOk()->assertExactJson(['following' => false, 'followers_count' => 0]);
        $this->deleteJson("/api/v1/users/{$other->id}/follow")->assertOk()->assertExactJson(['following' => false, 'followers_count' => 0]);
        $this->getJson('/api/v1/users/99999')->assertStatus(404);
    }

    public function test_user_search_needs_two_characters(): void
    {
        User::factory()->create(['name' => 'Rama Danadipa']);
        User::factory()->create(['name' => 'Sari']);
        $this->signedIn(User::factory()->create(['name' => 'Budi']));

        $this->getJson('/api/v1/users/search?q=r')->assertStatus(422)->assertJsonStructure(['error', 'errors' => ['q']]);
        $this->getJson('/api/v1/users/search?q=dana')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Rama Danadipa')->assertJsonStructure(['data' => [['id', 'name', 'bio', 'is_following']]]);
        $this->getJson('/api/v1/users/search?q=' . urlencode('%_'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_only_the_owner_deletes_a_post_and_comment_rights(): void
    {
        $owner    = User::factory()->create();
        $stranger = User::factory()->create();
        $post     = $this->makePost($owner, ['photo_path' => 'posts/x.jpg']);
        Storage::disk('public')->put('posts/x.jpg', 'jpeg');
        $comment  = PostComment::create(['post_id' => $post->id, 'user_id' => $stranger->id, 'body' => 'hai']);

        $this->signedIn($stranger);
        $this->deleteJson("/api/v1/posts/{$post->id}")->assertStatus(403)->assertJsonStructure(['error']);

        // The post owner may remove comments on their post; a third person may not.
        $this->signedIn(User::factory()->create());
        $this->deleteJson("/api/v1/comments/{$comment->id}")->assertStatus(403);
        $this->signedIn($owner);
        $this->deleteJson("/api/v1/comments/{$comment->id}")->assertOk();

        $this->deleteJson("/api/v1/posts/{$post->id}")->assertOk();
        $this->assertSoftDeleted($post);
        Storage::disk('public')->assertMissing('posts/x.jpg');
        $this->getJson("/api/v1/posts/{$post->id}")->assertStatus(404);
        $this->postJson("/api/v1/posts/{$post->id}/like")->assertStatus(404);
        $this->getJson('/api/v1/feed?scope=all')->assertJsonCount(0, 'data');
    }

    public function test_report_once_per_user_and_not_your_own(): void
    {
        $owner = User::factory()->create();
        $post  = $this->makePost($owner);

        $this->signedIn($owner);
        $this->postJson("/api/v1/posts/{$post->id}/report")->assertStatus(422);

        $this->signedIn();
        $this->postJson("/api/v1/posts/{$post->id}/report", ['reason' => 'Spam'])->assertOk()->assertExactJson(['reported' => true]);
        $this->postJson("/api/v1/posts/{$post->id}/report", ['reason' => 'Spam lagi'])->assertOk();
        $this->assertSame(1, DB::table('post_reports')->where('post_id', $post->id)->count());
        $this->assertNotNull($post->fresh()->reported_at);
        // Still visible until an admin decides.
        $this->getJson("/api/v1/posts/{$post->id}")->assertOk();
    }

    public function test_profile_accepts_a_bio(): void
    {
        $user = $this->signedIn(User::factory()->create(['name' => 'Rama', 'email' => 'rama@example.com']));

        $this->putJson('/api/v1/me/profile', ['bio' => "Suka jalur sepi\u{0000} di Jawa Tengah"])->assertOk()
            ->assertJsonPath('user.bio', 'Suka jalur sepi di Jawa Tengah')->assertJsonPath('user.name', 'Rama');
        $this->putJson('/api/v1/me/profile', ['bio' => str_repeat('x', 301)])->assertStatus(422)->assertJsonStructure(['errors' => ['bio']]);
        $this->putJson('/api/v1/me/profile', ['name' => 'Rama D', 'email' => 'rama@example.com', 'bio' => null])->assertOk()->assertJsonPath('user.bio', null);
        $this->putJson('/api/v1/me/profile', ['email' => 'rama@example.com'])->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);
        $this->assertSame('Rama D', $user->fresh()->name);
    }

    public function test_text_is_stored_plain(): void
    {
        $this->signedIn();
        $res = $this->postJson('/api/v1/posts', ['type' => 'status', 'body' => '<script>alert(1)</script> & co'])->assertStatus(201);
        $this->assertSame('<script>alert(1)</script> & co', $res->json('body'));
    }
}
