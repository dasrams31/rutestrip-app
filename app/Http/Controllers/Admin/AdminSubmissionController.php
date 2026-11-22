<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HikingRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * /admin/kiriman: hikes recorded in the Android app. They are already processed
 * (Python ingest, embedding) when they arrive, but stay hidden from search and
 * lists until an admin approves them here.
 */
class AdminSubmissionController extends Controller
{
    public function index()
    {
        $pending = HikingRoute::withoutGlobalScope('published')
            ->with('uploader:id,name,email')
            ->where('review_status', HikingRoute::PENDING)
            ->oldest('id')
            ->paginate(20);

        return view('admin.submissions', compact('pending'));
    }

    public function approve(Request $request, int $route): RedirectResponse
    {
        $r = $this->find($route);
        $r->forceFill([
            'review_status' => HikingRoute::PUBLISHED,
            'reviewed_by'   => $request->user()->id,
            'reviewed_at'   => now(),
            'review_note'   => null,
        ])->save();
        $this->forgetSearchCache();

        return back()->with('success', __('":name" disetujui dan sekarang tampil di pencarian.', ['name' => $r->name]));
    }

    public function reject(Request $request, int $route): RedirectResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:500']);
        $r = $this->find($route);
        $r->forceFill([
            'review_status' => HikingRoute::REJECTED,
            'reviewed_by'   => $request->user()->id,
            'reviewed_at'   => now(),
            'review_note'   => $data['note'] ?? null,
        ])->save();

        return back()->with('success', __('":name" ditolak. Pengirim melihat statusnya di aplikasi.', ['name' => $r->name]));
    }

    private function find(int $id): HikingRoute
    {
        return HikingRoute::withoutGlobalScope('published')
            ->where('review_status', HikingRoute::PENDING)
            ->findOrFail($id);
    }

    /** Search keeps its route list for 5 minutes; an approved route shows at once. */
    private function forgetSearchCache(): void
    {
        Cache::forget('routes_with_embeddings');
        Cache::forget('routes_with_embeddings_chat');
    }
}
