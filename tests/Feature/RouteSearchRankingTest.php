<?php

namespace Tests\Feature;

use App\Models\HikingRoute;
use App\Services\PythonProcessorService;
use App\Services\RouteSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Filters (named mountain, province/regency) and intent ordering. The fixture
 * (real statistics of 18 routes) is shared with python/test_search_scoring.py,
 * so both implementations must give the same order.
 */
class RouteSearchRankingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/fixtures/search_ranking.json')), true);
    }

    private function routes(): Collection
    {
        return collect($this->fixture()['routes'])->map(fn($r) => (new HikingRoute([
            'name' => $r['name'], 'distance_km' => $r['distance_km'], 'elevation_gain_m' => $r['elevation_gain_m'],
            'naismith_duration_hour' => $r['naismith_duration_hour'], 'average_grade_pct' => $r['average_grade_pct'],
            'narrative_text' => $r['narrative_text'],
            'sbert_embedding' => $r['embedding'], 'sbert_aspect_embeddings' => $r['aspect_embeddings'],
        ]))->forceFill(['id' => $r['id'], 'province' => $r['province'], 'regency' => $r['regency']]));
    }

    private function rank(string $query, int $limit = 50, bool $filters = true): Collection
    {
        return app(RouteSearchService::class)->rank($query, $this->fixture()['query_vector'], $this->routes(), $limit, $filters);
    }

    public function test_order_matches_the_shared_fixture_used_by_python(): void
    {
        foreach ($this->fixture()['cases'] as $case) {
            $this->assertSame($case['expected'], $this->rank($case['query'], 10)->pluck('name')->all(), $case['query']);
        }
    }

    public function test_gentle_orders_by_average_grade_then_gain_and_puts_steep_routes_after(): void
    {
        foreach (['jalur landai', 'trek datar', 'jalur yang melandai'] as $query) {
            $ranked = $this->rank($query)->filter(fn($r) => app(RouteSearchService::class)->statsReliable($r))->values();
            $grades = $ranked->pluck('average_grade_pct')->all();
            $sorted = $grades;
            sort($sorted);
            $this->assertSame($sorted, $grades, $query);
            $this->assertSame('Kawah Ijen via Sunrise View Trek', $ranked->first()->name);
        }

        // Ties on grade go to the smaller climb; >= 15% after every gentler route.
        $route = fn(string $name, float $grade, int $gain) => new HikingRoute([
            'name' => $name, 'distance_km' => $gain / ($grade * 10), 'elevation_gain_m' => $gain,
            'naismith_duration_hour' => 3, 'average_grade_pct' => $grade,
            'sbert_embedding' => [1.0, 0.0, 0.0], 'sbert_aspect_embeddings' => [[1.0, 0.0, 0.0]],
        ]);
        $ranked = app(RouteSearchService::class)->rank('jalur landai', [1.0, 0.0, 0.0], collect([
            $route('Gunung Curam', 15.0, 300), $route('Gunung Besar', 8.0, 900), $route('Gunung Kecil', 8.0, 400), $route('Gunung Datar', 4.0, 1200),
        ]));
        $this->assertSame(['Gunung Datar', 'Gunung Kecil', 'Gunung Besar', 'Gunung Curam'], $ranked->pluck('name')->all());
    }

    public function test_gentle_uses_the_steepest_stretch_not_only_the_average(): void
    {
        // Semeru-like: very low average over a long walk, but a ~50% summit slope.
        $route = fn(string $name, float $avg, ?float $steepest) => (new HikingRoute([
            'name' => $name, 'distance_km' => 10, 'elevation_gain_m' => (int) ($avg * 100),
            'naismith_duration_hour' => 4, 'average_grade_pct' => $avg,
            'sbert_embedding' => [1.0, 0.0, 0.0], 'sbert_aspect_embeddings' => [[1.0, 0.0, 0.0]],
        ]))->forceFill(['steepest_grade_pct' => $steepest]);

        $routes = collect([
            $route('Gunung Semeru', 5.9, 51.0),
            $route('Kawah Sunrise', 5.2, 7.6),
            $route('Jalur Hutan', 5.5, 13.6),
            $route('Bukit Sedang', 7.6, 17.9),
        ]);
        $search = app(RouteSearchService::class);

        $this->assertSame(['Kawah Sunrise', 'Jalur Hutan', 'Bukit Sedang', 'Gunung Semeru'],
            $search->rank('jalur landai', [1.0, 0.0, 0.0], $routes->map(fn($r) => clone $r), 4)->pluck('name')->all());

        // "curam" puts the steepest stretch first.
        $this->assertSame('Gunung Semeru', $search->rank('jalur curam', [1.0, 0.0, 0.0], $routes->map(fn($r) => clone $r), 4)->first()->name);
    }

    public function test_easy_orders_by_difficulty_score_and_hard_from_the_hardest(): void
    {
        foreach (['jalur untuk pemula', 'pendakian mudah', 'jalur santai', 'jalur ringan', 'jalur keluarga'] as $query) {
            $scores = $this->rank($query)->take(17)->map(fn($r) => round($r->difficulty_score))->all();
            $sorted = $scores;
            sort($sorted);
            $this->assertSame($sorted, $scores, $query);
        }
        foreach (['jalur sulit', 'jalur menantang', 'pendakian ekstrem', 'jalur berat'] as $query) {
            $scores = $this->rank($query)->take(17)->map(fn($r) => round($r->difficulty_score))->all();
            $sorted = $scores;
            rsort($sorted);
            $this->assertSame($sorted, $scores, $query);
            $this->assertSame('Mt. Semeru', $this->rank($query)->first()->name);
        }
    }

    public function test_curam_and_terjal_order_by_steepest_grade(): void
    {
        foreach (['jalur curam', 'jalur terjal', 'jalur yang tidak landai'] as $query) {
            $grades = $this->rank($query)->take(17)->pluck('average_grade_pct')->all();
            $sorted = $grades;
            rsort($sorted);
            $this->assertSame($sorted, $grades, $query);
        }
    }

    public function test_short_and_long_order_by_naismith_time(): void
    {
        foreach (['pendakian singkat', 'jalur pendek', 'yang cepat', 'bisa tektok'] as $query) {
            $hours = $this->rank($query)->take(17)->pluck('naismith_duration_hour')->all();
            $sorted = $hours;
            sort($sorted);
            $this->assertSame($sorted, $hours, $query);
        }
        foreach (['jalur panjang', 'pendakian lama', 'ekspedisi', 'jalur lintas'] as $query) {
            $hours = $this->rank($query)->take(17)->pluck('naismith_duration_hour')->all();
            $sorted = $hours;
            rsort($sorted);
            $this->assertSame($sorted, $hours, $query);
        }
    }

    public function test_routes_with_broken_statistics_go_last_when_an_intent_sets_the_order(): void
    {
        foreach (['jalur landai', 'jalur untuk pemula', 'jalur curam', 'pendakian singkat', 'jalur panjang'] as $query) {
            $this->assertSame('Gunung Prau via Wates', $this->rank($query)->last()->name, $query); // 75 km: vehicle segment
        }
    }

    public function test_named_mountain_keeps_only_its_routes_including_spelling_variants(): void
    {
        $cases = [
            'merbabu'                => ['Merbabu', 4],
            'Gunung Merbabu'         => ['Merbabu', 4],
            'mt. merbabu via selo'   => ['Merbabu', 4],
            'gunung slamet'          => ['Slamet', 1],
            'arjuna'                 => ['Arju', 2],
            'gunung arjuno'          => ['Arju', 2],
            'puncak upas'            => ['Upas', 1],
            'bukit sikendil'         => ['Sikendil', 1],
            'kawah ijen'             => ['Ijen', 2],
            'gunung anak krakatau'   => ['Krakatau', 1],
        ];
        foreach ($cases as $query => [$word, $count]) {
            $names = $this->rank($query)->pluck('name');
            $this->assertCount($count, $names, $query);
            $this->assertTrue($names->every(fn($n) => str_contains($n, $word)), $query . ': ' . $names->implode(', '));
        }

        // Best match first: the named basecamp, then the other routes of that mountain.
        $this->assertSame('Gunung Sumbing via Garung', $this->rank('sumbing via garung')->first()->name);
        $this->assertSame('Mt. Merbabu via Selo', $this->rank('mt. merbabu via selo')->first()->name);
        $this->assertCount(3, $this->rank('sumbing via garung'));
    }

    public function test_several_named_mountains_show_routes_of_any_of_them(): void
    {
        $names = $this->rank('merbabu atau sumbing')->pluck('name');

        $this->assertCount(7, $names);
        $this->assertTrue($names->every(fn($n) => str_contains($n, 'Merbabu') || str_contains($n, 'Sumbing')));
    }

    public function test_everyday_words_are_not_read_as_mountain_names(): void
    {
        // "anak" is an intent word and the start of "Anak Krakatau"; only "gunung anak ..." is the mountain.
        $search = app(RouteSearchService::class);
        $this->assertSame([], $search->detectMountains('jalur untuk anak', $this->routes()));
        $this->assertSame(['easy'], $search->detectIntents('jalur untuk anak'));
        $this->assertSame([], $search->detectIntents('gunung anak krakatau'));
        $this->assertCount(18, $this->rank('jalur untuk anak'));
    }

    public function test_named_region_keeps_only_routes_there_and_place_words_are_not_mountains(): void
    {
        $this->assertSame(['Puncak Upas via Sukawana (Trek 11)'], $this->rank('jalur di jawa barat')->pluck('name')->all());
        $this->assertSame(['Kawah Ijen via Sunrise View Trek'], $this->rank('pendakian dekat Banyuwangi')->pluck('name')->all());
        $this->assertTrue($this->rank('gunung di jatim')->every(fn($r) => $r->province === 'Jawa Timur'));

        $plan = app(RouteSearchService::class)->plan('gunung di Magelang', $this->routes());
        $this->assertFalse($plan['mountain_filter']);
        $this->assertTrue($plan['region_filter']);
        $this->assertSame(['Magelang'], $plan['region_labels']);

        // A named place without routes there filters nothing.
        $this->assertCount(18, $this->rank('jalur di banten'));
    }

    public function test_filter_and_intent_combine(): void
    {
        $this->assertSame(
            ['Mt. Merbabu via Selo', 'Gunung Merbabu via Grenden', 'Gunung Merbabu via Wekas', 'Mt. Merbabu via Suwanting'],
            $this->rank('jalur landai di merbabu')->pluck('name')->all()
        );

        $ranked = $this->rank('jalur pemula di jawa timur');
        $this->assertTrue($ranked->every(fn($r) => $r->province === 'Jawa Timur'));
        $this->assertSame('Kawah Ijen via Sunrise View Trek', $ranked->first()->name);

        // Gentle first; among the gentle ones, the beginner-friendly ones come first.
        $this->assertSame(
            ['Kawah Ijen via Sunrise View Trek', 'Puncak Upas via Sukawana (Trek 11)', 'Gunung Ijen', 'Gunung Anak Krakatau', 'Mt. Semeru'],
            $this->rank('jalur landai untuk pemula')->take(5)->pluck('name')->all()
        );
    }

    public function test_negation_flips_the_intent(): void
    {
        $search = app(RouteSearchService::class);
        $this->assertSame(['gentle'], $search->detectIntents('jalur yang tidak terlalu curam'));
        $this->assertSame(['easy'], $search->detectIntents('jalur yang tidak sulit'));
        $this->assertSame(['long'], $search->detectIntents('jangan yang singkat'));
        $this->assertSame([], $search->detectIntents('jalur landai tapi curam'));

        $this->assertSame($this->rank('jalur landai')->pluck('name')->all(), $this->rank('jalur yang tidak terlalu curam')->pluck('name')->all());
    }

    public function test_without_intent_the_hybrid_relevance_decides(): void
    {
        $ranked = $this->rank('pemandangan sabana');

        $this->assertCount(18, $ranked);
        $scores = $ranked->pluck('similarity_score')->all();
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);
        $this->assertSame('Gunung Arjuna via Lawang', $ranked->first()->name);
    }

    public function test_shown_score_never_rises_down_the_list(): void
    {
        foreach ($this->fixture()['cases'] as $case) {
            $scores = $this->rank($case['query'])->pluck('similarity_score')->all();
            $sorted = $scores;
            rsort($sorted);
            $this->assertSame($sorted, $scores, $case['query']);
        }
    }

    public function test_filters_can_be_turned_off(): void
    {
        $this->assertCount(18, $this->rank('merbabu', 50, false));
        $this->assertSame('Gunung Merbabu via Grenden', $this->rank('merbabu', 50, false)->first()->name);
    }

    public function test_results_page_says_what_was_filtered_and_how_it_is_sorted(): void
    {
        config(['services.python.auto_index' => false]);
        foreach ($this->fixture()['routes'] as $r) {
            (new HikingRoute([
                'name' => $r['name'], 'gpx_file_path' => 'gpx_files/x.gpx', 'distance_km' => $r['distance_km'],
                'elevation_gain_m' => $r['elevation_gain_m'], 'naismith_duration_hour' => $r['naismith_duration_hour'],
                'average_grade_pct' => $r['average_grade_pct'], 'narrative_text' => $r['narrative_text'],
                'sbert_embedding' => $r['embedding'], 'sbert_aspect_embeddings' => $r['aspect_embeddings'],
            ]))->forceFill(['province' => $r['province'], 'regency' => $r['regency']])->save();
        }
        $vector = $this->fixture()['query_vector'];
        $this->app->instance(PythonProcessorService::class, new class($vector) extends PythonProcessorService {
            public function __construct(private array $vector) {}

            public function getQueryEmbedding(string $query): array
            {
                return $this->vector;
            }
        });

        $this->post(route('search.submit'), ['query' => 'jalur landai di merbabu'])
            ->assertOk()
            ->assertSee('Hanya jalur Gunung Merbabu.')
            ->assertSee('Diurutkan dari yang paling landai.')
            ->assertSee('Cari di semua jalur')
            ->assertSee('Kemiringan rata-rata 12,8%, total naik 1.424 m.')
            ->assertSeeInOrder(['Mt. Merbabu via Selo', 'Gunung Merbabu via Grenden', 'Gunung Merbabu via Wekas'])
            ->assertDontSee('Gunung Sumbing via Garung');

        $this->post(route('search.submit'), ['query' => 'jalur landai di merbabu', 'all_routes' => '1'])
            ->assertOk()
            ->assertDontSee('Hanya jalur Gunung Merbabu.')
            ->assertSee('Diurutkan dari yang paling landai.')
            ->assertSee('Kawah Ijen via Sunrise View Trek');

        $this->post(route('search.submit'), ['query' => 'pendakian singkat di jawa timur'])
            ->assertOk()
            ->assertSee('Hanya jalur di Jawa Timur.')
            ->assertSee('Diurutkan dari waktu jalan yang paling singkat.');

        $this->post(route('search.submit'), ['query' => 'merbabu atau sumbing'])
            ->assertSee('Hanya jalur Gunung Merbabu dan Gunung Sumbing.')
            ->assertDontSee('Diurutkan dari');

        $this->withUnencryptedCookie('rs_lang', 'en')
            ->post(route('search.submit'), ['query' => 'jalur landai untuk pemula di merbabu'])
            ->assertSee('Only routes on Gunung Merbabu.')
            ->assertSee('Sorted from the gentlest slope.')
            ->assertSee('Routes that are also easy come first.')
            ->assertSee('Search all routes');
    }
}
