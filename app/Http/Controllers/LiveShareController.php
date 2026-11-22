<?php

namespace App\Http\Controllers;

use App\Models\LiveShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "Bagikan lokasi online": while there is signal, the app sends the hiker's latest
 * position to a secret link that family or the basecamp can open in a browser.
 *
 * App (SSO bearer): POST /api/v1/live {hours, label?} -> {token, url, expires_at}
 *                   POST /api/v1/live/{token}/position {lat, lng, acc?, ele?, bat?}
 *                   POST /api/v1/live/{token}/stop
 * Public:           GET /lacak/{token} (page, noindex) and /lacak/{token}/data (JSON).
 * Only the latest position is stored; a link ends when it expires or is stopped.
 */
class LiveShareController extends Controller
{
    /** Active links per account at once. */
    public const MAX_ACTIVE = 3;

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hours' => 'required|integer|min:1|max:' . LiveShare::MAX_HOURS,
            'label' => 'nullable|string|max:80',
        ]);
        $user = $request->user();

        // Older links of this account beyond the limit are ended, newest kept.
        $active = LiveShare::active()->where('user_id', $user->id)->orderByDesc('id')->get();
        foreach ($active->slice(self::MAX_ACTIVE - 1) as $old) {
            $old->forceFill(['stopped_at' => now()])->save();
        }

        $share = LiveShare::create([
            'user_id'    => $user->id,
            'token'      => LiveShare::newToken(),
            'label'      => isset($data['label']) ? trim(strip_tags($data['label'])) : null,
            'expires_at' => now()->addHours((int) $data['hours']),
        ]);

        return response()->json([
            'token'      => $share->token,
            'url'        => $share->url(),
            'expires_at' => $share->expires_at->toIso8601String(),
        ], 201);
    }

    public function position(Request $request, string $token): JsonResponse
    {
        $share = $this->own($request, $token);
        if (! $share->isActive()) {
            return response()->json(['error' => __('Tautan lokasi ini sudah berakhir.'), 'ended' => true], 410);
        }

        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'acc' => 'nullable|numeric|min:0|max:100000',
            'ele' => 'nullable|numeric|between:-500,9000',
            'bat' => 'nullable|integer|between:0,100',
        ]);

        $share->forceFill([
            'lat'         => round((float) $data['lat'], 6),
            'lng'         => round((float) $data['lng'], 6),
            'accuracy_m'  => isset($data['acc']) ? round((float) $data['acc'], 1) : null,
            'elevation_m' => isset($data['ele']) ? round((float) $data['ele'], 1) : null,
            'battery'     => $data['bat'] ?? null,
            'position_at' => now(),
        ])->save();

        return response()->json(['ok' => true, 'expires_at' => $share->expires_at->toIso8601String()]);
    }

    public function stop(Request $request, string $token): JsonResponse
    {
        $share = $this->own($request, $token);
        if ($share->stopped_at === null) {
            $share->forceFill(['stopped_at' => now()])->save();
        }

        return response()->json(['ok' => true]);
    }

    /** Public page: a map with the latest position. Not indexed, not cached. */
    public function show(string $token): Response
    {
        $share = $this->find($token);

        return response()
            ->view('live.show', ['share' => $share, 'payload' => $share ? $this->payload($share) : null], $share ? 200 : 404)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function data(string $token): JsonResponse
    {
        $share = $this->find($token);
        abort_if(! $share, 404);

        return response()->json($this->payload($share))
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store');
    }

    private function payload(LiveShare $share): array
    {
        $active = $share->isActive();

        return [
            'name'        => $share->label ?: ($share->user?->name ?? __('Pendaki')),
            'active'      => $active,
            // After the link ends the last position is no longer shown.
            'lat'         => $active ? $share->lat : null,
            'lng'         => $active ? $share->lng : null,
            'accuracy_m'  => $active ? $share->accuracy_m : null,
            'elevation_m' => $active ? $share->elevation_m : null,
            'battery'     => $active ? $share->battery : null,
            'position_at' => $active ? $share->position_at?->toIso8601String() : null,
            'expires_at'  => $share->expires_at->toIso8601String(),
        ];
    }

    private function find(string $token): ?LiveShare
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return null;
        }

        return LiveShare::with('user:id,name')->where('token', $token)->first();
    }

    private function own(Request $request, string $token): LiveShare
    {
        $share = $this->find($token);
        abort_if(! $share || $share->user_id !== $request->user()->id, 404);

        return $share;
    }
}
