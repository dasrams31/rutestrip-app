<?php

namespace Tests\Feature;

use App\Models\HikingRoute;
use App\Services\PythonProcessorService;
use App\Services\RouteSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_route_indexes_it_exactly_once(): void
    {
        config(['services.python.auto_index' => true]);

        $fake = new class extends PythonProcessorService {
            public int $calls = 0;

            public function indexRoutes(array $routes): array
            {
                $this->calls++;

                return array_map(fn($r) => [
                    'id'                => $r['id'],
                    'embedding_text'    => 'Jalur pendakian ' . $r['name'],
                    'embedding'         => [0.1, 0.2, 0.3],
                    'aspect_embeddings' => [[0.1, 0.2, 0.3]],
                ], $routes);
            }
        };
        $this->app->instance(PythonProcessorService::class, $fake);

        $route = HikingRoute::create([
            'name'           => 'Gunung Andong via Sawit',
            'gpx_file_path'  => 'gpx_files/andong.gpx',
            'narrative_text' => 'Jalur pendakian dengan jarak pendek.',
        ]);

        $this->assertSame(1, $fake->calls);
        $this->assertSame([[0.1, 0.2, 0.3]], $route->fresh()->sbert_aspect_embeddings);

        $route->update(['elevation_gain_m' => 500]);
        $this->assertSame(1, $fake->calls, 'Changing stats only must not re-index');

        $route->update(['description' => 'Bukit pendek dengan pemandangan sabana.']);
        $this->assertSame(2, $fake->calls);
    }

    public function test_gentle_query_prefers_low_grade_short_routes_over_long_ones(): void
    {
        $emb = [1.0, 0.0, 0.0];
        $routes = collect([
            ['Gunung Semeru', 39.8, 2356, 11.9, 5.9],
            ['Gunung Merbabu via Selo', 11.1, 1424, 4.6, 12.8],
            ['Gunung Slamet via Dipajaya', 6.6, 1870, 4.4, 28.3],
        ])->map(fn($r) => new HikingRoute([
            'name' => $r[0], 'distance_km' => $r[1], 'elevation_gain_m' => $r[2],
            'naismith_duration_hour' => $r[3], 'average_grade_pct' => $r[4],
            'sbert_embedding' => $emb, 'sbert_aspect_embeddings' => [$emb],
        ]));

        $ranked = app(RouteSearchService::class)->rank('jalur landai', $emb, $routes, 3);

        $this->assertSame('Gunung Merbabu via Selo', $ranked->first()->name);
        $this->assertSame('Gunung Slamet via Dipajaya', $ranked->last()->name);
    }

    public function test_negated_steepness_is_read_as_gentle(): void
    {
        $search = app(RouteSearchService::class);

        $this->assertSame(['gentle'], $search->detectIntents('jalur yang tidak terlalu curam'));
        $this->assertSame(['easy'], $search->detectIntents('jalur untuk pemula'));
        $this->assertSame([], $search->detectIntents('jalur landai tapi curam'));
    }
}
