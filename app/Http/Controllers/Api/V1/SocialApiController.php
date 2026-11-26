<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PostComment;
use App\Models\User;
use App\Services\SocialPresenter;
use App\Services\SocialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Linimasa (social timeline) for the mobile app, docs/API.md "Linimasa".
 * Every endpoint needs the SSO bearer token (SsoBearer). Lists are keyset pages of
 * 20: {"data": [...], "next_cursor": "opaque" | null}.
 * Errors: {"error": "...", "errors"?: {...}} with 401/403/404/422/429.
 */
class SocialApiController extends Controller
{
    public function __construct(private SocialService $social, private SocialPresenter $present)
    {
    }

    /** GET /feed?scope=following|all&cursor= */
    public function feed(Request $request): JsonResponse
    {
        $scope = $request->query('scope') === 'all' ? 'all' : 'following';
        [$posts, $next] = $this->social->feed($request->user(), $scope, $this->cursor($request));

        return $this->list($this->present->posts($posts, $request->user()), $next);
    }

    /** GET /stories */
    public function stories(Request $request): JsonResponse
    {
        $me = $request->user();

        return response()->json(['data' => array_map(fn ($group) => [
            'author' => $this->present->author($group['author']),
            'items'  => $this->present->posts($group['items'], $me),
        ], $this->social->stories($me))]);
    }

    /** POST /posts (multipart) */
    public function store(Request $request): JsonResponse
    {
        try {
            [$post, $created] = $this->social->createPost($request->user(), $request->all(), $this->photo($request));
        } catch (ValidationException $e) {
            return $this->invalid($e);
        } catch (HttpException $e) {
            return response()->json(['error' => $e->getMessage()], $e->getStatusCode(), $e->getHeaders());
        }

        $post->load(['user:id,name,bio', 'route:id,name']);

        return response()->json($this->present->post($post, $request->user()), $created ? 201 : 200);
    }

    /** GET /posts/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        $post = $this->social->findVisible($id);

        return $post ? response()->json($this->present->post($post, $request->user())) : $this->notFound();
    }

    /** DELETE /posts/{id}: owner only. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $post = $this->social->findVisible($id);
        if (! $post) {
            return $this->notFound();
        }
        if ((int) $post->user_id !== (int) $request->user()->id) {
            return $this->forbidden(__('Kamu hanya bisa menghapus kirimanmu sendiri.'));
        }
        $this->social->deletePost($post);

        return response()->json(['deleted' => true]);
    }

    /** POST /posts/{id}/like and DELETE /posts/{id}/like */
    public function like(Request $request, string $id): JsonResponse
    {
        $post = $this->social->findVisible($id);

        return $post ? response()->json($this->social->setLike($post, $request->user(), ! $request->isMethod('delete'))) : $this->notFound();
    }

    /** POST /posts/{id}/save and DELETE /posts/{id}/save */
    public function save(Request $request, string $id): JsonResponse
    {
        $post = $this->social->findVisible($id);

        return $post ? response()->json($this->social->setSave($post, $request->user(), ! $request->isMethod('delete'))) : $this->notFound();
    }

    /** GET /posts/{id}/comments?cursor=: oldest first. */
    public function comments(Request $request, string $id): JsonResponse
    {
        $post = $this->social->findVisible($id);
        if (! $post) {
            return $this->notFound();
        }
        [$comments, $next] = $this->social->comments($post, $this->cursor($request));

        return $this->list($comments->map(fn ($c) => $this->present->comment($c, $request->user()))->all(), $next);
    }

    /** POST /posts/{id}/comments {body} */
    public function storeComment(Request $request, string $id): JsonResponse
    {
        $post = $this->social->findVisible($id);
        if (! $post) {
            return $this->notFound();
        }
        try {
            $comment = $this->social->addComment($post, $request->user(), $request->input('body'));
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }

        return response()->json($this->present->comment($comment, $request->user()), 201);
    }

    /** DELETE /comments/{id}: the comment's author or the post owner. */
    public function destroyComment(Request $request, string $id): JsonResponse
    {
        $comment = ctype_digit($id) ? PostComment::find((int) $id) : null;
        if (! $comment || ! $this->social->findVisible($comment->post_id)) {
            return $this->notFound();
        }
        if (! $this->social->canDeleteComment($comment, $request->user())) {
            return $this->forbidden(__('Kamu hanya bisa menghapus komentarmu sendiri atau komentar di kirimanmu.'));
        }
        $this->social->deleteComment($comment);

        return response()->json(['deleted' => true]);
    }

