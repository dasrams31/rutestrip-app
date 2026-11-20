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
        $landing = Cache::remember('landing:v1', 600, fn() => $this->landingData());

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

        // Featured: most liked first, then newest, one route per mountain, with a track to draw.
        $featured = $routes
            ->filter(fn($r) => is_array($r->route_coordinates) && count($r->route_coordinates) > 1 && $mountainOf($r))
            ->sortBy([['likes_count', 'desc'], ['id', 'desc']])
            ->unique(fn($r) => $mountainOf($r))
            ->take(3)
            ->map(fn($r) => [
                'id'         => $r->id,
                'name'       => $r->name,
                'url'        => route('routes.show', $r),
                'difficulty' => $r->difficulty_level,
                'distance'   => (float) $r->distance_km,
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
                'message' => 'Belum ada data jalur pendakian. Silakan upload file GPX terlebih dahulu.'
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
            $notice = null;
            if (in_array('gentle', $this->routeSearch->detectIntents($query), true)
                && $routes->min('average_grade_pct') >= 5) {
                $notice = 'Belum ada jalur dengan kemiringan rata-rata di bawah 5% (kategori landai) di database. '
                    . 'Ditampilkan jalur paling ringan berdasarkan kemiringan, total tanjakan, dan durasi.';
            }

            return view('search.results', [
                'query'      => $query,
                'results'    => $results,
                'searchTime' => $searchTime,
                'notice'     => $notice,
            ]);

        } catch (\Throwable $e) {
            return back()->withErrors(['query' => 'Gagal melakukan pencarian: ' . $e->getMessage()])
                ->withInput();
        }
    }
}
