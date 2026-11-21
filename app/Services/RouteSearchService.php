<?php
namespace App\Services;

use App\Models\HikingRoute;
use Illuminate\Support\Collection;

/**
 * Route search: filters first, then a deterministic order.
 *
 * Mirrors python/search_scoring.py rank_routes() exactly (same words, same keys,
 * same rounding); tests/fixtures/search_ranking.json is checked by both sides.
 *
 * 1. Filters (hard). A mountain named in the query ("merbabu", "gunung slamet",
 *    "sumbing via garung", "puncak upas", "arjuna") keeps only routes of that
 *    mountain, any of them when several are named. A province or regency
 *    ("di Jawa Barat", "jabar", "dekat Malang") keeps only routes there, when
 *    there are any. Place words are never read as mountain names.
 * 2. Order.
 *    - A named mountain: routes matching more of the named words first
 *      ("sumbing via garung": Garung, then the other Sumbing routes).
 *    - Intent words decide the order from the GPX statistics (see sortKey()).
 *      Routes with broken statistics (stats_reliable) go last. The SBERT score
 *      only breaks ties at display precision.
 *    - No intent: hybrid relevance, SBERT cosine on the document (40%) and the
 *      closest aspect sentence (60%), blended with the region match (40%) and
 *      the share of route-name words matched (35%).
 */
class RouteSearchService
{
    public const W_DOC = 0.4;
    public const W_ASPECT = 0.6;
    public const W_ATTR = 0.45;
    public const W_NAME = 0.35;
    public const W_REGION = 0.4;
    public const STEEP_GRADE_PCT = 15.0;
    public const GENTLE_STEEPEST_PCT = 20.0; // steepest ~1 km stretch still called landai
    public const SHORT_HOURS = 4.0;
    public const MAX_HIKE_KM = 60.0;

    /** Province names as stored from Nominatim => words people type. */
    private const PROVINCE_ALIASES = [
        'jawa barat' => ['jawa barat', 'jabar', 'west java'],
        'jawa tengah' => ['jawa tengah', 'jateng', 'central java'],
        'jawa timur' => ['jawa timur', 'jatim', 'east java'],
        'daerah istimewa yogyakarta' => ['yogyakarta', 'jogjakarta', 'yogya', 'jogja', 'diy'],
        'banten' => ['banten'],
        'dki jakarta' => ['jakarta'],
        'lampung' => ['lampung'],
    ];

    private const INTENT_WORDS = [
        'gentle' => ['landai', 'datar', 'melandai'],
        'easy'   => ['mudah', 'pemula', 'santai', 'ringan', 'keluarga', 'aman', 'pertama', 'awam', 'anak'],
        'hard'   => ['sulit', 'menantang', 'ekstrem', 'ekstrim', 'berat', 'berpengalaman', 'expert', 'teknis'],
        'steep'  => ['curam', 'terjal'],
        'short'  => ['pendek', 'singkat', 'cepat', 'tektok', 'sebentar'],
        'long'   => ['panjang', 'lama', 'ekspedisi', 'jauh', 'lintas', 'traverse'],
    ];

    /** Which intent decides the order when several are asked; the rest only break ties. */
    public const INTENT_PRIORITY = ['gentle', 'steep', 'easy', 'hard', 'short', 'long'];

    private const NEGATIONS = ['tidak', 'bukan', 'jangan', 'tanpa', 'ga', 'gak', 'nggak', 'enggak', 'kurang'];

    private const OPPOSITE = ['gentle' => 'steep', 'steep' => 'gentle', 'easy' => 'hard', 'hard' => 'easy', 'short' => 'long', 'long' => 'short'];

    private const NAME_STOPWORDS = ['mt', 'mount', 'gunung', 'via', 'to', 'jalur', 'the', 'and', 'dan', 'semua', 'test', 'trail', 'python'];

    /** Words in front of a mountain name ("Gunung Slamet", "Puncak Upas", "Bukit Sikendil"). */
    private const MOUNTAIN_PREFIXES = ['gunung', 'mt', 'mount', 'g', 'puncak', 'bukit', 'kawah', 'gumuk', 'puthuk'];

    /** Words in route names that are not part of a mountain's name. */
    private const MOUNTAIN_GENERIC = ['ke', 'loop', 'baru', 'dua', 'besar', 'tahura', 'alternatif', 'trek'];

    /** Spellings people type => the spelling used for matching. */
    private const MOUNTAIN_ALIASES = [
        'arjuna' => 'arjuno', 'cereme' => 'ciremai', 'ceremai' => 'ciremai',
        'sundoro' => 'sindoro', 'perahu' => 'parahu', 'krakatoa' => 'krakatau',
    ];

