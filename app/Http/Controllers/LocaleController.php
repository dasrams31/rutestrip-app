<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Schema;

class LocaleController extends Controller
{
    /**
     * GET /lang/{locale}: remember the language (cookie + the user's saved
     * locale when that column exists) and go back to the page the visitor was on.
     */
    public function switch(Request $request, string $locale): RedirectResponse
    {
        $user = $request->user();

        if ($user && $user->getAttribute('locale') !== $locale && Schema::hasColumn($user->getTable(), 'locale')) {
            $user->forceFill(['locale' => $locale])->save();
        }

        Cookie::queue(SetLocale::cookie($locale));

        return redirect()->to($this->backUrl($request));
    }

    /**
     * Same-host referer without its ?lang= (which would override the choice); home otherwise.
     */
    private function backUrl(Request $request): string
    {
        $referer = (string) $request->headers->get('referer');
        $parts   = parse_url($referer);

        if (! $referer || ! $parts || ($parts['host'] ?? null) !== $request->getHost()) {
            return url('/');
        }

        parse_str($parts['query'] ?? '', $query);
        unset($query['lang']);

        $path = ($parts['path'] ?? '/') ?: '/';

        return url($path) . ($query ? '?' . http_build_query($query) : '');
    }
}
