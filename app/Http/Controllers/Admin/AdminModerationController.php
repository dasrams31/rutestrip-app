<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommunityMessage;
use App\Models\Post;
use App\Models\PostComment;
use App\Services\SocialService;
use Illuminate\Http\RedirectResponse;

/**
 * /admin/moderasi: community chat messages, Linimasa posts and Linimasa comments
 * that hikers reported. Chat actions are logged by ActivitySubscriber (message
 * deleted / report dismissed). A deleted post or comment is soft deleted and never
 * shown again; dismissing keeps it and clears the flag (the report rows stay, so
 * the same hiker cannot report it twice).
 */
class AdminModerationController extends Controller
{
    public function __construct(private SocialService $social)
    {
    }

    public function index()
    {
        $reports = CommunityMessage::with(['user:id,name,email', 'reporter:id,name'])
            ->whereNotNull('reported_at')
            ->orderByDesc('reported_at')->orderByDesc('id')
            ->paginate(20);

        $postReports = Post::with('user:id,name')
            ->withCount('reports')
            ->whereNotNull('reported_at')
            ->orderByDesc('reported_at')->orderByDesc('id')
            ->paginate(20, ['*'], 'kiriman');

        // Comments under a post that is gone are gone with it.
        $commentReports = PostComment::with('user:id,name')
            ->whereHas('post')
            ->withCount('reports')
            ->whereNotNull('reported_at')
            ->orderByDesc('reported_at')->orderByDesc('id')
            ->paginate(20, ['*'], 'komentar');

        return view('admin.moderation', compact('reports', 'postReports', 'commentReports'));
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

    public function destroyPost(Post $post): RedirectResponse
    {
        $this->social->deletePost($post);

        return back()->with('success', __('Kiriman dari :name dihapus dari linimasa.', ['name' => $post->user?->name ?? __('pendaki')]));
    }

    public function dismissPost(Post $post): RedirectResponse
    {
        $post->forceFill(['reported_at' => null])->save();

        return back()->with('success', __('Laporan diabaikan. Kirimannya tetap tampil di linimasa.'));
    }

    public function destroyComment(PostComment $comment): RedirectResponse
    {
        $this->social->deleteComment($comment);

        return back()->with('success', __('Komentar dari :name dihapus.', ['name' => $comment->user?->name ?? __('pendaki')]));
    }

    public function dismissComment(PostComment $comment): RedirectResponse
    {
        $comment->forceFill(['reported_at' => null])->save();

        return back()->with('success', __('Laporan diabaikan. Komentarnya tetap tampil.'));
    }
}
