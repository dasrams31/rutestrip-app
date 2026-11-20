<?php
namespace App\Console\Commands;

use App\Models\HikingRoute;
use App\Services\RouteSearchIndexer;
use Illuminate\Console\Command;

class ReindexRouteSearch extends Command
{
    protected $signature = 'routes:reindex {--chunk=20 : Routes per request to the Python processor}';

    protected $description = 'Rebuild SBERT search embeddings (document + aspect sentences) for all routes';

    public function handle(RouteSearchIndexer $indexer): int
    {
        $total = 0;
        $start = microtime(true);

        HikingRoute::whereNotNull('narrative_text')->chunkById((int) $this->option('chunk'), function ($routes) use ($indexer, &$total) {
            $total += $indexer->indexMany($routes);
            $this->info("Indexed {$total} routes...");
        });

        $this->info(sprintf('Done: %d routes in %.1f s', $total, microtime(true) - $start));

        return self::SUCCESS;
    }
}
