<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** /install and /install/lama (Android APK), the landing app section, the navbar link and the PWA section in settings. */
class AppDownloadTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        // A throwaway storage folder, so the test never reads or writes the real APKs.
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rs-app-download-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->storage . '/app/downloads');
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function fakeApk(string $file, string $bytes): void
    {
        file_put_contents($this->storage . '/app/downloads/' . $file, $bytes);
    }

    public function test_install_streams_the_arm64_apk_with_download_headers(): void
    {
        $this->fakeApk('RuteStrip-arm64-v8a.apk', 'PK fake arm64 apk');

        $response = $this->get('/install')->assertOk();

        $this->assertSame(route('app.download'), url('/install'));
        $this->assertSame('application/vnd.android.package-archive', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('filename=RuteStrip.apk', $response->headers->get('Content-Disposition'));
        $this->assertSame((string) strlen('PK fake arm64 apk'), $response->headers->get('Content-Length'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('max-age=600', $response->headers->get('Cache-Control'));
        $this->assertNotNull($response->headers->get('Last-Modified'));
        $response->assertDownload('RuteStrip.apk');
        $this->assertSame(
            realpath($this->storage . '/app/downloads/RuteStrip-arm64-v8a.apk'),
            realpath($response->baseResponse->getFile()->getPathname())
        );
    }

    public function test_install_lama_streams_the_32bit_apk(): void
    {
        $this->fakeApk('RuteStrip-armeabi-v7a.apk', 'PK fake v7a');

        $response = $this->get('/install/lama')->assertOk();

        $this->assertSame(route('app.download.legacy'), url('/install/lama'));
        $this->assertSame('application/vnd.android.package-archive', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('filename=RuteStrip-32bit.apk', $response->headers->get('Content-Disposition'));
        $this->assertSame('11', $response->headers->get('Content-Length'));
        $response->assertDownload('RuteStrip-32bit.apk');
    }

    public function test_missing_apk_shows_a_friendly_404_page(): void
    {
        $this->get('/install')->assertNotFound()
            ->assertSee('Aplikasi Android belum tersedia')
            ->assertSee('Berkas pemasangnya sedang tidak ada di server.')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee('Unduh versi 64-bit');

        $this->get('/install/lama')->assertNotFound()
            ->assertSee('HP lama (32-bit)')
            ->assertSee(route('app.download'), false);

        $this->get('/install?lang=en')->assertNotFound()->assertSee('The Android app is not available yet');
    }

    public function test_downloads_are_throttled_per_ip(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->get('/install')->assertNotFound();
        }
        $this->get('/install')->assertStatus(429);
    }

    public function test_landing_has_the_android_app_section(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('id="aplikasi"', false)
            ->assertSee('Unduh aplikasi Android')
            ->assertSee('href="' . route('app.download') . '"', false)
            ->assertSee('href="' . route('app.download.legacy') . '"', false)
            ->assertSee('HP lama (32-bit)')
            ->assertSee('/images/app/rekam-jalur.webp', false)
            ->assertSee('di luar Play Store');

        $this->assertFileExists(public_path('images/app/rekam-jalur.webp'));
    }

    public function test_navbar_links_to_the_app_and_the_banner_points_to_the_download(): void
    {
        $html = $this->get('/routes')->assertOk()
            ->assertSee('data-nav-app', false)
            ->assertSee('Unduh aplikasi')
            ->getContent();

        // The navbar item goes to the app site (features and the APK); the bottom card keeps the direct APK.
        $nav = substr($html, strpos($html, '<nav'), strpos($html, '</nav>') - strpos($html, '<nav'));
        $this->assertStringContainsString('href="https://app.rutestrip.ai.id" rel="noopener" data-nav-app', $nav);
        $this->assertStringNotContainsString('href="' . route('app.download') . '"', $nav);

        // The bottom card now offers the Android app; the PWA install moved to the settings page.
        $banner = substr($html, strpos($html, 'id="rs-install"'), 1500);
        $this->assertStringContainsString('Download aplikasi', $banner);
        $this->assertStringContainsString('href="' . route('app.download') . '"', $banner);
        $this->assertStringNotContainsString('Pasang RuteStrip di HP', $html);
    }

    public function test_settings_offers_the_web_app_install_for_a_signed_in_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('settings'))->assertOk()
            ->assertSee('id="pwa"', false)
            ->assertSee('Pasang versi web (PWA)')
            ->assertSee('data-pwa-install', false)
            ->assertSee('__rsInstallEvent', false)
            ->assertSee('href="' . route('app.download') . '"', false);
    }
}
