<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\UploadApiController;
use App\Models\User;
use App\Services\SsoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Per-account limits added for the mobile app: assistant, failed sign-ins, uploads per day. */
class MobileApiLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sso.secret' => 'test-sso-secret']);
        $this->withHeader('Accept-Language', 'id');
    }

    public function test_assistant_limit_follows_the_account_across_ips(): void
    {
        Http::preventStrayRequests();
        config(['services.gemini.key' => 'k', 'services.gemini.models' => ['m']]);
        Http::fake(['*' => Http::response('data: ' . json_encode(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]) . "\r\n\r\n", 200)]);
        $user = User::factory()->create();
        $token = app(SsoService::class)->issue($user->refresh());

        for ($i = 0; $i < 8; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.$i"])->withToken($token)
                ->postJson('/api/v1/assistant', ['message' => 'halo'])->assertOk();
        }
        // A ninth question from yet another IP is still the same account.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])->withToken($token)
            ->postJson('/api/v1/assistant', ['message' => 'halo'])->assertStatus(429);
    }

    public function test_wrong_passwords_pause_the_account_whatever_the_ip(): void
    {
        User::factory()->create(['email' => 'rama@example.com', 'password' => 'rahasia123']);

        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.$i"])
                ->postJson('/api/sso/login', ['email' => 'rama@example.com', 'password' => 'salah'])->assertStatus(401);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.1.1'])
            ->postJson('/api/sso/login', ['email' => 'RAMA@example.com', 'password' => 'rahasia123'])
            ->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_a_successful_sign_in_resets_the_failure_count(): void
    {
        User::factory()->create(['email' => 'rama@example.com', 'password' => 'rahasia123']);
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.2.0.$i"])->postJson('/api/sso/login', ['email' => 'rama@example.com', 'password' => 'salah']);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.2.1.1'])->postJson('/api/sso/login', ['email' => 'rama@example.com', 'password' => 'rahasia123'])->assertOk();
        $this->assertSame(0, RateLimiter::attempts('sso-api:login-fail:' . sha1('rama@example.com')));
    }

    public function test_uploads_per_day_are_capped(): void
    {
        $user = User::factory()->create();
        RateLimiter::increment('api-upload-day:' . $user->id, 86400, UploadApiController::PER_DAY);

        $this->withToken(app(SsoService::class)->issue($user->refresh()))
            ->post('/api/v1/routes', ['name' => 'X'], ['Accept' => 'application/json'])
            ->assertStatus(429)->assertJsonStructure(['error']);
    }
}
