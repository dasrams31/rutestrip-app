<?php
namespace App\Services;

use App\Models\HikingRoute;
use Illuminate\Support\Collection;

/**
 * Hybrid relevance scoring for route search.
 *
 * Core: SBERT (paraphrase-multilingual-MiniLM-L12-v2) + cosine similarity.
 * Added on top (mirrors python/search_scoring.py, keep weights in sync):
 *  - document text includes the route name, plus one embedding per narrative
 *    aspect sentence; the best aspect match is blended with the document match;
 *  - intent words (landai, pemula, curam, singkat, panjang, ...) are checked
 *    against the GPX statistics;
 *  - mountain/basecamp names in the query are matched against route names.
 */
class RouteSearchService
{
    public const W_DOC = 0.4;
    public const W_ASPECT = 0.6;
    public const W_ATTR = 0.45;
    public const STEEP_GRADE_PCT = 15.0;
    public const VERY_STEEP_GRADE_PCT = 30.0;
    public const MAX_HIKE_KM = 60.0;
    public const W_NAME = 0.35;

    private const INTENT_WORDS = [
        // "landai" = gentle slope; "pemula/mudah" = little climbing, short duration
        'gentle' => ['landai', 'datar', 'melandai'],
        'easy'  => ['mudah', 'pemula', 'santai', 'ringan', 'keluarga', 'aman', 'pertama', 'awam', 'anak'],
        'hard'  => ['curam', 'sulit', 'menantang', 'ekstrem', 'ekstrim', 'berat', 'terjal', 'berpengalaman', 'expert', 'teknis'],
        'short' => ['pendek', 'singkat', 'cepat', 'tektok', 'sebentar', 'dekat'],
        'long'  => ['panjang', 'lama', 'ekspedisi', 'jauh', 'lintas', 'traverse'],
    ];

    private const NEGATIONS = ['tidak', 'bukan', 'jangan', 'tanpa', 'ga', 'gak', 'nggak', 'enggak', 'kurang'];

    private const OPPOSITE = ['gentle' => 'hard', 'easy' => 'hard', 'hard' => 'gentle', 'short' => 'long', 'long' => 'short'];

    private const NAME_STOPWORDS = ['mt', 'mount', 'gunung', 'via', 'to', 'jalur', 'the', 'and', 'dan', 'semua', 'test', 'trail', 'python'];

    /**
     * Score routes for a query. Returns routes sorted by score with
     * similarity_score (0-100), cosine_value and match details set.
     */
    public function rank(string $query, array $queryEmbedding, Collection $routes, int $limit = 10): Collection
    {
        $routes = $routes->filter(fn(HikingRoute $r) => is_array($r->sbert_embedding) && ! empty($r->sbert_embedding))->values();

        $grades    = $routes->map(fn($r) => (float) $r->average_grade_pct)->all();
        $durations = $routes->map(fn($r) => (float) $r->naismith_duration_hour)->all();
        $gains     = $routes->map(fn($r) => (float) $r->elevation_gain_m)->all();
        $intents   = $this->detectIntents($query);
        $names     = $this->mentionedNames($query, $routes);

        return $routes->map(function (HikingRoute $route) use ($query, $queryEmbedding, $grades, $durations, $gains, $intents, $names) {
            $cosDoc = $this->cosine($queryEmbedding, $route->sbert_embedding);

            $cosAspect = $cosDoc;
            foreach ((array) $route->sbert_aspect_embeddings as $aspect) {
                if (is_array($aspect)) {
                    $cosAspect = max($cosAspect, $this->cosine($queryEmbedding, $aspect));
                }
            }

            $hasAspects = ! empty($route->sbert_aspect_embeddings);
            $score = $hasAspects ? self::W_DOC * $cosDoc + self::W_ASPECT * $cosAspect : $cosDoc;

            if ($intents) {
                $score = (1 - self::W_ATTR) * $score
                    + self::W_ATTR * $this->attributeScore($intents, $route, $grades, $durations, $gains);
            }

            if ($names) {
                $hit   = array_intersect($names, $this->nameTokens($route->name)) ? 1.0 : 0.0;
                $score = (1 - self::W_NAME) * $score + self::W_NAME * $hit;
            }

            $score = max(0.0, min(1.0, $score));

            $route->similarity_score = round($score * 100, 1);
            $route->cosine_value     = round($cosDoc, 4);
            $route->aspect_cosine    = round($cosAspect, 4);

            return $route;
        })
            ->filter(fn($r) => $r->similarity_score > 0)
            ->sortByDesc('similarity_score')
            ->take($limit)
            ->values();
    }

