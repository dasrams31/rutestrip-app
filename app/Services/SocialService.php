<?php

namespace App\Services;

use App\Http\Controllers\CommunityChatController;
use App\Models\HikingRoute;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Linimasa (social timeline, docs/API.md): the writes and the list queries shared
 * by the API (Api\V1\SocialApiController) and the web pages (LinimasaController).
 *
 * Text is stored as plain text (CommunityChatController::clean) and escaped on output.
 * likes_count and comments_count are recounted inside the write transaction, so they
 * always equal the rows and never go negative.
 */
class SocialService
{
    public const PER_PAGE = 20;

    public const BODY_MAX = 2000;

    public const COMMENT_MAX = 1000;

    public const BIO_MAX = 300;

    public const TRACK_MAX = 600;

    /** Raw points accepted before thinning (a long day recorded every second). */
    public const TRACK_RAW_MAX = 50000;

    public const PHOTO_MAX_KB = 8192;

    public const PHOTO_MAX_PX = 1600;

    public const PHOTO_QUALITY = 82;

    /** Larger images are refused before decoding (memory). */
    public const PHOTO_MAX_PIXELS = 50_000_000;

    public const POSTS_PER_DAY = 30;

    public function __construct(private RouteApiPresenter $routes)
    {
    }

    // --- Posts -----------------------------------------------------------------

    /**
     * Creates a post, or returns the one this account already made with the same
     * client_id (app outbox retry). Returns [Post, bool $created].
     *
     * @throws HttpException 429 after POSTS_PER_DAY posts in 24 h
     * @throws ValidationException
     */
    public function createPost(User $user, array $input, ?UploadedFile $photo): array
    {
        $clientId = $this->clientId($input['client_id'] ?? null);
        if ($clientId !== null && ($existing = $this->byClientId($user, $clientId))) {
            return [$existing, false];
        }

        $dayKey = 'social-post-day:' . $user->id;
        if (RateLimiter::tooManyAttempts($dayKey, self::POSTS_PER_DAY)) {
            throw new HttpException(429, __('Batas :max kiriman per hari sudah tercapai. Coba lagi besok.', ['max' => self::POSTS_PER_DAY]),
                null, ['Retry-After' => (string) RateLimiter::availableIn($dayKey)]);
        }

        $data = $this->validatePost($input, $photo);
        $data['user_id']   = $user->id;
        $data['client_id'] = $clientId;
        if ($data['type'] === 'story') {
            $data['expires_at'] = now()->addHours(Post::STORY_HOURS);
        }
        if ($photo && in_array($data['type'], ['photo', 'recording', 'story'], true)) {
            $data['photo_path'] = $this->storePhoto($photo);
        }

        try {
            $post = Post::create($data);
        } catch (QueryException $e) {
            // Two retries raced past the lookup above: the unique (user_id, client_id) index caught it.
            if (isset($data['photo_path'])) {
                Storage::disk('public')->delete($data['photo_path']);
            }
            if ($clientId !== null && ($existing = $this->byClientId($user, $clientId))) {
                return [$existing, false];
            }
            throw $e;
        }

        RateLimiter::hit($dayKey, 86400);

        return [$post, true];
    }

