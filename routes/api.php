<?php

use App\Http\Controllers\Api\V1\RouteApiController;
use App\Http\Controllers\Api\V1\SearchApiController;
use App\Http\Controllers\SsoApiController;
use Illuminate\Support\Facades\Route;

// Single sign-on API for the web chat (docs/SSO.md): stateless JSON, no session,
// no CSRF. Throttling per client IP happens in the controller.
Route::prefix('sso')->name('sso.api.')->group(function () {
    Route::post('/login', [SsoApiController::class, 'login'])->name('login');
    Route::post('/register', [SsoApiController::class, 'register'])->name('register');
    Route::get('/me', [SsoApiController::class, 'me'])->name('me');
});

// Mobile app API (docs/API.md): stateless JSON, Accept-Language id|en, signed-in
// users send the SSO token as "Authorization: Bearer". Throttled per client IP;
// the prefixes keep each counter apart.
Route::prefix('v1')->name('api.v1.')->middleware(\App\Http\Middleware\ApiLocale::class)->group(function () {
    Route::middleware('throttle:60,1,api-v1')->group(function () {
        Route::get('/routes', [RouteApiController::class, 'index'])->name('routes.index');
        Route::get('/routes/{route}', [RouteApiController::class, 'show'])->name('routes.show');
        Route::get('/filters', [RouteApiController::class, 'filters'])->name('filters');
        Route::get('/likes', [RouteApiController::class, 'likes'])->name('likes');
    });
    Route::post('/search', [SearchApiController::class, 'search'])
        ->middleware('throttle:20,1,api-v1-search')->name('search');
    Route::post('/routes/{route}/like', [RouteApiController::class, 'like'])
        ->middleware('throttle:30,1,api-v1-like')->name('routes.like');
});
