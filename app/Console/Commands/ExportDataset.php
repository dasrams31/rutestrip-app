<?php

namespace App\Console\Commands;

use App\Models\HikingRoute;
use App\Services\RouteConditionsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Export the route dataset (CSV + JSON + SBERT embeddings + GPX files) for
 * publishing, e.g. as a zip on Kaggle/Zenodo. Location and summit heights come
 * from RouteConditionsService (Nominatim / Open-Meteo, cached forever), so only
 * the first run makes external requests (about one per route, 1 req/s).
 */
class ExportDataset extends Command
{
    protected $signature = 'dataset:export {dir : Output directory}
                            {--no-gpx : Skip copying GPX files}';

    protected $description = 'Export the hiking route dataset (routes.csv, routes.json, embeddings.csv, gpx/)';

    private const COLUMNS = [
        'route_id', 'slug', 'name', 'mountain', 'via',
        'village', 'district', 'regency', 'province',
        'distance_km', 'elevation_gain_m', 'naismith_duration_hour', 'average_grade_pct',
        'min_elevation_m', 'max_elevation_m', 'difficulty', 'difficulty_score',
        'trailhead_lat', 'trailhead_lng', 'trailhead_elevation_m',
        'summit_lat', 'summit_lng', 'summit_elevation_m', 'elevation_source',
        'track_points', 'narrative_text', 'description', 'likes', 'average_rating',
        'source', 'source_url', 'license', 'gpx_file', 'url',
    ];

