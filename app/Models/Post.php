<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * One Linimasa post (docs/API.md): status, photo, recording or story.
 * Body is plain text; a story disappears 24 h after it was posted (expires_at).
 * A soft-deleted post (by its owner or an admin) never shows again.
 */
class Post extends Model
{
    use SoftDeletes;

    public const TYPES = ['status', 'photo', 'recording', 'story'];

    public const STORY_HOURS = 24;

    protected $fillable = [
        'user_id', 'type', 'body', 'photo_path', 'track', 'stats', 'place',
        'route_id', 'expires_at', 'client_id',
    ];

    protected function casts(): array
    {
        return [
            'track'          => 'array',
            'stats'          => 'array',
            'place'          => 'array',
            'expires_at'     => 'datetime',
            'reported_at'    => 'datetime',
            'likes_count'    => 'integer',
            'comments_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(HikingRoute::class, 'route_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class);
    }

    /** One row per hiker who reported the post (table post_reports). */
    public function reports(): HasMany
    {
        return $this->hasMany(PostReport::class);
    }

    /** Not an expired story (soft-deleted posts are already left out by SoftDeletes). */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }
}
