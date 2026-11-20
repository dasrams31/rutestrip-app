<?php
namespace App\Services;

use App\Models\HikingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the search embeddings of routes up to date: the document embedding
 * (route name + narrative + description, stored in sbert_embedding) and the
 * per-aspect embeddings used by RouteSearchService.
 */
class RouteSearchIndexer
{
    public function __construct(private PythonProcessorService $python)
    {
    }

    /**
     * Index one route. Failures are logged and swallowed so an upload never
     * fails because of indexing; the previous embedding stays in place.
     */
    public function index(HikingRoute $route): bool
    {
        try {
            return $this->indexMany(collect([$route])) === 1;
        } catch (\Throwable $e) {
            Log::warning('Route search indexing failed for route ' . $route->id . ': ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @return int number of routes updated
     */
    public function indexMany(Collection $routes): int
    {
        $routes = $routes->filter(fn($r) => filled($r->narrative_text))->keyBy('id');
        if ($routes->isEmpty()) {
            return 0;
        }

        $items = $this->python->indexRoutes($routes->map(fn(HikingRoute $r) => [
            'id'             => $r->id,
            'name'           => $r->name,
            'narrative_text' => $r->narrative_text,
            'description'    => $r->description,
        ])->values()->all());

        $updated = 0;
        foreach ($items as $item) {
            $route = $routes->get($item['id'] ?? null);
            if (! $route || empty($item['embedding'])) {
                continue;
            }

            $route->forceFill([
                'embedding_text'          => $item['embedding_text'] ?? null,
                'sbert_embedding'         => $item['embedding'],
                'sbert_aspect_embeddings' => $item['aspect_embeddings'] ?? [],
            ])->save();
            $updated++;
        }

        Cache::forget('routes_with_embeddings');

        return $updated;
    }
}
