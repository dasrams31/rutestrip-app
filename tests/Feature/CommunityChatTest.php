<?php

namespace Tests\Feature;

use App\Http\Controllers\CommunityChatController;
use App\Models\CommunityMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class CommunityChatTest extends TestCase
{
    use RefreshDatabase;

    private function send(User $user, string $body)
    {
        return $this->actingAs($user)->postJson(route('community.chat.store'), ['body' => $body]);
    }

    private function message(User $user, string $body = 'Halo', array $attributes = []): CommunityMessage
    {
        $message = CommunityMessage::create(['user_id' => $user->id, 'body' => $body]);
        if ($attributes) {
            $message->forceFill($attributes)->save();
        }

        return $message;
    }

    public function test_guests_get_json_401_and_the_sign_in_panel(): void
    {
        $this->getJson(route('community.chat.index'))->assertUnauthorized();
        $this->get(route('community.chat.index'))->assertUnauthorized()->assertJsonStructure(['message']); // no login redirect
        $this->getJson(route('community.chat.latest'))->assertUnauthorized();
        $this->postJson(route('community.chat.store'), ['body' => 'Halo'])->assertUnauthorized();
        $this->deleteJson(route('community.chat.destroy', 1))->assertUnauthorized();
        $this->postJson(route('community.chat.report', 1))->assertUnauthorized();
        $this->assertSame(0, CommunityMessage::count());

        $this->get('/')
            ->assertOk()
            ->assertSee('data-panel="community-guest"', false)
            ->assertSee('Obrolan Pendaki')
            ->assertSee('Masuk atau daftar dulu untuk ngobrol dengan sesama pendaki')
            ->assertSee(route('community.login', ['to' => 'register', 'back' => '/']))
            ->assertSee(route('community.login', ['back' => '/']))
            ->assertDontSee('data-panel="community"', false)
            ->assertDontSee(route('community.chat.index'), false)
            ->assertDontSee('?embed=1', false);
    }

    public function test_logged_in_user_gets_the_chat_panel(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('data-panel="community"', false)
            ->assertSee('Obrolan Umum')
            ->assertSee('Belum ada obrolan. Sapa pendaki lain duluan.')
            ->assertSee('maxlength="500"', false)
            ->assertSee(json_encode(route('community.chat.index')), false)
            ->assertDontSee('data-panel="community-guest"', false)
            ->assertDontSee('?embed=1', false);
    }

    public function test_send_and_receive(): void
    {
        $ana  = User::factory()->create(['name' => 'Ana Pendaki']);
        $budi = User::factory()->create(['name' => 'Budi']);

        $this->send($ana, "  Ada yang mau ke Merbabu minggu depan?\x07  ")
            ->assertCreated()
            ->assertJsonPath('message.name', 'Ana Pendaki')
            ->assertJsonPath('message.mine', true)
            ->assertJsonPath('message.body', 'Ada yang mau ke Merbabu minggu depan?')
            ->assertJsonPath('message.can_delete', true)
            ->assertJsonPath('message.can_report', false);

        $this->actingAs($budi)->getJson(route('community.chat.index'))
            ->assertOk()
            ->assertJsonPath('room', 'umum')
            ->assertJsonPath('has_more', false)
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.name', 'Ana Pendaki')
            ->assertJsonPath('messages.0.mine', false)
            ->assertJsonPath('messages.0.can_delete', false)
            ->assertJsonPath('messages.0.can_report', true)
            ->assertJsonPath('messages.0.body', 'Ada yang mau ke Merbabu minggu depan?');

        $this->assertDatabaseHas('community_messages', ['user_id' => $ana->id, 'room' => 'umum']);
        $this->actingAs($budi)->getJson(route('community.chat.index', ['room' => 'lain']))->assertUnprocessable();
    }

    public function test_after_returns_only_newer_messages(): void
    {
        $user = User::factory()->create();
        [$a, $b, $c] = [$this->message($user, 'satu'), $this->message($user, 'dua'), $this->message($user, 'tiga')];

        $this->actingAs($user)->getJson(route('community.chat.index', ['after' => $a->id]))
            ->assertOk()
            ->assertJsonPath('messages.*.id', [$b->id, $c->id])
            ->assertJsonPath('deleted', []);

        $this->getJson(route('community.chat.index', ['after' => $c->id]))->assertOk()->assertJsonCount(0, 'messages');
    }

    public function test_before_pages_through_older_messages_30_at_a_time(): void
    {
        $user = User::factory()->create();
        $ids  = [];
        for ($i = 1; $i <= 65; $i++) {
            $ids[] = $this->message($user, "pesan {$i}")->id;
        }

        $first = $this->actingAs($user)->getJson(route('community.chat.index'))->assertOk()->assertJsonPath('has_more', true);
        $this->assertSame(array_slice($ids, 35, 30), $first->json('messages.*.id')); // newest 30, oldest first

        $second = $this->getJson(route('community.chat.index', ['before' => $ids[35]]))->assertJsonPath('has_more', true);
        $this->assertSame(array_slice($ids, 5, 30), $second->json('messages.*.id'));

        $third = $this->getJson(route('community.chat.index', ['before' => $ids[5]]))->assertJsonPath('has_more', false);
        $this->assertSame(array_slice($ids, 0, 5), $third->json('messages.*.id'));
    }

    public function test_messages_older_than_30_days_are_hidden(): void
    {
        $user = User::factory()->create();
        $this->message($user, 'lama', ['created_at' => now()->subDays(31)]);
        $recent = $this->message($user, 'baru');

        $this->actingAs($user)->getJson(route('community.chat.index'))
            ->assertJsonPath('messages.*.id', [$recent->id])
            ->assertJsonPath('has_more', false);
    }

    public function test_length_empty_and_cleanup_rules(): void
    {
        $user = User::factory()->create();

        $this->send($user, str_repeat('a', 500))->assertCreated();
        $this->send($user, str_repeat('a', 501))->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->send($user, " \n\t \u{00A0} ")->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->send($user, "\x01\x02\x7F\u{202E}")->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->actingAs($user)->postJson(route('community.chat.store'), [])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->actingAs($user)->postJson(route('community.chat.store'), ['body' => ['x']])->assertUnprocessable();

        $this->send($user, "Baris satu   \r\n\r\n\r\n\r\n\r\n\r\nBaris dua\u{202E}\x1B\n\n\nBaris tiga")
            ->assertCreated()
            ->assertJsonPath('message.body', "Baris satu\n\n\n\nBaris dua\n\n\nBaris tiga");

        $this->assertSame(2, CommunityMessage::count());
    }

    public function test_rate_limits_6_per_30_seconds_and_200_per_day(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();

        for ($i = 1; $i <= 6; $i++) {
            $this->send($user, "pesan {$i}")->assertCreated();
        }
        $this->send($user, 'satu lagi')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('error', 'throttled');
        $this->send($other, 'saya masih boleh')->assertCreated();

        // Rejected messages do not count.
        $this->travel(31)->seconds();
        $this->send($user, '')->assertUnprocessable();
        $this->send($user, 'sudah boleh lagi')->assertCreated();

        RateLimiter::clear('community-chat:burst:' . $user->id);
        for ($i = 0; $i < 193; $i++) {
            RateLimiter::hit('community-chat:day:' . $user->id, 86400);
        }
        $this->send($user, 'besok saja')->assertStatus(429)->assertJsonPath('message', 'Batas pesan hari ini sudah tercapai. Lanjut ngobrol besok ya.');
        $this->assertSame(8, CommunityMessage::count());
    }

    public function test_text_is_escaped_and_only_https_links_are_linked(): void
    {
        $user = User::factory()->create();
        $raw  = "<script>alert(1)</script> <b>tebal</b> \"kutip\" lihat https://example.com/jalur?a=1&b=2. bukan http://insecure.test atau javascript:alert(1)";

        $res = $this->send($user, $raw)->assertCreated()->assertJsonPath('message.body', $raw);
        $html = $res->json('message.html');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &lt;b&gt;tebal&lt;/b&gt; &quot;kutip&quot;', $html);
        $this->assertStringContainsString('<a href="https://example.com/jalur?a=1&amp;b=2" target="_blank" rel="noopener nofollow ugc">https://example.com/jalur?a=1&amp;b=2</a>.', $html);
        $this->assertStringNotContainsString('href="http://', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertSame(1, substr_count($html, '<a '));

        // Stored as plain text.
        $this->assertSame($raw, CommunityMessage::first()->body);

        $this->assertSame('https:// &lt;i&gt;', CommunityChatController::render('https:// <i>'));
        $this->assertSame('<a href="https://a.test/x" target="_blank" rel="noopener nofollow ugc">https://a.test/x</a>&quot;&gt;', CommunityChatController::render('https://a.test/x">'));
    }

    public function test_users_delete_their_own_messages_only(): void
    {
        $ana  = User::factory()->create();
        $budi = User::factory()->create();
        $mine = $this->message($ana, 'punya Ana');

        $this->actingAs($budi)->deleteJson(route('community.chat.destroy', $mine))->assertForbidden();
        $this->assertNotSoftDeleted($mine);

        $this->actingAs($ana)->deleteJson(route('community.chat.destroy', $mine))
            ->assertOk()
            ->assertJsonPath('message.deleted', true)
            ->assertJsonPath('message.body', null)
            ->assertJsonPath('message.html', null)
            ->assertJsonPath('message.can_delete', false);
        $this->assertSoftDeleted($mine);

        $this->actingAs($ana)->deleteJson(route('community.chat.destroy', $mine))->assertNotFound();
    }

    public function test_admins_can_delete_any_message(): void
    {
        $user  = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $msg   = $this->message($user, 'pesan kasar');

        $this->actingAs($admin)->getJson(route('community.chat.index'))
            ->assertJsonPath('messages.0.can_delete', true)
            ->assertJsonPath('messages.0.can_report', true);

        $this->actingAs($admin)->deleteJson(route('community.chat.destroy', $msg))->assertOk()->assertJsonPath('message.deleted', true);
        $this->assertSoftDeleted($msg);
    }

    public function test_soft_deleted_messages_render_as_deleted_and_reach_open_panels(): void
    {
        $ana    = User::factory()->create(['name' => 'Ana']);
        $before = $this->message($ana, 'pertama');
        $gone   = $this->message($ana, 'rahasia');
        $gone->delete();

        $this->actingAs(User::factory()->create())->getJson(route('community.chat.index'))
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.1.id', $gone->id)
            ->assertJsonPath('messages.1.deleted', true)
            ->assertJsonPath('messages.1.name', 'Ana')
            ->assertJsonPath('messages.1.body', null)
            ->assertJsonPath('messages.1.html', null)
            ->assertJsonPath('messages.1.can_report', false)
            ->assertDontSee('rahasia');

        // A panel that already shows the message learns about the deletion on its next poll.
        $this->getJson(route('community.chat.index', ['after' => $gone->id]))->assertJsonPath('deleted', [$gone->id]);
        $this->travel(5)->minutes();
        $this->getJson(route('community.chat.index', ['after' => $gone->id]))->assertJsonPath('deleted', []);
        $this->assertNotSoftDeleted($before);
    }

    public function test_reporting_flags_a_message_for_admins(): void
    {
        $ana   = User::factory()->create();
        $budi  = User::factory()->create();
        $citra = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $msg   = $this->message($ana, 'spam');

        $this->actingAs($ana)->postJson(route('community.chat.report', $msg))->assertUnprocessable()->assertJsonPath('error', 'own_message');
        $this->assertNull($msg->fresh()->reported_at);

        $this->actingAs($budi)->postJson(route('community.chat.report', $msg))
            ->assertOk()
            ->assertJsonPath('reported', true)
            ->assertJsonPath('message.reported', true);
        $this->assertNotNull($msg->fresh()->reported_at);
        $this->assertSame($budi->id, (int) $msg->fresh()->reported_by);

        // Reporting again keeps the first reporter.
        $this->actingAs($citra)->postJson(route('community.chat.report', $msg))->assertOk();
        $this->assertSame($budi->id, (int) $msg->fresh()->reported_by);

        $this->actingAs($admin)->getJson(route('community.chat.index'))->assertJsonPath('messages.0.reported', true);
        $this->actingAs($budi)->getJson(route('community.chat.index'))->assertJsonPath('messages.0.reported', true);
        $this->actingAs($citra)->getJson(route('community.chat.index'))->assertJsonPath('messages.0.reported', false);
        $this->actingAs($ana)->getJson(route('community.chat.index'))->assertJsonPath('messages.0.reported', false);
    }

    public function test_latest_returns_the_newest_id_from_other_people(): void
    {
        $ana  = User::factory()->create();
        $budi = User::factory()->create();

        $this->actingAs($ana)->getJson(route('community.chat.latest'))->assertExactJson(['last_id' => 0]);

        $fromBudi = $this->message($budi, 'halo Ana');
        $this->message($ana, 'halo Budi');

        $this->actingAs($ana)->getJson(route('community.chat.latest'))->assertExactJson(['last_id' => $fromBudi->id]);
    }

    public function test_sign_in_link_brings_the_guest_back_to_the_open_panel(): void
    {
        $user = User::factory()->create(['password' => bcrypt('rahasia123')]);

        $this->get(route('community.login', ['to' => 'register', 'back' => '/routes?page=2']))->assertRedirect(route('register'));
        $this->get(route('community.login', ['back' => '/routes?page=2']))->assertRedirect(route('login'));
        $this->assertSame(url('/routes?page=2') . '#obrolan', session('url.intended'));

        $this->post(route('login.submit'), ['email' => $user->email, 'password' => 'rahasia123'])
            ->assertRedirect(url('/routes?page=2') . '#obrolan');

        // Logged in: straight back. The old address still works.
        $this->get(route('community.login', ['back' => '/search']))->assertRedirect(url('/search') . '#obrolan');
        $this->get(route('assistant.chat.login', ['back' => '/search']))->assertRedirect(url('/search') . '#obrolan');
    }

    public function test_sign_in_link_only_accepts_local_paths(): void
    {
        foreach (['//evil.example/x', '/\\evil.example', 'https://evil.example/', 'javascript:alert(1)', "/x\ty"] as $back) {
            $this->flushSession();
            $this->get(route('community.login', ['back' => $back]))->assertRedirect(route('login'));
            $this->assertSame(url('/') . '#obrolan', session('url.intended'), $back);
        }
    }
}
