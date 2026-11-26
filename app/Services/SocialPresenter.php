<?php

namespace App\Services;

use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * JSON shapes of the Linimasa (docs/API.md "Post JSON"), used by the API and,
 * as plain arrays, by the web pages. Text is returned as stored (plain text);
 * Blade escapes it, the app shows it as text.
 */
class SocialPresenter
{
    public function __construct(private SocialService $social)
    {
    }

    /** Several posts with liked/saved for the viewer in two queries. */
    public function posts(iterable $posts, ?User $viewer): array
    {
        $posts = collect($posts);
        $ids   = $posts->pluck('id')->map(fn ($id) => (int) $id)->all();
        [$liked, $saved] = $this->flags($ids, $viewer);

        return $posts->map(fn (Post $p) => $this->post($p, $viewer, $liked, $saved))->values()->all();
    }

    public function post(Post $p, ?User $viewer, ?array $liked = null, ?array $saved = null): array
    {
        if ($liked === null || $saved === null) {
            [$liked, $saved] = $this->flags([(int) $p->id], $viewer);
        }
        $stats = is_array($p->stats) ? $p->stats : null;
        $place = is_array($p->place) ? $p->place : null;

        return [
            'id'             => (int) $p->id,
            'type'           => $p->type,
            'body'           => $p->body,
            'photo_url'      => $p->photoUrl(),
            'track'          => is_array($p->track) && count($p->track) >= 2 ? $p->track : null,
            'stats'          => $stats ? [
                'distance_km' => isset($stats['distance_km']) ? (float) $stats['distance_km'] : null,
                'gain_m'      => isset($stats['gain_m']) ? (int) $stats['gain_m'] : null,
                'moving_s'    => isset($stats['moving_s']) ? (int) $stats['moving_s'] : null,
            ] : null,
            'place'          => $place ? ['lat' => (float) $place['lat'], 'lng' => (float) $place['lng'], 'label' => $place['label'] ?? null] : null,
            'route'          => $p->route ? ['id' => (int) $p->route->id, 'name' => $p->route->name] : null,
            'author'         => $this->author($p->user),
            'likes_count'    => (int) $p->likes_count,
            'comments_count' => (int) $p->comments_count,
            'liked'          => in_array((int) $p->id, $liked, true),
            'saved'          => in_array((int) $p->id, $saved, true),
            'is_mine'        => $viewer !== null && (int) $p->user_id === (int) $viewer->id,
            'created_at'     => $p->created_at?->toIso8601String(),
            'expires_at'     => $p->expires_at?->toIso8601String(),
            'share_url'      => route('posts.show', $p->id),
        ];
    }

    public function author(?User $user): array
    {
        return [
            'id'   => (int) ($user?->id ?? 0),
            'name' => $user?->name ?? __('Pendaki'),
            'bio'  => $user?->bio,
        ];
    }

    public function comment(PostComment $c, ?User $viewer): array
    {
        return [
            'id'         => (int) $c->id,
            'body'       => $c->body,
            'author'     => ['id' => (int) ($c->user?->id ?? 0), 'name' => $c->user?->name ?? __('Pendaki')],
            'created_at' => $c->created_at?->toIso8601String(),
            'is_mine'    => $viewer !== null && (int) $c->user_id === (int) $viewer->id,
        ];
    }

    /** {id, name, bio, is_following} for lists of people. */
    public function people(iterable $users, ?User $viewer): array
    {
        $users     = collect($users);
        $following = $this->social->followingIds($viewer, $users->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $users->map(fn (User $u) => [
            'id'           => (int) $u->id,
            'name'         => $u->name,
            'bio'          => $u->bio,
            'is_following' => in_array((int) $u->id, $following, true),
        ])->values()->all();
    }

    /** GET /users/{id}. */
    public function profile(User $user, ?User $viewer): array
    {
        $isMe = $viewer !== null && (int) $viewer->id === (int) $user->id;

        return [
            'id'              => (int) $user->id,
            'name'            => $user->name,
            'bio'             => $user->bio,
            'followers_count' => $this->social->followersCount($user),
            'following_count' => $this->social->followingCount($user),
            'posts_count'     => Post::visible()->where('user_id', $user->id)->count(),
            'is_following'    => ! $isMe && $this->social->isFollowing($viewer, $user),
            'follows_me'      => ! $isMe && $viewer !== null && $this->social->isFollowing($user, $viewer),
            'is_me'           => $isMe,
        ];
    }

    /** @return array{0: int[], 1: int[]} post ids the viewer liked and saved */
    private function flags(array $ids, ?User $viewer): array
    {
        if ($viewer === null || $ids === []) {
            return [[], []];
        }
        $pluck = fn (string $table) => DB::table($table)->where('user_id', $viewer->id)->whereIn('post_id', $ids)
            ->pluck('post_id')->map(fn ($id) => (int) $id)->all();

        return [$pluck('post_likes'), $pluck('post_saves')];
    }
}
