<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CommunityMessage;
use App\Models\HikingRoute;
use App\Models\SearchHistory;
use App\Models\SearchStat;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AltchaService;
use App\Services\LaravelLogReader;
use App\Services\PythonProcessorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Admin users / activity / log / moderation pages and the activity log behind them. */
class AdminInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('id'); // sentences checked outside a request (SetLocale only runs on web requests)
    }

    private function admin(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role' => 'admin', 'name' => 'Admin Satu']);
    }

    private function hiker(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role' => 'user', 'name' => 'Rama', 'password' => 'rahasia123']);
    }

    private function route(string $name = 'Gunung Prau via Wates', ?User $by = null): HikingRoute
    {
        return HikingRoute::create(['name' => $name, 'gpx_file_path' => 'gpx_files/x.gpx', 'uploaded_by' => $by?->id]);
    }

    public function test_only_admins_can_open_the_admin_pages(): void
    {
        $hiker   = $this->hiker();
        $message = CommunityMessage::create(['user_id' => $hiker->id, 'body' => 'Halo']);

        $requests = [
            ['get', route('admin.dashboard')],
            ['get', route('admin.users.index')],
            ['get', route('admin.users.show', $hiker)],
            ['post', route('admin.users.role', $hiker)],
            ['get', route('admin.activity')],
            ['get', route('admin.logs')],
            ['get', route('admin.moderation')],
            ['delete', route('admin.moderation.destroy', $message)],
            ['post', route('admin.moderation.dismiss', $message)],
        ];

        foreach ($requests as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
            $this->actingAs($hiker)->{$method}($url)->assertForbidden();
            auth()->logout();
        }

        $this->assertSame('user', $hiker->fresh()->role);
        $this->assertFalse($message->fresh()->trashed());
    }

    public function test_admin_sees_the_four_pages_with_tabs(): void
    {
        $admin = $this->admin();
        $hiker = $this->hiker();
        $this->route('Gunung Andong via Sawit', $hiker);

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()
            ->assertSee('Daftar pengguna')->assertSee('Rama')->assertSee('aria-current="page"', false)
            ->assertSee(route('admin.moderation'));
        $this->get(route('admin.users.index', ['q' => 'rama']))->assertOk()->assertSee($hiker->email)->assertSee('1 akun cocok dengan "rama"');
        $this->get(route('admin.users.show', $hiker))->assertOk()->assertSee('Rama mengunggah Gunung Andong via Sawit')->assertSee('Jadikan admin');
        $this->get(route('admin.activity'))->assertOk()->assertSee('Paling sering dicari')->assertSee('Rama mengunggah Gunung Andong via Sawit');
        $this->get(route('admin.logs'))->assertOk()->assertSee('Gagal masuk, 24 jam')->assertSee('Galat server');
        $this->get(route('admin.moderation'))->assertOk()->assertSee('Tidak ada laporan.');
        $this->get(route('admin.dashboard'))->assertOk()->assertSee(route('admin.users.index'));
    }

    public function test_login_records_last_login_and_a_log_entry_with_anonymised_ip(): void
    {
        $user = $this->hiker(['email' => 'rama@example.com']);
        $this->assertNull($user->last_login_at);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->post(route('login.submit'), ['email' => 'rama@example.com', 'password' => 'rahasia123'])
            ->assertRedirect(route('user.dashboard'));

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertSame(1, $user->login_count);

        $log = ActivityLog::where('action', 'auth.login')->sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('form', $log->meta['via']);
        $this->assertSame('203.0.113.0', $log->ip);
        $this->assertSame('Rama masuk ke akun', $log->sentence());
    }

    public function test_sso_api_login_is_recorded_too(): void
    {
        $user = $this->hiker(['email' => 'rama@example.com']);

        $this->postJson('/api/sso/login', ['email' => 'rama@example.com', 'password' => 'rahasia123'])->assertOk();
        $this->postJson('/api/sso/login', ['email' => 'rama@example.com', 'password' => 'salah-sekali'])->assertUnauthorized();

        $this->assertSame(1, $user->fresh()->login_count);
        $this->assertSame('chat', ActivityLog::where('action', 'auth.login')->sole()->meta['via']);
        $this->assertSame('chat', ActivityLog::where('action', 'auth.failed')->sole()->meta['via']);
    }

    public function test_failed_login_logs_the_email_but_never_the_password(): void
    {
        $this->hiker(['email' => 'rama@example.com']);

        $this->from(route('login'))->post(route('login.submit'), ['email' => ' rama@example.com ', 'password' => 'tebakan-rahasia-1'])
            ->assertRedirect(route('login'));
        $this->from(route('login'))->post(route('login.submit'), ['email' => 'siapa@example.com', 'password' => 'tebakan-rahasia-2']);

        $logs = ActivityLog::where('action', 'auth.failed')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('rama@example.com', $logs[0]->meta['email']);
        $this->assertNotNull($logs[0]->user_id);
        $this->assertNull($logs[1]->user_id);
        $this->assertStringNotContainsString('tebakan-rahasia', ActivityLog::all()->toJson());

        $this->actingAs($this->admin())->get(route('admin.logs'))->assertOk()
            ->assertSee('Percobaan masuk gagal untuk rama@example.com')
            ->assertSee('127.0.0.0');
    }

    public function test_admin_form_rejecting_a_hiker_is_not_counted_as_a_sign_in(): void
    {
        $user = $this->hiker(['email' => 'rama@example.com']);

        $this->from(route('admin.login'))->post(route('admin.login.submit'), ['email' => 'rama@example.com', 'password' => 'rahasia123']);

        $this->assertSame(0, $user->fresh()->login_count);
        $this->assertSame(['auth.admin_denied'], ActivityLog::pluck('action')->all());
    }

    public function test_register_logout_and_password_change_are_logged(): void
    {
        $this->mock(AltchaService::class, fn ($m) => $m->shouldReceive('verify')->andReturn(true));

        $this->post(route('register.submit'), [
            'name' => 'Sinta', 'email' => 'sinta@example.com', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123', 'altcha' => 'x',
        ])->assertRedirect(route('user.dashboard'));
        $sinta = User::where('email', 'sinta@example.com')->sole();
        $this->assertSame(1, ActivityLog::where('action', 'auth.registered')->where('user_id', $sinta->id)->count());
        $this->assertSame('register', ActivityLog::where('action', 'auth.login')->sole()->meta['via']);

        $this->actingAs($sinta)->post(route('settings.update'), [
            'section' => 'password', 'current_password' => 'rahasia123', 'password' => 'rahasia456', 'password_confirmation' => 'rahasia456',
        ])->assertRedirect(route('settings'));
        $change = ActivityLog::where('action', 'auth.password_changed')->sole();
        $this->assertSame('self', $change->meta['by']);
        $this->assertStringNotContainsString('rahasia456', ActivityLog::all()->toJson());

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertSame('button', ActivityLog::where('action', 'auth.logout')->sole()->meta['via']);
    }

    public function test_password_set_from_the_server_is_logged_as_server(): void
    {
        $user = $this->admin(['email' => 'admin@example.test']);

        $this->artisan('user:set-password', ['email' => 'admin@example.test'])
            ->expectsQuestion('Password baru (min. 8 karakter, tidak tampil saat diketik)', 'test-pass-123')
            ->expectsQuestion('Ulangi password baru', 'test-pass-123')
            ->assertSuccessful();

        $log = ActivityLog::where('action', 'auth.password_changed')->sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('server', $log->meta['by']);
        $this->assertSame('Password Admin Satu diganti dari server', $log->sentence());
    }

    public function test_route_create_and_delete_are_logged_with_the_name(): void
    {
        $admin = $this->admin();
        $route = $this->route('Gunung Prau via Wates', $this->hiker());

        $this->actingAs($admin)->delete(route('routes.destroy', $route))->assertRedirect(route('routes.index'));

        $deleted = ActivityLog::where('action', 'route.deleted')->sole();
        $this->assertSame($admin->id, $deleted->user_id);
        $this->assertSame('Admin Satu menghapus jalur Gunung Prau via Wates', $deleted->sentence());
        $this->assertSame('Rama mengunggah Gunung Prau via Wates', ActivityLog::where('action', 'route.created')->sole()->sentence());
    }

    public function test_review_is_logged(): void
    {
        $route = $this->route();
        $this->actingAs($this->hiker())->post(route('routes.review.store', $route), ['rating' => 4, 'comment' => 'Sabana bagus'])->assertRedirect();

        $this->assertSame('Rama memberi ulasan 4 bintang untuk Gunung Prau via Wates', ActivityLog::where('action', 'review.created')->sole()->sentence());
    }

    private function fakeSearch(): void
    {
        $this->route('Gunung Prau via Wates')->forceFill(['sbert_embedding' => [1.0, 0.0, 0.0]])->save();
        $this->app->instance(PythonProcessorService::class, new class extends PythonProcessorService {
            public function getQueryEmbedding(string $query): array
            {
                return [1.0, 0.0, 0.0];
            }
        });
    }

    public function test_guest_searches_add_to_an_anonymous_daily_count(): void
    {
        $this->fakeSearch();

        $this->post(route('search.submit'), ['query' => '  Gunung   PRAU  '])->assertOk();
        $this->post(route('search.submit'), ['query' => 'gunung prau'])->assertOk();
        $this->actingAs($this->hiker())->post(route('search.submit'), ['query' => 'gunung prau'])->assertOk();

        $row = SearchStat::sole();
        $this->assertSame('gunung prau', $row->query);
        $this->assertSame(2, (int) $row->hits);
        $this->assertSame(now()->toDateString(), substr((string) $row->day, 0, 10));
        $this->assertSame(1, SearchHistory::count());
        $this->assertSame(['id', 'day', 'query', 'hits'], array_keys((array) \DB::table('search_stats')->first()));
    }

    public function test_most_searched_adds_users_and_guests(): void
    {
        $hiker = $this->hiker();
        SearchHistory::create(['user_id' => $hiker->id, 'query' => 'Gunung  Prau', 'results_count' => 3]);
        SearchHistory::create(['user_id' => $hiker->id, 'query' => 'jalur landai', 'results_count' => 3]);
        SearchStat::record('gunung prau');
        SearchStat::record('GUNUNG PRAU');
        SearchStat::record('gunung prau');

        $this->actingAs($this->admin())->get(route('admin.activity'))->assertOk()
            ->assertSeeInOrder(['Paling sering dicari', 'gunung prau', '4', '1 dari pengguna, 3 dari tamu', 'jalur landai'])
            ->assertSee('Pencarian');
    }

    public function test_log_reader_masks_secrets_and_reads_only_the_tail(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rslog');
        $filler = str_repeat("[2026-11-01 10:00:00] local.INFO: filler line\n", 8000); // ~370 KB, beyond the 256 KB window
        file_put_contents($path, "[2026-11-01 09:00:00] local.ERROR: very old error\n" . $filler
            . "[2026-11-21 08:00:00] production.ERROR: Connect failed password=hunter2 token: abc123 api_key=\"zzz\" {\"exception\":\"[object] (PDOException(code: 0): x at /var/www/a.php:10)\"}\n"
            . "#0 /var/www/vendor/x.php(12): secret stack frame\n"
            . "[2026-11-21 08:05:00] production.WARNING: Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig and APP_KEY=base64:QUJDREVGR0hJSktMTU5PUFFSU1RVVldY\n"
            . "[2026-11-21 08:06:00] production.INFO: just info\n");

        $result = (new LaravelLogReader($path))->tail(200);
        @unlink($path);

        $this->assertSame('ok', $result['state']);
        $this->assertCount(2, $result['entries']);
        [$warning, $error] = $result['entries'];
        $this->assertSame('WARNING', $warning['level']);
        $this->assertSame('2026-11-21 08:00:00', $error['time']);
        $text = json_encode($result);
        foreach (['hunter2', 'abc123', 'zzz', 'eyJhbGci', 'QUJDREVG', 'stack frame', 'PDOException', 'very old error'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertStringContainsString('password=[disembunyikan]', $error['message']);

        $this->assertSame('missing', (new LaravelLogReader(sys_get_temp_dir() . '/tidak-ada-' . uniqid() . '.log'))->tail()['state']);
    }

    public function test_log_page_handles_a_missing_log_file_and_filters(): void
    {
        $this->app->instance(LaravelLogReader::class, new LaravelLogReader(sys_get_temp_dir() . '/tidak-ada-' . uniqid() . '.log'));
        $admin = $this->admin();
        $hiker = $this->hiker();
        app(ActivityLogger::class)->log('auth.logout', $hiker);
        app(ActivityLogger::class)->log('auth.logout', $admin);

        $this->actingAs($admin)->get(route('admin.logs'))->assertOk()->assertSee('File log belum ada.');
        $this->get(route('admin.logs', ['user' => 'rama']))->assertOk()->assertSee('Rama keluar')->assertDontSee('Admin Satu keluar');
        $this->get(route('admin.logs', ['action' => 'route.deleted']))->assertOk()->assertSee('Tidak ada catatan yang cocok dengan saringan ini.');
        $this->get(route('admin.logs', ['action' => 'bukan-aksi']))->assertSessionHasErrors('action');
    }

    public function test_moderation_delete_and_dismiss(): void
    {
        $admin    = $this->admin();
        $author   = $this->hiker();
        $reporter = $this->hiker(['name' => 'Sinta']);
        $bad  = CommunityMessage::create(['user_id' => $author->id, 'body' => 'Pesan kasar']);
        $fine = CommunityMessage::create(['user_id' => $author->id, 'body' => 'Pesan wajar']);

        $this->actingAs($reporter)->postJson(route('community.chat.report', $bad))->assertOk();
        $this->postJson(route('community.chat.report', $fine))->assertOk();
        $this->assertSame(2, ActivityLog::where('action', 'community.message_reported')->where('user_id', $reporter->id)->count());

        $this->actingAs($admin)->get(route('admin.moderation'))->assertOk()
            ->assertSee('Pesan kasar')->assertSee('Pesan wajar')->assertSee('oleh Sinta', false);

        $this->delete(route('admin.moderation.destroy', $bad))->assertRedirect();
        $this->post(route('admin.moderation.dismiss', $fine))->assertRedirect();

        $this->assertTrue($bad->fresh()->trashed());
        $this->assertNull($fine->fresh()->reported_at);
        $this->assertNull($fine->fresh()->reported_by);
        $this->assertFalse($fine->fresh()->trashed());
        $this->assertSame('Admin Satu menghapus pesan dari Rama', ActivityLog::where('action', 'community.message_deleted')->sole()->sentence());
        $this->assertSame('Admin Satu mengabaikan laporan pesan dari Rama', ActivityLog::where('action', 'community.report_dismissed')->sole()->sentence());

        $this->get(route('admin.moderation'))->assertOk()->assertSee('Tidak ada laporan.');

        // Deleting your own message is not moderation.
        $own = CommunityMessage::create(['user_id' => $author->id, 'body' => 'salah ketik']);
        $this->actingAs($author)->deleteJson(route('community.chat.destroy', $own))->assertOk();
        $this->assertSame(1, ActivityLog::where('action', 'community.message_deleted')->count());
    }

    public function test_promote_and_demote_with_last_admin_guard(): void
    {
        $admin = $this->admin();
        $hiker = $this->hiker();

        // The only admin cannot be demoted, not even by themselves.
        $this->actingAs($admin)->from(route('admin.users.show', $admin))
            ->post(route('admin.users.role', $admin), ['role' => 'user'])
            ->assertRedirect(route('admin.users.show', $admin))
            ->assertSessionHasErrors('role');
        $this->assertSame('admin', $admin->fresh()->role);
        $this->get(route('admin.users.show', $admin))->assertSee('Satu-satunya admin');

        $this->post(route('admin.users.role', $hiker), ['role' => 'admin'])->assertRedirect();
        $this->assertSame('admin', $hiker->fresh()->role);

        $before = (int) $hiker->fresh()->sso_version;
        $this->post(route('admin.users.role', $hiker), ['role' => 'user'])->assertRedirect();
        $this->assertSame('user', $hiker->fresh()->role);
        $this->assertSame($before + 1, (int) $hiker->fresh()->sso_version);

        $this->post(route('admin.users.role', $hiker), ['role' => 'superadmin'])->assertSessionHasErrors('role');

        $logs = ActivityLog::where('action', 'admin.role_changed')->orderBy('id')->get();
        $this->assertSame(['Admin Satu menjadikan Rama admin', 'Admin Satu mencabut peran admin Rama'], $logs->map->sentence()->all());

        // With a second admin, an admin may step down and lands on the user dashboard.
        $hiker->forceFill(['role' => 'admin'])->save();
        $this->post(route('admin.users.role', $admin), ['role' => 'user'])->assertRedirect(route('user.dashboard'));
        $this->assertSame('user', $admin->fresh()->role);
    }

    public function test_prune_removes_entries_older_than_180_days(): void
    {
        $old = ActivityLog::create(['action' => 'auth.logout']);
        $old->forceFill(['created_at' => now()->subDays(181)])->save();
        $edge = ActivityLog::create(['action' => 'auth.logout']);
        $edge->forceFill(['created_at' => now()->subDays(179)])->save();

        $this->artisan('model:prune', ['--model' => [ActivityLog::class]])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($edge);
    }

    public function test_ipv6_addresses_are_cut_to_48_bits(): void
    {
        $this->assertSame('2001:db8:85a3::', ActivityLogger::anonymizeIp('2001:db8:85a3:8d3:1319:8a2e:370:7348'));
        $this->assertSame('10.1.2.0', ActivityLogger::anonymizeIp('10.1.2.3'));
        $this->assertNull(ActivityLogger::anonymizeIp('bukan-ip'));
    }

    public function test_a_broken_log_table_never_breaks_the_sign_in(): void
    {
        $user = $this->hiker(['email' => 'rama@example.com']);
        \Illuminate\Support\Facades\Schema::drop('activity_logs');
        \Illuminate\Support\Facades\Exceptions::fake();

        $this->post(route('login.submit'), ['email' => 'rama@example.com', 'password' => 'rahasia123'])
            ->assertRedirect(route('user.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, $user->fresh()->login_count);
        \Illuminate\Support\Facades\Exceptions::assertReported(\Illuminate\Database\QueryException::class);
    }
}
