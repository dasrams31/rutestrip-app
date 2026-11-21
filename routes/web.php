<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminEvaluationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ProvenanceController;
use App\Http\Controllers\RouteConditionsController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\SusResponseController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Home - search page (served directly so the homepage itself is indexable)
Route::get('/', [SearchController::class, 'index'])->name('home');

// Search engines
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');

// GPX upload (single & batch): the pages are public (guests are asked to log in),
// submitting requires a logged-in user and is rate limited. The uploader is recorded.
Route::get('/routes/create', [RouteController::class, 'create'])->name('routes.create');
Route::get('/routes-batch', [RouteController::class, 'createBatch'])->name('routes.batch');
Route::middleware(['auth', 'throttle:10,1'])->group(function () {
    Route::post('/routes', [RouteController::class, 'store'])->name('routes.store');
    Route::post('/routes-batch', [RouteController::class, 'storeBatch'])->name('routes.batch.store');
});

// Public Routes - View
Route::get('/routes', [RouteController::class, 'index'])->name('routes.index');
Route::get('/routes/{route}', [RouteController::class, 'show'])->name('routes.show');
Route::get('/routes/{route}/provenance', [ProvenanceController::class, 'show'])->name('routes.provenance');
Route::get('/routes/{route}/provenance/{version}/download', [ProvenanceController::class, 'downloadVersion'])->name('routes.provenance.download-version');
Route::get('/routes/{route}/similar', [RouteController::class, 'similarRoutes'])->name('routes.similar');
// Route page extras: lazily fetched live conditions (external APIs, cached) and
// "log in to review" redirect that remembers the route page.
Route::get('/routes/{route}/conditions', [RouteConditionsController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('routes.conditions');
Route::get('/routes/{route}/review/login', [RouteConditionsController::class, 'loginToReview'])->name('routes.review.login');

// Reviews & Ratings (Auth required)
Route::middleware('auth')->group(function () {
    Route::post('/routes/{route}/review', [RouteController::class, 'storeReview'])->name('routes.review.store');
    Route::get('/routes/{route}/versions/create', [ProvenanceController::class, 'createVersion'])->name('routes.versions.create');
    Route::post('/routes/{route}/versions', [ProvenanceController::class, 'storeVersion'])->name('routes.versions.store');
    Route::get('/routes/{route}/provenance/export-verification-logs', [ProvenanceController::class, 'exportVerificationLogs'])->name('routes.provenance.export-verification-logs');
    Route::post('/routes/{route}/provenance/{version}/verify', [ProvenanceController::class, 'verify'])->name('routes.provenance.verify');
    Route::post('/routes/{route}/provenance/{version}/restore', [ProvenanceController::class, 'restore'])->name('routes.provenance.restore');
});

// Admin Only - Delete Route
Route::delete('/routes/{route}', [RouteController::class, 'destroy'])
    ->name('routes.destroy')
    ->middleware('auth');

// Search
Route::get('/search', [SearchController::class, 'index'])->name('search.index');

// AI chat lives at the dedicated webchat (WEBCHAT_URL, default chat.rutestrip.ai.id).
// Old /chat links redirect there; the RAG endpoints below stay available.
Route::redirect('/chat', config('services.webchat.url'), 302)->name('chat.index');
Route::redirect('/chat/new', config('services.webchat.url'), 302)->name('chat.new');
Route::post('/chat/send', [ChatController::class, 'send'])->name('chat.send');
Route::get('/chat/history', [ChatController::class, 'history'])->name('chat.history');
Route::post('/search', [SearchController::class, 'search'])->name('search.submit');

// SUS usability evaluation
Route::get('/evaluation/sus', [SusResponseController::class, 'create'])->name('evaluation.sus.create');
Route::post('/evaluation/sus', [SusResponseController::class, 'store'])->name('evaluation.sus.store');

// Documentation
Route::get('/arsitektur', function () {
    return view('architecture');
})->name('architecture');

// Cookie policy (linked from the cookie notice and the footer).
Route::view('/kebijakan-cookie', 'legal.cookies')->name('cookies.policy');

// User Authentication (Guest only)
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
    Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.submit')->middleware('throttle:5,1');
    Route::get('/admin/login', [AuthController::class, 'showAdminLoginForm'])->name('admin.login');
    Route::post('/admin/login', [AuthController::class, 'adminLogin'])->name('admin.login.submit');
});