    /** Mountain names that are also everyday words: only count after "gunung", "puncak", ... */
    private const MOUNTAIN_AMBIGUOUS = ['anak', 'sangar', 'kembang', 'lembu', 'akin', 'rante', 'kencur', 'geulis', 'bongkok'];

    /**
     * Rank routes for a query. Returns the ordered routes with similarity_score
     * (0-100, never rising down the list), cosine_value and aspect_cosine set.
     */
    public function rank(string $query, array $queryEmbedding, Collection $routes, int $limit = 10, bool $applyFilters = true): Collection
    {
        $routes = $routes->filter(fn(HikingRoute $r) => is_array($r->sbert_embedding) && ! empty($r->sbert_embedding))->values();
        $plan   = $this->plan($query, $routes, $applyFilters);
        $names  = $plan['names'];

        $rows = $plan['routes']->map(function (HikingRoute $route) use ($queryEmbedding, $plan, $names) {
            $cosDoc    = $this->cosine($queryEmbedding, $route->sbert_embedding);
            $cosAspect = $cosDoc;
            foreach ((array) $route->sbert_aspect_embeddings as $aspect) {
                if (is_array($aspect)) {
                    $cosAspect = max($cosAspect, $this->cosine($queryEmbedding, $aspect));
                }
            }

            $relevance = ! empty($route->sbert_aspect_embeddings) ? self::W_DOC * $cosDoc + self::W_ASPECT * $cosAspect : $cosDoc;
            if ($plan['use_region']) {
                $relevance = (1 - self::W_REGION) * $relevance + self::W_REGION * $this->regionHit($plan['regions'], $route);
            }
            $hit = $names ? count(array_intersect($names, $this->nameTokens($route->name))) / count($names) : 0.0;
            if ($names) {
                $relevance = (1 - self::W_NAME) * $relevance + self::W_NAME * $hit;
            }

            $route->cosine_value  = round($cosDoc, 4);
            $route->aspect_cosine = round($cosAspect, 4);

            return ['route' => $route, 'relevance' => $relevance, 'key' => $this->sortKey($plan, $route, $hit, $relevance)];
        })->all();

        usort($rows, fn($a, $b) => $this->compareKeys($a['key'], $b['key']));

        // Shown score: relevance, mixed with the position when an intent sets the order,
        // and never higher than the route above it (the labels follow the order).
        $n    = count($rows);
        $prev = 1.0;
        foreach ($rows as $i => $row) {
            $score = $row['relevance'];
            if ($plan['intents']) {
                $fit   = $n > 1 ? 1 - $i / ($n - 1) : 1.0;
                $score = (1 - self::W_ATTR) * $score + self::W_ATTR * $fit;
            }
            $prev = min($prev, max(0.0, min(1.0, $score)));
            $row['route']->similarity_score = round($prev * 100, 1);
        }

        return collect($rows)->pluck('route')
            ->filter(fn($r) => $r->similarity_score > 0)
            ->take($limit)
            ->values();
    }

    /**
     * What the query asks for and which routes are left after the filters.
     *
     * @return array{intents: string[], sort: ?string, also: string[], mountains: array, mountain_tokens: string[],
     *               mountain_filter: bool, regions: array, use_region: bool, region_filter: bool, region_labels: string[],
     *               names: string[], routes: Collection}
     */
    public function plan(string $query, Collection $routes, bool $applyFilters = true): array
    {
        $intents   = array_values(array_intersect(self::INTENT_PRIORITY, $this->detectIntents($query)));
        $regions   = $this->detectRegions($query, $routes);
        $placeWords = $this->regionWords($regions);
        $useRegion = ($regions['provinces'] || $regions['regencies']) && $routes->contains(fn($r) => filled($r->province));
        $mountains = $this->detectMountains($query, $routes, $placeWords);
        $tokens    = array_values(array_unique(array_merge(...array_column($mountains, 'tokens') ?: [[]])));

        $candidates     = $routes->values();
        $mountainFilter = false;
        $regionFilter   = false;
        if ($applyFilters && $tokens) {
            $candidates     = $candidates->filter(fn($r) => array_intersect($tokens, $this->mountainTokens($r->name)))->values();
            $mountainFilter = true;
        }
        if ($applyFilters && $useRegion) {
            $inRegion = $candidates->filter(fn($r) => $this->regionHit($regions, $r) > 0)->values();
            if ($inRegion->isNotEmpty()) {
                $candidates   = $inRegion;
                $regionFilter = true;
            }
        }

        return [
            'intents'         => $intents,
            'sort'            => $intents[0] ?? null,
            'also'            => array_slice($intents, 1),
            'mountains'       => $mountains,
            'mountain_tokens' => $tokens,
            'mountain_filter' => $mountainFilter,
            'regions'         => $regions,
            'use_region'      => $useRegion,
            'region_filter'   => $regionFilter,
            'region_labels'   => $regionFilter ? $this->regionLabels($regions, $routes) : [],
            'names'           => array_values(array_diff($this->mentionedNames($query, $routes), $placeWords)),
            'routes'          => $candidates,
        ];
    }

