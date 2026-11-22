<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HikingRoute;
use App\Models\SearchHistory;
use App\Models\SearchStat;
use App\Services\PythonProcessorService;
use App\Services\RouteApiPresenter;
use App\Services\RouteSearchService;
use App\Services\SsoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * POST /api/v1/search {query, all_routes?}: the website search (SearchController::search)
 * as JSON. Same query embedding, same RouteSearchService ranking and plan, and the
 * same sentences the results page shows above the cards.
 */
class SearchApiController extends Controller
{
    public function __construct(
        private PythonProcessorService $python,
        private RouteSearchService $search,
        private RouteApiPresenter $present,
        private SsoService $sso,
    ) {
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'query'      => 'required|string|min:3|max:500',
            'all_routes' => 'nullable|boolean',
        ]);

        $query = (string) $request->input('query');
        $start = microtime(true);

        $routes = Cache::remember('routes_with_embeddings', 300, function () {
            return HikingRoute::whereNotNull('sbert_embedding')->with(['activeGpxVersion', 'uploader'])->get();
        });

        if ($routes->isEmpty()) {
            return response()->json([
                'query'   => $query,
                'results' => [],
                'plan'    => null,
                'explanation' => null,
                'message' => __('Belum ada jalur di database. Jalur muncul di sini setelah ada yang mengunggah file GPX.'),
            ]);
        }

        try {
            $embedding = $this->python->getQueryEmbedding($query);
            if (! is_array($embedding) || empty($embedding)) {
                throw new \RuntimeException('Empty query embedding.');
            }
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => __('Pencarian sedang tidak bisa dipakai. Coba lagi sebentar lagi, atau buka daftar semua jalur.'),
            ], 503);
        }

        $applyFilters = ! $request->boolean('all_routes');
        $results      = $this->search->rank($query, $embedding, $routes->map(fn($r) => clone $r), 10, $applyFilters);
        $plan         = $this->search->plan($query, $routes, $applyFilters);

        $user = $this->sso->user($request->bearerToken());
        if ($user) {
            SearchHistory::create(['user_id' => $user->id, 'query' => $query, 'results_count' => $results->count()]);
        } else {
            SearchStat::record($query);
        }

        return response()->json([
            'query'   => $query,
            'results' => $results->map(fn(HikingRoute $r) => $this->present->summary($r) + [
                'similarity_score' => (float) $r->similarity_score,
                'match_label'      => $this->matchWord((float) $r->similarity_score),
                'why'              => $this->why($plan['sort'], $r),
            ])->values(),
            'plan' => [
                'intents'         => $plan['intents'],
                'sort'            => $plan['sort'],
                'also'            => $plan['also'],
                'mountains'       => array_column($plan['mountains'], 'label'),
                'mountain_filter' => $plan['mountain_filter'],
                'region_labels'   => $plan['region_labels'],
                'region_filter'   => $plan['region_filter'],
                'all_routes'      => ! $applyFilters,
            ],
            'explanation'    => $this->explanation($plan),
            'search_time_ms' => round((microtime(true) - $start) * 1000, 2),
        ]);
    }

    /** The lines above the result cards (resources/views/search/results.blade.php). */
    private function explanation(array $plan): array
    {
        $mountains = $this->joinList(array_column($plan['mountain_filter'] ? $plan['mountains'] : [], 'label'));
        $places    = $this->joinList($plan['region_labels']);

        $filter = match (true) {
            $mountains !== '' && $places !== '' => __('Hanya jalur :mountains di :places.', ['mountains' => $mountains, 'places' => $places]),
            $mountains !== '' => __('Hanya jalur :mountains.', ['mountains' => $mountains]),
            $places !== ''    => __('Hanya jalur di :places.', ['places' => $places]),
            default           => null,
        };
        $sort = match ($plan['sort']) {
            'gentle' => __('Diurutkan dari yang paling landai.'),
            'steep'  => __('Diurutkan dari yang paling curam.'),
            'easy'   => __('Diurutkan dari yang paling mudah.'),
            'hard'   => __('Diurutkan dari yang paling sulit.'),
            'short'  => __('Diurutkan dari waktu jalan yang paling singkat (Naismith, belum termasuk istirahat).'),
            'long'   => __('Diurutkan dari waktu jalan yang paling lama (Naismith, belum termasuk istirahat).'),
            default  => null,
        };
        $words = ['gentle' => __('landai'), 'steep' => __('curam'), 'easy' => __('mudah'), 'hard' => __('sulit'), 'short' => __('singkat'), 'long' => __('panjang')];
        $also  = $plan['also']
            ? __('Jalur yang juga :what didahulukan.', ['what' => $this->joinList(array_map(fn($i) => $words[$i], $plan['also']))])
            : null;

        $notice = null;
        if ($plan['sort'] === 'gentle' && $plan['routes']->min('average_grade_pct') >= 5) {
            $notice = __('Belum ada jalur yang benar-benar landai (kemiringan rata-rata di bawah 5%). Urutan dimulai dari jalur dengan kemiringan rata-rata paling kecil.');
        }

        return [
            'filter_line'      => $filter,
            'sort_line'        => $sort,
            'also_line'        => $also,
            'notice'           => $notice,
            'can_search_all'   => $filter !== null,
            'search_all_label' => __('Cari di semua jalur'),
        ];
    }

    /** One sentence on why a route showed up, from the numbers that set the order. */
    private function why(?string $sort, HikingRoute $route): ?string
    {
        $grade = $this->num($route->average_grade_pct, 1) . '%';
        $gain  = $this->num($route->elevation_gain_m);
        $km    = $this->num($route->distance_km, 1);

        return match ($sort) {
            'gentle', 'steep' => __('Kemiringan rata-rata :grade, total naik :gain m.', ['grade' => $grade, 'gain' => $gain]),
            'easy', 'hard'    => __('Total naik :gain m sepanjang :km km.', ['gain' => $gain, 'km' => $km]),
            'short'           => __('Sekitar :time jalan kaki sampai puncak.', ['time' => $this->hours($route->naismith_duration_hour)]),
            'long'            => __('Sekitar :time jalan kaki, panjang jalur :km km.', ['time' => $this->hours($route->naismith_duration_hour), 'km' => $km]),
            default           => null,
        };
    }

    private function matchWord(float $score): string
    {
        return match (true) {
            $score >= 70 => __('Sangat cocok'),
            $score >= 50 => __('Cocok'),
            $score >= 30 => __('Lumayan cocok'),
            default      => __('Sedikit mirip'),
        };
    }

    private function hours(mixed $hours): string
    {
        $hours = (float) $hours;
        $h     = (int) floor($hours);
        $m     = (int) round(($hours - $h) * 60);
        if ($m === 60) {
            $h++;
            $m = 0;
        }

        return __(':h j :m mnt', ['h' => $h, 'm' => $m]);
    }

    private function num(mixed $value, int $decimals = 0): string
    {
        $en = app()->getLocale() === 'en';

        return number_format((float) $value, $decimals, $en ? '.' : ',', $en ? ',' : '.');
    }

    /** "A", "A dan B", "A, B dan C" */
    private function joinList(array $items): string
    {
        return count($items) > 1
            ? implode(', ', array_slice($items, 0, -1)) . ' ' . __('dan') . ' ' . end($items)
            : implode('', $items);
    }
}