// ALTCHA challenge for the register form. Fetched by the widget without
// X-Requested-With, so it runs without the session: otherwise it becomes the
// "previous URL" and a failed register would redirect the user to this JSON.
Route::get('/altcha/challenge', [AuthController::class, 'altchaChallenge'])
    ->name('altcha.challenge')
    ->middleware('throttle:30,1')
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ]);

// Logout
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// User Dashboard
Route::prefix('user')->name('user.')->middleware('auth')->group(function () {
    Route::get('/dashboard', [UserController::class, 'dashboard'])->name('dashboard');
    Route::get('/dashboard/stats', [UserController::class, 'statsApi'])->name('dashboard.stats');
    Route::get('/favorites', [UserController::class, 'favorites'])->name('favorites');
    Route::post('/favorite/{route}', [UserController::class, 'toggleFavorite'])->name('favorite.toggle');
    Route::get('/history', [UserController::class, 'history'])->name('history');
    // user.profile, user.profile.update, user.password.update: see "Account settings" below.
});

// Admin Dashboard (role "admin" only: EnsureAdmin answers 403 to everyone else)
Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
    // Users, activity, logs and moderation (App\Http\Controllers\Admin).
    Route::get('/pengguna', [\App\Http\Controllers\Admin\AdminUserController::class, 'index'])->name('users.index');
    Route::get('/pengguna/{user}', [\App\Http\Controllers\Admin\AdminUserController::class, 'show'])->whereNumber('user')->name('users.show');
    Route::post('/pengguna/{user}/peran', [\App\Http\Controllers\Admin\AdminUserController::class, 'updateRole'])->whereNumber('user')->name('users.role');
    Route::get('/aktivitas', [\App\Http\Controllers\Admin\AdminActivityController::class, 'index'])->name('activity');
    Route::get('/log', [\App\Http\Controllers\Admin\AdminLogController::class, 'index'])->name('logs');
    Route::get('/moderasi', [\App\Http\Controllers\Admin\AdminModerationController::class, 'index'])->name('moderation');
    Route::delete('/moderasi/{message}', [\App\Http\Controllers\Admin\AdminModerationController::class, 'destroy'])->whereNumber('message')->name('moderation.destroy');
    Route::post('/moderasi/{message}/abaikan', [\App\Http\Controllers\Admin\AdminModerationController::class, 'dismiss'])->whereNumber('message')->name('moderation.dismiss');
    Route::post('/bulk-provenance-action', [AdminController::class, 'bulkProvenanceAction'])->name('bulk-provenance-action');
    Route::get('/paper-readiness', [AdminController::class, 'paperReadiness'])->name('paper-readiness');
    Route::get('/paper-readiness/export', [AdminController::class, 'exportPaperReadiness'])->name('paper-readiness.export');
    Route::get('/export-csv', [AdminController::class, 'exportCsv'])->name('export.csv');
    Route::get('/export-embeddings', [AdminController::class, 'exportEmbeddings'])->name('export.embeddings');
    Route::get('/evaluation/provenance', [AdminEvaluationController::class, 'provenance'])->name('evaluation.provenance');
    Route::get('/evaluation/provenance/export-summary', [AdminEvaluationController::class, 'exportSummary'])->name('evaluation.provenance.export-summary');
    Route::get('/evaluation/provenance/export-versions', [AdminEvaluationController::class, 'exportVersions'])->name('evaluation.provenance.export-versions');
    Route::get('/paper-outputs/provenance', [AdminEvaluationController::class, 'paperOutputs'])->name('paper-outputs.provenance');
    Route::post('/paper-outputs/provenance/capture-evaluation-snapshot', [AdminEvaluationController::class, 'captureEvaluationSnapshot'])->name('paper-outputs.provenance.capture-evaluation-snapshot');
    Route::get('/paper-outputs/provenance/export-integrity', [AdminEvaluationController::class, 'exportIntegrityTable'])->name('paper-outputs.provenance.export-integrity');
    Route::get('/paper-outputs/provenance/export-version-recovery', [AdminEvaluationController::class, 'exportVersionRecoveryTable'])->name('paper-outputs.provenance.export-version-recovery');
    Route::get('/paper-outputs/provenance/export-black-box-testing', [AdminEvaluationController::class, 'exportBlackBoxTestingTable'])->name('paper-outputs.provenance.export-black-box-testing');
    Route::get('/paper-outputs/provenance/export-sus-responses', [AdminEvaluationController::class, 'exportSusResponses'])->name('paper-outputs.provenance.export-sus-responses');
    Route::get('/paper-outputs/provenance/export-recommendation-precision', [AdminEvaluationController::class, 'exportRecommendationPrecisionTable'])->name('paper-outputs.provenance.export-recommendation-precision');
    Route::get('/paper-outputs/provenance/export-before-after', [AdminEvaluationController::class, 'exportBeforeAfterTable'])->name('paper-outputs.provenance.export-before-after');
    Route::get('/paper-outputs/provenance/export-evaluation-results', [AdminEvaluationController::class, 'exportEvaluationResults'])->name('paper-outputs.provenance.export-evaluation-results');
});