    /**
     * Rules from docs/API.md. Returns the columns to store.
     *
     * @throws ValidationException
     */
    public function validatePost(array $input, ?UploadedFile $photo): array
    {
        $errors = [];
        $type   = $input['type'] ?? null;
        if (! is_string($type) || ! in_array($type, Post::TYPES, true)) {
            throw ValidationException::withMessages(['type' => __('Jenis kiriman tidak dikenal.')]);
        }

        $body = $this->text($input['body'] ?? null);
        if ($body !== null && mb_strlen($body) > self::BODY_MAX) {
            $errors['body'] = __('Tulisan terlalu panjang (maks. :max karakter).', ['max' => self::BODY_MAX]);
        }

        $place = $this->place($input['place'] ?? null, $errors);
        $track = in_array($type, ['recording'], true) ? $this->track($input['track'] ?? null, $errors) : null;
        $stats = $type === 'recording' ? $this->stats($input['stats'] ?? null, $errors) : null;

        $hasPhoto = false;
        if ($photo !== null && $type !== 'status') {
            $photoError = $this->photoError($photo);
            $photoError ? $errors['photo'] = $photoError : $hasPhoto = true;
        }

        $routeId = null;
        if (isset($input['route_id']) && $input['route_id'] !== '' && $input['route_id'] !== null) {
            $routeId = filter_var($input['route_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($routeId === false || ! HikingRoute::whereKey($routeId)->exists()) {
                $errors['route_id'] = __('Jalur yang dipilih tidak ditemukan.');
                $routeId = null;
            }
        }

        // What each type needs (skipped when that field already has its own error).
        $need = match ($type) {
            'status'    => $body === null && $place === null && ! isset($errors['place']) ? ['body', __('Tulis sesuatu atau tandai lokasi.')] : null,
            'photo'     => ! $hasPhoto && ! isset($errors['photo']) ? ['photo', __('Pilih foto yang mau dibagikan.')] : null,
            'recording' => $track === null && ! isset($errors['track']) ? ['track', __('Rekaman perlu jalur dengan minimal 2 titik.')] : null,
            'story'     => $body === null && ! $hasPhoto && ! isset($errors['photo']) ? ['body', __('Cerita perlu foto atau tulisan.')] : null,
        };
        if ($need) {
            $errors[$need[0]] = $need[1];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'type'     => $type,
            'body'     => $body,
            'track'    => $track,
            'stats'    => $stats,
            'place'    => $place,
            'route_id' => $routeId ?: null,
        ];
    }

    /** Soft delete; the photo file goes too (nobody can see the post again). */
    public function deletePost(Post $post): void
    {
        $post->delete();
        if ($post->photo_path) {
            Storage::disk('public')->delete($post->photo_path);
        }
    }

    /** A visible post by id, or null (deleted, expired story, unknown). */
    public function findVisible(int|string $id): ?Post
    {
        if (! ctype_digit((string) $id)) {
            return null;
        }

        return Post::visible()->with(['user:id,name,bio', 'route:id,name'])->find((int) $id);
    }

    // --- Likes, saves, comments, reports -----------------------------------------

    /** Returns ['liked' => bool, 'likes_count' => int]. */
    public function setLike(Post $post, User $user, bool $on): array
    {
        $count = DB::transaction(function () use ($post, $user, $on) {
            if ($on) {
                DB::table('post_likes')->insertOrIgnore(['post_id' => $post->id, 'user_id' => $user->id, 'created_at' => now()]);
            } else {
                DB::table('post_likes')->where(['post_id' => $post->id, 'user_id' => $user->id])->delete();
            }
            $count = DB::table('post_likes')->where('post_id', $post->id)->count();
            DB::table('posts')->where('id', $post->id)->update(['likes_count' => $count]);

            return $count;
        });
        $post->likes_count = $count;

        return ['liked' => $on, 'likes_count' => $count];
    }

    public function setSave(Post $post, User $user, bool $on): array
    {
        if ($on) {
            DB::table('post_saves')->insertOrIgnore(['post_id' => $post->id, 'user_id' => $user->id, 'created_at' => now()]);
        } else {
            DB::table('post_saves')->where(['post_id' => $post->id, 'user_id' => $user->id])->delete();
        }

        return ['saved' => $on];
    }

    /** @throws ValidationException */
    public function addComment(Post $post, User $user, mixed $body): PostComment
    {
        $body = $this->text($body);
        if ($body === null) {
            throw ValidationException::withMessages(['body' => __('Komentar masih kosong.')]);
        }
        if (mb_strlen($body) > self::COMMENT_MAX) {
            throw ValidationException::withMessages(['body' => __('Komentar terlalu panjang (maks. :max karakter).', ['max' => self::COMMENT_MAX])]);
        }

        return DB::transaction(function () use ($post, $user, $body) {
            $comment = PostComment::create(['post_id' => $post->id, 'user_id' => $user->id, 'body' => $body]);
            $this->recountComments($post);
            $comment->setRelation('user', $user);

            return $comment;
        });
    }

    public function deleteComment(PostComment $comment): void
    {
        DB::transaction(function () use ($comment) {
            $comment->delete();
            $this->recountComments($comment->post_id);
        });
    }

    /** Comment author or the post owner. */
    public function canDeleteComment(PostComment $comment, User $user): bool
    {
        return (int) $comment->user_id === (int) $user->id
            || (int) Post::withTrashed()->whereKey($comment->post_id)->value('user_id') === (int) $user->id;
    }

    /** One report per user and post; the first one puts the post on /admin/moderasi. */
    public function reportPost(Post $post, User $user, mixed $reason): void
    {
        $this->report('post_reports', 'post_id', $post->id, 'posts', $user, $reason);
    }

    public function reportComment(PostComment $comment, User $user, mixed $reason): void
    {
        $this->report('post_comment_reports', 'post_comment_id', $comment->id, 'post_comments', $user, $reason);
    }

    // --- Follows -------------------------------------------------------------------

    /**
     * Returns ['following' => bool, 'followers_count' => int].
     *
     * @throws ValidationException when following yourself
     */
    public function setFollow(User $follower, User $followed, bool $on): array
    {
        if ((int) $follower->id === (int) $followed->id) {
            throw ValidationException::withMessages(['user' => __('Kamu tidak bisa mengikuti diri sendiri.')]);
        }

        if ($on) {
            DB::table('follows')->insertOrIgnore(['follower_id' => $follower->id, 'followed_id' => $followed->id, 'created_at' => now()]);
        } else {
            DB::table('follows')->where(['follower_id' => $follower->id, 'followed_id' => $followed->id])->delete();
        }

        return ['following' => $on, 'followers_count' => $this->followersCount($followed)];
    }

    public function followersCount(User $user): int
    {
        return DB::table('follows')->where('followed_id', $user->id)->count();
    }

    public function followingCount(User $user): int
    {
        return DB::table('follows')->where('follower_id', $user->id)->count();
    }

    public function isFollowing(?User $viewer, User $user): bool
    {
        return $viewer !== null && DB::table('follows')->where(['follower_id' => $viewer->id, 'followed_id' => $user->id])->exists();
    }

    /** Ids among $userIds that $viewer follows. */
    public function followingIds(?User $viewer, array $userIds): array
    {
        if ($viewer === null || $userIds === []) {
            return [];
        }

        return DB::table('follows')->where('follower_id', $viewer->id)->whereIn('followed_id', $userIds)
            ->pluck('followed_id')->map(fn ($id) => (int) $id)->all();
    }

    // --- Lists ---------------------------------------------------------------------

    /** Base query for posts anyone may see, with what the JSON needs. */
    public function postQuery(): Builder
    {
        return Post::visible()->with(['user:id,name,bio', 'route:id,name']);
    }

    /** Feed: following = me + people I follow, or all. Stories are never in the feed. */
    public function feed(User $user, string $scope, ?string $cursor): array
    {
        $query = $this->postQuery()->where('type', '!=', 'story');
        if ($scope !== 'all') {
            $query->where(fn ($q) => $q->where('user_id', $user->id)
                ->orWhereIn('user_id', DB::table('follows')->select('followed_id')->where('follower_id', $user->id)));
        }

        return $this->page($query, 'posts.id', 'id', $cursor);
    }

    /**
     * Active stories of me and the people I follow, grouped by author: mine first,
     * then the author with the newest story first. Items oldest first.
     *
     * @return array<int, array{author: User, items: Collection}>
     */
    public function stories(User $user): array
    {
        $posts = $this->postQuery()->where('type', 'story')->whereNotNull('expires_at')
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->orWhereIn('user_id', DB::table('follows')->select('followed_id')->where('follower_id', $user->id)))
            ->orderBy('id')->limit(500)->get();

        return $posts->groupBy('user_id')
            ->map(fn (Collection $items) => ['author' => $items->first()->user, 'items' => $items->values(), 'newest' => (int) $items->max('id')])
            ->sortBy(fn ($g) => [(int) $g['author']->id === (int) $user->id ? 0 : 1, -$g['newest']])
            ->values()->all();
    }

