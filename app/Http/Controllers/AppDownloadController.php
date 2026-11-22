<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

/**
 * Android app downloads (APK installed outside the Play Store).
 *
 * The APK files live in storage/app/downloads (never in public/ or in git) and are
 * copied there by hand on each release:
 *   - RuteStrip-arm64-v8a.apk     -> /install       (64-bit phones)
 *   - RuteStrip-armeabi-v7a.apk   -> /install/lama  (older 32-bit phones)
 * When a file is missing the visitor gets a friendly page (404) instead of an error.
 */
class AppDownloadController extends Controller
{
    public const BUILDS = [
        'arm64' => ['file' => 'RuteStrip-arm64-v8a.apk', 'name' => 'RuteStrip.apk'],
        'legacy' => ['file' => 'RuteStrip-armeabi-v7a.apk', 'name' => 'RuteStrip-32bit.apk'],
    ];

    public function arm64(): Response
    {
        return $this->send('arm64');
    }

    public function legacy(): Response
    {
        return $this->send('legacy');
    }

    public static function path(string $build): string
    {
        return storage_path('app/downloads/' . self::BUILDS[$build]['file']);
    }

    private function send(string $build): Response
    {
        $path = self::path($build);

        if (! is_file($path) || ! is_readable($path)) {
            return response()->view('app-download.missing', ['legacy' => $build === 'legacy'], 404);
        }

        // BinaryFileResponse streams the file and sets Content-Length (and handles Range requests).
        // Short public cache: the URL stays the same when a new release replaces the file.
        $response = response()->download($path, self::BUILDS[$build]['name'], [
            'Content-Type' => 'application/vnd.android.package-archive',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=600, must-revalidate',
        ]);
        $response->setLastModified(\DateTime::createFromFormat('U', (string) filemtime($path)));

        return $response;
    }
}
