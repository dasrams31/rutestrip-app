<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminEvaluationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ProvenanceController;
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

// GPX upload (single & batch) changes the public dataset: admins only.
Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/routes/create', [RouteController::class, 'create'])->name('routes.create');
    Route::post('/routes', [RouteController::class, 'store'])->name('routes.store');
    Route::get('/routes-batch', [RouteController::class, 'createBatch'])->name('routes.batch');
    Route::post('/routes-batch', [RouteController::class, 'storeBatch'])->name('routes.batch.store');
});

// Public Routes - View
Route::get('/routes', [RouteController::class, 'index'])->name('routes.index');
Route::get('/routes/{route}', [RouteController::class, 'show'])->name('routes.show');
Route::get('/routes/{route}/provenance', [ProvenanceController::class, 'show'])->name('routes.provenance');
Route::get('/routes/{route}/provenance/{version}/download', [ProvenanceController::class, 'downloadVersion'])->name('routes.provenance.download-version');
Route::get('/routes/{route}/similar', [RouteController::class, 'similarRoutes'])->name('routes.similar');

// Reviews & Ratings (Auth required)
Route::middleware('auth')->group(function () {
    Route::post('/routes/{route}/review', [RouteController::class, 'storeReview'])->name('routes.review.store');
    Route::post('/routes/{route}/like', [RouteController::class, 'toggleLike'])->name('routes.like.toggle');
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

// User Authentication (Guest only)
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
    Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.submit');
    Route::get('/admin/login', [AuthController::class, 'showAdminLoginForm'])->name('admin.login');
    Route::post('/admin/login', [AuthController::class, 'adminLogin'])->name('admin.login.submit');
});

// Logout
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// User Dashboard
Route::prefix('user')->name('user.')->middleware('auth')->group(function () {
    Route::get('/dashboard', [UserController::class, 'dashboard'])->name('dashboard');
    Route::get('/dashboard/stats', [UserController::class, 'statsApi'])->name('dashboard.stats');
    Route::get('/favorites', [UserController::class, 'favorites'])->name('favorites');
    Route::post('/favorite/{route}', [UserController::class, 'toggleFavorite'])->name('favorite.toggle');
    Route::get('/history', [UserController::class, 'history'])->name('history');
    Route::get('/profile', [UserController::class, 'editProfile'])->name('profile');
    Route::put('/profile', [UserController::class, 'updateProfile'])->name('profile.update');
    Route::put('/password', [UserController::class, 'updatePassword'])->name('password.update');
});

// Admin Dashboard
Route::prefix('admin')->name('admin.')->middleware('auth')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
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