    public function userPosts(User $author, ?string $cursor): array
    {
        return $this->page($this->postQuery()->where('user_id', $author->id), 'posts.id', 'id', $cursor);
    }

    public function savedPosts(User $user, ?string $cursor): array
    {
        $query = $this->postQuery()->join('post_saves', 'post_saves.post_id', '=', 'posts.id')
            ->where('post_saves.user_id', $user->id)
            ->select('posts.*', 'post_saves.id as save_id');

        return $this->page($query, 'post_saves.id', 'save_id', $cursor);
    }

    public function comments(Post $post, ?string $cursor): array
    {
        return $this->page(PostComment::with('user:id,name')->where('post_id', $post->id), 'id', 'id', $cursor, 'asc');
    }

    /** followers or following of $user, newest follow first. */
    public function people(User $user, string $which, ?string $cursor): array
    {
        [$mine, $theirs] = $which === 'followers' ? ['followed_id', 'follower_id'] : ['follower_id', 'followed_id'];
        $query = User::query()->join('follows', "follows.$theirs", '=', 'users.id')
            ->where("follows.$mine", $user->id)
            ->select('users.id', 'users.name', 'users.bio', 'follows.id as follow_id');

        return $this->page($query, 'follows.id', 'follow_id', $cursor);
    }

    /** Name contains $q (at least 2 characters), 20 results. */
    public function searchUsers(string $q): Collection
    {
        $like = '%' . addcslashes($q, '\\%_') . '%';

        return User::query()->where('name', 'like', $like)->orderBy('name')->orderBy('id')
            ->limit(self::PER_PAGE)->get(['id', 'name', 'bio']);
    }

