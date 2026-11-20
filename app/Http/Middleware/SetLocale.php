<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Site language (docs/SSO.md): ?lang= (also stores the rs_lang cookie)
 * > rs_lang cookie > the user's saved locale > Indonesian.
 * URLs stay the same for both languages; Indonesian is the main language.
 */
class SetLocale
{
    public const SUPPORTED = ['id', 'en'];
    public const DEFAULT = 'id';
    public const COOKIE = 'rs_lang';

    public function handle(Request $request, Closure $next): Response
    {
        $fromQuery = $this->valid($request->query('lang'));

        $locale = $fromQuery
            ?? $this->valid($request->cookie(self::COOKIE))
            ?? $this->valid($request->user()?->getAttribute('locale'))
            ?? self::DEFAULT;

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        if ($fromQuery && $request->cookie(self::COOKIE) !== $fromQuery) {
            Cookie::queue(self::cookie($fromQuery));
        }

        return $next($request);
    }

    public static function cookie(string $locale): \Symfony\Component\HttpFoundation\Cookie
    {
        // Shared with chat.rutestrip.ai.id: readable by JS, one year, SameSite=Lax.
        return Cookie::make(
            self::COOKIE,
            $locale,
            60 * 24 * 365,
            '/',
            config('services.sso.cookie_domain') ?: null,
            config('services.sso.cookie_secure'),
            false,
            false,
            'lax'
        );
    }

    private function valid(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::SUPPORTED, true) ? $value : null;
    }
}