    public function handle(RouteConditionsService $conditions): int
    {
        $dir = rtrim($this->argument('dir'), '/\\');
        File::ensureDirectoryExists($dir);
        if (! $this->option('no-gpx')) {
            File::ensureDirectoryExists("$dir/gpx");
        }

        $osmSources = $this->osmSources();
        $routes = HikingRoute::orderBy('id')->get();
        $rows = [];
        $json = [];
        $embeddings = [];

        $bar = $this->output->createProgressBar($routes->count());
        foreach ($routes as $route) {
            $points = $conditions->points($route);
            $location = null;
            if ($points) {
                $location = $conditions->location($route, $points['basecamp']['lat'], $points['basecamp']['lng']);
                if ($location === null) {
                    usleep(1100000); // Nominatim allows one request per second; retry once
                    $location = $conditions->location($route, $points['basecamp']['lat'], $points['basecamp']['lng']);
                }
            }

            $slug = Str::slug($route->name) ?: 'route';
            $gpxName = sprintf('%03d-%s.gpx', $route->id, $slug);
            $source = $this->source($route, $osmSources);

            if (! $this->option('no-gpx') && $route->gpx_file_path && Storage::disk('public')->exists($route->gpx_file_path)) {
                File::copy(Storage::disk('public')->path($route->gpx_file_path), "$dir/gpx/$gpxName");
            } else {
                $gpxName = null;
            }

            preg_match('/dari (\d+)m/', (string) $route->narrative_text, $min);
            [$mountain, $via] = $this->mountainAndVia($route->name);
            $coords = is_array($route->route_coordinates) ? $route->route_coordinates : [];

            $row = [
                'route_id' => $route->id,
                'slug' => $slug,
                'name' => $route->name,
                'mountain' => $mountain,
                'via' => $via,
                'village' => $location['village'] ?? null,
                'district' => $location['district'] ?? null,
                'regency' => $location['regency'] ?? null,
                'province' => $location['province'] ?? null,
                'distance_km' => (float) $route->distance_km,
                'elevation_gain_m' => (int) $route->elevation_gain_m,
                'naismith_duration_hour' => (float) $route->naismith_duration_hour,
                'average_grade_pct' => (float) $route->average_grade_pct,
                'min_elevation_m' => isset($min[1]) ? (int) $min[1] : null,
                'max_elevation_m' => $route->max_elevation_m,
                'difficulty' => $route->difficulty_level,
                'difficulty_score' => round($route->difficulty_score, 1),
                'trailhead_lat' => $points['basecamp']['lat'] ?? null,
                'trailhead_lng' => $points['basecamp']['lng'] ?? null,
                'trailhead_elevation_m' => $points['basecamp']['elevation_m'] ?? null,
                'summit_lat' => $points['summit']['lat'] ?? null,
                'summit_lng' => $points['summit']['lng'] ?? null,
                'summit_elevation_m' => $points['summit']['elevation_m'] ?? null,
                'elevation_source' => $points['elevation_source'] ?? null,
                'track_points' => count($coords),
                'narrative_text' => $route->narrative_text,
                'description' => $route->description,
                'likes' => $route->likes_count,
                'average_rating' => $route->average_rating,
                'source' => $source['source'],
                'source_url' => $source['url'],
                'license' => $source['license'],
                'gpx_file' => $gpxName ? "gpx/$gpxName" : null,
                'url' => route('routes.show', $route),
            ];

            $rows[] = $row;
            $json[] = $row + [
                'route_coordinates' => $coords,
                'elevation_profile' => $points['profile'] ?? null,
            ];

            $vector = is_array($route->sbert_embedding) ? $route->sbert_embedding : json_decode((string) $route->sbert_embedding, true);
            if (is_array($vector) && $vector) {
                $embeddings[] = array_merge([$route->id], array_map(fn($v) => round((float) $v, 6), $vector));
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $this->writeCsv("$dir/routes.csv", self::COLUMNS, array_map(fn($r) => array_values($r), $rows));
        File::put("$dir/routes.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($embeddings) {
            $dims = count($embeddings[0]) - 1;
            $header = array_merge(['route_id'], array_map(fn($i) => "e$i", range(0, $dims - 1)));
            $this->writeCsv("$dir/embeddings.csv", $header, $embeddings);
        }

        $located = count(array_filter($rows, fn($r) => $r['province']));
        $this->info(sprintf('Exported %d routes (%d with location, %d embeddings) to %s', count($rows), $located, count($embeddings), $dir));

        return self::SUCCESS;
    }

    private function writeCsv(string $path, array $header, array $rows): void
    {
        $fh = fopen($path, 'w');
        fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it correctly
        fputcsv($fh, $header);
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);
    }

    /**
     * "Gunung Merbabu via Selo" -> ["Merbabu", "Selo"]; "Mt. Slamet via Guci" -> ["Slamet", "Guci"].
     */
    private function mountainAndVia(string $name): array
    {
        $parts = preg_split('/\s+via\s+/i', $name, 2);
        $mountain = preg_replace('/^(mt\.?|mount|gunung|puncak|bukit|kawah|jalur|puthuk|gumuk)\s+/i', '', trim($parts[0]));

        return [$mountain ?: $name, $parts[1] ?? null];
    }

    /**
     * file name => OSM link, from webchat-api/gpx_db/OSM_SOURCES.md.
     */
    private function osmSources(): array
    {
        $path = base_path('webchat-api/gpx_db/OSM_SOURCES.md');
        if (! File::exists($path)) {
            return [];
        }

        $map = [];
        foreach (file($path) as $line) {
            if (preg_match('/^\|\s*(.+?\.gpx)\s*\|\s*.*?\((https:\/\/www\.openstreetmap\.org\/[^)]+)\)/', $line, $m)) {
                $map[Str::lower(pathinfo($m[1], PATHINFO_FILENAME))] = $m[2];
            }
        }

        return $map;
    }

    private function source(HikingRoute $route, array $osm): array
    {
        // The importer turns "A - B" into "A via B", so compare without "via".
        $key = fn(string $s) => str_replace('-via-', '-', '-' . Str::slug($s) . '-');
        $original = preg_replace('/^\d+_[A-Za-z0-9]{8}_/', '', pathinfo((string) $route->gpx_file_path, PATHINFO_FILENAME));
        foreach ($osm as $file => $url) {
            if (in_array($key($file), [$key($original), $key($route->name)], true)) {
                return ['source' => 'OpenStreetMap', 'url' => $url, 'license' => 'ODbL-1.0'];
            }
        }

        $byUser = $route->uploaded_by && optional($route->uploader)->role !== 'admin';

        return [
            'source' => $byUser ? 'RuteStrip user upload' : 'RuteStrip research collection',
            'url' => null,
            'license' => 'See README',
        ];
    }
}
