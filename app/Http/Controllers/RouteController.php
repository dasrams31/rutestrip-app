<?php
namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Favorite;
use App\Models\HikingRoute;
use App\Models\Rating;
use App\Services\GpxVersionService;
use App\Services\PythonProcessorService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RouteController extends Controller
{
    private PythonProcessorService $pythonService;
    private GpxVersionService $gpxVersionService;

    public function __construct(PythonProcessorService $pythonService, GpxVersionService $gpxVersionService)
    {
        $this->pythonService      = $pythonService;
        $this->gpxVersionService = $gpxVersionService;
    }

    /**
     * Display a listing of hiking routes
     */
    public function index(Request $request)
    {
        $filter = $request->query('provenance_filter');

        $routesQuery = HikingRoute::with(['activeGpxVersion', 'uploader'])->latest();

        match ($filter) {
            'invalid' => $routesQuery->whereHas('activeGpxVersion', fn($query) => $query->where('verification_status', 'invalid')),
            'verified' => $routesQuery->whereHas('activeGpxVersion', fn($query) => $query->where('verification_status', 'verified')),
            default => null,
        };

        $routes = $routesQuery->paginate(12)->withQueryString();

        return view('routes.index', compact('routes', 'filter'));
    }

    /**
     * Show the form for uploading a new GPX file
     */
    public function create()
    {
        $this->rememberUploadPage();

        return view('routes.create');
    }

    /**
     * Store a newly created hiking route
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'gpx_file'    => 'required|file|max:10240', // Max 10MB
            'change_log'  => 'nullable|string|max:1000',
        ]);

        // Validate file extension
        $gpxFile   = $request->file('gpx_file');
        $extension = strtolower($gpxFile->getClientOriginalExtension());
        if (! in_array($extension, ['gpx', 'xml'])) {
            return back()->withErrors(['gpx_file' => 'File harus berformat GPX atau XML.'])->withInput();
        }

        if (! $this->isValidGpx($gpxFile->getRealPath())) {
            return back()->withErrors(['gpx_file' => 'Isi file bukan GPX yang valid.'])->withInput();
        }

        // Store GPX file under a safe name with a .gpx extension
        $filePath = $this->storeGpx($gpxFile);
        $fullPath = \Illuminate\Support\Facades\Storage::disk('public')->path($filePath);

        try {
            // Process GPX with Python
            $result = $this->pythonService->ingest($fullPath);

            // Combine manual description with auto-generated narrative (Data Fusion)
            $narrativeText = $result['narrative_text'] ?? '';
            if ($request->filled('description')) {
                $narrativeText = $request->description . ' ' . $narrativeText;
            }

            // Create hiking route record
            $route = HikingRoute::create([
                'name'                   => $request->name,
                'description'            => $request->description,
                'gpx_file_path'          => $filePath,
                'uploaded_by'            => Auth::id(),
                'route_coordinates'      => $result['route_coordinates'] ?? null,
                'distance_km'            => $result['distance_km'] ?? null,
                'elevation_gain_m'       => $result['elevation_gain_m'] ?? null,
                'naismith_duration_hour' => $result['naismith_duration_hour'] ?? null,
                'average_grade_pct'      => $result['average_grade_pct'] ?? null,
                'narrative_text'         => $narrativeText,
                'sbert_embedding'        => $result['embedding'] ?? null,
            ]);

            $this->gpxVersionService->createInitialVersion(
                $route,
                $filePath,
                Auth::id(),
                $request->input('change_log')
            );

            return redirect()->route('routes.index')
                ->with('success', 'Jalur pendakian berhasil ditambahkan!');

        } catch (\Exception $e) {
            // Delete uploaded file if processing fails
            Storage::disk('public')->delete($filePath);

            return back()->withErrors(['gpx_file' => 'Gagal memproses file GPX: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Display the specified hiking route with similar recommendations
     */
    public function show(HikingRoute $route)
    {
        $route->load(['activeGpxVersion', 'gpxVersions.uploader']);
        $similarRoutes = collect([]);

        // Calculate similar routes using cosine similarity
        if ($route->sbert_embedding) {
            $routeEmbedding = $route->sbert_embedding;

            $allRoutes = HikingRoute::where('id', '!=', $route->id)
                ->whereNotNull('sbert_embedding')
                ->with('activeGpxVersion')
                ->get();

            $similarRoutes = $allRoutes->map(function ($otherRoute) use ($routeEmbedding) {
                $otherEmbedding = $otherRoute->sbert_embedding;

                // Calculate cosine similarity
                $dotProduct = 0;
                $normA      = 0;
                $normB      = 0;

                for ($i = 0; $i < count($routeEmbedding); $i++) {
                    $dotProduct += $routeEmbedding[$i] * $otherEmbedding[$i];
                    $normA += $routeEmbedding[$i] ** 2;
                    $normB += $otherEmbedding[$i] ** 2;
                }

                $normA = sqrt($normA);
                $normB = sqrt($normB);

                $similarity = ($normA * $normB) > 0 ? $dotProduct / ($normA * $normB) : 0;

                $otherRoute->similarity_score = round($similarity * 100, 1);
                return $otherRoute;
            })
                ->sortByDesc('similarity_score')
                ->take(4);
        }

        return view('routes.show', compact('route', 'similarRoutes'));
    }

    /**
     * Remove the specified hiking route
     */
    public function destroy(HikingRoute $route)
    {
        abort_unless(Auth::check() && Auth::user()->isAdmin(), 403);

        $this->gpxVersionService->deleteFilesForRoute($route);

        $route->delete();

        return redirect()->route('routes.index')
            ->with('success', 'Jalur pendakian berhasil dihapus!');
    }

    /**
     * Show the form for batch uploading GPX files
     */
    public function createBatch()
    {
        $this->rememberUploadPage();

        return view('routes.batch');
    }

    /**
     * Guests see a "login to upload" card; send them back here after login.
     */
    private function rememberUploadPage(): void
    {
        if (Auth::guest()) {
            session()->put('url.intended', url()->current());
        }
    }

    /**
     * Store multiple hiking routes from batch upload
     */
    public function storeBatch(Request $request)
    {
        // PHP silently drops files beyond max_file_uploads, so validate against it
        $maxFiles = min(50, (int) ini_get('max_file_uploads') ?: 20);
        $request->validate([
            'gpx_files'   => "required|array|min:1|max:{$maxFiles}",
            'gpx_files.*' => 'required|file|max:10240',
        ], [
            'gpx_files.required' => 'Pilih minimal satu file GPX (total ukuran maksimal ' . ini_get('post_max_size') . ').',
            'gpx_files.max'      => "Maksimal {$maxFiles} file per batch.",
        ]);

        // Additional validation for file extensions
        $allowedExtensions = ['gpx', 'xml'];
        foreach ($request->file('gpx_files') as $index => $file) {
            $extension = strtolower($file->getClientOriginalExtension());
            if (! in_array($extension, $allowedExtensions) || ! $this->isValidGpx($file->getRealPath())) {
                return back()->withErrors([
                    'gpx_files' => "File #{$index}: {$file->getClientOriginalName()} bukan file GPX/XML yang valid.",
                ])->withInput();
            }
        }

        $results = [
            'success' => [],
            'failed'  => [],
        ];

        foreach ($request->file('gpx_files') as $gpxFile) {
            $originalName = $gpxFile->getClientOriginalName();
            $routeName    = pathinfo($originalName, PATHINFO_FILENAME);

            // Store GPX file under a safe name with a .gpx extension
            $filePath = $this->storeGpx($gpxFile);
            $fullPath = \Illuminate\Support\Facades\Storage::disk('public')->path($filePath);

            try {
                // Process GPX with Python
                $result = $this->pythonService->ingest($fullPath);

                if (! isset($result['success']) || ! $result['success']) {
                    throw new \Exception($result['error'] ?? 'Unknown error');
                }

                // Create hiking route record
                $route = HikingRoute::create([
                    'name'                   => $routeName,
                    'gpx_file_path'          => $filePath,
                    'uploaded_by'            => Auth::id(),
                    'route_coordinates'      => $result['route_coordinates'] ?? null,
                    'distance_km'            => $result['distance_km'] ?? null,
                    'elevation_gain_m'       => $result['elevation_gain_m'] ?? null,
                    'naismith_duration_hour' => $result['naismith_duration_hour'] ?? null,
                    'average_grade_pct'      => $result['average_grade_pct'] ?? null,
                    'narrative_text'         => $result['narrative_text'] ?? null,
                    'sbert_embedding'        => $result['embedding'] ?? null,
                ]);

                $this->gpxVersionService->createInitialVersion(
                    $route,
                    $filePath,
                    Auth::id(),
                    'Initial GPX upload from batch import.'
                );

                $results['success'][] = $originalName;

            } catch (\Exception $e) {
                // Delete uploaded file if processing fails
                Storage::disk('public')->delete($filePath);
                $results['failed'][] = [
                    'file'  => $originalName,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $successCount = count($results['success']);
        $failedCount  = count($results['failed']);

        if ($failedCount === 0) {
            return redirect()->route('routes.index')
                ->with('success', "Berhasil mengupload {$successCount} file GPX!");
        } elseif ($successCount === 0) {
            return back()
                ->withErrors(['gpx_files' => 'Semua file gagal diproses.'])
                ->with('batch_results', $results);
        } else {
            return redirect()->route('routes.index')
                ->with('warning', "Berhasil: {$successCount} file, Gagal: {$failedCount} file.")
                ->with('batch_results', $results);
        }
    }

    /**
     * Store a review (comment + rating) for a route
     */
    public function storeReview(Request $request, HikingRoute $route)
    {
        $validated = $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $userId = Auth::id();

        // Update or create rating
        Rating::updateOrCreate(
            ['user_id' => $userId, 'hiking_route_id' => $route->id],
            ['rating' => $validated['rating']]
        );

        // Create comment if provided
        if (! empty($validated['comment'])) {
            Comment::create([
                'user_id'         => $userId,
                'hiking_route_id' => $route->id,
                'content'         => $validated['comment'],
            ]);
        }

        return back()->with('success', 'Review berhasil dikirim!');
    }

    /**
     * Toggle like (favorite) for a route
     */
    public function toggleLike(HikingRoute $route)
    {
        $userId = Auth::id();
        $like   = Favorite::where('user_id', $userId)
            ->where('hiking_route_id', $route->id)
            ->first();

        if ($like) {
            $like->delete();
            return back()->with('success', 'Like dihapus');
        }

        Favorite::create([
            'user_id'         => $userId,
            'hiking_route_id' => $route->id,
        ]);

        return back()->with('success', 'Rute disukai! ❤️');
    }

    /**
     * Show similar routes page
     */
    public function similarRoutes(HikingRoute $route)
    {
        $similarRoutes = collect([]);

        if ($route->sbert_embedding) {
            $routeEmbedding = $route->sbert_embedding;

            $allRoutes = HikingRoute::where('id', '!=', $route->id)
                ->whereNotNull('sbert_embedding')
                ->get();

            $similarRoutes = $allRoutes->map(function ($otherRoute) use ($routeEmbedding) {
                $otherEmbedding = $otherRoute->sbert_embedding;

                $dotProduct = 0;
                $normA      = 0;
                $normB      = 0;

                for ($i = 0; $i < count($routeEmbedding); $i++) {
                    $dotProduct += $routeEmbedding[$i] * $otherEmbedding[$i];
                    $normA += $routeEmbedding[$i] ** 2;
                    $normB += $otherEmbedding[$i] ** 2;
                }

                $normA      = sqrt($normA);
                $normB      = sqrt($normB);
                $similarity = ($normA * $normB) > 0 ? $dotProduct / ($normA * $normB) : 0;

                $otherRoute->similarity_score = round($similarity * 100, 1);
                $otherRoute->cosine_value     = round($similarity, 4);
                return $otherRoute;
            })
                ->filter(fn($r) => $r->similarity_score > 30) // Min 30% similarity
                ->sortByDesc('similarity_score')
                ->take(20);
        }

        return view('routes.similar', compact('route', 'similarRoutes'));
    }

    /**
     * Store an uploaded GPX under a sanitised name, always with a .gpx
     * extension (never .xml, which browsers could render as XHTML).
     */
    private function storeGpx(UploadedFile $file): string
    {
        $base = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'route';

        return $file->storeAs('gpx_files', time() . '_' . Str::random(8) . '_' . $base . '.gpx', 'public');
    }

    /**
     * The file must be well-formed XML whose root element is <gpx>.
     * Network access is disabled while parsing.
     */
    private function isValidGpx(?string $path): bool
    {
        if (! $path || ! is_file($path)) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_file($path, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml !== false && strtolower($xml->getName()) === 'gpx';
    }
}
