<?php

namespace App\Http\Middleware;

use App\Services\SsoService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Mobile API language: Accept-Language id or en (default id), like the SSO API. */
class ApiLocale
{
    public function __construct(private SsoService $sso)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->sso->requestLocale($request));

        return $next($request);
    }
}