    /**
     * Keyset page: PER_PAGE rows after the cursor, ordered by $column.
     * Returns [Collection $items, ?string $nextCursor].
     */
    public function page(Builder $query, string $column, string $attribute, ?string $cursor, string $dir = 'desc'): array
    {
        $after = $this->decodeCursor($cursor);
        if ($after !== null) {
            $query->where($column, $dir === 'desc' ? '<' : '>', $after);
        }

        $rows  = $query->orderBy($column, $dir)->limit(self::PER_PAGE + 1)->get();
        $more  = $rows->count() > self::PER_PAGE;
        $items = $rows->take(self::PER_PAGE)->values();

        return [$items, $more ? $this->encodeCursor((int) $items->last()->getAttribute($attribute)) : null];
    }

    public function encodeCursor(int $id): string
    {
        return rtrim(strtr(base64_encode('k' . $id), '+/', '-_'), '=');
    }

    public function decodeCursor(?string $cursor): ?int
    {
        if (! is_string($cursor) || $cursor === '' || strlen($cursor) > 40 || preg_match('/[^A-Za-z0-9_-]/', $cursor)) {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);

        return is_string($raw) && preg_match('/^k(\d{1,18})$/', $raw, $m) ? (int) $m[1] : null;
    }

    // --- Text and profile ------------------------------------------------------------

    /** Plain text (no control characters), trimmed; null when empty. */
    public function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $text = CommunityChatController::clean((string) $value);

