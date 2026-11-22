<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\HikingRoute;
use App\Models\Rating;
use App\Services\ActivityLogger;
use App\Services\RouteApiPresenter;
use App\Services\RouteConditionsService;
use App\Services\SsoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Route page extras for the mobile app (docs/API.md): reviews, live conditions
 * and the GPX version history, as on the website's route page.
 */
class RouteExtrasApiController extends Controller
{
    public function __construct(private RouteApiPresenter $present, private SsoService $sso)
    {
    }

    /** GET /api/v1/routes/{id}/reviews: average, count, latest 30 written reviews, the viewer's own rating. */
    public function reviews(Request $request, HikingRoute $route): JsonResponse
    {
        $ratings  = Rating::where('hiking_route_id', $route->id)->pluck('rating', 'user_id');
        $comments = Comment::with('user:id,name')->where('hiking_route_id', $route->id)->latest('id')->take(30)->get();
        $user     = $this->sso->user($request->bearerToken());

        return response()->json([
            'average_rating' => $ratings->isEmpty() ? null : round((float) $ratings->avg(), 1),
            'review_count'   => $ratings->count(),
            'my_rating'      => $user ? ($ratings[$user->id] ?? null) : null,
            'reviews'        => $comments->map(fn(Comment $c) => $this->present->review($c, isset($ratings[$c->user_id]) ? (int) $ratings[$c->user_id] : null))->values(),
        ]);
    }

    /** POST /api/v1/routes/{id}/reviews {rating 1-5, comment?}: same rules as the website form. */
    public function storeReview(Request $request, HikingRoute $route): JsonResponse
    {
        $data = $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);
        $userId = $request->user()->id;

        $rating = Rating::updateOrCreate(['user_id' => $userId, 'hiking_route_id' => $route->id], ['rating' => $data['rating']]);
        $comment = trim((string) ($data['comment'] ?? ''));
        if ($comment !== '') {
            Comment::create(['user_id' => $userId, 'hiking_route_id' => $route->id, 'content' => $comment]);
        }

        app(ActivityLogger::class)->log('review.created', null, $route, [
            'name' => $route->name, 'rating' => (int) $data['rating'], 'comment' => $comment !== '', 'repeat' => ! $rating->wasRecentlyCreated, 'via' => 'app',
        ]);

        return $this->reviews($request, $route)->setStatusCode(201);
    }

    /** GET /api/v1/routes/{id}/conditions: basecamp/summit, location, weather and safety notes (cached server side). */
    public function conditions(HikingRoute $route, RouteConditionsService $conditions): JsonResponse
    {
        return response()->json($conditions->conditions($route), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** GET /api/v1/routes/{id}/versions: GPX file history with fingerprints and download links. */
    public function versions(HikingRoute $route): JsonResponse
    {
        $versions = $route->gpxVersions()->with('uploader:id,name')->orderByDesc('version_number')->get();

        return response()->json([
            'data' => $versions->map(fn($v) => [
                'version'      => (int) $v->version_number,
                'active'       => (bool) $v->is_active,
                'status'       => $v->verification_status,
                'status_label' => __($v->verification_status_explanation),
                'hash'         => $v->file_hash,
                'change_log'   => $v->change_log,
                'uploader'     => $v->uploader?->name ?? 'Tim RuteStrip',
                'created_at'   => $v->created_at?->toIso8601String(),
                'download_url' => route('routes.provenance.download-version', [$route, $v]),
            ])->values(),
        ]);
    }
}
