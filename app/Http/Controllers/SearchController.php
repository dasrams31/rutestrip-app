<?php
namespace App\Http\Controllers;

use App\Models\HikingRoute;
use App\Models\SearchHistory;
use App\Services\PythonProcessorService;
use App\Services\RouteSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class SearchController extends Controller
{
    public function __construct(
        private PythonProcessorService $pythonService,
        private RouteSearchService $routeSearch
    ) {
    }

    /**
     * Show search form
     */
    public function index()
    {
        // Landing data, cached briefly: plain arrays only (no external calls, Cache-only locations).
        // v2: featured routes skip tracks that draw as a straight line or an up-and-back line.
        $landing = Cache::remember('landing:v2', 600, fn() => $this->landingData());

        return view('search.index', $landing);
    }

    /**
     * Stats (routes, mountains, provinces from cached trailhead locations,
     * total trail length) and a few featured routes for the home page.
     */
    private function landingData(): array
    {
        $conditions = app(\App\Services\RouteConditionsService::class);
        $routes     = HikingRoute::query()
            ->select(['id', 'name', 'distance_km', 'elevation_gain_m', 'naismith_duration_hour', 'average_grade_pct', 'narrative_text', 'route_coordinates'])
            ->withCount('likes')
            ->get();

        $mountainOf = fn(HikingRoute $r) => preg_match('/^(?:mt\.?|gunung|g\.)\s+([\p{L}\-]+)/iu', trim((string) $r->name), $m)
            ? mb_convert_case($m[1], MB_CASE_TITLE)
            : null;

        $provinces = $routes->map(fn($r) => $conditions->cachedLocation($r)['province'] ?? null)->filter()->unique();

        // Featured: most liked first, then newest, one route per mountain, with a track that
        // actually looks like a trail when drawn (see trackLooksLikeTrail).
        $featured = $routes
            ->filter(fn($r) => $mountainOf($r) && $this->trackLooksLikeTrail($r->route_coordinates))
            ->sortBy([['likes_count', 'desc'], ['id', 'desc']])
            ->unique(fn($r) => $mountainOf($r))
            ->take(3)
            ->map(fn($r) => [
                'id'         => $r->id,
                'name'       => $r->name,
                'url'        => route('routes.show', $r),
                'difficulty' => $r->difficulty_level,
                'distance'   => (float) $r->distance_km,
                'gain'       => (int) round((float) $r->elevation_gain_m),
                'hours'      => (float) $r->naismith_duration_hour,
                'summit'     => $r->max_elevation_m,
                'likes'      => (int) $r->likes_count,
                'basecamp'   => $conditions->basecampName($r),
                'track'      => $this->thinTrack($r->route_coordinates, 80),
            ])
            ->values()
            ->all();

        return [
            'stats' => [
                'routes'    => $routes->count(),
                'mountains' => $routes->map($mountainOf)->filter()->unique()->count(),
                'provinces' => $provinces->count(),
                'km'        => (int) round($routes->sum('distance_km')),
            ],
            'featured' => $featured,
        ];
    }

    /**
     * Whether a stored track is good enough to headline the landing page:
     * - at least 40 points;
     * - its bounding box is not a sliver (shorter side >= 25% of the longer side),
     *   so it does not draw as a straight line;
     * - it is not an up-and-back recording: at most 60% of the way back (after the
     *   point farthest from the start) may lie on top of the way out (those draw as
     *   one line that ends where it began).
     */
    private function trackLooksLikeTrail(mixed $coords): bool
    {
        if (! is_array($coords) || count($coords) < 40) {
            return false;
        }

        $track = $this->thinTrack($coords, 160);
        if (count($track) < 40) {
            return false;
        }

        // Local metres (equirectangular around the track's mean latitude).
        $lat0 = deg2rad(array_sum(array_column($track, 0)) / count($track));
        $pts  = array_map(fn($p) => [$p[1] * 111320 * cos($lat0), $p[0] * 110540], $track);
        $xs   = array_column($pts, 0);
        $ys   = array_column($pts, 1);
        $w    = max($xs) - min($xs);
        $h    = max($ys) - min($ys);
        $long = max($w, $h);
        if ($long < 200 || min($w, $h) < 0.25 * $long) {
            return false;
        }

        // Turnaround = the point farthest from the start (recordings are often denser
        // on the way up, so the middle index is not the summit). Compare the way back
        // with the way out.
        $dist   = array_map(fn($p) => hypot($p[0] - $pts[0][0], $p[1] - $pts[0][1]), $pts);
        $turn   = array_search(max($dist), $dist, true);
        $first  = array_slice($pts, 0, $turn + 1);
        $second = array_slice($pts, $turn + 1);
        if (count($second) < 3 || count($first) < 2) {
            return true; // one-way track (traverse or ascent only)
        }
        $near   = max(40.0, 0.03 * hypot($w, $h));
        $onTop  = 0;
        foreach ($second as $p) {
            for ($i = 0, $n = count($first) - 1; $i < $n; $i++) {
                if ($this->segmentDistance($p, $first[$i], $first[$i + 1]) <= $near) {
                    $onTop++;
                    break;
                }
            }
        }

        return $onTop <= 0.6 * count($second);
    }

    /** Distance from point $p to segment $a-$b (all in metres). */
    private function segmentDistance(array $p, array $a, array $b): float
    {
        [$dx, $dy] = [$b[0] - $a[0], $b[1] - $a[1]];
        $len2 = $dx * $dx + $dy * $dy;
        $t    = $len2 > 0 ? max(0, min(1, (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / $len2)) : 0;

        return hypot($p[0] - ($a[0] + $t * $dx), $p[1] - ($a[1] + $t * $dy));
    }

    /**
     * At most $max [lat, lng] points of a track (for small map thumbnails).
     */
    private function thinTrack(array $coords, int $max): array
    {
        $coords = array_values(array_filter($coords, fn($p) => is_array($p) && isset($p[0], $p[1])));
        $step   = max(1, (int) ceil(count($coords) / $max));
        $out    = [];
        foreach ($coords as $i => $p) {
            if ($i % $step === 0 || $i === count($coords) - 1) {
                $out[] = [round((float) $p[0], 5), round((float) $p[1], 5)];
            }
        }

        return $out;
    }

    /**
     * Process search and show results: SBERT query embedding + hybrid scoring in PHP
     */
    public function search(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:3|max:500',
        ]);

        $query     = $request->input('query');
        $startTime = microtime(true);

        // Get all routes with embeddings (cached for 5 minutes)
        $routes = Cache::remember('routes_with_embeddings', 300, function () {
            return HikingRoute::whereNotNull('sbert_embedding')->with(['activeGpxVersion', 'uploader'])->get();
        });

        if ($routes->isEmpty()) {
            return view('search.results', [
                'query'   => $query,
                'results' => collect([]),
                'message' => __('Belum ada jalur di database. Jalur muncul di sini setelah ada yang mengunggah file GPX.'),
            ]);
        }

        try {
            // Get query embedding from Python (one-time call)
            $queryEmbedding = $this->pythonService->getQueryEmbedding($query);

            if (! is_array($queryEmbedding) || empty($queryEmbedding)) {
                throw new \RuntimeException('Embedding query kosong atau tidak valid.');
            }

            // Hybrid relevance (SBERT cosine on document + aspects, intent and name match)
            $results = $this->routeSearch->rank($query, $queryEmbedding, $routes->map(fn($r) => clone $r), 10);

            $searchTime = round((microtime(true) - $startTime) * 1000, 2);

            // Save search history for logged-in users
            if (Auth::check()) {
                SearchHistory::create([
                    'user_id'       => Auth::id(),
                    'query'         => $query,
                    'results_count' => $results->count(),
                ]);
            }

            // Be explicit when the user asks for gentle/beginner routes but the
            // dataset has none below the narrative's "landai" threshold (5%).
            $intents = $this->routeSearch->detectIntents($query);
            $notice  = null;
            if (in_array('gentle', $intents, true) && $routes->min('average_grade_pct') >= 5) {
                $notice = __('Jalur yang benar-benar landai (kemiringan rata-rata di bawah 5%) belum ada di database. Ini jalur yang paling ringan: tanjakannya paling sedikit dan waktunya paling singkat.');
            }

            return view('search.results', [
                'query'      => $query,
                'results'    => $results,
                'searchTime' => $searchTime,
                'notice'     => $notice,
                'intents'    => $intents,
            ]);

        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['query' => __('Pencarian sedang tidak bisa dipakai. Coba lagi sebentar lagi, atau buka daftar semua jalur.')])
                ->withInput();
        }
    }
}
