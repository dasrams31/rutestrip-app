<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HikingRoute extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'basecamp_name',
        'basecamp_address',
        'basecamp_lat',
        'basecamp_lng',
        'entry_fee',
        'contact_phone',
        'facilities',
        'best_season',
        'tips',
        'image_path',
        'route_coordinates',
        'gpx_file_path',
        'distance_km',
        'elevation_gain_m',
        'naismith_duration_hour',
        'average_grade_pct',
        'narrative_text',
        'embedding_text',
        'sbert_embedding',
        'sbert_aspect_embeddings',
    ];

    protected $casts = [
        'distance_km'            => 'float',
        'elevation_gain_m'       => 'integer',
        'naismith_duration_hour' => 'float',
        'average_grade_pct'      => 'float',
        'sbert_embedding'        => 'array',
        'sbert_aspect_embeddings' => 'array',
        'route_coordinates'      => 'array',
    ];

    protected static function booted(): void
    {
        // Keep search embeddings (name + narrative + aspects) in sync whenever the
        // text that feeds them changes. Disabled in tests via ROUTE_SEARCH_AUTO_INDEX=false.
        // (wasRecentlyCreated stays true on the same instance, so "created" and
        // "updated" are handled separately.)
        $index = function (HikingRoute $route) {
            if (config('services.python.auto_index', true)) {
                app(\App\Services\RouteSearchIndexer::class)->index($route);
            }
        };

        static::created($index);
        static::updated(function (HikingRoute $route) use ($index) {
            if ($route->wasChanged(['name', 'narrative_text', 'description'])) {
                $index($route);
            }
        });
    }

    /**
     * Get formatted distance
     */
    public function getFormattedDistanceAttribute(): string
    {
        return number_format($this->distance_km, 2) . ' km';
    }

    /**
     * Get formatted duration
     */
    public function getFormattedDurationAttribute(): string
    {
        $hours   = floor($this->naismith_duration_hour);
        $minutes = round(($this->naismith_duration_hour - $hours) * 60);
        return "{$hours} jam {$minutes} menit";
    }

    /**
     * Get formatted grade
     */
    public function getFormattedGradeAttribute(): string
    {
        return number_format($this->average_grade_pct, 1) . '%';
    }

    /**
     * Get difficulty level based on grade
     */
    public function getDifficultyLevelAttribute(): string
    {
        if ($this->average_grade_pct < 5) {
            return 'Mudah';
        }

        if ($this->average_grade_pct < 10) {
            return 'Sedang';
        }

        if ($this->average_grade_pct < 15) {
            return 'Sulit';
        }

        return 'Sangat Sulit';
    }

    /**
     * Relationships
     */
    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function ratings()
    {
        return $this->hasMany(Rating::class);
    }

    public function likes()
    {
        return $this->hasMany(Favorite::class);
    }

    public function gpxVersions()
    {
        return $this->hasMany(GpxVersion::class);
    }

    public function activeGpxVersion()
    {
        return $this->hasOne(GpxVersion::class)->where('is_active', true);
    }

    /**
     * Get average rating
     */
    public function getAverageRatingAttribute(): float
    {
        return round($this->ratings()->avg('rating') ?? 0, 1);
    }

    /**
     * Get total likes count
     */
    public function getLikesCountAttribute(): int
    {
        return $this->likes()->count();
    }

    /**
     * Check if user liked this route
     */
    public function isLikedBy($userId): bool
    {
        return $this->likes()->where('user_id', $userId)->exists();
    }
}
