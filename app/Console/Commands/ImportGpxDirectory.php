<?php
namespace App\Console\Commands;

use App\Models\HikingRoute;
use App\Services\GpxVersionService;
use App\Services\PythonProcessorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Import a folder of GPX files (e.g. webchat-api/gpx_db) into hiking_routes.
 *
 * Skips files that would hurt search quality:
 *  - exact duplicates (same file hash) or the same track already in the DB
 *    (name match, or distance within 3% and elevation gain within 5%);
 *  - compilations of several routes in one file ("semua jalur", "4 jalur");
 *  - GPX without elevation data or longer than 60 km (vehicle segments);
 *  - noisy elevation: total gain above 2x the (max - min) elevation range, or
 *    an average grade above 35%;
 *  - wrong units (max elevation above 3,800 m, higher than any peak in Java)
 *    or lowland travel recorded (min elevation below 20% of max on a > 1,000 m peak).
 */
class ImportGpxDirectory extends Command
{
    protected $signature = 'routes:import-gpx-dir
        {dir : Folder containing .gpx files (absolute or relative to the project root)}
        {--dry-run : Only report what would be imported}';

    protected $description = 'Import a folder of GPX files into hiking_routes, skipping duplicates and unreliable tracks';

    private const MAX_HIKE_KM = 60.0;
    private const MAX_GAIN_TO_RANGE = 2.0;
    private const MAX_ELEVATION_M = 3800;
    private const MAX_GRADE_PCT = 35.0;