    /**
     * Sort key, compared element by element (smaller first):
     * - named mountain: share of the named words in the route name, higher first;
     * - with an intent: routes with reliable statistics first, then routes that fit
     *   every asked intent (gentle: steepest 1 km stretch < 20% and average grade < 15%;
     *   steep: the opposite; easy: Mudah or
     *   Sedang; hard: Sulit or Sangat Sulit; short: under 4 h; long: 4 h or more),
     *   then the intents in INTENT_PRIORITY order:
     *     gentle: steepest stretch up, then average grade up, then elevation gain up
     *     steep:  steepest stretch down, then average grade down, then difficulty down
     *   (steepest stretch = steepest_grade_pct; the average alone hides short steep
     *   parts, e.g. Semeru averages ~6% over 40 km but its summit slope is ~50%);
     *     easy:   difficulty score up, then Naismith time up
     *     hard:   difficulty score down, then average grade down
     *     short:  Naismith time up;  long: Naismith time down
     *   at display precision (grade 0.1%, gain 1 m, time 1 minute, score 1 point);
     * - then relevance (SBERT + region + name) down, then name.
     */
    private function sortKey(array $plan, HikingRoute $route, float $hit, float $relevance): array
    {
        $key = $plan['mountain_tokens'] ? [-$hit] : [];

        if ($plan['intents']) {
            $grade   = (float) $route->average_grade_pct;
            $steep   = $route->steepest_grade_pct !== null ? (float) $route->steepest_grade_pct : $grade;
            $s10     = $this->roundHalfUp($steep * 10);
            $gentle  = $steep < self::GENTLE_STEEPEST_PCT && $grade < self::STEEP_GRADE_PCT;
            $hours   = (float) $route->naismith_duration_hour;
            $diff    = (float) $route->difficulty_score;
            $g10     = $this->roundHalfUp($grade * 10);
            $minutes = $this->roundHalfUp($hours * 60);
            $gain    = $this->roundHalfUp((float) $route->elevation_gain_m);
            $points  = $this->roundHalfUp($diff);
            $easy    = in_array($route->difficulty_level, ['Mudah', 'Sedang'], true);

            $fits = [
                'gentle' => $gentle,
                'steep'  => ! $gentle,
                'easy'   => $easy,
                'hard'   => ! $easy,
                'short'  => $hours < self::SHORT_HOURS,
                'long'   => $hours >= self::SHORT_HOURS,
            ];
            $metrics = [
                'gentle' => [$s10, $g10, $gain],
                'steep'  => [-$s10, -$g10, -$points],
                'easy'   => [$points, $minutes],
                'hard'   => [-$points, -$g10],
                'short'  => [$minutes],
                'long'   => [-$minutes],
            ];

            $reliable = $this->statsReliable($route);
            $key[]    = $reliable ? 0 : 1;
            $key[]    = $reliable ? count(array_filter($plan['intents'], fn($i) => ! $fits[$i])) : 0;
            foreach ($plan['intents'] as $intent) {
                array_push($key, ...($reliable ? $metrics[$intent] : array_fill(0, count($metrics[$intent]), 0)));
            }
        }

        $key[] = -$relevance;
        $key[] = (string) $route->name;

        return $key;
    }

    private function compareKeys(array $a, array $b): int
    {
        foreach ($a as $i => $x) {
            $c = is_string($x) ? strcmp($x, $b[$i]) : ($x <=> $b[$i]);
            if ($c !== 0) {
                return $c < 0 ? -1 : 1;
            }
        }

        return 0;
    }

    private function roundHalfUp(float $value): int
    {
        return (int) floor($value + 0.5);
    }

    /** GPX without elevation (gain 0) or with vehicle segments (> 60 km): grade and time mean nothing. */
    public function statsReliable(HikingRoute $route): bool
    {
        $km = (float) $route->distance_km;

        return (float) $route->elevation_gain_m > 0 && $km > 0 && $km <= self::MAX_HIKE_KM;
    }

