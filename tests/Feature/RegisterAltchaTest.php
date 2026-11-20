<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AltchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterAltchaTest extends TestCase
{
    use RefreshDatabase;

    private const FAIL = 'Cek bukan robot belum lolos. Centang kotaknya lagi, lalu kirim ulang.';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.altcha.max_number' => 50, 'services.altcha.hmac_key' => 'test-hmac-key']);
    }

    /** Fetch a challenge from the endpoint and solve it like the browser widget does. */
    private function solvedPayload(array $override = []): string
    {
        $challenge = $this->getJson(route('altcha.challenge'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json();

        $number = null;
        for ($n = 0; $n <= $challenge['maxnumber']; $n++) {
            if (hash('sha256', $challenge['salt'] . $n) === $challenge['challenge']) {
                $number = $n;
                break;
            }
        }
        $this->assertNotNull($number, 'challenge should be solvable within maxnumber');

        return base64_encode(json_encode(array_merge([
            'algorithm' => $challenge['algorithm'],
            'challenge' => $challenge['challenge'],
            'number'    => $number,
            'salt'      => $challenge['salt'],
            'signature' => $challenge['signature'],
            'took'      => 10,
        ], $override)));
    }

    private function form(array $extra = []): array
    {
        return array_merge([
            'name'                  => 'Pendaki Baru',
            'email'                 => 'pendaki@example.com',
            'password'              => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ], $extra);
    }

    private function assertRejected($response): void
    {
        $response->assertRedirect(route('register'))
            ->assertSessionHasErrors(['altcha' => self::FAIL])
            ->assertSessionHasInput('email', 'pendaki@example.com')
            ->assertSessionMissing('_old_input.password');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'pendaki@example.com']);
    }

    public function test_challenge_endpoint_returns_signed_altcha_challenge(): void
    {
        $challenge = $this->getJson(route('altcha.challenge'))->assertOk()->json();

        $this->assertSame('SHA-256', $challenge['algorithm']);
        $this->assertSame(50, $challenge['maxnumber']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}\?expires=\d+$/', $challenge['salt']);
        $this->assertSame(hash_hmac('sha256', $challenge['challenge'], 'test-hmac-key'), $challenge['signature']);
    }

    public function test_register_page_renders_widget_and_honeypot(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('<altcha-widget', false)
            ->assertSee(route('altcha.challenge'), false)
            ->assertSee('name="website"', false)
            ->assertSee('js/altcha-init.js', false);
    }

    public function test_register_with_valid_solved_payload_succeeds(): void
    {
        $this->from(route('register'))
            ->post(route('register.submit'), $this->form(['altcha' => $this->solvedPayload()]))
            ->assertRedirect(route('user.dashboard'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'pendaki@example.com', 'role' => 'user']);
    }

    public function test_failed_register_returns_to_form_after_widget_fetched_challenge(): void
    {
        // Real browser order: open the form, the widget fetches a challenge, then submit.
        $this->get(route('register'))->assertOk();
        $this->get(route('altcha.challenge'))->assertOk();

        $this->assertRejected($this->post(route('register.submit'), $this->form()));
    }

    public function test_register_without_payload_fails(): void
    {
        $this->assertRejected($this->from(route('register'))->post(route('register.submit'), $this->form()));
    }

    public function test_register_with_garbage_payload_fails(): void
    {
        $this->assertRejected($this->from(route('register'))
            ->post(route('register.submit'), $this->form(['altcha' => 'bm90LWpzb24=']))); // "not-json"
    }

    public function test_register_with_wrong_number_fails(): void
    {
        $payload = json_decode(base64_decode($this->solvedPayload()), true);
        $payload['number'] = ($payload['number'] + 1) % 51;

        $this->assertRejected($this->from(route('register'))
            ->post(route('register.submit'), $this->form(['altcha' => base64_encode(json_encode($payload))])));
    }

    public function test_register_with_forged_signature_fails(): void
    {
        // Self-made challenge: correct proof of work but signed with another key.
        $salt      = 'aaaaaaaaaaaaaaaaaaaaaaaa?expires=' . (time() + 600);
        $challenge = hash('sha256', $salt . '7');
        $payload   = base64_encode(json_encode([
            'algorithm' => 'SHA-256', 'challenge' => $challenge, 'number' => 7, 'salt' => $salt,
            'signature' => hash_hmac('sha256', $challenge, 'attacker-key'),
        ]));

        $this->assertRejected($this->from(route('register'))
            ->post(route('register.submit'), $this->form(['altcha' => $payload])));
    }

    public function test_register_with_expired_payload_fails(): void
    {
        $payload = $this->solvedPayload();

        $this->travel(11)->minutes();

        $this->assertRejected($this->from(route('register'))
            ->post(route('register.submit'), $this->form(['altcha' => $payload])));
    }

    public function test_replayed_payload_is_rejected(): void
    {
        $payload = $this->solvedPayload();

        $this->post(route('register.submit'), $this->form(['email' => 'pertama@example.com', 'altcha' => $payload]))
            ->assertRedirect(route('user.dashboard'));
        auth()->logout();

        $this->assertRejected($this->from(route('register'))
            ->post(route('register.submit'), $this->form(['altcha' => $payload])));
    }

    public function test_filled_honeypot_fails_even_with_valid_payload(): void
    {
        $this->assertRejected($this->from(route('register'))->post(route('register.submit'), $this->form([
            'altcha'  => $this->solvedPayload(),
            'website' => 'https://spam.example',
        ])));
    }

    public function test_register_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('register.submit'), $this->form())->assertRedirect();
        }

        $this->post(route('register.submit'), $this->form())->assertStatus(429);
    }

    public function test_service_rejects_payload_with_other_algorithm(): void
    {
        $payload = json_decode(base64_decode($this->solvedPayload()), true);
        $payload['algorithm'] = 'SHA-512';

        $this->assertFalse(app(AltchaService::class)->verify(base64_encode(json_encode($payload))));
    }
}