    public function handle(PythonProcessorService $python, GpxVersionService $versions): int
    {
        $dir = $this->argument('dir');
        $dir = is_dir($dir) ? $dir : base_path($dir);
        if (! is_dir($dir)) {
            $this->error("Folder not found: {$dir}");
            return self::FAILURE;
        }

        $files = collect(File::files($dir))
            ->filter(fn($f) => strtolower($f->getExtension()) === 'gpx')
            ->sortBy(fn($f) => $f->getFilename())
            ->values();

        $known = HikingRoute::all(['id', 'name', 'distance_km', 'elevation_gain_m'])
            ->map(fn($r) => ['name' => $this->nameKey($r->name), 'km' => (float) $r->distance_km, 'gain' => (float) $r->elevation_gain_m, 'label' => $r->name])
            ->all();
        $knownHashes = HikingRoute::pluck('gpx_file_path')
            ->filter(fn($p) => $p && Storage::disk('public')->exists($p))
            ->map(fn($p) => hash_file('sha256', Storage::disk('public')->path($p)))
            ->flip()
            ->all();

        $imported = $skipped = $failed = 0;

        foreach ($files as $file) {
            $filename = $file->getFilename();
            $name     = $this->prettyName($file->getFilenameWithoutExtension());
            $hash     = hash_file('sha256', $file->getPathname());

            if (isset($knownHashes[$hash])) {
                $this->line("skip  {$filename}: same file already imported");
                $skipped++;
                continue;
            }
            if (preg_match('/semua\s*jalur|\d\s*jalur/i', $filename)) {
                $this->line("skip  {$filename}: several routes in one file");
                $skipped++;
                continue;
            }

            try {
                $result = $python->ingest($file->getPathname());
            } catch (\Throwable $e) {
                $this->error("fail  {$filename}: {$e->getMessage()}");
                $failed++;
                continue;
            }

            $km   = (float) ($result['distance_km'] ?? 0);
            $gain = (float) ($result['elevation_gain_m'] ?? 0);

            if ($gain <= 0 || $km <= 0 || $km > self::MAX_HIKE_KM) {
                $this->line(sprintf('skip  %s: unreliable stats (%.1f km, %d m gain)', $filename, $km, $gain));
                $skipped++;
                continue;
            }

            $grade = (float) ($result['average_grade_pct'] ?? 0);
            [$minEle, $maxEle] = preg_match('/dari (\d+)m\s+hingga (\d+)m/', $result['narrative_text'] ?? '', $m) ? [(int) $m[1], (int) $m[2]] : [0, 0];
            $range = max(0, $maxEle - $minEle);
            if ($maxEle > self::MAX_ELEVATION_M || ($maxEle > 1000 && $minEle < 0.2 * $maxEle)) {
                $this->line(sprintf('skip  %s: implausible elevation %d-%d m (wrong units or travel to the trailhead recorded)', $filename, $minEle, $maxEle));
                $skipped++;
                continue;
            }
            if ($grade > self::MAX_GRADE_PCT || ($range > 0 && $gain > self::MAX_GAIN_TO_RANGE * $range)) {
                $this->line(sprintf('skip  %s: noisy elevation (%d m gain over a %d m range, %.1f%%)', $filename, $gain, $range, $grade));
                $skipped++;
                continue;
            }

            // Same name, or near-identical stats on the same mountain (shared name word).
            $key       = $this->nameKey($name);
            $duplicate = collect($known)->first(fn($k) => $k['name'] === $key
                || (array_intersect(explode(' ', $k['name']), explode(' ', $key))
                    && abs($k['km'] - $km) <= 0.03 * max($k['km'], $km)
                    && abs($k['gain'] - $gain) <= 0.05 * max($k['gain'], $gain, 1)));
            if ($duplicate) {
                $this->line("skip  {$filename}: same track as \"{$duplicate['label']}\"");
                $skipped++;
                continue;
            }

            $this->info(sprintf('add   %s -> "%s" (%.1f km, %d m, %.1f%%)', $filename, $name, $km, $gain, $result['average_grade_pct'] ?? 0));
            $known[]            = ['name' => $this->nameKey($name), 'km' => $km, 'gain' => $gain, 'label' => $name];
            $knownHashes[$hash] = true;

            if ($this->option('dry-run')) {
                $imported++;
                continue;
            }

            $storedPath = 'gpx_files/' . time() . '_' . substr($hash, 0, 8) . '_' . Str::slug($name) . '.gpx';
            Storage::disk('public')->put($storedPath, File::get($file->getPathname()));

            $route = HikingRoute::create([
                'name'                   => $name,
                'gpx_file_path'          => $storedPath,
                'route_coordinates'      => $result['route_coordinates'] ?? null,
                'distance_km'            => $km,
                'elevation_gain_m'       => $gain,
                'naismith_duration_hour' => $result['naismith_duration_hour'] ?? null,
                'average_grade_pct'      => $result['average_grade_pct'] ?? null,
                'narrative_text'         => $result['narrative_text'] ?? null,
                'sbert_embedding'        => $result['embedding'] ?? null,
            ]);

            $versions->createInitialVersion($route, $storedPath, null, 'Imported from ' . $filename);
            $imported++;
        }

        $verb = $this->option('dry-run') ? 'Would import' : 'Imported';
        $this->info("{$verb}: {$imported}, skipped: {$skipped}, failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** "Mt. Sumbing via Garung" / "Gunung_Kembang_Via_Blembem_23_Des_2018" -> readable title. */
    private function prettyName(string $raw): string
    {
        $name = str_replace('_', ' ', $raw);
        $name = preg_replace('/\s+\d{1,2}\s+[a-z]{3}\s+\d{4}$/i', '', $name);   // trailing date
        $name = preg_replace('/\s*\(\d+\)$/', '', $name);                         // "(1)" copies
        $name = preg_replace('/^\s*(mt\.?|mount)\s+/i', 'Gunung ', $name);
        if (! preg_match('/\bvia\b/i', $name)) {
            $name = preg_replace('/\s+-\s+/', ' via ', $name, 1);                 // "Agung - Besakih"
        }
        // Capitalise each word but keep existing capitals (acronyms like "PGPI")
        $name = implode(' ', array_map(fn($w) => Str::ucfirst($w), explode(' ', Str::squish($name))));
        $name = preg_replace_callback('/\b(Via|Ke|Dan)\b/', fn($m) => Str::lower($m[1]), $name);

        $prefixes = ['Gunung ', 'Bukit ', 'Puncak ', 'Kawah ', 'Jalur ', 'Puthuk ', 'Gumuk '];

        return Str::startsWith($name, $prefixes) ? $name : 'Gunung ' . $name;
    }

    private function nameKey(?string $name): string
    {
        $name = Str::lower((string) $name);
        $name = preg_replace('/\b(mt|mount|gunung|gurung|via|jalur|to|bc|basecamp|puncak)\b/', ' ', $name);

        // Word order ignored: "Argopuro Bremi Baderan" == "Argopuro Baderan to Bremi"
        $words = array_unique(preg_split('/[^a-z]+/', $name, -1, PREG_SPLIT_NO_EMPTY));
        sort($words);

        return implode(' ', $words);
    }
}
