<?php

namespace App\Http\Middleware;

use App\Services\SsoService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mobile API (docs/API.md): the SSO token in "Authorization: Bearer" signs the
 * user in for this request only (no session), so controllers shared with the
 * website (community chat, assistant) see the same $request->user().
 */
class SsoBearer
{
    public function __construct(private SsoService $sso)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->sso->user($request->bearerToken());
        if (! $user) {
            return response()->json(['error' => __('account.api.invalid_token')], 401);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
