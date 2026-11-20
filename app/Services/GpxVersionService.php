<?php
namespace App\Services;

use App\Models\GpxVersion;
use App\Models\HikingRoute;
use App\Models\VerificationLog;
use Illuminate\Support\Facades\Storage;

class GpxVersionService
{
    public function __construct(
        private GpxHashService $hashService
    )
    {
    }

    public function createInitialVersion(HikingRoute $route, string $filePath, ?int $uploadedBy = null, ?string $changeLog = null): GpxVersion
    {
        return $this->createVersion($route, $filePath, $uploadedBy, $changeLog ?: 'Initial GPX upload.');
    }

    public function createVersion(HikingRoute $route, string $filePath, ?int $uploadedBy = null, ?string $changeLog = null): GpxVersion
    {
        $versionNumber = ((int) $route->gpxVersions()->max('version_number')) + 1;
        $absolutePath  = \Illuminate\Support\Facades\Storage::disk('public')->path($filePath);
        $fileHash      = $this->hashService->hash($absolutePath);

        $route->gpxVersions()->update(['is_active' => false]);

        $version = $route->gpxVersions()->create([
            'version_number'      => $versionNumber,
            'gpx_file_path'       => $filePath,
            'file_hash'           => $fileHash,
            'verification_status' => 'verified',
            'change_log'          => $changeLog,
            'uploaded_by'         => $uploadedBy,
            'is_active'           => true,
            'verified_at'         => now(),
        ]);

        $this->logVerification($version, $fileHash, $fileHash, 'verified', 'Hash dibuat saat upload GPX.', $uploadedBy);

        return $version;
    }

    public function verify(GpxVersion $version, ?int $verifiedBy = null): GpxVersion
    {
        $absolutePath = \Illuminate\Support\Facades\Storage::disk('public')->path($version->gpx_file_path);

        try {
            $result = $this->hashService->verify($absolutePath, $version->file_hash);

            $version->update([
                'verification_status' => $result['status'],
                'verified_at'         => now(),
            ]);

            $this->logVerification(
                $version,
                $version->file_hash,
                $result['actual_hash'],
                $result['status'],
                $result['message'],
                $verifiedBy
            );
        } catch (\Throwable $e) {
            $version->update(['verification_status' => 'verification_failed']);

            $this->logVerification(
                $version,
                $version->file_hash,
                null,
                'verification_failed',
                $e->getMessage(),
                $verifiedBy
            );
        }

        return $version->refresh();
    }

    public function restore(GpxVersion $version, ?int $uploadedBy = null): GpxVersion
    {
        $route = $version->route;

        $route->gpxVersions()->update(['is_active' => false]);
        $version->update(['is_active' => true]);

        $route->update(['gpx_file_path' => $version->gpx_file_path]);

        $this->logVerification(
            $version,
            $version->file_hash,
            $version->file_hash,
            $version->verification_status,
            'Versi GPX dipulihkan sebagai versi aktif.',
            $uploadedBy
        );

        return $version->refresh();
    }

    public function deleteFilesForRoute(HikingRoute $route): void
    {
        $paths = $route->gpxVersions()->pluck('gpx_file_path');

        if ($route->gpx_file_path) {
            $paths->push($route->gpx_file_path);
        }

        $paths->unique()->each(function (string $path) {
            Storage::disk('public')->delete($path);
        });
    }

    private function logVerification(GpxVersion $version, string $expectedHash, ?string $actualHash, string $status, ?string $message, ?int $verifiedBy, string $source = 'local'): void
    {
        VerificationLog::create([
            'gpx_version_id' => $version->id,
            'source'         => $source,
            'expected_hash'  => $expectedHash,
            'actual_hash'    => $actualHash,
            'status'         => $status,
            'message'        => $message,
            'verified_by'    => $verifiedBy,
            'verified_at'    => now(),
        ]);
    }
}
