<?php
namespace App\Console\Commands;

use App\Models\HikingRoute;
use App\Services\PythonProcessorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Re-run the Python GPX processor on each route's current GPX file and update
 * the statistics and narrative (difficulty, suitability). Search embeddings are
 * refreshed by the HikingRoute observer when the narrative changes.
 */
class ReprocessRoutes extends Command
{
    protected $signature = 'routes:reprocess {--dry-run : Show difficulty changes without saving}';

    protected $description = 'Recompute statistics, difficulty and narrative for all routes from their GPX files';

    public function handle(PythonProcessorService $python): int
    {
        $updated = $failed = 0;

        foreach (HikingRoute::orderBy('id')->get() as $route) {
            if (! $route->gpx_file_path || ! Storage::disk('public')->exists($route->gpx_file_path)) {
                $this->warn("skip  #{$route->id} {$route->name}: GPX file missing");
                continue;
            }

            try {
                $result = $python->ingest(Storage::disk('public')->path($route->gpx_file_path));
            } catch (\Throwable $e) {
                $this->error("fail  #{$route->id} {$route->name}: {$e->getMessage()}");
                $failed++;
                continue;
            }

            $before = $route->difficulty_level;
            $route->fill([
                'distance_km'            => $result['distance_km'] ?? $route->distance_km,
                'elevation_gain_m'       => $result['elevation_gain_m'] ?? $route->elevation_gain_m,
                'naismith_duration_hour' => $result['naismith_duration_hour'] ?? $route->naismith_duration_hour,
                'average_grade_pct'      => $result['average_grade_pct'] ?? $route->average_grade_pct,
                'steepest_grade_pct'     => $result['steepest_grade_pct'] ?? $route->steepest_grade_pct,
                'narrative_text'         => $result['narrative_text'] ?? $route->narrative_text,
                'route_coordinates'      => $result['route_coordinates'] ?? $route->route_coordinates,
            ]);
            $after = $route->difficulty_level;

            $this->line(sprintf('%s #%d %s: %s%s', $before === $after ? '     ' : 'diff ', $route->id, $route->name,
                $before === $after ? $after : "{$before} -> {$after}", sprintf(' (skor %.0f)', $route->difficulty_score)));

            if (! $this->option('dry-run')) {
                $route->save();
                $updated++;
            }
        }

        $this->info($this->option('dry-run') ? 'Dry run, nothing saved.' : "Updated: {$updated}, failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
