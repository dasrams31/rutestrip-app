<?php

namespace App\Http\Controllers;

use App\Models\HikingRoute;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use App\Services\SocialPresenter;
use App\Services\SocialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Linimasa on the website (docs/API.md "Web"): the timeline, single post pages,
 * profiles, follower lists and saved posts. Actions are plain POST forms with CSRF;
 * fetched with "Accept: application/json" they answer with the API's JSON instead
 * of a redirect (resources/views/linimasa/partials/scripts.blade.php).
 * Post and profile pages are public and read-only for guests, and noindex.
 */
class LinimasaController extends Controller
{
    public function __construct(private SocialService $social, private SocialPresenter $present)
    {
    }

    /** GET /linimasa?tab=mengikuti|semua&cursor= (auth). With X-Requested-With: just the next cards. */
    public function index(Request $request)
    {
        $me    = $request->user();
        $tab   = $request->query('tab') === 'semua' ? 'semua' : 'mengikuti';
        [$posts, $next] = $this->social->feed($me, $tab === 'semua' ? 'all' : 'following', $this->cursor($request));
        $cards = $this->present->posts($posts, $me);
        $more  = $next ? route('linimasa', ['tab' => $tab, 'cursor' => $next]) : null;

        if ($request->ajax()) {
            return view('linimasa.partials.cards', ['cards' => $cards, 'more' => $more]);
        }

        $stories = array_map(fn ($g) => [
            'author' => $this->present->author($g['author']),
            'items'  => $this->present->posts($g['items'], $me),
        ], $this->social->stories($me));

        return view('linimasa.index', [
            'tab'       => $tab,
            'cards'     => $cards,
            'more'      => $more,
            'firstPage' => $this->cursor($request) === null,
            'stories'   => $stories,
            'routes'    => HikingRoute::orderBy('name')->get(['id', 'name']),
            'profile'   => $this->present->profile($me, $me),
        ]);
    }

    /** POST /linimasa: the web composer (status, photo, or either as a 24 h story). */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $photo = $request->file('photo');
        $input = $request->only(['body', 'route_id']);
        $input['type'] = $request->boolean('story') ? 'story' : ($photo ? 'photo' : 'status');

        try {
            [$post] = $this->social->createPost($request->user(), $input, $photo instanceof \Illuminate\Http\UploadedFile ? $photo : null);
        } catch (HttpException $e) {
            if ($request->expectsJson()) {
                return response()->json(['error' => $e->getMessage()], $e->getStatusCode(), $e->getHeaders());
            }

            return back()->withInput()->with('warning', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json($this->present->post($post->load(['user:id,name,bio', 'route:id,name']), $request->user()), 201);
        }

        return redirect()->route('linimasa', $post->type === 'story' ? [] : ['tab' => 'mengikuti'])
            ->with('success', $post->type === 'story' ? __('Cerita terkirim. Tampil 24 jam di baris cerita.') : __('Kiriman terbit di linimasa.'));
    }

    /** GET /p/{id}: public single post page. */
    public function show(Request $request, string $post)
    {
        $model = $this->post($post);
        [$comments, $next] = $this->social->comments($model, $this->cursor($request));
        $viewer = $request->user();

        return view('linimasa.show', [
            'post'     => $this->present->post($model, $viewer),
            'model'    => $model,
            'comments' => $comments->map(fn (PostComment $c) => $this->present->comment($c, $viewer) + [
                'can_delete' => $viewer !== null && $this->social->canDeleteComment($c, $viewer),
            ])->all(),
            'moreComments' => $next ? route('posts.show', ['post' => $model->id, 'cursor' => $next]) . '#komentar' : null,
        ]);
    }

    /** GET /p/{id}/masuk: guest "Masuk untuk berkomentar"; back to the comments after login. */
    public function login(Request $request, string $post): RedirectResponse
    {
        $back = route('posts.show', $this->post($post)->id) . '#komentar';
        if ($request->user()) {
            return redirect()->to($back);
        }
        $request->session()->put('url.intended', $back);

        return redirect()->route('login');
    }

    /** DELETE /p/{id}: owner only. */
    public function destroy(Request $request, string $post): RedirectResponse|JsonResponse
    {
        $model = $this->post($post);
        abort_unless((int) $model->user_id === (int) $request->user()->id, 403);
        $this->social->deletePost($model);

        if ($request->expectsJson()) {
            return response()->json(['deleted' => true]);
        }

        return redirect()->route('linimasa')->with('success', __('Kiriman dihapus.'));
    }

    /** POST or DELETE /p/{id}/suka */
    public function like(Request $request, string $post): RedirectResponse|JsonResponse
    {
        $result = $this->social->setLike($this->post($post), $request->user(), ! $request->isMethod('delete'));

        return $request->expectsJson() ? response()->json($result) : $this->backTo((int) $post);
    }

    /** POST or DELETE /p/{id}/simpan */
    public function save(Request $request, string $post): RedirectResponse|JsonResponse
    {
        $on     = ! $request->isMethod('delete');
        $result = $this->social->setSave($this->post($post), $request->user(), $on);

        return $request->expectsJson() ? response()->json($result)
            : $this->backTo((int) $post)->with('success', $on ? __('Kiriman disimpan.') : __('Kiriman dihapus dari simpanan.'));
    }

