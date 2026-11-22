<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A hiker's live position link (see LiveShareController). Only the latest fix is stored. */
class LiveShare extends Model
{
    public const MAX_HOURS = 48;

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

    public function url(): string
    {
        return route('live.show', $this->token);
    }
}
