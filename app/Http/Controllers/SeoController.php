<?php
namespace App\Http\Controllers;

use App\Models\HikingRoute;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * robots.txt and sitemap.xml for search engines (Google, Bing, ...).
 */
class SeoController extends Controller
{
    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /dashboard',
            'Disallow: /profile',
            'Disallow: /favorites',
            'Disallow: /history',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /routes/create',
            'Disallow: /routes-batch',
            'Disallow: /evaluation',
            'Disallow: /chat/',
            'Disallow: /*/versions/create',
            '',
            'Sitemap: ' . route('sitemap'),
        ];

        return response(implode("\n", $lines) . "\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $xml = Cache::remember('sitemap_xml', 3600, function () {
            $urls = [
                ['loc' => url('/'), 'priority' => '1.0', 'changefreq' => 'weekly', 'lastmod' => null],
                ['loc' => route('routes.index'), 'priority' => '0.9', 'changefreq' => 'weekly', 'lastmod' => null],
                ['loc' => route('architecture'), 'priority' => '0.3', 'changefreq' => 'monthly', 'lastmod' => null],
            ];

            HikingRoute::query()->orderBy('id')->get(['id', 'name', 'updated_at'])->each(function (HikingRoute $route) use (&$urls) {
                $urls[] = [
                    'loc'        => route('routes.show', $route),
                    'priority'   => '0.8',
                    'changefreq' => 'monthly',
                    'lastmod'    => $route->updated_at?->toAtomString(),
                ];
            });

            $body = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach ($urls as $u) {
                $body .= '  <url><loc>' . e($u['loc']) . '</loc>'
                    . ($u['lastmod'] ? '<lastmod>' . $u['lastmod'] . '</lastmod>' : '')
                    . '<changefreq>' . $u['changefreq'] . '</changefreq>'
                    . '<priority>' . $u['priority'] . '</priority></url>' . "\n";
            }

            return $body . '</urlset>' . "\n";
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
