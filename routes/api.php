<?php

use App\Http\Controllers\SsoApiController;
use Illuminate\Support\Facades\Route;

// Single sign-on API for the web chat (docs/SSO.md): stateless JSON, no session,
// no CSRF. Throttling per client IP happens in the controller.
Route::prefix('sso')->name('sso.api.')->group(function () {
    Route::post('/login', [SsoApiController::class, 'login'])->name('login');
    Route::post('/register', [SsoApiController::class, 'register'])->name('register');
    Route::get('/me', [SsoApiController::class, 'me'])->name('me');
});
