<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only administrators may continue (used for GPX uploads that change the
 * public route dataset).
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Hanya admin yang dapat mengunggah file GPX.');

        return $next($request);
    }
}
