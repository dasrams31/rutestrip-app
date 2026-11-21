<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommunityMessage;
use Illuminate\Http\RedirectResponse;

/**
 * /admin/moderasi: community chat messages that hikers reported.
 * Both actions are logged by ActivitySubscriber (message deleted / report dismissed).
 */
class AdminModerationController extends Controller
{
    public function index()
    {
        $reports = CommunityMessage::with(['user:id,name,email', 'reporter:id,name'])
            ->whereNotNull('reported_at')
            ->orderByDesc('reported_at')->orderByDesc('id')
            ->paginate(20);

        return view('admin.moderation', compact('reports'));
    }

    public function destroy(CommunityMessage $message): RedirectResponse
    {
        $message->delete(); // soft delete: the chat shows "Pesan dihapus"

        return back()->with('success', __('Pesan dari :name dihapus.', ['name' => $message->user?->name ?? __('pendaki')]));
    }

    public function dismiss(CommunityMessage $message): RedirectResponse
    {
        $message->forceFill(['reported_at' => null, 'reported_by' => null])->save();

        return back()->with('success', __('Laporan diabaikan. Pesannya tetap tampil di obrolan.'));
    }
}