    /**
     * Intents in the order their words appear. A negation in the two words before
     * flips the intent ("tidak terlalu curam" = gentle, "tidak sulit" = easy).
     * A mountain name after "gunung"/"puncak"/... is not an intent ("Gunung Anak Krakatau").
     * Opposite intents cancel each other.
     */
    public function detectIntents(string $query): array
    {
        preg_match_all('/[a-z]+/', mb_strtolower($query), $m);
        $words   = $m[0];
        $intents = [];

        foreach ($words as $i => $word) {
            if ($i > 0 && in_array($word, self::MOUNTAIN_AMBIGUOUS, true) && in_array($words[$i - 1], self::MOUNTAIN_PREFIXES, true)) {
                continue;
            }
            foreach (self::INTENT_WORDS as $intent => $vocab) {
                if (in_array($word, $vocab, true)) {
                    $prev    = array_slice($words, max(0, $i - 2), $i - max(0, $i - 2));
                    $negated = (bool) array_intersect($prev, self::NEGATIONS);
                    $intents[$negated ? self::OPPOSITE[$intent] : $intent] = true;
                }
            }
        }

        if ((isset($intents['hard']) || isset($intents['steep'])) && (isset($intents['gentle']) || isset($intents['easy']))) {
            unset($intents['gentle'], $intents['easy'], $intents['hard'], $intents['steep']);
        }
        if (isset($intents['short'], $intents['long'])) {
            unset($intents['short'], $intents['long']);
        }

        return array_keys($intents);
    }

    /**
     * Mountains named in the query, as groups of words that belong to one mountain
     * ("gede pangrango", "anak krakatau") with a label taken from the route names.
     *
     * @return array<int, array{tokens: string[], label: string}>
     */
    public function detectMountains(string $query, Collection $routes, array $exclude = []): array
    {
        $routeTokens = $routes->map(fn($r) => $this->mountainTokens($r->name))->all();
        $vocab       = array_flip(array_merge(...array_values($routeTokens) ?: [[]]));
        $words       = $this->queryWords($query);
        $groups      = [];
        $last        = null;

        foreach ($words as $i => $word) {
            $prev = $words[$i - 1] ?? '';
            $hit  = isset($vocab[$word]) && ! in_array($word, $exclude, true)
                && (! $this->isCommonWord($word) || in_array($prev, self::MOUNTAIN_PREFIXES, true));
            if (! $hit) {
                $last = null;
                continue;
            }
            $together = $last !== null && collect($routeTokens)->contains(fn($t) => in_array($last, $t, true) && in_array($word, $t, true));
            if ($together) {
                $groups[count($groups) - 1][] = $word;
            } elseif (! in_array($word, array_merge([], ...$groups ?: [[]]), true)) {
                $groups[] = [$word];
            }
            $last = $word;
        }

        return array_map(fn($tokens) => ['tokens' => $tokens, 'label' => $this->mountainLabel($tokens, $routes)], $groups);
    }

    /** Mountain words of a route name: the part before "via", without "Gunung", "Puncak", ... */
    public function mountainTokens(?string $name): array
    {
        $head = preg_split('/\s+via\s+|\(/', mb_strtolower((string) $name))[0];
        preg_match_all('/[a-z]+/', $head, $m);
        $skip = array_merge(self::NAME_STOPWORDS, self::MOUNTAIN_PREFIXES, self::MOUNTAIN_GENERIC);

        return array_values(array_unique(array_filter(
            array_map(fn($w) => self::MOUNTAIN_ALIASES[$w] ?? $w, $m[0]),
            fn($w) => strlen($w) >= 3 && ! in_array($w, $skip, true)
        )));
    }

    /** Words of a route name usable for name matching (mountain and basecamp). */
    public function nameTokens(?string $name): array
    {
        preg_match_all('/[a-z]+/', mb_strtolower((string) $name), $m);
        $skip = array_merge(self::NAME_STOPWORDS, self::MOUNTAIN_PREFIXES, self::MOUNTAIN_GENERIC, self::NEGATIONS, ...array_values(self::INTENT_WORDS));

        return array_values(array_unique(array_filter(
            array_map(fn($w) => self::MOUNTAIN_ALIASES[$w] ?? $w, $m[0]),
            fn($w) => strlen($w) >= 3 && ! in_array($w, $skip, true)
        )));
    }