// ---------------------------------------------------------------------------
// Account settings (unified account for the site and the chat, docs/SSO.md).
// The old profile page redirects here; its form routes keep their names and
// go through SettingsController so a password change always bumps sso_version.
// SSO JSON API: routes/api.php.
// ---------------------------------------------------------------------------
Route::middleware('auth')->group(function () {
    Route::get('/settings', [\App\Http\Controllers\SettingsController::class, 'show'])->name('settings');
    Route::post('/settings', [\App\Http\Controllers\SettingsController::class, 'update'])->name('settings.update');
    Route::get('/user/profile', fn () => redirect()->route('settings', status: 301))->name('user.profile');
    Route::put('/user/profile', [\App\Http\Controllers\SettingsController::class, 'updateProfile'])->name('user.profile.update');
    Route::put('/user/password', [\App\Http\Controllers\SettingsController::class, 'updatePassword'])->name('user.password.update');
});

// ---------------------------------------------------------------------------
// Site UI: language switch (ID | EN, rs_lang cookie, see SetLocale) and likes
// without login (guests are identified by the rs_vid visitor cookie).
// ---------------------------------------------------------------------------
Route::get('/lang/{locale}', [\App\Http\Controllers\LocaleController::class, 'switch'])
    ->whereIn('locale', \App\Http\Middleware\SetLocale::SUPPORTED)
    ->name('lang.switch');
Route::post('/routes/{route}/like', [RouteController::class, 'toggleLike'])
    ->middleware('throttle:30,1')
    ->name('routes.like.toggle');

// ---------------------------------------------------------------------------
// Floating widgets (components/floating-assistants):
// - right: "Asisten RuteStrip" Q&A chatbot for everyone (POST JSON
//   {message, history} -> {reply}; CSRF, rate limited per IP in the controller);
// - left: "Obrolan Pendaki", community chat between logged-in hikers (JSON,
//   auth, guests get 401; limits in CommunityChatController). The guest
//   "Daftar / Masuk" link brings the visitor back to the page afterwards;
//   /assistant/chat/login is the old address of that link.
// ---------------------------------------------------------------------------
Route::post('/assistant', [\App\Http\Controllers\AssistantController::class, 'ask'])->name('assistant.ask');
Route::get('/komunitas/masuk', [\App\Http\Controllers\CommunityChatController::class, 'login'])->name('community.login');
Route::get('/assistant/chat/login', [\App\Http\Controllers\CommunityChatController::class, 'login'])->name('assistant.chat.login');
// Throttle prefixes keep these counters apart from other "throttle:x,y" routes
// (without a prefix every such route shares one counter per user).
Route::prefix('komunitas/chat')->name('community.chat.')->middleware('auth')
    ->controller(\App\Http\Controllers\CommunityChatController::class)->group(function () {
        Route::get('/', 'index')->middleware('throttle:90,1,cc-read')->name('index');
        Route::get('/latest', 'latest')->middleware('throttle:30,1,cc-latest')->name('latest');
        Route::post('/', 'store')->name('store');
        Route::delete('/{message}', 'destroy')->whereNumber('message')->middleware('throttle:30,1,cc-delete')->name('destroy');
        Route::post('/{message}/report', 'report')->whereNumber('message')->middleware('throttle:20,1,cc-report')->name('report');
    });
