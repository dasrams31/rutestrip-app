<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Self-hosted ALTCHA proof-of-work captcha (https://altcha.org, v1 challenge format).
 *
 * Challenge: sha256(salt . number) with a random secret number in [0, maxnumber];
 * the browser widget brute-forces the number. The HMAC signature lets the server
 * verify the solution without storing issued challenges. Solved challenges are
 * remembered in the cache until they expire so a payload cannot be replayed.
 */
class AltchaService
{
    public const ERROR_MESSAGE = 'Verifikasi anti-robot gagal, silakan coba lagi.';

    private const ALGORITHM = 'SHA-256';

    public function createChallenge(): array
    {
        $maxNumber = $this->maxNumber();
        $expires   = now()->getTimestamp() + $this->ttl();
        $salt      = bin2hex(random_bytes(12)) . '?expires=' . $expires;
        $number    = random_int(0, $maxNumber);
        $challenge = hash('sha256', $salt . $number);

        return [
            'algorithm' => self::ALGORITHM,
            'challenge' => $challenge,
            'maxnumber' => $maxNumber,
            'salt'      => $salt,
            'signature' => hash_hmac('sha256', $challenge, $this->key()),
        ];
    }

    /**
     * Verify a base64-encoded ALTCHA payload and mark it as used.
     */
    public function verify(?string $payload): bool
    {
        if (! is_string($payload) || $payload === '' || strlen($payload) > 2048) {
            return false;
        }

        $data = json_decode((string) base64_decode($payload, true), true);
        if (! is_array($data)) {
            return false;
        }

        $algorithm = $data['algorithm'] ?? null;
        $challenge = $data['challenge'] ?? null;
        $number    = $data['number'] ?? null;
        $salt      = $data['salt'] ?? null;
        $signature = $data['signature'] ?? null;

        if ($algorithm !== self::ALGORITHM
            || ! is_string($challenge) || ! is_string($salt) || ! is_string($signature)
            || ! is_int($number) || $number < 0 || $number > $this->maxNumber()) {
            return false;
        }

        $expires = $this->expiresFromSalt($salt);
        $now = now()->getTimestamp();
        if ($expires === null || $expires < $now) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $challenge, $this->key());
        if (! hash_equals($expectedSignature, $signature)
            || ! hash_equals($challenge, hash('sha256', $salt . $number))) {
            return false;
        }

        // Cache::add only succeeds for a key that is not stored yet: replay protection.
        $ttl = max(1, $expires - $now);

        return Cache::add('altcha:used:' . $challenge, true, $ttl);
    }

    private function expiresFromSalt(string $salt): ?int
    {
        parse_str(explode('?', $salt, 2)[1] ?? '', $params);
        $expires = $params['expires'] ?? null;

        return is_string($expires) && ctype_digit($expires) ? (int) $expires : null;
    }

    private function maxNumber(): int
    {
        return max(1, (int) config('services.altcha.max_number', 100000));
    }

    private function ttl(): int
    {
        return max(30, (int) config('services.altcha.expires', 600));
    }

    private function key(): string
    {
        $key = (string) config('services.altcha.hmac_key');
        if ($key !== '') {
            return $key;
        }

        // No dedicated key configured: derive one from APP_KEY so dev/tests still work.
        return hash_hmac('sha256', 'altcha-hmac-key', (string) config('app.key'));
    }
}
