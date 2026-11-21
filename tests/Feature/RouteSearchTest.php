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

    public function test_gentle_query_orders_by_average_grade(): void
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

        // "landai" = lowest average grade first (owner's rule), even for a long route.
        $this->assertSame(['Gunung Semeru', 'Gunung Merbabu via Selo', 'Gunung Slamet via Dipajaya'], $ranked->pluck('name')->all());
    }

    public function test_more_specific_name_match_ranks_higher(): void
    {
        $emb = [1.0, 0.0, 0.0];
        $routes = collect(['Mt. Sumbing via Garung', 'Mt. Sumbing via Butuh Kaliangkrik', 'Mt. Merbabu via Selo'])
            ->map(fn($n) => new HikingRoute([
                'name' => $n, 'distance_km' => 10, 'elevation_gain_m' => 1500,
                'naismith_duration_hour' => 5, 'average_grade_pct' => 12,
                'sbert_embedding' => $emb, 'sbert_aspect_embeddings' => [$emb],
            ]));

        $ranked = app(RouteSearchService::class)->rank('sumbing via butuh', $emb, $routes, 3);

        // A named mountain is a filter: only Sumbing routes, the named basecamp first.
        $this->assertSame(['Mt. Sumbing via Butuh Kaliangkrik', 'Mt. Sumbing via Garung'], $ranked->pluck('name')->all());
    }

    private function placedRoutes(array $places): \Illuminate\Support\Collection
    {
        $emb = [1.0, 0.0, 0.0];

        return collect($places)->map(fn($p) => (new HikingRoute([
            'name' => $p[0], 'distance_km' => $p[3], 'elevation_gain_m' => $p[4],
            'naismith_duration_hour' => $p[3] / 5 + $p[4] / 600, 'average_grade_pct' => $p[4] / ($p[3] * 10),
            'sbert_embedding' => $emb, 'sbert_aspect_embeddings' => [$emb],
        ]))->forceFill(['province' => $p[1], 'regency' => $p[2]]));
    }

    public function test_query_naming_a_province_ranks_routes_there_first(): void
    {
        $routes = $this->placedRoutes([
            ['Kawah Ijen via Sunrise View Trek', 'Jawa Timur', 'Banyuwangi', 1.9, 98],
            ['Puncak Koromong via Tugu Baleendah', 'Jawa Barat', 'Kabupaten Bandung', 3.3, 390],
            ['Gunung Andong via Gogik', 'Jawa Tengah', 'Magelang', 0.9, 252],
            ['Puncak Upas via Sukawana', 'Jawa Barat', 'Kabupaten Bandung Barat', 8.0, 536],
        ]);
        $search = app(RouteSearchService::class);

        foreach (['jalur landai di Jawa Barat', 'jalur pemula jabar', 'west java easy trail'] as $query) {
            $ranked = $search->rank($query, [1.0, 0.0, 0.0], $routes->map(fn($r) => clone $r), 4);
            $this->assertSame(['Jawa Barat', 'Jawa Barat'], $ranked->take(2)->pluck('province')->all(), $query);
        }

        // Regency names count too, and "barat" is not treated as a mountain name.
        $ranked = $search->rank('pendakian dekat Banyuwangi', [1.0, 0.0, 0.0], $routes->map(fn($r) => clone $r), 4);
        $this->assertSame('Kawah Ijen via Sunrise View Trek', $ranked->first()->name);
    }

    public function test_place_words_do_nothing_when_no_route_has_a_location(): void
    {
        $routes = $this->placedRoutes([
            ['Gunung Merbabu via Selo', null, null, 11.1, 1424],
            ['Gunung Slamet via Dipajaya', null, null, 6.6, 1870],
        ]);
        $search = app(RouteSearchService::class);

        $plain = $search->rank('jalur pendakian', [1.0, 0.0, 0.0], $routes->map(fn($r) => clone $r), 2)->pluck('similarity_score')->all();
        $placed = $search->rank('jalur pendakian di jawa barat', [1.0, 0.0, 0.0], $routes->map(fn($r) => clone $r), 2)->pluck('similarity_score')->all();

        $this->assertSame($plain, $placed);
        $this->assertSame(['provinces' => ['jawa barat'], 'regencies' => []], $search->detectRegions('di Jawa Barat', $routes));
    }

    public function test_difficulty_uses_climb_distance_steepness_and_altitude(): void
    {
        $route = fn(float $km, int $gain, float $grade, int $maxEle) => new HikingRoute([
            'distance_km' => $km, 'elevation_gain_m' => $gain, 'average_grade_pct' => $grade,
            'narrative_text' => "Total kenaikan elevasi {$gain} meter dengan ketinggian dari 1000m hingga {$maxEle}m.",
        ]);

        $this->assertSame('Mudah', $route(1.2, 255, 20.6, 1889)->difficulty_level);          // Bukit Sikendil
        $this->assertSame('Sedang', $route(3.2, 866, 27.4, 1745)->difficulty_level);         // Andong: short but steep
        $this->assertSame('Sulit', $route(3.5, 1233, 34.9, 2804)->difficulty_level);         // Cikuray: >= 1,200 m climb
        $this->assertSame('Sulit', $route(11.1, 1424, 12.8, 3128)->difficulty_level);        // Merbabu via Selo
        $this->assertSame('Sangat Sulit', $route(39.8, 2356, 5.9, 3623)->difficulty_level);  // Semeru: low grade, long
    }

    public function test_negated_steepness_is_read_as_gentle(): void
    {
        $search = app(RouteSearchService::class);

        $this->assertSame(['gentle'], $search->detectIntents('jalur yang tidak terlalu curam'));
        $this->assertSame(['easy'], $search->detectIntents('jalur untuk pemula'));
        $this->assertSame([], $search->detectIntents('jalur landai tapi curam'));
    }
}