    public function detectIntents(string $query): array
    {
        preg_match_all('/[a-z]+/', mb_strtolower($query), $m);
        $words   = $m[0];
        $intents = [];

        foreach ($words as $i => $word) {
            foreach (self::INTENT_WORDS as $intent => $vocab) {
                if (in_array($word, $vocab, true)) {
                    $prev    = array_slice($words, max(0, $i - 2), $i - max(0, $i - 2));
                    $negated = (bool) array_intersect($prev, self::NEGATIONS);
                    $intents[$negated ? self::OPPOSITE[$intent] : $intent] = true;
                }
            }
        }

        if (isset($intents['hard']) && (isset($intents['gentle']) || isset($intents['easy']))) {
            unset($intents['gentle'], $intents['easy'], $intents['hard']);
        }
        if (isset($intents['short'], $intents['long'])) {
            unset($intents['short'], $intents['long']);
        }

        return array_keys($intents);
    }

    public function nameTokens(?string $name): array
    {
        preg_match_all('/[a-z]+/', mb_strtolower((string) $name), $m);

        return array_values(array_filter(
            array_unique($m[0]),
            fn($w) => strlen($w) >= 3 && ! in_array($w, self::NAME_STOPWORDS, true)
        ));
    }

    private function mentionedNames(string $query, Collection $routes): array
    {
        $vocab = $routes->flatMap(fn($r) => $this->nameTokens($r->name))->unique()->all();
        preg_match_all('/[a-z]+/', mb_strtolower($query), $m);

        return array_values(array_intersect(array_unique($m[0]), $vocab));
    }

    /**
     * "gentle" (landai) mixes grade 40%, elevation gain 30% and duration 30% —
     * a 40 km route can average 6% and still be a long climb — and halves
     * routes averaging >= 15%. "easy" (pemula/mudah) is grade 20%, gain 40%,
     * duration 40%, halving routes >= 30%. "hard" is grade 60%, gain 20%,
     * duration 20%.
     */
    private function attributeScore(array $intents, HikingRoute $route, array $grades, array $durations, array $gains): float
    {
        // GPX without elevation or with vehicle segments: grade/duration are meaningless.
        if ((float) $route->elevation_gain_m <= 0 || (float) $route->distance_km <= 0 || (float) $route->distance_km > self::MAX_HIKE_KM) {
            return 0.0;
        }

        $grade = (float) $route->average_grade_pct;
        $g     = $this->percentile($grade, $grades);
        $d     = $this->percentile((float) $route->naismith_duration_hour, $durations);
        $e     = $this->percentile((float) $route->elevation_gain_m, $gains);

        $parts = [];
        foreach ($intents as $intent) {
            $parts[] = match ($intent) {
                'gentle' => (0.4 * (1 - $g) + 0.3 * (1 - $e) + 0.3 * (1 - $d)) * ($grade >= self::STEEP_GRADE_PCT ? 0.5 : 1.0),
                'easy'  => (0.2 * (1 - $g) + 0.4 * (1 - $e) + 0.4 * (1 - $d)) * ($grade >= self::VERY_STEEP_GRADE_PCT ? 0.5 : 1.0),
                'hard'  => 0.6 * $g + 0.2 * $e + 0.2 * $d,
                'short' => 1 - $d,
                'long'  => $d,
            };
        }

        return $parts ? array_sum($parts) / count($parts) : 0.0;
    }

    private function percentile(float $value, array $values): float
    {
        if (! $values) {
            return 0.5;
        }
        $below = count(array_filter($values, fn($v) => $v < $value));
        $equal = count(array_filter($values, fn($v) => $v == $value));

        return ($below + 0.5 * $equal) / count($values);
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