    /** POST /p/{id}/komentar {body} */
    public function comment(Request $request, string $post): RedirectResponse|JsonResponse
    {
        $model   = $this->post($post);
        $comment = $this->social->addComment($model, $request->user(), $request->input('body'));

        if ($request->expectsJson()) {
            return response()->json($this->present->comment($comment, $request->user()), 201);
        }

        return redirect()->to(route('posts.show', $model->id) . '#komentar-' . $comment->id);
    }

    /** DELETE /komentar/{id}: the comment's author or the post owner. */
    public function destroyComment(Request $request, PostComment $comment): RedirectResponse|JsonResponse
    {
        $this->post((string) $comment->post_id);
        abort_unless($this->social->canDeleteComment($comment, $request->user()), 403);
        $this->social->deleteComment($comment);

        return $request->expectsJson() ? response()->json(['deleted' => true])
            : redirect()->to(route('posts.show', $comment->post_id) . '#komentar')->with('success', __('Komentar dihapus.'));
    }

    /** POST /p/{id}/laporkan {reason?} */
    public function report(Request $request, string $post): RedirectResponse|JsonResponse
    {
        $model = $this->post($post);
        if ((int) $model->user_id === (int) $request->user()->id) {
            throw ValidationException::withMessages(['post' => __('Kirimanmu sendiri tidak bisa dilaporkan.')]);
        }
        $this->social->reportPost($model, $request->user(), $request->input('reason'));

        return $request->expectsJson() ? response()->json(['reported' => true])
            : $this->backTo($model->id)->with('success', __('Terima kasih. Admin akan memeriksa kiriman ini.'));
    }

    /** POST /komentar/{id}/laporkan {reason?} */
    public function reportComment(Request $request, PostComment $comment): RedirectResponse|JsonResponse
    {
        $this->post((string) $comment->post_id);
        if ((int) $comment->user_id === (int) $request->user()->id) {
            throw ValidationException::withMessages(['comment' => __('Komentarmu sendiri tidak bisa dilaporkan.')]);
        }
        $this->social->reportComment($comment, $request->user(), $request->input('reason'));

        return $request->expectsJson() ? response()->json(['reported' => true])
            : redirect()->to(route('posts.show', $comment->post_id) . '#komentar-' . $comment->id)
                ->with('success', __('Terima kasih. Admin akan memeriksa komentar ini.'));
    }

    /** GET /u/{id}: profile with posts (public, read-only for guests). */
    public function profile(Request $request, User $user)
    {
        $viewer = $request->user();
        [$posts, $next] = $this->social->userPosts($user, $this->cursor($request));
        $cards = $this->present->posts($posts, $viewer);
        $more  = $next ? route('profiles.show', ['user' => $user->id, 'cursor' => $next]) : null;

        if ($request->ajax()) {
            return view('linimasa.partials.cards', ['cards' => $cards, 'more' => $more]);
        }

        return view('linimasa.profile', [
            'user'    => $user,
            'profile' => $this->present->profile($user, $viewer),
            'cards'   => $cards,
            'more'    => $more,
        ]);
    }

    /** GET /u/{id}/pengikut */
    public function followers(Request $request, User $user)
    {
        return $this->people($request, $user, 'followers');
    }

    /** GET /u/{id}/mengikuti */
    public function following(Request $request, User $user)
    {
        return $this->people($request, $user, 'following');
    }

    /** POST or DELETE /u/{id}/ikuti */
    public function follow(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $result = $this->social->setFollow($request->user(), $user, ! $request->isMethod('delete'));

        return $request->expectsJson() ? response()->json($result) : back();
    }

    /** GET /akun/disimpan (auth): saved posts, private to the saver. */
    public function saved(Request $request)
    {
        $me = $request->user();
        [$posts, $next] = $this->social->savedPosts($me, $this->cursor($request));
        $cards = $this->present->posts($posts, $me);
        $more  = $next ? route('account.saved', ['cursor' => $next]) : null;

        if ($request->ajax()) {
            return view('linimasa.partials.cards', ['cards' => $cards, 'more' => $more]);
        }

        return view('linimasa.saved', ['cards' => $cards, 'more' => $more]);
    }

    private function people(Request $request, User $user, string $which)
    {
        [$people, $next] = $this->social->people($user, $which, $this->cursor($request));
        $route = $which === 'followers' ? 'profiles.followers' : 'profiles.following';

        return view('linimasa.people', [
            'user'    => $user,
            'which'   => $which,
            'people'  => $this->present->people($people, $request->user()),
            'more'    => $next ? route($route, ['user' => $user->id, 'cursor' => $next]) : null,
            'profile' => $this->present->profile($user, $request->user()),
        ]);
    }

    /** A visible post or 404 (deleted, expired story, removed by an admin). */
    private function post(string $id): Post
    {
        return $this->social->findVisible($id) ?? abort(404);
    }

    private function cursor(Request $request): ?string
    {
        $cursor = $request->query('cursor');

        return is_string($cursor) ? $cursor : null;
    }

    /** Back to the page the form was on, at the post's card. */
    private function backTo(int $postId): RedirectResponse
    {
        $previous = url()->previous();

        return redirect()->to(($previous ?: route('posts.show', $postId)) . '#post-' . $postId);
    }
}
