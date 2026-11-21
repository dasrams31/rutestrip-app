<?php

namespace App\Console\Commands;

use App\Models\HikingRoute;
use App\Services\RouteConditionsService;
use Illuminate\Console\Command;

/**
 * Fill hiking_routes.province / regency from the trailhead location
 * (OpenStreetMap Nominatim, cached forever, so re-runs are instant).
 * New routes get theirs the first time their page loads its conditions.
 */
class LocateRoutes extends Command
{
    protected $signature = 'routes:locate {--missing : Only routes without a province}';

    protected $description = 'Store the trailhead province and regency on every route';

    public function handle(RouteConditionsService $conditions): int
    {
        $query = HikingRoute::orderBy('id');
        if ($this->option('missing')) {
            $query->whereNull('province');
        }

        $located = 0;
        $failed = [];
        foreach ($query->get() as $route) {
            $points = $conditions->points($route);
            if (! $points) {
                $failed[] = $route->id;
                continue;
            }

            $location = $conditions->location($route, $points['basecamp']['lat'], $points['basecamp']['lng']);
            if ($location === null) {
                usleep(1100000); // Nominatim allows one request per second; retry once
                $location = $conditions->location($route, $points['basecamp']['lat'], $points['basecamp']['lng']);
            }

            $location && ! empty($location['province']) ? $located++ : $failed[] = $route->id;
        }

        $this->info("Located: {$located}" . ($failed ? ', without location: ' . implode(', ', $failed) : ''));

        return self::SUCCESS;
    }
}
