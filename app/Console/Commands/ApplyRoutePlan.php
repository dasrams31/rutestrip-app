<?php

namespace App\Console\Commands;

use App\Models\HikingRoute;
use App\Services\GpxVersionService;
use App\Services\PythonProcessorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Apply a route audit plan (python/tools/build_audit_report.py -> plan.json):
 * "replace" entries become a NEW GPX version of the route (the old track stays in
 * the version history), processed exactly like an admin version upload.
 * Dry run unless --apply is given.
 */
class ApplyRoutePlan extends Command
{
    protected $signature = 'routes:apply-plan {plan : Path to plan.json}
                            {--dir= : Folder the plan\'s file paths are relative to (default: the plan\'s folder)}
                            {--only= : Comma-separated route ids to apply}
                            {--apply : Write changes (otherwise only list what would happen)}';

    protected $description = 'Apply route_audit plan.json replacements as new GPX versions';

    public function handle(PythonProcessorService $python, GpxVersionService $versions): int
    {
        $planPath = $this->argument('plan');
        if (! File::exists($planPath)) {
            $this->error("Plan not found: {$planPath}");

            return self::FAILURE;
        }

        $baseDir = rtrim($this->option('dir') ?: dirname($planPath), '/\\');
        $only = array_filter(array_map('intval', explode(',', (string) $this->option('only'))));
        $plan = json_decode(File::get($planPath), true) ?: [];
        $apply = (bool) $this->option('apply');

        $done = $skipped = $failed = 0;
        foreach ($plan as $entry) {
            if (($entry['action'] ?? null) !== 'replace') {
                continue;
            }
            $routeId = (int) ($entry['route_id'] ?? 0);
            if ($only && ! in_array($routeId, $only, true)) {
                continue;
            }

            $route = HikingRoute::find($routeId);
            $file = $baseDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $entry['file'] ?? '');
            if (! $route || ! File::exists($file)) {
                $this->warn(sprintf('skip #%d: %s', $routeId, ! $route ? 'route not found' : "file missing {$file}"));
                $skipped++;
                continue;
            }

            $note = $this->changeLog($entry);
            if (! $apply) {
                $this->line(sprintf('would update #%d %s <- %s', $route->id, $route->name, basename($file)));
                $this->line('   ' . $note);
                continue;
            }

            $stored = 'gpx_files/' . time() . '_' . Str::random(8) . '_' . (Str::slug($route->name) ?: 'route') . '.gpx';
            Storage::disk('public')->put($stored, File::get($file));
            $fullPath = Storage::disk('public')->path($stored);

            try {
                $result = $python->ingest($fullPath);
                if (! ($result['success'] ?? true) || empty($result['route_coordinates'])) {
                    throw new \RuntimeException($result['error'] ?? 'processor returned no track');
                }

                $route->update([
                    'gpx_file_path'          => $stored,
                    'route_coordinates'      => $result['route_coordinates'],
                    'distance_km'            => $result['distance_km'] ?? $route->distance_km,
                    'elevation_gain_m'       => $result['elevation_gain_m'] ?? $route->elevation_gain_m,
                    'naismith_duration_hour' => $result['naismith_duration_hour'] ?? $route->naismith_duration_hour,
                    'average_grade_pct'      => $result['average_grade_pct'] ?? $route->average_grade_pct,
                    'steepest_grade_pct'     => $result['steepest_grade_pct'] ?? $route->steepest_grade_pct,
                    'narrative_text'         => $result['narrative_text'] ?? $route->narrative_text,
                    'sbert_embedding'        => $result['embedding'] ?? $route->sbert_embedding,
                ]);

                $version = $versions->createVersion($route, $stored, null, $note);
                $this->info(sprintf('ok   #%d %s -> v%d (%.2f km, +%d m)', $route->id, $route->name, $version->version_number, $route->distance_km, $route->elevation_gain_m));
                $done++;
            } catch (Throwable $e) {
                Storage::disk('public')->delete($stored);
                $this->error(sprintf('fail #%d %s: %s', $route->id, $route->name, $e->getMessage()));
                $failed++;
            }
        }

        $this->line($apply
            ? "Updated: {$done}, skipped: {$skipped}, failed: {$failed}"
            : 'Dry run. Add --apply to write the changes.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function changeLog(array $entry): string
    {
        $parts = ['Jalur dilengkapi sampai puncak memakai jalan setapak OpenStreetMap (audit puncak).'];
        if (! empty($entry['km_added'])) {
            $parts[] = sprintf('Tambahan %.2f km.', $entry['km_added']);
        }
        if (! empty($entry['peak_name']) && ! empty($entry['new_max_ele'])) {
            $parts[] = sprintf('Titik tertinggi sekarang %d m (puncak %s %s m).', $entry['new_max_ele'], $entry['peak_name'], $entry['peak_ele'] ?? '?');
        }
        if (($entry['status'] ?? '') === 'EXTENDED_REVIEW') {
            $parts[] = 'Sambungan 3 sampai 6 km, sudah ditinjau lewat peta.';
        }
        $parts[] = 'Data jalur (c) kontributor OpenStreetMap, ODbL.';

        return Str::limit(implode(' ', $parts), 1000);
    }
}
