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
 * POST /api/v1/routes (multipart: name, description?, gpx_file, clean_track?, source?):
 * the website's single GPX upload (RouteController::store) as JSON for the app.
 * Same checks, track cleaning, Python ingest and first GPX version; the
 * signed-in user (SsoBearer) is recorded as the uploader. source=recording (a hike
 * recorded in the app) is processed the same way but waits for an admin.
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

    /** Uploads per account per day: each one is processed by the Python service and published at once. */
    public const PER_DAY = 30;

    public function store(Request $request): JsonResponse
    {
        $dayKey = 'api-upload-day:' . $request->user()->id;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($dayKey, self::PER_DAY)) {
            return response()->json(['error' => __('Batas unggah hari ini sudah tercapai. Coba lagi besok.')], 429)
                ->header('Retry-After', (string) \Illuminate\Support\Facades\RateLimiter::availableIn($dayKey));
        }

        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'gpx_file'    => 'required|file|max:10240',
            'clean_track' => 'nullable|boolean',
            'source'      => 'nullable|in:upload,recording',
        ]);
        // A hike recorded in the app is processed now but shown only after an admin approves it.
        $recording = $request->input('source') === 'recording';

        $file = $request->file('gpx_file');
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['gpx', 'xml'], true)) {
            return $this->invalid(__('File harus berformat GPX atau XML.'));
        }
        if (! RouteController::isValidGpx($file->getRealPath())) {
            return $this->invalid(__('Isi file bukan GPX yang valid.'));
        }

        \Illuminate\Support\Facades\RateLimiter::hit($dayKey, 86400);
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
                'source'                 => $recording ? 'recording' : 'upload',
                'review_status'          => $recording ? HikingRoute::PENDING : HikingRoute::PUBLISHED,
            ]);

            $this->versions->createInitialVersion($route, $path, $userId,
                trim(($recording ? 'Rekaman dari aplikasi Android. ' : 'Diunggah dari aplikasi Android. ') . GpxTrackCleaner::summary($cleaning, 'id')) ?: null);
        } catch (\Throwable $e) {
            report($e);
            Storage::disk('public')->delete($path);

            return response()->json(['error' => __('Gagal memproses file GPX. Periksa filenya, lalu coba lagi.')], 422);
        }

        if ($recording) {
            return response()->json([
                'data'    => self::submission($route),
                'message' => __('Rekaman terkirim dan sudah diproses. Jalur ini tampil di pencarian setelah disetujui admin.'),
            ], 201);
        }

        return response()->json([
            'data'    => $this->present->summary($route->loadCount('likes')),
            'message' => trim(__('Jalur pendakian berhasil ditambahkan!') . ' ' . GpxTrackCleaner::summary($cleaning)),
        ], 201);
    }

    /**
     * GET /api/v1/me/submissions: everything this account sent (GPX uploads and
     * app recordings) with its review status, newest first.
     */
    public function submissions(Request $request): JsonResponse
    {
        $page = HikingRoute::withoutGlobalScope('published')
            ->where('uploaded_by', $request->user()->id)
            ->latest('id')
            ->paginate(20, ['id', 'name', 'distance_km', 'elevation_gain_m', 'source', 'review_status', 'review_note', 'reviewed_at', 'created_at']);

        return response()->json([
            'data' => collect($page->items())->map(fn (HikingRoute $r) => self::submission($r))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    private static function submission(HikingRoute $r): array
    {
        return [
            'id'               => $r->id,
            'name'             => $r->name,
            'distance_km'      => $r->distance_km,
            'elevation_gain_m' => $r->elevation_gain_m,
            'source'           => $r->source,
            'status'           => $r->review_status,
            'note'             => $r->review_status === HikingRoute::REJECTED ? $r->review_note : null,
            'reviewed_at'      => $r->reviewed_at?->toIso8601String(),
            'created_at'       => $r->created_at?->toIso8601String(),
        ];
    }

    private function invalid(string $message): JsonResponse
    {
        return response()->json(['error' => $message, 'errors' => ['gpx_file' => [$message]]], 422);
    }
}
