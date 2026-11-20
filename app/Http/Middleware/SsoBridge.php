<?php

namespace App\Http\Middleware;

use App\Services\SsoService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the Laravel session in step with the shared rs_sso cookie (docs/SSO.md).
 *
 * - Guest + valid token: log the token's user in.
 * - Logged in + valid token for the same user: mark the session "bound" (the
 *   browser demonstrably holds the cookie) and refresh the token when it is
 *   older than half its lifetime.
 * - Logged in + token present but invalid, expired, stale (sv) or for another
 *   user: log out ("logged out on the other site"), then sign in the token's
 *   user if the token itself is valid.
 * - Logged in + no token: log out when the session is bound or came from the
 *   remember-me cookie. Other sessions (opened before SSO existed, or by tests
 *   via actingAs) get a fresh cookie instead, so nobody is thrown out on deploy.
 *
 * Binding only once the cookie comes back (instead of at login) means a browser
 * that refuses the cookie (misconfigured domain or Secure on http) stays logged
 * in here instead of being bounced back to the login page on every request.
 */
class SsoBridge
{
    public const BOUND = 'sso_bound';

    /** Requests that log in or out themselves and set or delete the cookie. */
    private const SKIP_ROUTES = ['login.submit', 'admin.login.submit', 'register.submit', 'logout'];

    public function __construct(private SsoService $sso)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || $request->routeIs(...self::SKIP_ROUTES)) {
            return $next($request);
        }

        $guard     = Auth::guard('web');
        $token     = $request->cookie(SsoService::COOKIE);
        $tokenUser = is_string($token) && $token !== '' ? $this->sso->user($token) : null;
        $session   = $request->session();

        if ($guard->guest()) {
            if ($tokenUser) {
                $this->loginFromToken($request, $tokenUser);
                $this->refreshIfOld($token, $tokenUser);
            } elseif ($token !== null) {
                Cookie::queue($this->sso->forgetCookie());
            }

            return $next($request);
        }

        $user = $guard->user();

        if ($tokenUser && $tokenUser->is($user)) {
            $session->put(self::BOUND, true);
            $this->refreshIfOld($token, $user);

            return $next($request);
        }

        $tokenPresent = $token !== null && $token !== '';
        if (! $tokenPresent && ! $session->get(self::BOUND) && ! $guard->viaRemember()) {
            Cookie::queue($this->sso->cookie($user));

            return $next($request);
        }

        // Logged out (or switched account) on the other site, or password changed.
        $guard->logout();
        $session->invalidate();
        $session->regenerateToken();

        if ($tokenUser) {
            $this->loginFromToken($request, $tokenUser);
            $this->refreshIfOld($token, $tokenUser);
        } elseif ($tokenPresent) {
            Cookie::queue($this->sso->forgetCookie());
        }

        return $next($request);
    }

    /** Sliding expiry: re-issue once a token is past half its lifetime. */
    private function refreshIfOld(string $token, $user): void
    {
        if ($this->sso->secondsLeft($token) < SsoService::TTL_DAYS * 86400 / 2) {
            Cookie::queue($this->sso->cookie($user));
        }
    }

    private function loginFromToken(Request $request, $user): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(self::BOUND, true);
    }
}
