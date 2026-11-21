<?php
namespace App\Services;

use App\Models\HikingRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Basecamp/summit info, trailhead location and live weather for the route page.
 *
 * Everything that needs the network (Open-Meteo elevation + forecast, Nominatim)
 * only runs from the lazily fetched /routes/{route}/conditions endpoint, never
 * while the page itself renders. Results are cached: elevations and location
 * forever (they don't change), weather for WEATHER_TTL seconds.
 */
class RouteConditionsService
{
    public const USER_AGENT    = 'RuteStrip/1.0 (+https://rutestrip.ai.id)';
    public const TIMEZONE      = 'Asia/Jakarta';
    public const WEATHER_TTL   = 900;  // 15 minutes
    public const WEATHER_RETRY = 60;   // after a failed weather call
    public const GEO_RETRY     = 600;  // after a failed elevation/geocoding call

    private const FORECAST_URL  = 'https://api.open-meteo.com/v1/forecast';
    private const ELEVATION_URL = 'https://api.open-meteo.com/v1/elevation';
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/reverse';

    private const WEATHER_CODES = [
        0  => 'Cerah',
        1  => 'Cerah berawan',
        2  => 'Berawan sebagian',
        3  => 'Mendung',
        45 => 'Berkabut',
        48 => 'Kabut berembun beku',
        51 => 'Gerimis ringan',
        53 => 'Gerimis sedang',
        55 => 'Gerimis lebat',
        56 => 'Gerimis beku ringan',
        57 => 'Gerimis beku lebat',
        61 => 'Hujan ringan',
        63 => 'Hujan sedang',
        65 => 'Hujan lebat',
        66 => 'Hujan beku ringan',
        67 => 'Hujan beku lebat',
        71 => 'Salju ringan',
        73 => 'Salju sedang',
        75 => 'Salju lebat',
        77 => 'Butiran salju',
        80 => 'Hujan lokal ringan',
        81 => 'Hujan lokal sedang',
        82 => 'Hujan lokal sangat lebat',
        85 => 'Hujan salju ringan',
        86 => 'Hujan salju lebat',
        95 => 'Badai petir',
        96 => 'Badai petir dengan hujan es ringan',
        99 => 'Badai petir dengan hujan es lebat',
    ];

    private const COMPASS = ['Utara', 'Timur Laut', 'Timur', 'Tenggara', 'Selatan', 'Barat Daya', 'Barat', 'Barat Laut'];

    /**
     * Full payload for the conditions endpoint.
     */
    public function conditions(HikingRoute $route): array
    {
        $points = $this->points($route);

        if (! $points) {
            return [
                'route_id' => $route->id,
                'points'   => null,
                'location' => null,
                'weather'  => null,
                'safety'   => null,
                'sources'  => $this->sources(),
            ];
        }

        $weather = $this->weather($route, $points);

        return [
            'route_id' => $route->id,
            'points'   => $points,
            'location' => $this->location($route, $points['basecamp']['lat'], $points['basecamp']['lng']),
            'weather'  => $weather,
            'safety'   => $weather ? $this->safety($weather) : null,
            'sources'  => $this->sources(),
        ];
    }

    /**
     * Basecamp (first track point; tracks run trailhead -> summit) and summit,
     * using elevations stored in the track when present. Never touches the network.
     */
    public function trail(HikingRoute $route): ?array
    {
        $coords = $this->coordinates($route);

        if (! $coords) {
            return null;
        }

        $start   = $coords[0];
        $withEle = array_filter($coords, fn($p) => isset($p[2]));
        $summit  = $withEle
            ? array_reduce($withEle, fn($best, $p) => $best === null || $p[2] > $best[2] ? $p : $best)
            : $coords[count($coords) - 1];

        $basecampEle = isset($start[2]) ? (int) round($start[2]) : $this->narrativeMinElevation($route);
        $summitEle   = isset($summit[2]) ? (int) round($summit[2]) : $this->plausibleElevation($route->max_elevation_m);

        $profile = null;
        if (isset($start[2])) {
            $step    = max(1, (int) ceil(count($coords) / 100));
            $indexes = array_values(array_filter(array_keys($coords), fn($i) => $i % $step === 0 && isset($coords[$i][2])));
            $profile = $this->profile($route, $coords, $indexes, array_map(fn($i) => $coords[$i][2], $indexes));
        }

        return [
            'basecamp' => $this->point($start[0], $start[1], $basecampEle, [
                'name' => $this->basecampName($route),
            ]),
            'summit' => $this->point($summit[0], $summit[1], $summitEle),
            'elevation_source' => isset($start[2]) ? 'gpx' : 'narrative',
            'profile' => $profile,
        ];
    }

    /**
     * Same as trail(), refined with the Open-Meteo 90 m elevation model when the
     * track has no per-point elevation: the summit becomes the highest sampled
     * point and the basecamp gets its own height. Cached forever per route.
     * With $fetch = false only the cache is read (used while rendering the page).
     */
    public function points(HikingRoute $route, bool $fetch = true): ?array
    {
        $trail = $this->trail($route);

        if (! $trail || $trail['elevation_source'] === 'gpx') {
            return $trail;
        }

        $start = $trail['basecamp'];
        $key   = sprintf('route-points:v2:%d:%.4f,%.4f:%d', $route->id, $start['lat'], $start['lng'], count($this->coordinates($route)));

        if ($cached = Cache::get($key)) {
            // The name follows the current route name, it is not part of the cached geometry.
            $cached['basecamp']['name'] = $trail['basecamp']['name'];

            return $cached;
        }

        if (! $fetch || Cache::has($key . ':retry')) {
            return $trail;
        }

        $refined = $this->refineWithElevationModel($route, $trail);

        if (! $refined) {
            Cache::put($key . ':retry', true, self::GEO_RETRY);

            return $trail;
        }

        Cache::forever($key, $refined);

        return $refined;
    }

    /**
     * "Mt. Merbabu via Selo" -> "Basecamp Selo"; otherwise the manual basecamp name.
     */
    public function basecampName(HikingRoute $route): ?string
    {
        $name = trim((string) $route->name);

        if (preg_match('/\bvia\s+(.+)$/iu', $name, $m)) {
            return 'Basecamp ' . ucwords(trim($m[1]));
        }

        // Traverses: "Mt. Argopuro Baderan to Bremi" starts at Baderan.
        if (preg_match('/^(?:mt\.?|gunung|g\.)\s+\S+\s+(.+?)\s+(?:to|ke)\s+\S/iu', $name, $m)) {
            return 'Basecamp ' . ucwords(trim($m[1]));
        }

        return $route->basecamp_name ?: null;
    }

    /**
     * Village / district / regency / province of a point via Nominatim.
     * Cached forever per route + rounded coordinates; failures are retried after GEO_RETRY.
     */
    public function location(HikingRoute $route, float $lat, float $lng): ?array
    {
        $key = sprintf('route-location:%d:%.4f,%.4f', $route->id, $lat, $lng);

        if ($cached = Cache::get($key)) {
            $this->storeOnRoute($route, $cached);

            return $cached;
        }

        if (Cache::has($key . ':retry')) {
            return null;
        }

        $location = $this->reverseGeocode($lat, $lng);

        if (! $location) {
            Cache::put($key . ':retry', true, self::GEO_RETRY);

            return null;
        }

        $this->storeOnRoute($route, $location);

        return Cache::rememberForever($key, fn() => $location);
    }

    /**
     * Keep province/regency on the route so search can rank by place
     * ("jalur landai di Jawa Barat") without reading the cache per route.
     */
    private function storeOnRoute(HikingRoute $route, array $location): void
    {
        $province = $location['province'] ?? null;
        $regency  = $location['regency'] ?? null;

        if ($route->exists && ($route->province !== $province || $route->regency !== $regency)) {
            $route->forceFill(['province' => $province, 'regency' => $regency])->saveQuietly();
        }
    }

    /**
     * The trailhead location if location() has already cached it; never calls
     * an external API (safe while rendering lists of routes).
     */
    public function cachedLocation(HikingRoute $route): ?array
    {
        $coords = $this->coordinates($route);

        if (! $coords) {
            return null;
        }

        $cached = Cache::get(sprintf('route-location:%d:%.4f,%.4f', $route->id, round($coords[0][0], 6), round($coords[0][1], 6)));

        return is_array($cached) ? $cached : null;
    }

    /**
     * Current weather at basecamp and summit, cached WEATHER_TTL seconds per route.
     */
    public function weather(HikingRoute $route, array $points): ?array
    {
        $key = 'route-weather:' . $route->id;

        if ($cached = Cache::get($key)) {
            return $cached;
        }

        if (Cache::has($key . ':retry')) {
            return null;
        }

        $weather = $this->fetchWeather($points);

        if (! $weather) {
            Cache::put($key . ':retry', true, self::WEATHER_RETRY);

            return null;
        }

        Cache::put($key, $weather, self::WEATHER_TTL);

        return $weather;
    }

    /**
     * Short safety hints derived from the current conditions.
     */
    public function safety(array $weather): array
    {
        $summit   = $weather['summit'];
        $basecamp = $weather['basecamp'];
        $messages = [];

        if (($summit['wind_speed_kmh'] ?? 0) > 30 || ($summit['wind_gusts_kmh'] ?? 0) > 45) {
            $messages[] = 'Angin kencang di puncak (' . round((float) $summit['wind_speed_kmh']) . ' km/jam'
                . (! empty($summit['wind_gusts_kmh']) ? ', hembusan ' . round((float) $summit['wind_gusts_kmh']) . ' km/jam' : '')
                . '). Hindari punggungan terbuka dan bawa jaket penahan angin.';
        }

        $feels = $summit['apparent_temperature_c'] ?? $summit['temperature_c'] ?? null;
        if ($feels !== null && $feels < 5) {
            $messages[] = 'Suhu di puncak sangat dingin (terasa ' . round((float) $feels) . ' °C). Bawa pakaian berlapis, sarung tangan, dan kantong tidur yang memadai untuk mencegah hipotermia.';
        }

        $codes = [(int) ($summit['weather_code'] ?? 0), (int) ($basecamp['weather_code'] ?? 0)];
        if (max($codes) >= 95) {
            $messages[] = 'Ada potensi badai petir. Tunda pendakian ke puncak dan jauhi area terbuka serta pohon tunggal.';
        } elseif ($this->isRain($codes) || ($summit['precipitation_mm'] ?? 0) > 0 || ($basecamp['precipitation_mm'] ?? 0) > 0) {
            $messages[] = 'Sedang hujan di jalur. Jalur bisa licin; bawa jas hujan dan lindungi perlengkapan dari air.';
        }

        if (array_intersect($codes, [45, 48])) {
            $messages[] = 'Kabut menurunkan jarak pandang. Tetap di jalur yang bertanda dan jangan berpisah dari rombongan.';
        }

        return [
            'level'    => $messages ? 'caution' : 'ok',
            'messages' => $messages ?: ['Tidak ada peringatan cuaca saat ini. Tetap cek ulang sebelum berangkat dan ikuti arahan petugas basecamp.'],
        ];
    }

    public function weatherText(?int $code): string
    {
        return self::WEATHER_CODES[$code] ?? 'Tidak diketahui';
    }

    public function compass(?float $degrees): ?string
    {
        if ($degrees === null) {
            return null;
        }

        return self::COMPASS[(int) round(fmod($degrees + 360, 360) / 45) % 8];
    }

    // ------------------------------------------------------------------

    private function sources(): array
    {
        return [
            'weather'   => 'Open-Meteo',
            'location'  => 'OpenStreetMap Nominatim',
            'elevation' => 'Open-Meteo Elevation (DEM 90 m)',
        ];
    }

    /**
     * Valid [lat, lng(, ele)] points from the stored track.
     */
    private function coordinates(HikingRoute $route): array
    {
        $coords = is_array($route->route_coordinates) ? $route->route_coordinates : [];

        return array_values(array_filter($coords, fn($p) => is_array($p)
            && isset($p[0], $p[1]) && is_numeric($p[0]) && is_numeric($p[1])
            && abs($p[0]) <= 90 && abs($p[1]) <= 180));
    }

    private function point(float $lat, float $lng, ?int $elevation, array $extra = []): array
    {
        $lat = round($lat, 6);
        $lng = round($lng, 6);

        return $extra + [
            'lat'         => $lat,
            'lng'         => $lng,
            'elevation_m' => $elevation,
            'directions_url' => "https://www.google.com/maps/dir/?api=1&destination={$lat},{$lng}",
            'osm_url'        => "https://www.openstreetmap.org/?mlat={$lat}&mlon={$lng}#map=15/{$lat}/{$lng}",
        ];
    }

    private function narrativeMinElevation(HikingRoute $route): ?int
    {
        return preg_match('/dari (\d+)m/', (string) $route->narrative_text, $m)
            ? $this->plausibleElevation((int) $m[1])
            : null;
    }

    /**
     * Some imported narratives carry feet or broken values; ignore anything
     * outside what a mountain in Indonesia can be.
     */
    private function plausibleElevation(?int $metres): ?int
    {
        return $metres !== null && $metres > 0 && $metres < 5000 ? $metres : null;
    }

    private function refineWithElevationModel(HikingRoute $route, array $trail): ?array
    {
        $coords  = $this->coordinates($route);
        $step    = max(1, (int) ceil(count($coords) / 100));
        $indexes = array_values(array_filter(array_keys($coords), fn($i) => $i % $step === 0));
        $sample  = array_map(fn($i) => $coords[$i], $indexes);

        try {
            $response = $this->http()->get(self::ELEVATION_URL, [
                'latitude'  => implode(',', array_map(fn($p) => round($p[0], 5), $sample)),
                'longitude' => implode(',', array_map(fn($p) => round($p[1], 5), $sample)),
            ]);
        } catch (Throwable $e) {
            Log::warning('Open-Meteo elevation failed', ['route' => $route->id, 'error' => $e->getMessage()]);

            return null;
        }

        $elevations = $response->successful() ? $response->json('elevation') : null;

        if (! is_array($elevations) || count($elevations) !== count($sample) || ! is_numeric($elevations[0] ?? null)) {
            return null;
        }

        $top = 0;
        foreach ($elevations as $i => $ele) {
            if (is_numeric($ele) && $ele > $elevations[$top]) {
                $top = $i;
            }
        }

        $summitEle = $this->plausibleElevation($route->max_elevation_m);
        // The 90 m model flattens peaks; trust the GPX maximum unless it is clearly off.
        if ($summitEle === null || abs($summitEle - $elevations[$top]) > 400) {
            $summitEle = (int) round($elevations[$top]);
        }

        return [
            'basecamp' => $this->point($sample[0][0], $sample[0][1], (int) round($elevations[0]), [
                'name' => $trail['basecamp']['name'],
            ]),
            'summit'           => $this->point($sample[$top][0], $sample[$top][1], $summitEle),
            'elevation_source' => 'dem',
            'profile'          => $this->profile($route, $coords, $indexes, $elevations),
        ];
    }

    /**
     * Elevation profile as [[km, metres], ...] at the given track indexes.
     * Distances follow the stored (simplified) track, scaled to the route's
     * GPX distance so the last point matches the distance shown on the page.
     */
    private function profile(HikingRoute $route, array $coords, array $indexes, array $elevations): ?array
    {
        if (count($indexes) < 2) {
            return null;
        }

        $cumulative = [0.0];
        for ($i = 1, $n = count($coords); $i < $n; $i++) {
            $cumulative[$i] = $cumulative[$i - 1] + $this->distanceKm($coords[$i - 1], $coords[$i]);
        }

        $trackKm = $cumulative[$indexes[count($indexes) - 1]];
        $scale   = $trackKm > 0 && $route->distance_km > 0 ? $route->distance_km / $trackKm : 1.0;

        $profile = [];
        foreach ($indexes as $k => $i) {
            if (is_numeric($elevations[$k] ?? null)) {
                $profile[] = [round($cumulative[$i] * $scale, 2), (int) round($elevations[$k])];
            }
        }

        return count($profile) >= 2 ? $profile : null;
    }

    private function distanceKm(array $a, array $b): float
    {
        $lat1 = deg2rad($a[0]);
        $lat2 = deg2rad($b[0]);
        $h = sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin(deg2rad($b[1] - $a[1]) / 2) ** 2;

        return 2 * 6371 * asin(min(1, sqrt($h)));
    }

    private function reverseGeocode(float $lat, float $lng): ?array
    {
        $result = null;

        // Nominatim usage policy: at most one request per second for the whole app.
        $allowed = RateLimiter::attempt('nominatim-reverse', 1, function () use ($lat, $lng, &$result) {
            try {
                $response = $this->http()->get(self::NOMINATIM_URL, [
                    'format'          => 'jsonv2',
                    'lat'             => round($lat, 5),
                    'lon'             => round($lng, 5),
                    'zoom'            => 14,
                    'addressdetails'  => 1,
                    'accept-language' => 'id',
                ]);
                $result = $response->successful() ? $response->json() : null;
            } catch (Throwable $e) {
                Log::warning('Nominatim reverse failed', ['error' => $e->getMessage()]);
            }
        }, 1);

        $address = $allowed && is_array($result) ? ($result['address'] ?? null) : null;

        if (! is_array($address)) {
            return null;
        }

        $location = [
            'village'  => $address['village'] ?? $address['hamlet'] ?? $address['suburb'] ?? $address['neighbourhood'] ?? null,
            'district' => $address['city_district'] ?? $address['town'] ?? $address['municipality'] ?? null,
            'regency'  => $address['county'] ?? $address['city'] ?? $address['regency'] ?? null,
            'province' => $address['state'] ?? $address['province'] ?? null,
        ];

        if (! array_filter($location)) {
            return null;
        }

        return $location + [
            'label'        => implode(', ', array_unique(array_filter($location))),
            'display_name' => $result['display_name'] ?? null,
        ];
    }

    private function fetchWeather(array $points): ?array
    {
        $b = $points['basecamp'];
        $s = $points['summit'];

        $query = [
            'latitude'        => $b['lat'] . ',' . $s['lat'],
            'longitude'       => $b['lng'] . ',' . $s['lng'],
            'current'         => 'temperature_2m,apparent_temperature,relative_humidity_2m,precipitation,weather_code,wind_speed_10m,wind_direction_10m,wind_gusts_10m,is_day',
            'daily'           => 'sunrise,sunset',
            'timezone'        => self::TIMEZONE,
            'forecast_days'   => 1,
            'wind_speed_unit' => 'kmh',
        ];

        // Height-correct both temperatures; without both heights Open-Meteo uses its own terrain model.
        if ($b['elevation_m'] !== null && $s['elevation_m'] !== null) {
            $query['elevation'] = $b['elevation_m'] . ',' . $s['elevation_m'];
        }

        try {
            $response = $this->http()->get(self::FORECAST_URL, $query);
        } catch (Throwable $e) {
            Log::warning('Open-Meteo forecast failed', ['error' => $e->getMessage()]);

            return null;
        }

        $data = $response->successful() ? $response->json() : null;

        // Two locations come back as a list; be lenient if only an object returns.
        if (is_array($data) && isset($data['current'])) {
            $data = [$data, $data];
        }

        if (! is_array($data) || ! isset($data[0]['current'], $data[1]['current'])) {
            return null;
        }

        $now = Carbon::now(self::TIMEZONE);

        return [
            'fetched_at'    => $now->toIso8601String(),
            'updated_label' => $now->format('H:i') . ' WIB',
            'observed_at'   => $data[0]['current']['time'] ?? null,
            'basecamp'      => $this->mapCurrent($data[0], $b['elevation_m']),
            'summit'        => $this->mapCurrent($data[1], $s['elevation_m']),
            'sunrise'       => $this->clock($data[0]['daily']['sunrise'][0] ?? null),
            'sunset'        => $this->clock($data[0]['daily']['sunset'][0] ?? null),
        ];
    }

    private function mapCurrent(array $data, ?int $elevation): array
    {
        $c    = $data['current'];
        $num  = fn($k) => isset($c[$k]) && is_numeric($c[$k]) ? round((float) $c[$k], 1) : null;
        $code = isset($c['weather_code']) ? (int) $c['weather_code'] : null;
        $dir  = $num('wind_direction_10m');

        return [
            'elevation_m'            => $elevation ?? (isset($data['elevation']) ? (int) round($data['elevation']) : null),
            'temperature_c'          => $num('temperature_2m'),
            'apparent_temperature_c' => $num('apparent_temperature'),
            'humidity_pct'           => $num('relative_humidity_2m'),
            'precipitation_mm'       => $num('precipitation'),
            'wind_speed_kmh'         => $num('wind_speed_10m'),
            'wind_gusts_kmh'         => $num('wind_gusts_10m'),
            'wind_direction_deg'     => $dir,
            'wind_direction'         => $this->compass($dir),
            'weather_code'           => $code,
            'weather_text'           => $this->weatherText($code),
            'is_day'                 => isset($c['is_day']) ? (bool) $c['is_day'] : null,
        ];
    }

    private function clock(?string $isoLocal): ?string
    {
        return $isoLocal && preg_match('/T(\d{2}:\d{2})/', $isoLocal, $m) ? $m[1] : null;
    }

    private function isRain(array $codes): bool
    {
        foreach ($codes as $code) {
            if (($code >= 51 && $code <= 67) || ($code >= 80 && $code <= 82)) {
                return true;
            }
        }

        return false;
    }

    private function http()
    {
        return Http::timeout(5)
            ->connectTimeout(3)
            // One quick retry when the connection itself fails (e.g. an SSL
            // handshake timeout); HTTP error responses are not retried.
            ->retry(2, 400, fn($e) => $e instanceof \Illuminate\Http\Client\ConnectionException, throw: false)
            ->acceptJson()
            ->withUserAgent(self::USER_AGENT);
    }
}
