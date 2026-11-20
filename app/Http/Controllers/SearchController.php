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
        return view('search.index');
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
            return HikingRoute::whereNotNull('sbert_embedding')->with('activeGpxVersion')->get();
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

            return view('search.results', [
                'query'      => $query,
                'results'    => $results,
                'searchTime' => $searchTime,
            ]);

        } catch (\Throwable $e) {
            return back()->withErrors(['query' => 'Gagal melakukan pencarian: ' . $e->getMessage()])
                ->withInput();
        }
    }
}
