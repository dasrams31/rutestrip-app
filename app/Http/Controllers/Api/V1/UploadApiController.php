<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\RouteController;
use App\Models\HikingRoute;
use App\Services\GpxTrackCleaner;
use App\Services\GpxVersionService;
use App\Services\PythonProcessorService;
use App\Services\RouteApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * POST /api/v1/routes (multipart: name, description?, gpx_file, clean_track?):
 * the website's single GPX upload (RouteController::store) as JSON for the app.
 * Same checks, track cleaning, Python ingest and first GPX version; the
 * signed-in user (SsoBearer) is recorded as the uploader.
 */
class UploadApiController extends Controller
{
    public function __construct(
        private PythonProcessorService $python,
        private GpxVersionService $versions,
        private GpxTrackCleaner $cleaner,
        private RouteApiPresenter $present,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'gpx_file'    => 'required|file|max:10240',
            'clean_track' => 'nullable|boolean',
        ]);

        $file = $request->file('gpx_file');
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['gpx', 'xml'], true)) {
            return $this->invalid(__('File harus berformat GPX atau XML.'));
        }
        if (! RouteController::isValidGpx($file->getRealPath())) {
            return $this->invalid(__('Isi file bukan GPX yang valid.'));
        }

        $path     = RouteController::storeGpx($file);
        $fullPath = Storage::disk('public')->path($path);
        $userId   = $request->user()->id;

        try {
            $cleaning = $request->boolean('clean_track', true) ? $this->cleaner->clean($fullPath, $request->input('name')) : [];
            $result   = $this->python->ingest($fullPath);

            $narrative = (string) ($result['narrative_text'] ?? '');
            if ($request->filled('description')) {
                $narrative = $request->input('description') . ' ' . $narrative;
            }

            $route = HikingRoute::create([
                'name'                   => $request->input('name'),
                'description'            => $request->input('description'),
                'gpx_file_path'          => $path,
                'uploaded_by'            => $userId,
                'route_coordinates'      => $result['route_coordinates'] ?? null,
                'distance_km'            => $result['distance_km'] ?? null,
                'elevation_gain_m'       => $result['elevation_gain_m'] ?? null,
                'naismith_duration_hour' => $result['naismith_duration_hour'] ?? null,
                'average_grade_pct'      => $result['average_grade_pct'] ?? null,
                'steepest_grade_pct'     => $result['steepest_grade_pct'] ?? null,
                'summit_route'           => ! preg_match(GpxTrackCleaner::KEEP_WHOLE_PATTERN, $request->input('name')),
                'narrative_text'         => $narrative,
                'sbert_embedding'        => $result['embedding'] ?? null,
            ]);

            $this->versions->createInitialVersion($route, $path, $userId,
                trim('Diunggah dari aplikasi Android. ' . GpxTrackCleaner::summary($cleaning, 'id')) ?: null);
        } catch (\Throwable $e) {
            report($e);
            Storage::disk('public')->delete($path);

            return response()->json(['error' => __('Gagal memproses file GPX. Periksa filenya, lalu coba lagi.')], 422);
        }

        return response()->json([
            'data'    => $this->present->summary($route->loadCount('likes')),
            'message' => trim(__('Jalur pendakian berhasil ditambahkan!') . ' ' . GpxTrackCleaner::summary($cleaning)),
        ], 201);
    }

    private function invalid(string $message): JsonResponse
    {
        return response()->json(['error' => $message, 'errors' => ['gpx_file' => [$message]]], 422);
    }
}
