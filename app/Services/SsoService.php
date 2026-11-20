<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Single sign-on between rutestrip.ai.id and chat.rutestrip.ai.id (docs/SSO.md).
 *
 * Tokens are HS256 JWTs carried in the shared, unencrypted rs_sso cookie.
 * A token is valid when its signature and exp check out, the user still exists
 * and its "sv" claim equals users.sso_version (bumped on password change).
 */
class SsoService
{
    public const COOKIE = 'rs_sso';

    public const LANG_COOKIE = 'rs_lang';

    public const ISSUER = 'rutestrip.ai.id';

    public const TTL_DAYS = 30;

    public const LOCALES = ['id', 'en'];

    /** Issue a signed token for the user. */
    public function issue(User $user): string
    {
        $now = now()->getTimestamp();

        return $this->encode([
            'iss'    => self::ISSUER,
            'sub'    => (string) $user->getKey(),
            'name'   => $user->name,
            'email'  => $user->email,
            'role'   => $user->role ?? 'user',
            'locale' => $this->normalizeLocale($user->locale),
            'sv'     => (int) $user->sso_version,
            'iat'    => $now,
            'exp'    => $now + self::TTL_DAYS * 86400,
        ]);
    }

    /**
     * Check signature, algorithm, issuer and expiry. Returns the claims or null.
     * Does not touch the database: see user() for the full check.
     */
    public function decode(?string $token): ?array
    {
        if (! is_string($token) || $token === '' || strlen($token) > 4096) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode((string) $this->base64UrlDecode($headerB64), true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $signature = $this->base64UrlDecode($signatureB64);
        $expected  = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $this->secret(), true);
        if ($signature === null || ! hash_equals($expected, $signature)) {
            return null;
        }

        $claims = json_decode((string) $this->base64UrlDecode($payloadB64), true);
        if (! is_array($claims)
            || ($claims['iss'] ?? null) !== self::ISSUER
            || ! is_string($claims['sub'] ?? null) || ! ctype_digit($claims['sub'])
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= now()->getTimestamp()
            || ! is_int($claims['sv'] ?? null)) {
            return null;
        }

        return $claims;
    }

    /** The user a token belongs to, or null when invalid, expired, unknown or stale (sv). */
    public function user(?string $token): ?User
    {
        $claims = $this->decode($token);
        if ($claims === null) {
            return null;
        }

        $user = User::find((int) $claims['sub']);

        return $user && (int) $user->sso_version === $claims['sv'] ? $user : null;
    }

    /** Seconds until the token expires (0 when invalid). */
    public function secondsLeft(?string $token): int
    {
        $claims = $this->decode($token);

        return $claims ? max(0, $claims['exp'] - now()->getTimestamp()) : 0;
    }

    /** The `user` object of the JSON API. */
    public function userPayload(User $user): array
    {
        return [
            'id'     => (int) $user->getKey(),
            'name'   => $user->name,
            'email'  => $user->email,
            'role'   => $user->role ?? 'user',
            'locale' => $this->normalizeLocale($user->locale),
        ];
    }

    /** rs_sso cookie holding a fresh token for the user. */
    public function cookie(User $user): Cookie
    {
        return $this->makeCookie(self::COOKIE, $this->issue($user), now()->addDays(self::TTL_DAYS)->getTimestamp(), true);
    }

    /** Expired rs_sso cookie with the same Domain and Path, so the browser drops it. */
    public function forgetCookie(): Cookie
    {
        return $this->makeCookie(self::COOKIE, '', 1, true);
    }

    /** rs_lang cookie (readable by JavaScript on both sites), one year. */
    public function langCookie(string $locale): Cookie
    {
        return $this->makeCookie(self::LANG_COOKIE, $this->normalizeLocale($locale), now()->addYear()->getTimestamp(), false);
    }

    public function normalizeLocale(?string $locale): string
    {
        return in_array($locale, self::LOCALES, true) ? $locale : 'id';
    }

    /** Request locale for the JSON API: Accept-Language (id or en), default id. */
    public function requestLocale(Request $request): string
    {
        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(substr(str_replace('-', '_', $language), 0, 2));
            if (in_array($primary, self::LOCALES, true)) {
                return $primary;
            }
        }

        return 'id';
    }

    /**
     * Client IP for throttling. The chat calls Laravel server-to-server and forwards
     * the visitor's IP in X-RuteStrip-Client-IP; that header is only trusted together
     * with the shared internal secret.
     */
    public function clientIp(Request $request): string
    {
        $secret   = (string) config('services.sso.internal_secret');
        $provided = $request->header('X-RuteStrip-Internal');
        $clientIp = $request->header('X-RuteStrip-Client-IP');

        if ($secret !== '' && is_string($provided) && hash_equals($secret, $provided)
            && is_string($clientIp) && filter_var($clientIp, FILTER_VALIDATE_IP)) {
            return $clientIp;
        }

        return (string) $request->ip();
    }

    private function makeCookie(string $name, string $value, int $expires, bool $httpOnly): Cookie
    {
        return new Cookie(
            $name,
            $value,
            $expires,
            '/',
            config('services.sso.cookie_domain') ?: null,
            (bool) config('services.sso.cookie_secure'),
            $httpOnly,
            false,
            Cookie::SAMESITE_LAX,
        );
    }

    private function encode(array $claims): string
    {
        $header  = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $header . '.' . $payload, $this->secret(), true);

        return $header . '.' . $payload . '.' . $this->base64UrlEncode($signature);
    }

    private function secret(): string
    {
        $secret = (string) config('services.sso.secret');
        if ($secret !== '') {
            return $secret;
        }

        // No shared secret configured: derive one from APP_KEY so dev/tests still work.
        return hash_hmac('sha256', 'rutestrip-sso-secret', (string) config('app.key'));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): ?string
    {
        if ($data === '' || preg_match('/[^A-Za-z0-9_-]/', $data)) {
            return null;
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
