<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\HikingRoute;
use App\Models\User;
use App\Services\RouteApiPresenter;
use App\Services\RouteSearchService;
use App\Services\SsoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Route list, filters, detail and likes for the mobile app (docs/API.md).
 * Stateless JSON; a signed-in user is the SSO bearer token (/api/sso/login).
 */
class RouteApiController extends Controller
{
    public const PER_PAGE = 20;

    public const SIMILAR = 4;

    public function __construct(
        private RouteApiPresenter $present,
        private RouteSearchService $search,
        private SsoService $sso,
    ) {
    }

    /** GET /api/v1/routes?page=&per_page=&mountain=&province= */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
            'mountain' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
        ]);

        $mountain = trim((string) $request->query('mountain'));
        $province = trim((string) $request->query('province'));

        $routes = $this->listRoutes()
            ->when($mountain !== '', fn($c) => $c->filter(fn($r) => strcasecmp((string) $this->present->mountain($r->name), $mountain) === 0))
            ->when($province !== '', fn($c) => $c->filter(fn($r) => strcasecmp((string) $r->province, $province) === 0))
            ->values();

        $perPage = (int) $request->query('per_page', self::PER_PAGE);
        $page    = LengthAwarePaginator::resolveCurrentPage();
        $items   = $routes->forPage($page, $perPage)->map(fn($r) => $this->present->summary($r))->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($routes->count() / $perPage)),
                'per_page'     => $perPage,
                'total'        => $routes->count(),
                'mountain'     => $mountain !== '' ? $mountain : null,
                'province'     => $province !== '' ? $province : null,
            ],
        ]);
    }

    /** GET /api/v1/filters: mountains and provinces with route counts, plus the example searches. */
    public function filters(): JsonResponse
    {
        $routes = $this->listRoutes();

        $count = fn(Collection $names) => $names->filter()->countBy()->sortKeys()
            ->map(fn($n, $name) => ['name' => $name, 'count' => $n])->values();

        return response()->json([
            'mountains' => $count($routes->map(fn($r) => $this->present->mountain($r->name))),
            'provinces' => $count($routes->pluck('province')),
            'examples'  => [
                __('Jalur landai untuk pemula'),
                __('Pendakian singkat 2-3 jam'),
                __('Jalur curam dan menantang'),
            ],
            'total_routes' => $routes->count(),
        ]);
    }

    /** GET /api/v1/routes/{id} */
    public function show(Request $request, HikingRoute $route): JsonResponse
    {
        $route->loadCount('likes');
        $user = $this->sso->user($request->bearerToken());

        return response()->json([
            'data' => $this->present->detail($route) + [
                'liked'   => $user ? $route->isLikedBy($user->id) : false,
                'similar' => $this->similar($route),
            ],
        ]);
    }

    /** POST /api/v1/routes/{id}/like {liked?: bool}; signed-in users only. */
    public function like(Request $request, HikingRoute $route): JsonResponse
    {
        $user = $this->user($request);
        if (! $user) {
            return $this->unauthorized();
        }

        $request->validate(['liked' => 'nullable|boolean']);

        $owner  = fn() => Favorite::where('hiking_route_id', $route->id)->where('user_id', $user->id);
        $liked  = $owner()->exists();
        $wanted = $request->has('liked') ? $request->boolean('liked') : ! $liked;

        if ($wanted && ! $liked) {
            try {
                Favorite::create(['user_id' => $user->id, 'hiking_route_id' => $route->id]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // A parallel request already stored this like.
            }
        } elseif (! $wanted && $liked) {
            $owner()->delete();
        }

        return response()->json(['liked' => $wanted, 'count' => $route->likes()->count()]);
    }

    /** GET /api/v1/likes: the signed-in user's liked routes, newest like first. */
    public function likes(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (! $user) {
            return $this->unauthorized();
        }

        $ids = Favorite::where('user_id', $user->id)->latest('id')->pluck('hiking_route_id')->all();
        $routes = HikingRoute::query()->select(RouteApiPresenter::LIST_COLUMNS)->withCount('likes')
            ->whereIn('id', $ids)->get()
            ->sortBy(fn($r) => array_search($r->id, $ids, true))
            ->map(fn($r) => $this->present->summary($r))
            ->values();

        return response()->json(['data' => $routes]);
    }

    /** All routes for lists (no embeddings), by name. */
    private function listRoutes(): Collection
    {
        return HikingRoute::query()->select(RouteApiPresenter::LIST_COLUMNS)->withCount('likes')
            ->orderBy('name')->get();
    }

    /** Closest routes by SBERT document cosine, as on the route page. */
    private function similar(HikingRoute $route): array
    {
        if (! is_array($route->sbert_embedding) || ! $route->sbert_embedding) {
            return [];
        }

        // Same cache entry as the search (SearchController / SearchApiController).
        $all = Cache::remember('routes_with_embeddings', 300, function () {
            return HikingRoute::whereNotNull('sbert_embedding')->with(['activeGpxVersion', 'uploader'])->get();
        });

        return $all
            ->filter(fn($r) => $r->id !== $route->id && is_array($r->sbert_embedding))
            ->map(fn($r) => ['route' => $r, 'cos' => $this->search->cosine($route->sbert_embedding, $r->sbert_embedding)])
            ->sortByDesc('cos')
            ->take(self::SIMILAR)
            ->map(fn($row) => $this->present->summary($row['route']) + ['similarity_score' => round($row['cos'] * 100, 1)])
            ->values()
            ->all();
    }

    private function user(Request $request): ?User
    {
        return $this->sso->user($request->bearerToken());
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['error' => __('account.api.invalid_token')], 401);
    }
}
