<?php

namespace App\Services;

use App\Models\HikingRoute;
use Illuminate\Support\Str;

/**
 * JSON shapes of hiking routes for the mobile app API (/api/v1).
 * Only stored data and the same derived values the website shows; nothing
 * here calls an external service.
 */
class RouteApiPresenter
{
    /** Columns a list needs: everything except the (large) embeddings. */
    public const LIST_COLUMNS = [
        'id', 'name', 'description', 'basecamp_name', 'distance_km', 'elevation_gain_m',
        'naismith_duration_hour', 'average_grade_pct', 'steepest_grade_pct', 'summit_route',
        'narrative_text', 'route_coordinates', 'province', 'regency', 'created_at',
    ];

    public function __construct(private RouteConditionsService $conditions)
    {
    }

    /** One route in a list (route list, search results, similar routes, likes). */
    public function summary(HikingRoute $route, int $trackPoints = 60): array
    {
        return [
            'id'                     => (int) $route->id,
            'name'                   => $route->name,
            'mountain'               => $this->mountain($route->name),
            'basecamp'               => $this->conditions->basecampName($route),
            'province'               => $route->province,
            'regency'                => $route->regency,
            'distance_km'            => $this->num($route->distance_km, 2),
            'elevation_gain_m'       => $route->elevation_gain_m !== null ? (int) round((float) $route->elevation_gain_m) : null,
            'naismith_duration_hour' => $this->num($route->naismith_duration_hour, 2),
            'duration_label'         => $route->duration_label,
            'summit_route'           => $route->summit_route !== false,
            'average_grade_pct'      => $this->num($route->average_grade_pct, 1),
            'steepest_grade_pct'     => $this->num($route->steepest_grade_pct, 1),
            'max_elevation_m'        => $route->max_elevation_m,
            'difficulty_level'       => $route->difficulty_level,
            'difficulty_label'       => __($route->difficulty_level),
            'likes_count'            => (int) $route->likes_count,
            'track'                  => $this->thin($route->route_coordinates, $trackPoints),
            'web_url'                => route('routes.show', $route),
        ];
    }

    /** Full route for the detail screen. */
    public function detail(HikingRoute $route): array
    {
        // Cache only: the route page refines missing elevations lazily, the API never waits on it.
        $points = $this->conditions->points($route, false);

        $route->loadMissing(['activeGpxVersion', 'uploader:id,name']);
        $reviews = $route->ratings()->count();

        return $this->summary($route, 0) + [
            'uploader'        => $route->uploader_name,
            'average_rating'  => $reviews ? round((float) $route->ratings()->avg('rating'), 1) : null,
            'review_count'    => $reviews,
            'gpx_status'      => $route->activeGpxVersion?->verification_status,
            'practical'       => array_filter([
                'basecamp_name'    => $route->basecamp_name,
                'basecamp_address' => $route->basecamp_address,
                'basecamp_lat'     => $route->basecamp_lat !== null ? (float) $route->basecamp_lat : null,
                'basecamp_lng'     => $route->basecamp_lng !== null ? (float) $route->basecamp_lng : null,
                'entry_fee'        => $route->entry_fee !== null ? (int) $route->entry_fee : null,
                'contact_phone'    => $route->contact_phone,
                'facilities'       => $route->facilities,
                'best_season'      => $route->best_season,
                'tips'             => $route->tips,
            ], fn($v) => $v !== null && $v !== ''),
            'description'     => $route->description,
            'narrative_text'  => $route->narrative_text,
            'difficulty_hint' => __($route->difficulty_hint),
            'naismith_note'   => __('Dihitung dengan rumus Naismith, belum termasuk istirahat dan beban carrier.'),
            'coordinates'     => $this->thin($route->route_coordinates, 400),
            'basecamp_point'  => $points ? $this->point($points['basecamp']) : null,
            'summit_point'    => $points ? $this->point($points['summit']) : null,
            'elevation_source' => $points['elevation_source'] ?? null,
            'elevation_profile' => $points['profile'] ?? null,
        ];
    }

    /**
     * "Gunung Merbabu" for "Mt. Merbabu via Selo", "Gunung Argopuro" for the
     * traverse "Mt. Argopuro Baderan to Bremi"; names without a mountain word
     * ("Puncak Upas", "Bukit Sikendil") keep the part before "via".
     */
    public function mountain(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $hasVia = (bool) preg_match('/\s+via\s+/iu', $name);
        $head   = trim(preg_split('/\s+via\s+|\s*\(/iu', $name)[0]);

        if (preg_match('/^(?:mt\.?|mount|gunung|g\.)\s+(.+)$/iu', $head, $m)) {
            $rest = trim($m[1]);
            if (! $hasVia && preg_match('/^(\S+)\s+.+?\s+(?:to|ke)\s+\S/iu', $rest, $t)) {
                $rest = $t[1];
            }

            return 'Gunung ' . Str::title($rest);
        }

        return $head !== '' ? $head : null;
    }

    /** At most $max [lat, lng] points, evenly spread, first and last kept. */
    public function thin(mixed $coords, int $max): array
    {
        if ($max <= 0 || ! is_array($coords)) {
            return [];
        }

        $coords = array_values(array_filter($coords, fn($p) => is_array($p)
            && isset($p[0], $p[1]) && is_numeric($p[0]) && is_numeric($p[1])));
        $count = count($coords);
        if ($count < 2) {
            return [];
        }

        $keep = min($count, $max);
        $step = ($count - 1) / ($keep - 1);
        $out  = [];
        for ($i = 0; $i < $keep; $i++) {
            $p     = $coords[(int) round($i * $step)];
            $out[] = [round((float) $p[0], 5), round((float) $p[1], 5)];
        }

        return $out;
    }

    /** One review: the user's rating and, when written, their comment (website "Ulasan Pendaki"). */
    public function review(\App\Models\Comment|\App\Models\Rating $item, ?int $rating): array
    {
        return [
            'id'         => (int) $item->id,
            'name'       => $item->user?->name ?? __('Anonim'),
            'rating'     => $rating,
            'comment'    => $item instanceof \App\Models\Comment ? $item->content : null,
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }

    private function point(array $point): array
    {
        return array_intersect_key($point, array_flip(['name', 'lat', 'lng', 'elevation_m']));
    }

    private function num(mixed $value, int $decimals): ?float
    {
        return $value !== null && is_numeric($value) ? round((float) $value, $decimals) : null;
    }
}