    /** POST /posts/{id}/report {reason?} */
    public function report(Request $request, string $id): JsonResponse
    {
        $post = $this->social->findVisible($id);
        if (! $post) {
            return $this->notFound();
        }
        if ((int) $post->user_id === (int) $request->user()->id) {
            return response()->json(['error' => __('Kirimanmu sendiri tidak bisa dilaporkan.')], 422);
        }
        $this->social->reportPost($post, $request->user(), $request->input('reason'));

        return response()->json(['reported' => true]);
    }

    /** POST /comments/{id}/report {reason?} (addition: the web page offers it too). */
    public function reportComment(Request $request, string $id): JsonResponse
    {
        $comment = ctype_digit($id) ? PostComment::find((int) $id) : null;
        if (! $comment || ! $this->social->findVisible($comment->post_id)) {
            return $this->notFound();
        }
        if ((int) $comment->user_id === (int) $request->user()->id) {
            return response()->json(['error' => __('Komentarmu sendiri tidak bisa dilaporkan.')], 422);
        }
        $this->social->reportComment($comment, $request->user(), $request->input('reason'));

        return response()->json(['reported' => true]);
    }

    /** GET /users/search?q= */
    public function searchUsers(Request $request): JsonResponse
    {
        $q = $this->social->line($request->query('q'));
        if ($q === null || mb_strlen($q) < 2 || mb_strlen($q) > 100) {
            $message = __('Ketik minimal 2 huruf nama.');

            return response()->json(['error' => $message, 'errors' => ['q' => [$message]]], 422);
        }

        return response()->json(['data' => $this->present->people($this->social->searchUsers($q), $request->user())]);
    }

    /** GET /users/{id} */
    public function user(Request $request, string $id): JsonResponse
    {
        $user = $this->findUser($id);

        return $user ? response()->json($this->present->profile($user, $request->user())) : $this->notFound(__('Pengguna tidak ditemukan.'));
    }

    /** GET /users/{id}/posts?cursor= */
    public function userPosts(Request $request, string $id): JsonResponse
    {
        $user = $this->findUser($id);
        if (! $user) {
            return $this->notFound(__('Pengguna tidak ditemukan.'));
        }
        [$posts, $next] = $this->social->userPosts($user, $this->cursor($request));

        return $this->list($this->present->posts($posts, $request->user()), $next);
    }

    /** GET /users/{id}/followers and /users/{id}/following */
    public function people(Request $request, string $id): JsonResponse
    {
        $user = $this->findUser($id);
        if (! $user) {
            return $this->notFound(__('Pengguna tidak ditemukan.'));
        }
        $which = str_ends_with($request->path(), '/followers') ? 'followers' : 'following';
        [$people, $next] = $this->social->people($user, $which, $this->cursor($request));

        return $this->list($this->present->people($people, $request->user()), $next);
    }

    /** POST /users/{id}/follow and DELETE /users/{id}/follow */
    public function follow(Request $request, string $id): JsonResponse
    {
        $user = $this->findUser($id);
        if (! $user) {
            return $this->notFound(__('Pengguna tidak ditemukan.'));
        }
        try {
            return response()->json($this->social->setFollow($request->user(), $user, ! $request->isMethod('delete')));
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }
    }

    /** GET /me/saved?cursor= */
    public function saved(Request $request): JsonResponse
    {
        [$posts, $next] = $this->social->savedPosts($request->user(), $this->cursor($request));

        return $this->list($this->present->posts($posts, $request->user()), $next);
    }

    private function photo(Request $request): ?\Illuminate\Http\UploadedFile
    {
        $file = $request->file('photo');

        return $file instanceof \Illuminate\Http\UploadedFile ? $file : null;
    }

    private function cursor(Request $request): ?string
    {
        $cursor = $request->query('cursor');

        return is_string($cursor) ? $cursor : null;
    }

    private function findUser(string $id): ?User
    {
        return ctype_digit($id) ? User::find((int) $id) : null;
    }

    private function list(array $data, ?string $next): JsonResponse
    {
        return response()->json(['data' => $data, 'next_cursor' => $next]);
    }

    private function invalid(ValidationException $e): JsonResponse
    {
        $errors = $e->errors();

        return response()->json(['error' => collect($errors)->flatten()->first(), 'errors' => $errors], 422);
    }

    private function notFound(?string $message = null): JsonResponse
    {
        return response()->json(['error' => $message ?? __('Kiriman tidak ditemukan.')], 404);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['error' => $message], 403);
    }
}
