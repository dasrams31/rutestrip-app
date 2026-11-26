<?php

use App\Http\Controllers\Api\V1\AccountApiController;
use App\Http\Controllers\Api\V1\RouteApiController;
use App\Http\Controllers\Api\V1\RouteExtrasApiController;
use App\Http\Controllers\Api\V1\SearchApiController;
use App\Http\Controllers\Api\V1\SocialApiController;
use App\Http\Controllers\Api\V1\UploadApiController;
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
        Route::get('/routes/{route}/reviews', [RouteExtrasApiController::class, 'reviews'])->name('routes.reviews');
        Route::get('/routes/{route}/versions', [RouteExtrasApiController::class, 'versions'])->name('routes.versions');
    });
    // Live conditions call Open-Meteo and Nominatim (cached): same limit as the website endpoint.
    Route::get('/routes/{route}/conditions', [RouteExtrasApiController::class, 'conditions'])
        ->middleware('throttle:30,1,api-v1-conditions')->name('routes.conditions');
    Route::post('/search', [SearchApiController::class, 'search'])
        ->middleware('throttle:20,1,api-v1-search')->name('search');
    Route::post('/routes/{route}/like', [RouteApiController::class, 'like'])
        ->middleware('throttle:30,1,api-v1-like')->name('routes.like');

    // Signed-in only (SsoBearer turns the bearer token into $request->user()).
    Route::middleware(\App\Http\Middleware\SsoBearer::class)->group(function () {
        Route::post('/routes', [UploadApiController::class, 'store'])
            ->middleware('throttle:10,1,api-v1-upload')->name('routes.store');

        // Obrolan Pendaki and Asisten RuteStrip: the website's own controllers and limits.
        Route::prefix('community')->name('community.')->controller(\App\Http\Controllers\CommunityChatController::class)->group(function () {
            Route::get('/messages', 'index')->middleware('throttle:90,1,api-v1-cc-read')->name('index');
            Route::get('/latest', 'latest')->middleware('throttle:30,1,api-v1-cc-latest')->name('latest');
            Route::post('/messages', 'store')->name('store');
            Route::delete('/messages/{message}', 'destroy')->whereNumber('message')->middleware('throttle:30,1,api-v1-cc-delete')->name('destroy');
            Route::post('/messages/{message}/report', 'report')->whereNumber('message')->middleware('throttle:20,1,api-v1-cc-report')->name('report');
        });
        Route::post('/assistant', [\App\Http\Controllers\AssistantController::class, 'ask'])->name('assistant');

        // Live position link ("Bagikan lokasi online"), see LiveShareController.
        Route::post('/live', [\App\Http\Controllers\LiveShareController::class, 'start'])->middleware('throttle:10,1,api-v1-live-start')->name('live.start');
        Route::post('/live/{token}/position', [\App\Http\Controllers\LiveShareController::class, 'position'])
            ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1,api-v1-live-pos')->name('live.position');
        Route::post('/live/{token}/stop', [\App\Http\Controllers\LiveShareController::class, 'stop'])
            ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:20,1,api-v1-live-stop')->name('live.stop');

        Route::post('/routes/{route}/reviews', [RouteExtrasApiController::class, 'storeReview'])
            ->middleware('throttle:10,1,api-v1-review')->name('routes.reviews.store');

        // Linimasa (social timeline), docs/API.md. Behind SsoBearer the throttles count per account.
        Route::controller(SocialApiController::class)->group(function () {
            Route::middleware('throttle:60,1,api-v1-social-read')->group(function () {
                Route::get('/feed', 'feed')->name('feed');
                Route::get('/stories', 'stories')->name('stories');
                Route::get('/posts/{id}', 'show')->whereNumber('id')->name('posts.show');
                Route::get('/posts/{id}/comments', 'comments')->whereNumber('id')->name('posts.comments');
                Route::get('/users/{id}', 'user')->whereNumber('id')->name('users.show');
                Route::get('/users/{id}/posts', 'userPosts')->whereNumber('id')->name('users.posts');
                Route::get('/users/{id}/followers', 'people')->whereNumber('id')->name('users.followers');
                Route::get('/users/{id}/following', 'people')->whereNumber('id')->name('users.following');
                Route::get('/me/saved', 'saved')->name('me.saved');
            });
            Route::get('/users/search', 'searchUsers')->middleware('throttle:30,1,api-v1-user-search')->name('users.search');
            Route::post('/posts', 'store')->middleware('throttle:10,1,api-v1-post')->name('posts.store');
            Route::delete('/posts/{id}', 'destroy')->whereNumber('id')->middleware('throttle:30,1,api-v1-post-delete')->name('posts.destroy');
            Route::match(['post', 'delete'], '/posts/{id}/like', 'like')->whereNumber('id')->middleware('throttle:60,1,api-v1-post-like')->name('posts.like');
            Route::match(['post', 'delete'], '/posts/{id}/save', 'save')->whereNumber('id')->middleware('throttle:60,1,api-v1-post-save')->name('posts.save');
            Route::post('/posts/{id}/comments', 'storeComment')->whereNumber('id')->middleware('throttle:20,1,api-v1-comment')->name('posts.comments.store');
            Route::delete('/comments/{id}', 'destroyComment')->whereNumber('id')->middleware('throttle:30,1,api-v1-comment-delete')->name('comments.destroy');
            Route::post('/posts/{id}/report', 'report')->whereNumber('id')->middleware('throttle:20,1,api-v1-social-report')->name('posts.report');
            Route::post('/comments/{id}/report', 'reportComment')->whereNumber('id')->middleware('throttle:20,1,api-v1-social-report')->name('comments.report');
            Route::match(['post', 'delete'], '/users/{id}/follow', 'follow')->whereNumber('id')->middleware('throttle:30,1,api-v1-follow')->name('users.follow');
        });

        Route::prefix('me')->name('me.')->controller(AccountApiController::class)->group(function () {
            Route::get('/', 'show')->middleware('throttle:60,1,api-v1')->name('show');
            Route::get('/history', 'historyIndex')->middleware('throttle:60,1,api-v1')->name('history');
            Route::get('/submissions', [UploadApiController::class, 'submissions'])->middleware('throttle:60,1,api-v1')->name('submissions');
            Route::put('/profile', 'updateProfile')->middleware('throttle:10,1,api-v1-account')->name('profile');
            Route::put('/password', 'updatePassword')->middleware('throttle:5,1,api-v1-password')->name('password');
            Route::put('/locale', 'updateLocale')->middleware('throttle:10,1,api-v1-account')->name('locale');
            Route::post('/logout-all', 'logout')->middleware('throttle:5,1,api-v1-account')->name('logout-all');
        });
    });
});