        return $text === '' ? null : $text;
    }

    /** One line of plain text (bios and place labels). */
    public function line(mixed $value): ?string
    {
        $text = $this->text($value);

        return $text === null ? null : (trim(preg_replace('/\s+/u', ' ', $text) ?? '') ?: null);
    }

    // --- Internals -------------------------------------------------------------------

    private function clientId(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        if (strlen($value) > 40 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
            throw ValidationException::withMessages(['client_id' => __('client_id hanya boleh huruf, angka, titik, titik dua, garis bawah, atau tanda hubung (maks. 40).')]);
        }

        return $value;
    }

    /**
     * The post this account made with $clientId. A retry for a post that was deleted
     * since gets a 422, so the app drops it from its outbox instead of retrying forever.
     *
     * @throws ValidationException
     */
    private function byClientId(User $user, string $clientId): ?Post
    {
        $post = Post::withTrashed()->where('user_id', $user->id)->where('client_id', $clientId)->first();
        if ($post?->trashed()) {
            throw ValidationException::withMessages(['client_id' => __('Kiriman dengan client_id ini sudah dihapus.')]);
        }

        return $post;
    }

    private function json(mixed $value): mixed
    {
        if (is_string($value)) {
            if (strlen($value) > 2_000_000) {
                return false;
            }
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : false;
        }

        return $value;
    }

    private function track(mixed $value, array &$errors): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $points = $this->json($value);
        if (! is_array($points) || ! array_is_list($points) || count($points) > self::TRACK_RAW_MAX) {
            $errors['track'] = __('Format jalur tidak dikenali.');

            return null;
        }
        foreach ($points as $p) {
            if (! is_array($p) || ! isset($p[0], $p[1]) || ! is_numeric($p[0]) || ! is_numeric($p[1])
                || abs((float) $p[0]) > 90 || abs((float) $p[1]) > 180) {
                $errors['track'] = __('Format jalur tidak dikenali.');

                return null;
            }
        }
        if (count($points) < 2) {
            $errors['track'] = __('Rekaman perlu jalur dengan minimal 2 titik.');

            return null;
        }

        return $this->routes->thin($points, self::TRACK_MAX);
    }

    private function stats(mixed $value, array &$errors): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $stats = $this->json($value);
        $limits = ['distance_km' => 2000, 'gain_m' => 30000, 'moving_s' => 3_000_000];
        if (! is_array($stats)) {
            $errors['stats'] = __('Format statistik tidak dikenali.');

            return null;
        }

        $out = [];
        foreach ($limits as $key => $max) {
            if (! array_key_exists($key, $stats) || $stats[$key] === null) {
                continue;
            }
            if (! is_numeric($stats[$key]) || $stats[$key] < 0 || $stats[$key] > $max) {
                $errors['stats'] = __('Format statistik tidak dikenali.');

                return null;
            }
            $out[$key] = $key === 'distance_km' ? round((float) $stats[$key], 2) : (int) round((float) $stats[$key]);
        }

        return $out ?: null;
    }

    private function place(mixed $value, array &$errors): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $place = $this->json($value);
        if (! is_array($place) || ! isset($place['lat'], $place['lng']) || ! is_numeric($place['lat']) || ! is_numeric($place['lng'])
            || abs((float) $place['lat']) > 90 || abs((float) $place['lng']) > 180) {
            $errors['place'] = __('Lokasi perlu lat dan lng yang valid.');

            return null;
        }
        $label = $this->line($place['label'] ?? null);
        if ($label !== null && mb_strlen($label) > 80) {
            $errors['place'] = __('Nama lokasi maks. :max karakter.', ['max' => 80]);

            return null;
        }

        return ['lat' => round((float) $place['lat'], 6), 'lng' => round((float) $place['lng'], 6), 'label' => $label];
    }

    /** Checks the bytes, not the name: JPEG, PNG or WebP that GD can open. */
    private function photoError(UploadedFile $photo): ?string
    {
        $invalid = __('Foto harus berupa gambar JPG, PNG, atau WebP.');
        if (! $photo->isValid()) {
            return __('Foto gagal diunggah. Coba lagi.');
        }
        if ($photo->getSize() > self::PHOTO_MAX_KB * 1024) {
            return __('Foto maks. :mb MB.', ['mb' => self::PHOTO_MAX_KB / 1024]);
        }
        $info = @getimagesize($photo->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return $invalid;
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::PHOTO_MAX_PIXELS) {
            return __('Ukuran gambar terlalu besar.');
        }

        return null;
    }

    /**
     * Re-encodes to JPEG (longest side max PHOTO_MAX_PX, quality PHOTO_QUALITY) with GD,
     * which drops EXIF (location, camera). A random name under storage/app/public/posts.
     *
     * @throws ValidationException when GD cannot decode the image
     */
    private function storePhoto(UploadedFile $photo): string
    {
        $previous = ini_get('memory_limit');
        @ini_set('memory_limit', '512M');

        try {
            $bytes = (string) file_get_contents($photo->getRealPath());
            $src   = @imagecreatefromstring($bytes);
            if (! $src) {
                throw ValidationException::withMessages(['photo' => __('Foto harus berupa gambar JPG, PNG, atau WebP.')]);
            }
            $src = $this->orient($src, $photo->getRealPath());

            $w = imagesx($src);
            $h = imagesy($src);
            $scale = min(1, self::PHOTO_MAX_PX / max($w, $h));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));

            // White canvas: transparent PNG/WebP areas become white instead of black.
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($src);
            imageinterlace($dst, true);

            ob_start();
            imagejpeg($dst, null, self::PHOTO_QUALITY);
            $jpeg = (string) ob_get_clean();
            imagedestroy($dst);

            $path = 'posts/' . Str::random(40) . '.jpg';
            Storage::disk('public')->put($path, $jpeg);

            return $path;
        } finally {
            @ini_set('memory_limit', (string) $previous);
        }
    }

    /** Phone photos store rotation in EXIF; apply it before EXIF is dropped. */
    private function orient(\GdImage $img, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $img;
        }
        $rotated = imagerotate($img, $angle, 0);
        if ($rotated) {
            imagedestroy($img);

            return $rotated;
        }

        return $img;
    }

    private function report(string $table, string $column, int $id, string $target, User $user, mixed $reason): void
    {
        $reason = $this->line($reason);
        DB::transaction(function () use ($table, $column, $id, $target, $user, $reason) {
            $inserted = DB::table($table)->insertOrIgnore([
                $column      => $id,
                'user_id'    => $user->id,
                'reason'     => $reason !== null ? mb_substr($reason, 0, 300) : null,
                'created_at' => now(),
            ]);
            if ($inserted) {
                DB::table($target)->where('id', $id)->whereNull('reported_at')->update(['reported_at' => now()]);
            }
        });
    }

    private function recountComments(Post|int $post): void
    {
        $id    = $post instanceof Post ? $post->id : $post;
        $count = PostComment::where('post_id', $id)->count();
        DB::table('posts')->where('id', $id)->update(['comments_count' => $count]);
        if ($post instanceof Post) {
            $post->comments_count = $count;
        }
    }
}