    /**
     * "Gunung Merbabu" for ["merbabu"]: the most common name head (the part before
     * "via", "Mt." written as "Gunung") among routes with all the words; ties go
     * to the shortest.
     */
    private function mountainLabel(array $tokens, Collection $routes): string
    {
        $heads = $routes
            ->filter(fn($r) => ! array_diff($tokens, $this->mountainTokens($r->name)))
            ->map(function ($r) {
                $head = preg_split('/\s+via\s+|\(/i', trim((string) $r->name))[0];
                $head = preg_replace('/^(mt\.?|mount|g\.)\s+/i', 'Gunung ', trim($head));

                return preg_replace('/\s+/', ' ', $head);
            })
            ->countBy()
            ->all();

        uksort($heads, fn($a, $b) => [$heads[$b], strlen($a), $a] <=> [$heads[$a], strlen($b), $b]);

        return (string) (array_key_first($heads) ?? ucwords(implode(' ', $tokens)));
    }

    private function isCommonWord(string $word): bool
    {
        return in_array($word, self::MOUNTAIN_AMBIGUOUS, true)
            || in_array($word, self::NEGATIONS, true)
            || in_array($word, array_merge(...array_values(self::INTENT_WORDS)), true);
    }

    private function queryWords(string $query): array
    {
        preg_match_all('/[a-z]+/', mb_strtolower($query), $m);

        return array_map(fn($w) => self::MOUNTAIN_ALIASES[$w] ?? $w, $m[0]);
    }

    private function mentionedNames(string $query, Collection $routes): array
    {
        $vocab = $routes->flatMap(fn($r) => $this->nameTokens($r->name))->unique()->all();

        return array_values(array_intersect(array_unique($this->queryWords($query)), $vocab));
    }

    /**
     * Provinces and regencies named in the query. Regencies come from the
     * routes' own stored locations, so only places we have routes in count.
     *
     * @return array{provinces: string[], regencies: string[]}
     */
    public function detectRegions(string $query, Collection $routes): array
    {
        $text = ' ' . preg_replace('/[^a-z]+/', ' ', mb_strtolower($query)) . ' ';

        $provinces = [];
        foreach (self::PROVINCE_ALIASES as $province => $aliases) {
            foreach ($aliases as $alias) {
                if ($this->hasPhrase($text, $alias)) {
                    $provinces[] = $province;
                    break;
                }
            }
        }

        $regencies = $routes->map(fn($r) => $this->normPlace($r->regency))
            ->filter(fn($reg) => strlen($reg) >= 4 && $this->hasPhrase($text, $reg))
            ->unique()->values()->all();

        return ['provinces' => $provinces, 'regencies' => $regencies];
    }

    /** "Jawa Barat", "Kabupaten Bandung": names as stored on the routes. */
    private function regionLabels(array $regions, Collection $routes): array
    {
        $labels = array_map(fn($p) => str_replace('Dki ', 'DKI ', ucwords($p)), $regions['provinces']);
        foreach ($regions['regencies'] as $regency) {
            $labels[] = trim((string) $routes->first(fn($r) => $this->normPlace($r->regency) === $regency)?->regency) ?: ucwords($regency);
        }

        return $labels;
    }

    private function regionHit(array $regions, HikingRoute $route): float
    {
        return in_array($this->normPlace($route->province), $regions['provinces'], true)
            || in_array($this->normPlace($route->regency), $regions['regencies'], true) ? 1.0 : 0.0;
    }

    private function regionWords(array $regions): array
    {
        $words = [];
        foreach ($regions['provinces'] as $province) {
            foreach (self::PROVINCE_ALIASES[$province] as $alias) {
                array_push($words, ...explode(' ', $alias));
            }
        }
        foreach ($regions['regencies'] as $regency) {
            array_push($words, ...explode(' ', $regency));
        }

        return array_values(array_unique($words));
    }

    private function normPlace(?string $value): string
    {
        $value = preg_replace('/^(kabupaten|kota|kab\.?)\s+/', '', trim(mb_strtolower((string) $value)));

        return preg_replace('/\s+/', ' ', (string) $value);
    }

    private function hasPhrase(string $text, string $phrase): bool
    {
        return (bool) preg_match('/(?<![a-z])' . preg_quote($phrase, '/') . '(?![a-z])/', $text);
    }

    public function cosine(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        $n   = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na  += $a[$i] * $a[$i];
            $nb  += $b[$i] * $b[$i];
        }

        return ($na == 0.0 || $nb == 0.0) ? 0.0 : $dot / (sqrt($na) * sqrt($nb));
    }
}
