<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A hiker's live position link (see LiveShareController). Only the latest fix is stored. */
class LiveShare extends Model
{
    use MassPrunable;

    public const MAX_HOURS = 48;

    /** Position columns: cleared the moment a link ends (stopped or expired). */
    public const POSITION = ['lat' => null, 'lng' => null, 'accuracy_m' => null, 'elevation_m' => null, 'battery' => null, 'position_at' => null];

    protected $fillable = ['user_id', 'token', 'label', 'expires_at'];

    protected $casts = [
        'lat'         => 'float',
        'lng'         => 'float',
        'accuracy_m'  => 'float',
        'elevation_m' => 'float',
        'battery'     => 'integer',
        'position_at' => 'datetime',
        'expires_at'  => 'datetime',
        'stopped_at'  => 'datetime',
    ];

    protected $hidden = ['token'];

    public static function newToken(): string
    {
        return Str::random(40);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->stopped_at === null && $this->expires_at->isFuture();
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('stopped_at')->where('expires_at', '>', now());
    }

    /** Ends the link now and forgets where the hiker was. */
    public function end(): void
    {
        $this->forceFill(self::POSITION + ['stopped_at' => $this->stopped_at ?? now()])->save();
    }

    /**
     * Ended links keep no position; rows are deleted a day after they end
     * (`php artisan model:prune`, scheduled daily, and on every new link).
     */
    public static function forgetEnded(): void
    {
        static::query()
            ->where(fn (Builder $q) => $q->whereNotNull('stopped_at')->orWhere('expires_at', '<=', now()))
            ->whereNotNull('position_at')
            ->update(self::POSITION);
    }

    public function prunable(): Builder
    {
        return static::query()->where(fn (Builder $q) => $q->where('stopped_at', '<', now()->subDay())->orWhere('expires_at', '<', now()->subDay()));
    }

    public function url(): string
    {
        return route('live.show', $this->token);
    }
}
