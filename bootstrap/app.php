<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Single sign-on (docs/SSO.md): the shared cookies stay unencrypted so the
        // chat can read them; SsoBridge syncs the session with rs_sso.
        $middleware->encryptCookies(except: ['rs_sso', 'rs_lang']);
        $middleware->web(append: [\App\Http\Middleware\SsoBridge::class]);
        // Must run before route "auth"/"guest" checks, which the priority list would otherwise hoist above it.
        $middleware->prependToPriorityList(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, \App\Http\Middleware\SsoBridge::class);
        // Site language (?lang= > rs_lang > users.locale > id), after SsoBridge so the SSO user counts.
        $middleware->web(append: [\App\Http\Middleware\SetLocale::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
