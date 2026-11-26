<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Linimasa on the website: /linimasa, /p/{id}, /u/{id}, follower lists, saved posts, settings bio, moderation. */
class SocialWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function makePost(User $user, array $attributes = []): Post
    {
        return Post::create(array_merge(['user_id' => $user->id, 'type' => 'status', 'body' => 'Jalur licin setelah pos 3'], $attributes));
    }

    public function test_guests_can_read_post_and_profile_pages_without_actions(): void
    {
        $author = User::factory()->create(['name' => 'Sari', 'bio' => 'Pendaki <b>Lawu</b>']);
        $post   = $this->makePost($author, [
            'type' => 'recording', 'body' => '<script>alert(1)</script> Cerah',
            'track' => [[-7.45, 110.43], [-7.44, 110.44], [-7.43, 110.445]],
            'stats' => ['distance_km' => 9.4, 'gain_m' => 1320, 'moving_s' => 18600],
        ]);

        $page = $this->get('/p/' . $post->id)->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; Cerah', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('property="og:type" content="article"', false)
            ->assertSee('property="og:description"', false)
            ->assertSee('id="post-map"', false)
            ->assertSee(route('posts.login', $post->id), false);
        $page->assertDontSee(route('posts.like', $post->id), false)
            ->assertDontSee(route('posts.comments.store', $post->id), false)
            ->assertDontSee(route('posts.report', $post->id), false);

        $this->get('/u/' . $author->id)->assertOk()
            ->assertSee('Sari')->assertSee('Pendaki &lt;b&gt;Lawu&lt;/b&gt;', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee(route('profiles.follow', $author->id), false);
        $this->get('/u/' . $author->id . '/pengikut')->assertOk();
        $this->get('/u/' . $author->id . '/mengikuti')->assertOk();

        $this->get('/linimasa')->assertRedirect(route('login'));
        $this->get('/akun/disimpan')->assertRedirect(route('login'));
        $this->post('/p/' . $post->id . '/suka')->assertRedirect(route('login'));
        $this->get('/p/' . $post->id . '/masuk')->assertRedirect(route('login'));
        $this->assertStringEndsWith('/p/' . $post->id . '#komentar', session('url.intended'));
    }

    public function test_post_page_open_graph_uses_the_photo(): void
    {
        $author = User::factory()->create(['name' => 'Sari']);
        $fake = UploadedFile::fake()->image('a.jpg', 1200, 800); // the temp file lives as long as the object
        Storage::disk('public')->put('posts/abc.jpg', file_get_contents($fake->getRealPath()));
        $post = $this->makePost($author, ['type' => 'photo', 'photo_path' => 'posts/abc.jpg']);

        $this->get('/p/' . $post->id)->assertOk()
            ->assertSee('property="og:image" content="' . Storage::disk('public')->url('posts/abc.jpg') . '"', false)
            ->assertSee('property="og:image:width" content="1200"', false)
            ->assertSee('property="og:image:height" content="800"', false);
    }

    public function test_deleted_or_expired_posts_are_not_found(): void
    {
        $author  = User::factory()->create();
        $deleted = $this->makePost($author);
        $deleted->delete();
        $story = $this->makePost($author, ['type' => 'story', 'expires_at' => now()->subMinute()]);

        $this->get('/p/' . $deleted->id)->assertNotFound();
        $this->get('/p/' . $story->id)->assertNotFound();
        $this->get('/p/abc')->assertNotFound();
    }

    public function test_timeline_tabs_composer_and_more_link(): void
    {
        $me     = User::factory()->create(['name' => 'Rama']);
        $friend = User::factory()->create(['name' => 'Teman Mendaki']);
        $other  = User::factory()->create(['name' => 'Orang Lain']);
        DB::table('follows')->insert(['follower_id' => $me->id, 'followed_id' => $friend->id, 'created_at' => now()]);
        $this->makePost($friend, ['body' => 'Dari teman']);
        $this->makePost($other, ['body' => 'Dari orang lain']);
        $this->makePost($friend, ['type' => 'story', 'body' => 'Cerita teman', 'expires_at' => now()->addHour()]);

        $this->actingAs($me)->get('/linimasa')->assertOk()
            ->assertSee('Dari teman')->assertDontSee('Dari orang lain')
            ->assertSee('data-story="0"', false)->assertSee('Cerita teman')
            ->assertSee(route('posts.store'), false)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        $this->actingAs($me)->get('/linimasa?tab=semua')->assertOk()->assertSee('Dari orang lain');

        for ($i = 0; $i < 22; $i++) {
            $this->makePost($other, ['body' => "Catatan nomor $i"]);
        }
        $page = $this->actingAs($me)->get('/linimasa?tab=semua')->assertOk()->assertSee('data-more', false);
        preg_match('/href="([^"]+)" data-more/', $page->getContent(), $m);
        $next = html_entity_decode($m[1]);

        // Fetched by the "Muat lagi" script: only the next cards, no layout.
        $this->actingAs($me)->get($next, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertSee('Dari orang lain')->assertDontSee('<html', false);
    }

    public function test_web_composer_posts_status_photo_and_story(): void
    {
        $me = User::factory()->create();

        $this->actingAs($me)->post('/linimasa', ['body' => 'Status dari web'])->assertRedirect();
        $this->actingAs($me)->post('/linimasa', ['body' => 'Foto', 'photo' => UploadedFile::fake()->image('f.jpg', 800, 600)])->assertRedirect();
        $this->actingAs($me)->post('/linimasa', ['body' => 'Cerita', 'story' => '1'])->assertRedirect();
        $this->actingAs($me)->post('/linimasa', ['body' => ''])->assertSessionHasErrors('body');

        $this->assertSame(['status', 'photo', 'story'], Post::orderBy('id')->pluck('type')->all());
        $this->assertNotNull(Post::where('type', 'story')->value('expires_at'));
        Storage::disk('public')->assertExists(Post::where('type', 'photo')->value('photo_path'));
    }

    public function test_like_save_follow_forms_work_with_and_without_fetch(): void
    {
        $me     = User::factory()->create();
        $author = User::factory()->create();
        $post   = $this->makePost($author);

        $this->actingAs($me)->from('/linimasa')->post("/p/{$post->id}/suka")->assertRedirect('/linimasa#post-' . $post->id);
        $this->assertSame(1, $post->fresh()->likes_count);
        $this->actingAs($me)->deleteJson("/p/{$post->id}/suka")->assertOk()->assertExactJson(['liked' => false, 'likes_count' => 0]);

        $this->actingAs($me)->postJson("/p/{$post->id}/simpan")->assertOk()->assertExactJson(['saved' => true]);
        $this->actingAs($me)->get('/akun/disimpan')->assertOk()->assertSee('Jalur licin setelah pos 3');

        $this->actingAs($me)->postJson("/u/{$author->id}/ikuti")->assertOk()->assertExactJson(['following' => true, 'followers_count' => 1]);
        $this->actingAs($me)->get("/u/{$author->id}")->assertOk()->assertSee('aria-pressed="true"', false);
        $this->actingAs($me)->get("/u/{$author->id}/pengikut")->assertOk()->assertSee($me->name);
        $this->actingAs($me)->from("/u/{$me->id}")->post("/u/{$me->id}/ikuti")->assertSessionHasErrors('user');
        $this->assertSame(1, DB::table('follows')->count());
    }

    public function test_comments_delete_and_report_permissions_on_the_web(): void
    {
        $owner    = User::factory()->create();
        $stranger = User::factory()->create();
        $post     = $this->makePost($owner);

        $this->actingAs($stranger)->post("/p/{$post->id}/komentar", ['body' => 'Airnya ada?'])->assertRedirect();
        $comment = PostComment::firstOrFail();
        $this->assertSame(1, $post->fresh()->comments_count);
        $this->actingAs($stranger)->get("/p/{$post->id}")->assertSee('Airnya ada?')->assertSee(route('comments.destroy', $comment->id), false);

        $this->actingAs($stranger)->delete("/p/{$post->id}")->assertForbidden();
        $this->actingAs($owner)->post("/komentar/{$comment->id}/laporkan")->assertRedirect();
        $this->assertNotNull($comment->fresh()->reported_at);
        $this->actingAs($stranger)->post("/p/{$post->id}/laporkan")->assertRedirect();
        $this->assertNotNull($post->fresh()->reported_at);
        $this->actingAs($owner)->from("/p/{$post->id}")->post("/p/{$post->id}/laporkan")->assertSessionHasErrors('post');

        $this->actingAs($owner)->delete("/komentar/{$comment->id}")->assertRedirect();
        $this->assertSame(0, $post->fresh()->comments_count);
        $this->actingAs($owner)->delete("/p/{$post->id}")->assertRedirect(route('linimasa'));
        $this->assertSoftDeleted($post);
    }

    public function test_settings_page_edits_the_bio(): void
    {
        $me = User::factory()->create(['name' => 'Rama', 'email' => 'rama@example.com']);

        $this->actingAs($me)->get('/settings')->assertOk()->assertSee('name="bio"', false);
        $this->actingAs($me)->post('/settings', ['section' => 'profile', 'name' => 'Rama', 'email' => 'rama@example.com', 'bio' => "Merbabu\nlima kali"])
            ->assertRedirect(route('settings'));
        $this->assertSame('Merbabu lima kali', $me->fresh()->bio);
        $this->actingAs($me)->post('/settings', ['section' => 'profile', 'name' => 'Rama', 'email' => 'rama@example.com', 'bio' => str_repeat('a', 301)])
            ->assertSessionHasErrors('bio');
    }

    public function test_admin_moderates_reported_posts_and_comments(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $author = User::factory()->create(['name' => 'Penulis']);
        $kept   = $this->makePost($author, ['body' => 'Kiriman wajar']);
        $bad    = $this->makePost($author, ['body' => 'Kiriman spam']);
        $comment = PostComment::create(['post_id' => $kept->id, 'user_id' => $author->id, 'body' => 'Komentar spam']);
        DB::table('posts')->whereIn('id', [$kept->id, $bad->id])->update(['reported_at' => now()]);
        DB::table('post_comments')->where('id', $comment->id)->update(['reported_at' => now()]);

        $this->actingAs($admin)->get(route('admin.moderation'))->assertOk()
            ->assertSee('Kiriman wajar')->assertSee('Kiriman spam')->assertSee('Komentar spam')->assertSee('Tidak ada laporan.');

        $this->actingAs($admin)->post(route('admin.moderation.posts.dismiss', $kept->id))->assertRedirect();
        $this->actingAs($admin)->delete(route('admin.moderation.posts.destroy', $bad->id))->assertRedirect();
        $this->actingAs($admin)->delete(route('admin.moderation.comments.destroy', $comment->id))->assertRedirect();

        $this->assertNull($kept->fresh()->reported_at);
        $this->assertSoftDeleted($bad);
        $this->assertSoftDeleted($comment);
        $this->get('/p/' . $bad->id)->assertNotFound();
        $this->actingAs($admin)->get(route('admin.moderation'))->assertDontSee('Kiriman spam')->assertDontSee('Komentar spam');

        $this->actingAs($author)->delete(route('admin.moderation.posts.destroy', $kept->id))->assertForbidden();
    }

    public function test_navbar_links_to_the_timeline(): void
    {
        $this->get('/routes')->assertOk()->assertSee(route('linimasa'), false)->assertSee('Linimasa');
        $me = User::factory()->create();
        $this->actingAs($me)->get('/routes')->assertSee(route('account.saved'), false)->assertSee(route('profiles.show', $me->id), false);
    }
}
