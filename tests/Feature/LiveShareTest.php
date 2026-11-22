<?php

namespace Tests\Feature;

use App\Models\LiveShare;
use App\Models\User;
use App\Services\SsoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Bagikan lokasi online": app sends the latest position to a secret, expiring link. */
class LiveShareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sso.secret' => 'test-sso-secret']);
        $this->withHeader('Accept-Language', 'id');
    }

    private function as(User $user): static
    {
        return $this->withToken(app(SsoService::class)->issue($user->refresh()));
    }

    public function test_start_send_view_and_stop(): void
    {
        $user = User::factory()->create(['name' => 'Rama']);

        $start = $this->as($user)->postJson('/api/v1/live', ['hours' => 6])->assertCreated()->assertJsonStructure(['token', 'url', 'expires_at']);
        $token = $start->json('token');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $token);
        $this->assertStringEndsWith('/lacak/' . $token, $start->json('url'));

        // Before the first fix the page loads with a waiting note.
        $this->get('/lacak/' . $token)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('Rama');

        $this->as($user)->postJson("/api/v1/live/$token/position", ['lat' => -7.4501, 'lng' => 110.4301, 'acc' => 8, 'ele' => 2840, 'bat' => 64])->assertOk();
        $this->getJson("/lacak/$token/data")->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('lat', -7.4501)
            ->assertJsonPath('battery', 64)
            ->assertJsonPath('name', 'Rama');

        $this->as($user)->postJson("/api/v1/live/$token/stop")->assertOk();
        $this->getJson("/lacak/$token/data")->assertOk()->assertJsonPath('active', false)->assertJsonPath('lat', null);
        $this->as($user)->postJson("/api/v1/live/$token/position", ['lat' => -7.45, 'lng' => 110.43])->assertStatus(410);
    }

    public function test_only_the_owner_can_send_and_links_expire(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $token = $this->as($owner)->postJson('/api/v1/live', ['hours' => 1])->json('token');

        $this->as($other)->postJson("/api/v1/live/$token/position", ['lat' => -7.45, 'lng' => 110.43])->assertNotFound();
        $this->as($other)->postJson("/api/v1/live/$token/stop")->assertNotFound();

        $this->as($owner)->postJson("/api/v1/live/$token/position", ['lat' => -7.45, 'lng' => 110.43])->assertOk();
        $this->travel(61)->minutes();
        $this->getJson("/lacak/$token/data")->assertJsonPath('active', false)->assertJsonPath('lat', null);
    }

    public function test_input_is_checked_and_unknown_links_are_404(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/v1/live', ['hours' => 2])->assertStatus(401);
        $this->as($user)->postJson('/api/v1/live', ['hours' => 72])->assertStatus(422);
        $token = $this->as($user)->postJson('/api/v1/live', ['hours' => 2])->json('token');
        $this->as($user)->postJson("/api/v1/live/$token/position", ['lat' => 120, 'lng' => 110])->assertStatus(422);

        $this->get('/lacak/' . str_repeat('a', 40))->assertNotFound();
        $this->get('/lacak/short')->assertNotFound();
        $this->getJson('/lacak/' . str_repeat('b', 40) . '/data')->assertNotFound();
    }

    public function test_ended_links_keep_no_position_and_old_rows_are_deleted(): void
    {
        $user = User::factory()->create();
        $a = $this->as($user)->postJson('/api/v1/live', ['hours' => 1])->json('token');
        $b = $this->as($user)->postJson('/api/v1/live', ['hours' => 6])->json('token');
        foreach ([$a, $b] as $t) {
            $this->as($user)->postJson("/api/v1/live/$t/position", ['lat' => -7.45, 'lng' => 110.43, 'bat' => 50])->assertOk();
        }

        // Stopping clears the stored position at once.
        $this->as($user)->postJson("/api/v1/live/$b/stop")->assertOk();
        $row = LiveShare::where('token', $b)->first();
        $this->assertNull($row->lat);
        $this->assertNull($row->battery);
        $this->assertNull($row->position_at);

        // Expiry: the next visit or new link clears it too, even without the scheduler.
        $this->travel(61)->minutes();
        $this->getJson("/lacak/$a/data")->assertJsonPath('active', false);
        $this->assertNull(LiveShare::where('token', $a)->first()->lat);

        // A day after ending, the rows are deleted.
        $this->travel(25)->hours();
        $this->artisan('model:prune', ['--model' => [LiveShare::class]])->assertSuccessful();
        $this->assertSame(0, LiveShare::count());
    }

    public function test_at_most_three_active_links_per_account(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 4) as $_) {
            $this->as($user)->postJson('/api/v1/live', ['hours' => 2])->assertCreated();
        }
        $this->assertSame(3, LiveShare::active()->where('user_id', $user->id)->count());
    }
}
